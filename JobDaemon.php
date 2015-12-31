<?php
/**
 * The MIT License (MIT)
 *
 * Copyright (c) 2015 Daniel Kadosh
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

/**
 * PHP implementation of a daemon to parallelize work (jobs) under multiple
 * fork()'ed child processes.
 *
 * While there are other mechanisms (gearman, PECL pthreads) to parallelize
 * work with PHP, this solution may have advantages for some architectures
 * where simplicity and ease of implementation are of major importance.
 *
 * This may not be the right solution to handle a very high volume of child
 * processes starting and stopping frequently.  With that said, one could
 * implement a "worker" pattern, where each child process runs multiple
 * "jobs" or requests in a loop before it decides to shut itself down.
 *
 * This is well-suited for ETL applications, where the biggest bottleneck is
 * a database or calling some CPU-intensive program (image/video processing),
 * and you want to use PHP to implement the controlling & launching code.
 *
 * Ideally, all your requires/includes should run when the daemon parent
 * process starts, such that fork()'ing a child doesn't incur the overhead
 * of PHP re-compiling the application into bytecode.  In other words,
 * use of an autoloader will reduce efficiency when launching new child
 * processes.
 *
 * This is an abstract class, with only 3 methods to integrate with your
 * application:
 * 1) loadConfig(): Optional, to load or update any app configuration.
 * 2) getNextJob(): Called before launching a child process, to return either
 *                  metadata about the next job, or NULL if no job ready.
 * 3) childRun(): Entrypoint for your application to run a job, given the
 *                metadata from above.
 *
 * Your application must extend this JobDaemon class, instantiate it,
 * optionally call some methods to tweak settings (like setMaxChildren),
 * and then call the daemonRun() method.
 * When daemonRun() returns, your application can handle any extra post-run
 * cleanup required.
 *
 * Features:
 * - Truly daemonized at startup by parent forking itself and detaching
 *   from the TTY, and creating a PID file (only 1 daemon instance on server).
 * - Can run as a non-root user, as root, or optionally use seteuid() to a
 *   different user (useful when auto-launched at system boot time).
 * - Handles SIGTERM / SIGQUIT signals: no more children spawned, optionally
 *   sends signal to children, and waits for all child processes to die.
 * - Handles SIGHUP: calls user-defineable loadConfig() method.
 * - Full setup as a Un*x daemon with an init.d script
 * - Uses "daemon" logging level, to separate out daemon debug logging
 *   from application logging.
 */

// Written to use this logger class:
require_once('Logger.php');

/*
 * Settings required for daemon to run forever, and proper handling of signals
 * sent to it.
 */
declare(ticks = 1); // Be sure that each signal is handled when it is received.
ini_set("max_execution_time", 0);
ini_set("max_input_time", 0);
set_time_limit(0);

// Main class
abstract class JobDaemon {
	// Sleep time when no job is ready
	protected $nIdleSleepTimeUsec = 2000000; // 2 seconds

	// Sleep time between checks when all slots taken
	protected $nNoSlotsSleepTimeUsec = 10000; // 10mSec

	// Maximum concurrent running child processes
	private $nMaxChildren = 2;

	// Signals the daemon should handle
	protected $aSignalsToHandle = [	SIGQUIT, SIGTERM, SIGHUP ];

	// Storage for unprocessed signal
	public static $nSignalReceived = NULL;

	// RAM size for SysV shared memory segment
	// Default from sysvshm.init_mem in the php.ini, or 10000 bytes.
	// Settable ONLY through constructor
	private $nShmMemsize = NULL;

	// SysV Inter-Process Communication
	// Settable ONLY from constructor
	private $nIPCKey;			// System V IPC key from ftok()
	private $rSharedMemSegment;	// Shared memory segment from shm_attach()
	private $rMutexGlobal;		// Global Mutex
	private $sPIDFileName;		// PID file for daemon + SYS V IPC
	private $nParentPID;
	private $sDaemonName;		// Base filename of daemon (PHP script name)
	private $nUIDToRunUnder;	// Optional, to not run as root

	// Keep track of child PIDs, to send them kill signals as needed
	private $bSendChildrenUNIXSignals = FALSE;
	private $aChildPIDs = array(); // Array used only in parent process

	// Booleans to manage termination of the daemon
	protected $bTerminate;		// Request to terminate cleanly, wait on children
	private $bRunLoop;			// Flag for main loop to continue running

	// Indeces of variables in shared memory: for shm_put_var(), shm_get_var()
	const _IDX_RUNSTATUS = 0;	// Parent run status
	const _IDX_CHILDREN	 = 1;	// Count of active child processes
	const _IDX_SLOTS	 = 2;	// Array of available slots
	const _IDX_DATA		 = 3;	// Application shared data

