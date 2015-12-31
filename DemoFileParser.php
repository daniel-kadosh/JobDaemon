#!/usr/bin/php
<?php
/*
 * Simple example that illustrates the how to use the JobDaemon class.
 *
 * This sample app monitors an "incoming" directory for files, and will process
 * each file in its own "thread" (forked child process), and then moves the
 * file to either a "done" or an "error" directory.
 *
 * Each child process in this example processes exactly 1 file and then exits.
 *
 * Some possible reasons to process just 1 file per thread are:
 * 1) If the processing is REALLY long (minutes/hours?)
 * 2) if one really needs to completely clean out from RAM anything particularly
 *    large or sensitive from one run to another.
 */

error_reporting(E_ALL);
require_once ('JobDaemon.php');

// Init the logging class singleton
define('DAEMON_NAME', basename($_SERVER['SCRIPT_NAME'],'.php'));
$sLogfile = DAEMON_NAME.'.log';
Logger::getInstance($sLogfile)->setLogLevel(Logger::DAEMON);

class FileParser extends JobDaemon {
	private $aDir;

	public function demoAppInit() {
		// Set up directories
		$sBaseDir = dirname(__FILE__);
		$aDir['incoming'] = "$sBaseDir/_incoming"; // incoming files
		$aDir['done']     = "$sBaseDir/_done";     // files parsed OK
		$aDir['error']    = "$sBaseDir/_error";    // files not parsed
		foreach ( $aDir as $sPath ) {
			if ( ! file_exists($sPath) ) {
				mkdir($sPath, 0775, TRUE);
			}
		}
		$this->aDir = $aDir;

		// For DEMO: populate _incoming directory with PHP files of this program
		$sIncomingPath = $aDir['incoming'];
		echo `cp $sBaseDir/*.php $sIncomingPath/`;
		// And create an empty file
		touch($sIncomingPath.'/empty_file');
	}

	/*
	 * Optional: read in config files or perform other "init" actions
	 * Called from MTDaemon's _prerun(), plus when a SIGHUP signal is received.
	 * NOTE: Runs under the PARENT process.
	 */
	public function loadConfig() {
		// To keep this example simple, we're not really doing any config
		// work here.
		$sMsg = sprintf('%s: Monitoring Queue dir=%s',
				DAEMON_NAME,
				$this->aDir['incoming']
			);
		Logger::getInstance()->info($sMsg);
	}

	/**
	 * Method to return quickly with (a) no work to do, or (b) next file
	 * to process.
	 * NOTE: Runs under the PARENT process
	 *
	 * @param int $nSlot Slot number in daemon to potentially fill
	 * @return string Metadata for child: filename to process
	 */
	public function getNextJob($nSlot) {
		Logger::getInstance()->debug2(__METHOD__." called to fill slot $nSlot");
		$sFileToProcess = NULL;

		// Get shared arrays (application variables)
		// First, we lock other processes out while we manipulate these arrays.
		$this->mutexLock();
		$aFileList = $this->getAppVar('aFileList'); // FIFO queue
		$aFilesInProcess = $this->getAppVar('aFilesInProcess');
		if ( is_null($aFilesInProcess) ) {
			$aFilesInProcess = array();
		}

		// Use ls to get files, only if the $aFileList queue is empty
		if ( empty($aFileList) && is_dir($this->aDir['incoming']) ) {
			$sCmd = 'ls -1U '.$this->aDir['incoming'];
			exec($sCmd, $aFileList);
			// Take out files already in process
			if ( count($aFileList) && count($aFilesInProcess) ) {
				$aFileList = array_diff($aFileList, $aFilesInProcess);
			}
		}

		// Pull out a file to process from FIFO queue
		if ( count($aFileList) ) {
			// We have a file ready for processing!
			$sFileToProcess = array_shift($aFileList);
			array_push($aFilesInProcess, $sFileToProcess);

			Logger::getInstance()->debug("About to process $sFileToProcess, and remaining in queue:");
			Logger::getInstance()->debug($aFileList);
		}

		// Store updated shared arrays back
		$this->setAppVar('aFileList', $aFileList);
		$this->setAppVar('aFilesInProcess', $aFilesInProcess);

		// Finally, release the lock
		$this->mutexUnlock();

		// We're choosing to return the full filename as the metadata for
		// the child process to act upon.
		return empty($sFileToProcess) ?
			NULL : $sFileToProcess;
	}