	// Parent's run status
	const _STAT_TERMINATE = 0;	// Terminate all children
	const _STAT_RUN		  = 1;	// Normal run state

	////////////////////////////////////////////////////////////////////////
	//
	// The 3 methods to implement in the application that extends this class

	/**
	 * Optional method called at init, and upon receipt of a SIGHUP signal,
	 * meant to (re)load the application configuration.
	 * If bSendChildrenUNIXSignals is TRUE, this method will be called under
	 * the child processes as well when the parent gets a SIGHUP.
	 */
	public function loadConfig() {}

	/**
	 * Request metadata for the next job to run in a new child process.
	 * This method should perform whatever checks or queries are needed in your
	 * application to determine if a new "job" is ready for processing, and
	 * if there is, it should return whatever metadata your child process may
	 * need to run that job. Use TRUE your app doesn't need to pass
	 * metadata to the child.
	 * Return NULL/FALSE if there is currently no job ready and the daemon
	 * should wait.
	 * You can set/get (small) application-specific variables to share among
	 * parent and child processes with setAppVar() and getAppVar().
	 *
	 * @param int $nSlot Child slot number
	 * @return mixed Metadata for child, or NULL or FALSE if no job ready
	 */
	abstract protected function getNextJob($nSlot);

	/**
	 * "Main" method for newly forked child process to start its work.
	 * You can set/get (small) application-specific variables to share among
	 * parent and child processes with setAppVar() and getAppVar().
	 *
	 * @param mixed $mJobMetadata Metadata about job returned by getNextJob()
	 * @param int $nSlot Child slot number
	 * @return int Exit code for child process to call exit()
	 */
	abstract protected function childRun($mJobMetadata, $nSlot);

	////////////////////////////////////////////////////////////////////////
	//
	// Constructor and its initialization methods

	/**
	 * @param int $nUIDToRunUnder Existing User ID to run under, to avoid
	 *        running as root.
	 * @param string $sPIDFileName Filename for parent's process ID, and
	 *        for SysV IPC. Default: /var/run/SCRIPT_NAME.pid
	 * @param int $nShmMemsize Shared RAM to reserve for IPC; Default from
	 *        sysvshm.init_mem in the php.ini, or 10000 bytes
	 * @return JobDaemon
	 */
	public function __construct($nUIDToRunUnder=NULL, $sPIDFileName=NULL, $nShmMemsize=NULL) {
		// Ensure a PID file is created
		$this->sDaemonName = basename($_SERVER['SCRIPT_NAME'], '.php');
		$this->sPIDFileName = empty($sPIDFileName) ?
				'/var/run/'.$this->sDaemonName.'.pid' : $sPIDFileName;
		if ( ! $this->_checkPIDFile() ) {
			throw new Exception('Cannot secure PID file '.$this->sPIDFileName);
		}

		// Daemonize -- fork & disconnect from tty
		$nNewPID = pcntl_fork();
		if ( $nNewPID==-1 ) {
			throw new Exception('Cannot fork porcess');
		} elseif ( $nNewPID ) {
			print $this->sDaemonName.": Starting daemon under pid=$nNewPID\n";
			exit(0);
		}
		// Now running as detached process -- the real parent
		if ( ! posix_setsid() ) {
			throw new Exception('Cannot dettach from terminal!');
		}

		// Basic checks OK, write out PID file
		$this->nParentPID = posix_getpid();
		$this->nUIDToRunUnder = (int) $nUIDToRunUnder;
		if ( ! $this->_writePIDFile() ) {
			// Should never happen, unless disk full...
			throw new Exception('Cannot write to PID file!');
		}
		if ( ! $this->_switchUser() ) {
			throw new Exception("Cannot switch to UID=$nUIDToRunUnder");
		}

		// Init optional variables
		if ( ! empty($nShmMemsize) ) {
			$this->nShmMemsize = (int) $nShmMemsize;
		}
	}

	/**
	 * Manage PID file: verifies no other instance of daemon is running,
	 * and will immediately exit(-1) if it DOES find one running, or it can't
	 * create the PID file.
	 *
	 * @return boolean TRUE=Success
	 */
	private function _checkPIDFile() {
		// Ensure no currently running daemon
		if ( file_exists($this->sPIDFileName) ) {
			$nOldPID = trim(file_get_contents($this->sPIDFileName));

			// Clean up if previous daemon didn't clean up after itself
			// and handle EPERM error
			//   http://www.php.net/manual/en/function.posix-kill.php#82560
			if ( $nOldPID ) {
				// This signal of 0 only checks whether the old PID is running
				$bRunning = posix_kill($nOldPID, 0);
				if ( posix_get_last_error()==1 ) {
					$bRunning = TRUE;
				}
				// ONLY if confirmed not running should we remove the file
				if ( !$bRunning ) {
					unlink($this->sPIDFileName);
				}
			}
		}

		if ( file_exists($this->sPIDFileName) ) {
			print 'Daemon pid='.$nOldPID.' still running according to PID file '.$this->sPIDFileName."\n";
			exit(-1);
		}
		if ( ! touch($this->sPIDFileName) ) {
			Logger::getInstance()->error('Cannot create PID file '.$this->sPIDFileName);
			exit(-1);
		}
		return TRUE;
	}

	/**
	 * Create the PID file, ideally under /var/run
	 *
	 * @return boolean TRUE=Success
	 */
	private function _writePIDFile() {
		// Just touched it, so MUST be able to write to it!
		if ( ! file_put_contents($this->sPIDFileName, $this->nParentPID) ) {
			Logger::getInstance()->error('Cannot write PID file '.$this->sPIDFileName);
			return FALSE;
		}
		// Success!
		Logger::getInstance()->daemon('Successfully created PID file '.$this->sPIDFileName);
		return TRUE;
	}

	/**
	 * Runs seteuid(nUIDToRunUnder), and chowns the PIDFileName
	 *
	 * @return boolean TRUE=Success
	 */
	private function _switchUser() {
		if ( empty($this->nUIDToRunUnder) ||
				posix_geteuid()==$this->nUIDToRunUnder ) {
			// Nothing to do!
			return TRUE;
		}
		// Ensure we can delete our own PID file
		chown($this->sPIDFileName,$this->nUIDToRunUnder);
		if ( ! posix_seteuid($this->nUIDToRunUnder) ) {
			// Couldn't change UID, so clean up!
			unlink($this->sPIDFileName);
			return FALSE;
		}
		return TRUE;
	}

	/**
	 * Registered signal handler function - has to be static
	 *
	 * @param int $nSigno
	 */
	public static function signalHandler($nSigno) {
		// Using a global variable for the instantiated object, and application
		// to have easy access to the signal received.
		static::$nSignalReceived = $nSigno;
		Logger::getInstance()->warn("Daemon received signal #".static::$nSignalReceived);
	}

	////////////////////////////////////////////////////////////////////////
	//
	// Main daemon run-time handler and its helper methods