	/**
	 * Child starts its work here.
	 * NOTE: Runs under a CHILD process
	 *
	 * @param string $sFileName Filename from incoming directory
	 * @param int $nSlot Slot number in daemon for this child process
	 * @return int Exit code for child
	 */
	public function childRun($sFileName, $nSlot) {
		$sMsg = sprintf('slot=%d file=%s',
				$nSlot, basename($sFileName)
			);
		Logger::getInstance()->info('## Start '.$sMsg);

		$sFullFilename = $this->aDir['incoming'].'/'.$sFileName;
		try {
			// Call parsing method that returns TRUE if OK, FALSE if not.
			$oFileGrokker = new FileGrokker();
			$bProcessedOK = $oFileGrokker->Grok1File($sFullFilename);
		} catch( Exception $e ) {
			Logger::getInstance()->error('ProcessFile error '.$sMsg.': '.$e->getMessage());
			$bProcessedOK = FALSE;
		}

		// Done, take file off "in-process" list
		// As before, lock out other processes while we manipulate arrays
		$this->mutexLock();

		$aFilesInProcess = $this->getAppVar('aFilesInProcess');
		$nFileToRemove = array_search(basename($sFileName), $aFilesInProcess);
		unset($aFilesInProcess[$nFileToRemove]);
		$this->setAppVar('aFilesInProcess', $aFilesInProcess);

		// Move file out of incoming directory
		$sDestDir = $bProcessedOK ?
				$this->aDir['done'] : $this->aDir['error'];
		$sDestFile = $sDestDir.'/'.basename($sFileName);
		rename($sFullFilename, $sDestFile);

		// Finally, release the lock
		$this->mutexUnlock();

		Logger::getInstance()->info('-- End '.$sMsg);
		return 0;
	}
}

// For demo purposes, class & method that actually "processes" a file
class FileGrokker {
	/**
	 *
	 * @param string $sFileName
	 * @return boolean TRUE= Success
	 * @throws Exception
	 */
	public function Grok1File($sFileName) {
		$sContent = file_get_contents($sFileName);

		// Empty file => to error directory
		if ( empty($sContent) ) {
			Logger::getInstance()->warn('Not processing empty file '.$sFileName);
			return FALSE;
		}

		// Demonstrate throwing an exception => to error directory
		if ( preg_match('/'.DAEMON_NAME.'/', $sFileName) ) {
			throw new Exception('Bad file!');
		}

		// Good file - pretend to do something useful with it!
		$nRand = rand(10, 20);
		$sMsg = sprintf("%s read %d bytes from file '%s' | pausing %d seconds.",
				__METHOD__,
				strlen($sContent),
				$sFileName,
				$nRand
		);
		Logger::getInstance()->info($sMsg);
		sleep($nRand);
		return TRUE;
	}
}

// Set up and run the daemon
try {
	$oDaemon = NULL;

	// Initialize daemon class
	$nUIDToRunUnder = NULL;             // Don't try to change UID
	$sPIDFilename = DAEMON_NAME.'.pid';	// PID file simply in current dir
	$oDaemon = new FileParser($nUIDToRunUnder, $sPIDFilename);

	// Now set some daemon options
	$oDaemon->setMaxChildren(2); // Only up to 2 child processes
	$oDaemon->setSendChildrenUNIXSignals(FALSE); // Don't forward signals
	$oDaemon->setIdleSleepTime(5 * 1000000); // Wait 5sec when no job avail.

	printf("Will run %d child process with PID file %s\n",
			$oDaemon->getMaxChildren(),
			$oDaemon->getPIDFile()
	);

	// Call our own custom init method
	$oDaemon->demoAppInit();

	// Start daemon
	$oDaemon->daemonRun();

} catch( Exception $e ) {
	if ( $oDaemon==NULL ) {
		$sErr = ': Daemon class failed to instantiate: ';
	} else {
		$sErr = ': Daemon died: ';
	}
	Logger::getInstance()->crit(DAEMON_NAME.$sErr.$e->getMessage());
	die($sErr."\n");
}
exit(0);