	/**
	 * Main daemon loop: request next job using getNextJob() and execute
	 * childRun() in a separate child process.
	 * Takes care of all the child process housekeeping like cleaning up
	 * after zombie processes.
	 */
	public function daemonRun() {
		$this->bRunLoop   = TRUE;
		$this->bTerminate = FALSE;
		$this->_daemonInit();

		while ( $this->bRunLoop ) {
			$this->mutexLock();

			// First clear out ALL children that ended or are zombies
			$this->_waitForChildrenEnding(FALSE);

			// Check for, and process signals
			$this->_checkAndProcessSignalReceived();

			// See if some bad error happened that we need to exit
			if ( ! $this->bRunLoop ) {
				$this->mutexUnlock();
				continue;
			}

			// Wait for a free slot
			while ( !$this->bTerminate && !$this->hasAvailableSlot() ) {
				$this->mutexUnlock();
				Logger::getInstance()->daemon('Sleeping until slots free up');

				if ( $this->_waitForChildrenEnding(TRUE) ) {
					// Got a signal, go back to main loop to process it, and
					// get back into this loop to wait for a new slot.
					continue 2;
				}

				// Detect error condition and terminate
				if ( ! $this->bRunLoop ) {
					continue 2;
				}

				$this->mutexLock();
			}

			// If terminating, just loop until all jobs are finished.
			if ( $this->bTerminate ) {
				// Let children know to terminate
				$this->setRunStatus(self::_STAT_TERMINATE);
				$this->mutexUnlock();
				$this->_waitForChildrenTermination();
				// This terminates the daemon
				$this->bRunLoop = FALSE;
				continue;
			}

			// Double-check for signal before setting up a new child
			if ( static::$nSignalReceived ) {
				// Let's just go to top of the main loop again to process it
				$this->mutexUnlock();
				continue;
			}

			// Prep a slot for the new child process
			$nSlot = $this->_assignSlot();
			if ( is_null($nSlot) ) {
				// No free slot after all - might happen if app reduced max
				// number of children.
				$this->mutexUnlock();
				continue;
			} else if ( $nSlot==-1 ) {
				// Error assigning slot - This should NOT happen!
				$this->mutexUnlock();
				$this->bRunLoop = FALSE; // Quit now
				continue;
			}

			// Go get the next job
			$this->_incRunningChildren();
			$this->mutexUnlock();
			try {
				$mJobMetadata = $this->getNextJob($nSlot);
			} catch (Exception $e) {
				// If we caught an exception here, the app is borked, so we
				// should attempt to terminate gracefully.
				Logger::getInstance()->error('getNextJob() Exception: '.$e->getMessage());
				$this->bTerminate = TRUE;
				$this->_clearSlotAndDecChildren($nSlot);
				continue;
			}

			// No job ready, sleep for a bit, and start loop again
			if ( ! $mJobMetadata ) {
				// Release slot
				$this->_clearSlotAndDecChildren($nSlot);

				// Sleep ONLY if getNextJob() didn't want to terminate
				if ( ! $this->bTerminate ) {
					Logger::getInstance()->daemon("No job, sleeping at most $this->nIdleSleepTimeUsec microsec");
					usleep($this->nIdleSleepTimeUsec);
				}
				// Back to top of loop
				continue;
			}

			// All clear: fork off the child process
			$nPID = pcntl_fork();

			// Handle fork() error:
			if ( $nPID==-1 ) {
				Logger::getInstance()->error(__METHOD__.' pcntl_fork() failed');
				// Don't just quit; wait till children finish
				$this->bTerminate = TRUE;
				continue;
			}

			// PARENT process: get ready for next loop around
			if ( $nPID>0 ) {
				// Capture the child's PID, and clean up RAM.
				$this->aChildPIDs[$nSlot] = $nPID;
				unset($mJobMetadata);

				// Brief pause to let ending or zombie children "settle"
				// before cleaning up for them at the top of this loop.
				usleep(20);
				continue;
			}

			// CHILD process: Kick off its work here
			$this->aChildPIDs = array(); // clear out RAM in child process
			$this->_executeChild($mJobMetadata, $nSlot);
			// Above method calls exit() no matter what
		}

		$this->_daemonEnd();
		// Don't exit() here, let application handle that
	}

	/**
	 * Called before the main loop starts:
	 * Initialize IPC variables and shared memory, call loadConfig(), and
	 * install signal handler method.
	 */
	private function _daemonInit() {
		Logger::getInstance()->info($this->sDaemonName.': Starting daemon');

		// Set up shared memory
		$this->nIPCKey = ftok($this->sPIDFileName, 'g'); // System V IPC Key
		if ( empty($this->nShmMemsize) ) {
			// Using default shared memory size from PHP config
			$this->rSharedMemSegment = shm_attach($this->nIPCKey);
		} else {
			$this->rSharedMemSegment = shm_attach($this->nIPCKey, $this->nShmMemsize);
		}
		$this->rMutexGlobal = sem_get($this->nIPCKey);

		// Initialize some important variables
		shm_put_var($this->rSharedMemSegment, self::_IDX_DATA, array());
		$this->_setRunningChildren(0);
		$this->setRunStatus(self::_STAT_RUN);

		// Call to optional method -- could modify nMaxChildren
		$this->loadConfig();

		// Initialize slots
		$aSlots = array_fill(0, $this->nMaxChildren, FALSE);
		shm_put_var($this->rSharedMemSegment, self::_IDX_SLOTS, $aSlots);

		// Install signal handler method
		$sSignalHandlerMethod = __CLASS__.'::signalHandler';
		foreach ( $this->aSignalsToHandle as $nSignal) {
			pcntl_signal($nSignal, $sSignalHandlerMethod);
		}
	}

	/**
	 * Hook called when the main loop finishes and the daemon needs to exit.
	 * Cleans up all semaphores and removes PID file.
	 */
	private function _daemonEnd() {
		// Clean up all UNIX semaphore data
		shm_remove($this->rSharedMemSegment);
		sem_remove($this->rMutexGlobal);
		shm_detach($this->rSharedMemSegment);

		unlink($this->sPIDFileName);
		Logger::getInstance()->warn($this->sDaemonName.': daemon exited.');
	}

	/**
	 * Method to fully handle start and exit of a child process
	 * @param mixed $mJobMetadata
	 * @param int $nSlot
	 */
	private function _executeChild(&$mJobMetadata,$nSlot) {
		$nMyPID = posix_getpid();
		Logger::getInstance()->daemon("Running child slot $nSlot with PID=$nMyPID");
		try {
			// Call "main()" for the child process
			$nExitCode = $this->childRun($mJobMetadata, $nSlot);
		} catch (Exception $e) {
			Logger::getInstance()->error('childRun() Exception: '.$e->getMessage());
			$nExitCode = -1;
		}
		unset($mJobMetadata);

		// Child process finished its work at this point.

		// If our real parent process died, child needs to exit WITHOUT
		// clean-up, as that WILL taint another running instance
		// of the daemon.
		$nParentPID = posix_getppid();
		if ( $nParentPID==1 ) {
			Logger::getInstance()->warn(__METHOD__." child suicide PID=$nMyPID; parent not running any more.");
			// KILL SELF WITH PREJUDICE, as the normal exit() will leave
			// the process alive
			posix_kill($nMyPID, SIGKILL);
			exit(-1); // should NEVER get here
		}

		// CHILD process finally exits cleanly here
		$this->_clearSlotAndDecChildren($nSlot);
		exit($nExitCode);
	}

	/**
	 * Loop waiting for child(ren) to exit
	 *
	 * @param boolean $bWait4ChildEndOrSignal FALSE=Just clean up zombies,
	 *						TRUE=Loop until signal received or 1 child ends
	 * @return boolean TRUE=Received a signal
	 */
	private function _waitForChildrenEnding($bWait4ChildEndOrSignal) {
		$nStatus = NULL;

		do {
			// Check for a Unix signal sent to us
			if ( $bWait4ChildEndOrSignal && static::$nSignalReceived ) {
				return TRUE;
			}

			// 100uS between checks, to better handle Zombies
			usleep(100);
			$nChildPID = pcntl_wait($nStatus, WNOHANG);
			if ( $nChildPID>0 ) {
				Logger::getInstance()->daemon("Child PID=$nChildPID ended");
			}
			$bContinueLoop = $bWait4ChildEndOrSignal ?
					($nChildPID==0) : ($nChildPID>0);
		} while ( $bContinueLoop );

		// Check for error returned by pcntl_wait
		if ( $nChildPID<0 ) {
			$this->_handlePcntlError('pcntl_wait(n,WNOHANG)');
		}
		return FALSE;
	}

	/**
	 * Wait indefinitely for all children to terminate themselves
	 */
	private function _waitForChildrenTermination() {
		Logger::getInstance()->warn("Terminating daemon gracefully");

		$nStatus = NULL;
		while ( ($nChildren = $this->getRunningChildren(TRUE)) > 0 ) {
			Logger::getInstance()->daemon("Waiting for $nChildren children to end");

			// Blocking call to wait for a child to end
			$nChildPID = pcntl_wait($nStatus);

			if ( $nChildPID>0 ) {
				Logger::getInstance()->daemon("Child PID=$nChildPID ended");
			} else {
				$this->_handlePcntlError('pcntl_wait(n)');
				break;
			}

			usleep(10); // Brief pause between checks for running children
		}
	}

	/**
	 * Error logging from pcntl* functions
	 *
	 * @param string $sMethodCalled
	 */
	private function _handlePcntlError($sMethodCalled) {
		$nError = pcntl_get_last_error();
		if ( $nError==10 ) {
			return; // ignore: no child processes
		}
		$sErr = pcntl_strerror( $nError );
		Logger::getInstance()->crit("ABORT: $sMethodCalled error: $sErr");
		$this->bRunLoop = FALSE;
	}

	/**
	 * Check for, and do actual work to handle SIGQUIT, SIGTERM, SIGHUP.
	 * If so configured, pass on signal to child processes as well.
	 */
	private function _checkAndProcessSignalReceived() {
		if ( empty(static::$nSignalReceived) ) {
			return;
		}

		switch ( static::$nSignalReceived ) {
			case SIGQUIT: // Treat same as SIGTERM
			case SIGTERM:
				$sChildAction = $this->bSendChildrenUNIXSignals ?
						'killing children' : 'waiting for children to end';
				Logger::getInstance()->info("Got termination signal, $sChildAction and terminating.");
				$this->bTerminate = TRUE;
				break;
			case SIGHUP:  // Reload config
				Logger::getInstance()->info('Got SIGHUP, calling loadConfig.');
				$this->loadConfig();
				break;
			default:      // No action by the parent
				break;
		}

		// IFF configured to do so, propagate ALL signals to existing children
		if ( $this->bSendChildrenUNIXSignals && ! empty($this->aChildPIDs) ) {
			foreach ( $this->aChildPIDs as $nPID ) {
				if ( $nPID<1 ) {
					continue; // Unused slot, nothing to do.
				}
				// Try to relay the signal twice
				for ( $nAttempts=1; $nAttempts<3; $nAttempts++ ) {
					Logger::getInstance()->info("Parent signaling child PID=$nPID signal=".static::$nSignalReceived." Attempt #$nAttempts");
					posix_kill($nPID, static::$nSignalReceived);
					$nErrno = posix_get_last_error();
					if ( $nErrno ) {
						Logger::getInstance()->warn("posix_kill errno=$nErrno: ".posix_get_last_error());
						usleep(1000);
					} else {
						// No error, signal sent properly
						break;
					}
				}
			}
		}

		// Clear out processed signal
		static::$nSignalReceived = NULL;
	}

	/**
	 * Helper method to clear out a child slot in the daemon.
	 *
	 * @param integer $nSlot Slot number
	 */
	private function _clearSlotAndDecChildren($nSlot) {
		$this->mutexLock();
		$this->_releaseSlot($nSlot);
		$this->_decRunningChildren();
		$this->mutexUnlock();
	}

	////////////////////////////////////////////////////////////////////////
	//
	// Methods to manage the mutex lock

	/**
	 * Global mutex lock
	 */
	protected function mutexLock() {
		Logger::getInstance()->daemon('Mutex lock');
		$bResult = sem_acquire($this->rMutexGlobal);
		if ( ! $bResult ) {
			Logger::getInstance()->error(__METHOD__." ABORT: sem_acquire() failed.");
			exit(-1);
		}
	}

	/**
	 * Global mutex UNlock
	 */
	protected function mutexUnlock() {
		Logger::getInstance()->daemon('Mutex UNlock');
		$bResult = sem_release($this->rMutexGlobal);
		if ( ! $bResult ) {
			Logger::getInstance()->error(__METHOD__." ABORT: sem_release() failed.");
			exit(-1);
		}
	}

	////////////////////////////////////////////////////////////////////////
	//
	// Methods to manage the application variables in shared RAM

	/**
	 * Get an application variable from shared memory
	 *
	 * @param string $sName
	 * @param boolean $bLock TRUE=Perform a mutex lock during operation
	 * @return mixed Value of app. variable $sName, or NULL if non-existent
	 */
	public function getAppVar($sName, $bLock = FALSE) {
		if ( $bLock ) {
			$this->mutexLock();
		}
		$aAppVars = shm_get_var($this->rSharedMemSegment, self::_IDX_DATA);
		$sValue = isset($aAppVars[$sName]) ? $aAppVars[$sName] : NULL;
		if ( $bLock ) {
			$this->mutexUnlock();
		}

		// Unserialize if needed
		if ( $sValue!==FALSE ) {
			$oUnserialized = @unserialize($sValue);
			if ( $oUnserialized!==FALSE ) {
				return $oUnserialized;
			}
		}
		return $sValue;
	}

	/**
	 * Set an application variable in shared memory
	 *
	 * @param string $sName
	 * @param mixed $mValue (Will be serialized if object/array)
	 * @param boolean $bLock TRUE=Perform a mutex lock during operation
	 * @return boolean Success status of shm_put_var()
	 */
	public function setAppVar($sName, $mValue, $bLock = FALSE) {
		if ( $bLock ) {
			$this->mutexLock();
		}
		$aAppVars = shm_get_var($this->rSharedMemSegment, self::_IDX_DATA);
		$aAppVars[$sName] = ( is_object($mValue) || is_array($mValue) ) ?
				serialize($mValue) : $mValue;
		$bResult = shm_put_var($this->rSharedMemSegment, self::_IDX_DATA, $aAppVars);
		if ( $bLock ) {
			$this->mutexUnlock();
		}
		return $bResult;
	}

	////////////////////////////////////////////////////////////////////////
	//
	// Methods to manage the run status

	/**
	 * Getter for run status
	 *
	 * @param boolean $bLock TRUE=Perform a mutex lock during operation
	 * @return int _STAT_RUN or _STAT_TERMINATE
	 */
	public function getRunStatus($bLock = FALSE) {
		if ( $bLock ) {
			$this->mutexLock();
		}
		$nStatus = shm_get_var($this->rSharedMemSegment, self::_IDX_RUNSTATUS);
		if ( $bLock ) {
			$this->mutexUnlock();
		}
		return $nStatus;
	}

	/**
	 * Setter for run status
	 *
	 * @param int $nStatus _STAT_RUN or _STAT_TERMINATE
	 * @param boolean $bLock TRUE=Perform a mutex lock during operation
	 * @return boolean TRUE=Success
	 */
	public function setRunStatus($nStatus, $bLock = FALSE) {
		if ( $bLock ) {
			$this->mutexLock();
		}
		$bResult = shm_put_var($this->rSharedMemSegment, self::_IDX_RUNSTATUS, $nStatus);
		if ( $bLock ) {
			$this->mutexUnlock();
		}
		return $bResult;
	}

	////////////////////////////////////////////////////////////////////////
	//
	// Methods to manage the count of running child processes

	/**
	 * Getter for number of running children
	 *
	 * @param boolean $bLock TRUE=Perform a mutex lock during operation
	 * @return int Running children
	 */
	public function getRunningChildren($bLock = FALSE) {
		if ( $bLock ) {
			$this->mutexLock();
		}
		$nChildren = shm_get_var($this->rSharedMemSegment, self::_IDX_CHILDREN);
		if ( $bLock ) {
			$this->mutexUnlock();
		}
		return $nChildren;
	}

	/**
	 * Setter for number of running children
	 *
	 * @return boolean TRUE=Success
	 */
	private function _setRunningChildren($nChildren) {
		$bResult = shm_put_var($this->rSharedMemSegment, self::_IDX_CHILDREN, $nChildren);
		return $bResult;
	}

	/**
	 * Increment the number of running children
	 *
	 * @return boolean TRUE=Success
	 */
	private function _incRunningChildren() {
		$nChildren = $this->getRunningChildren() + 1;
		$bResult = shm_put_var($this->rSharedMemSegment, self::_IDX_CHILDREN, $nChildren);
		return $bResult;
	}

	/**
	 * Decrement the number of running children
	 *
	 * @return boolean TRUE=Success
	 */
	private function _decRunningChildren() {
		$nChildren = $this->getRunningChildren() - 1;
		$bResult = shm_put_var($this->rSharedMemSegment, self::_IDX_CHILDREN, $nChildren);
		return $bResult;
	}

	////////////////////////////////////////////////////////////////////////
	//
	// Methods to manage slots for child processes

	/**
	 * Check if there's an available child slot
	 *
	 * @return boolean
	 */
	public function hasAvailableSlot($bLock = FALSE) {
		$nChildren = $this->getRunningChildren($bLock);
		return ( $nChildren < $this->nMaxChildren );
	}

	/**
	 * Assign a child slot
	 *
	 * @param boolean $bLock TRUE=Perform a mutex lock during operation
	 * @return int Slot number, NULL if no free slots, -1 for error
	 */
	private function _assignSlot($bLock = FALSE) {
		$nSlot = NULL;
		if ( $bLock ) {
			$this->mutexLock();
		}
		$aSlots = shm_get_var($this->rSharedMemSegment, self::_IDX_SLOTS);
		$this->_cleanUpSlots($aSlots);
		for ( $i = 0; $i<$this->nMaxChildren; $i++ ) {
			if ( ! isset($aSlots[$i]) || $aSlots[$i]==FALSE ) {
				$aSlots[$i] = TRUE;
				$nSlot = $i;
				break;
			}
		}
		if ( ! is_null($nSlot) ) {
			$bResult = shm_put_var($this->rSharedMemSegment, self::_IDX_SLOTS, $aSlots);
		} else {
			$bResult = TRUE; // fake success
		}
		if ( $bLock ) {
			$this->mutexUnlock();
		}

		if ( ! $bResult ) {
			// This should NEVER happen!
			Logger::getInstance()->error(__METHOD__." Failed to assign slot $nSlot");
			return -1;
		}
		if ( is_null($nSlot) ) {
			Logger::getInstance()->daemon('No free slots.');
		} else {
			Logger::getInstance()->daemon("Assigned new slot $nSlot");
			// "Reserve" a spot in the array to hold a PID for this slot
			$this->aChildPIDs[$nSlot] = 0;
		}
		return $nSlot;
	}

	/**
	 * Release a child slot
	 *
	 * @param int $nSlot
	 * @param boolean $bLock TRUE=Perform a mutex lock during operation
	 * @return boolean Success status
	 */
	private function _releaseSlot($nSlot, $bLock = FALSE) {
		if ( $bLock ) {
			$this->mutexLock();
		}
		$aSlots = shm_get_var($this->rSharedMemSegment, self::_IDX_SLOTS);
		$aSlots[$nSlot] = FALSE;
		$this->_cleanUpSlots($aSlots);
		$bResult = shm_put_var($this->rSharedMemSegment, self::_IDX_SLOTS, $aSlots);
		if ( $bLock ) {
			$this->mutexUnlock();
		}

		if ( ! $bResult ) {
			// This should NEVER happen!
			Logger::getInstance()->error(__METHOD__." Failed to release slot $nSlot");
			return FALSE;
		}
		Logger::getInstance()->daemon("Released slot $nSlot");
		// Clean up child PID for this slot
		unset($this->aChildPIDs[$nSlot]);
		return TRUE;
	}


	/**
	 * Clean up $aSlots after decrementing max children
	 *
	 * @param array $aSlots
	 */
	private function _cleanUpSlots(&$aSlots) {
		$nSlotCount = count($aSlots);
		if ( $nSlotCount <= $this->nMaxChildren ) {
			// Nothing to do
			return;
		}
		for ( $nSlot = $this->nMaxChildren; $nSlot<$nSlotCount; $nSlot++ ) {
			if ( $aSlots[$nSlot]==FALSE ) {
				unset($aSlots[$nSlot]);
				unset($this->aChildPIDs[$nSlot]);
			}
		}
	}

	/**
	 * Set the number of available slots to new value of nMaxChildren.
	 * NOTE: If decreasing nMaxChildren, it will NOT kill off any running
	 * child processes, but merely not launch any new ones until some die
	 * off -- better to AVOID decrementing, as it may wait longer than
	 * expected for enough child slots to free up.
	 *
	 * @param int $nMaxChildren New number of max children
	 * @param boolean $bLock TRUE=Perform a mutex lock during operation
	 * @return boolean TRUE=Success
	 */
	public function setMaxChildren($nMaxChildren, $bLock = FALSE) {
		// Sanity checks
		$nMaxChildren = (int) $nMaxChildren;
		if ( $nMaxChildren==$this->nMaxChildren ) {
			return TRUE; // Nothing to do!
		}
		if ( $nMaxChildren<1 ) {
			Logger::getInstance()->warn("Cannot have less than 1 child, staying at max children=$this->nMaxChildren");
			return FALSE;
		}

		// Check if we haven't initialized daemon - simple setter if so!
		if ( ! shm_has_var($this->rSharedMemSegment, self::_IDX_SLOTS) ) {
			$this->nMaxChildren = $nMaxChildren;
			return TRUE;
		}

		if ( $bLock ) {
			$this->mutexLock();
		}
		$aSlots = shm_get_var($this->rSharedMemSegment, self::_IDX_SLOTS);
		$nSlotCount = count($aSlots);
		if ( $nMaxChildren > $nSlotCount ) {
			$this->nMaxChildren = $nMaxChildren;
			for ( $i = $nSlotCount; $i<$nMaxChildren; $i++ ) {
				$aSlots[$i] = FALSE;
			}
			$bResult = shm_put_var($this->rSharedMemSegment, self::_IDX_SLOTS, $aSlots);
			if ( ! $bResult ) {
				// This should NEVER happen!
				Logger::getInstance()->error(__METHOD__." Failed increasing slots!");
			} else {
				Logger::getInstance()->info("Increased max slots to $nMaxChildren");

			}
		} else {
			$this->nMaxChildren = $nMaxChildren;
			Logger::getInstance()->info("Reduced max slots to $nMaxChildren");
			$bResult = TRUE;
		}
		if ( $bLock ) {
			$this->mutexUnlock();
		}
		return $bResult;
	}

	////////////////////////////////////////////////////////////////////////
	//
	// Ancillary simple setters & getters

	/**
	 * Setter for time to sleep when application determines no new jobs ready
	 * to launch a new child process.
	 * Minimum is 100 microseconds.
	 *
	 * @param int $nIdleSleepTimeUsec In microseconds
	 */
	public function setIdleSleepTime($nIdleSleepTimeUsec) {
		$nIdleSleepTimeUsec = (int) $nIdleSleepTimeUsec;
		$this->nIdleSleepTimeUsec = $nIdleSleepTimeUsec < 100 ?
				100 : $nIdleSleepTimeUsec;
	}

	/**
	 * Setter for flag to forward kill signal to running children
	 *
	 * @param boolean $bSendChildrenUNIXSignals
	 */
	public function setSendChildrenUNIXSignals($bSendChildrenUNIXSignals) {
		$this->bSendChildrenUNIXSignals = $bSendChildrenUNIXSignals;
	}

	/**
	 * Getter for PID filename
	 *
	 * @return string
	 */
	public function getPIDFile() {
		return $this->sPIDFileName;
	}

	/**
	 * Getter for max. child processes
	 *
	 * @return int
	 */
	public function getMaxChildren() {
		return $this->nMaxChildren;
	}
}
