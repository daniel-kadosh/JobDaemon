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
 * Efficient and complete logging class, modeled after syslog's log levels.
 *
 * Log format is readily sortable, includes microseconds, process ID and log
 * level.  Optionally can include the PHP filename and line number of where a
 * log line was initiated.
 *
 * It will attempt discover the system's timezone and use that, or UTC if
 * unsuccessful.  Also has setTimeZoneName() method to override.
 */

class Logger {
	// If no filename given, use stdout
	const STDOUT_LOGFILE = 'php://stdout';

	// Need a TZ string for DateTime
	const DEFAULT_TIME_ZONE = 'UTC'; // In case we can't discover system's TZ

	// Logging levels -- from syslog + a few more debug levels
	const EMERG  = 0;	// System unusable
	const ALERT  = 1;	// Take action immediately
	const CRIT   = 2;	// Critical condition
	const ERROR  = 3;	// Error condition
	const WARN   = 4;	// Warning condition
	const NOTICE = 5;	// Normal but significant condition
	const INFO   = 6;	// Informational
	const DEBUG  = 7;
	const DEBUG2 = 8;
	const DAEMON = 9;	// Debugging of daemon class

	// Default log verbosity
	const DEFAULT_LOGLEVEL = 6; // INFO from above

	// Human-readable translation table of logging levels
	protected $aLogLevelNames = [
		self::EMERG   => 'EMERG',
		self::ALERT   => 'ALERT',
		self::CRIT    => 'CRIT',
		self::ERROR   => 'ERROR',
		self::WARN    => 'WARN',
		self::NOTICE  => 'NOTICE',
		self::INFO    => 'INFO',
		self::DEBUG   => 'DEBUG',
		self::DEBUG2  => 'DEBUG2',
		self::DAEMON  => 'DAEMON'
	];

	// Singleton class: hold object
	protected static $_INSTANCE = NULL;

	// Config variables
	protected $sLogFilename;
	protected $nLogLevel;      // One of the above log level constants
	protected $sTimeZoneName;
	protected $bShowBacktrace; // TRUE= append '[file:line]' to each log line

	// Run-time variables
	protected $oDateTimeZoneDesired;
	protected $oDateTimeZoneUTC;
	protected $rLogStream;

	/**
	 * Init with given log filename, default log level, and attempt to get
	 * the OS' current timezone and use that or UTC if unsuccessful.
	 *
	 * @param string $sLogFilename Optional filename, or will use stdout
	 * @return Logger
	 */
	public function __construct($sLogFilename = NULL) {
		$this->setLogFilename($sLogFilename);
		$this->setShowBackTrace(FALSE);
		$this->setLogLevel(self::DEFAULT_LOGLEVEL);

		// Attempt to pull TZ from Unix, or use self::DEFAULT_TIME_ZONE
		$sDiscoveredTZ = $this->_discoverTimeZone();
		$this->setTimeZoneName($sDiscoveredTZ);
		$this->oDateTimeZoneUTC = new DateTimeZone('UTC');
	}

	/**
	 * Close file on exit
	 */
	public function __destruct() {
		fclose($this->rLogStream);
	}

	/**
	 * Getter of singleton instance
	 *
	 * @param string $sLogFilename Optional filename, or will use stdout
	 * @return Logger
	 */
	public static function getInstance($sLogFilename = NULL) {
		if ( empty(self::$_INSTANCE) ) {
			self::$_INSTANCE = new Logger($sLogFilename);
		}
		return self::$_INSTANCE;
	}

	/**
	 * Closes previous log file if any, and opens new log file
	 *
	 * @param string $sLogFilename Filename for logging
	 * @return Logger
	 */
	public function setLogFilename($sLogFilename) {
		// Close previously open log file
		if ( ! empty($this->sLogFilename) &&
				$this->sLogFilename != static::STDOUT_LOGFILE ) {
			fclose($this->rLogStream);
		}

		// Open new log file
		$sLogFilename = trim($sLogFilename);
		$this->sLogFilename = empty($sLogFilename) ?
				static::STDOUT_LOGFILE : $sLogFilename;
		$sMode = $this->sLogFilename==static::STDOUT_LOGFILE ? 'w' : 'a';
		$this->rLogStream = fopen($this->sLogFilename, $sMode);
		if ( !$this->rLogStream ) {
			throw new Exception(__METHOD__." Failed to open log file: $sLogFilename");
		}
		return $this;
	}

	/**
	 * @return string Name of current log file
	 */
	public function getLogFilename() {
		return $this->sLogFilename;
	}

	/**
	 * To facilitate log file rotation, call this method post-rotation.
	 * It simply closes the current log file, and opens a new one by
	 * the same name.
	 *
	 * @return Logger
	 */
	public function reopenLogFile() {
		$this->setLogFilename($this->sLogFilename);
		return $this;
	}

	/**
	 * Control wether '[file:line]' is appended to the end of each log line
	 *
	 * @param boolean $bShowBacktrace
	 * @return Logger
	 */
	public function setShowBackTrace($bShowBacktrace) {
		$this->bShowBacktrace = $bShowBacktrace;
		return $this;
	}

	/**
	 * @param mixed $mLogLevel Integer constant like Logger::INFO, or
	 *                         string like 'info'
	 * @return Logger
	 */
	public function setLogLevel($mLogLevel) {
		// Helper method ensures we get a valid INT log level
		$this->nLogLevel = $this->_stringLogLevel2Int($mLogLevel);
		if ( is_null($this->nLogLevel) ) {
			$this->nLogLevel = self::DEFAULT_LOGLEVEL;
		}

		// Automatically enable backtrace logging in DEBUG or lower levels
		if ($this->nLogLevel >= self::DEBUG) {
			$this->setShowBackTrace(TRUE);
		}
		return $this;
	}

	/**
	 * Get the current logging level either as an integer (Logger::INFO)
	 * or as a string ('INFO')
	 *
	 * @param boolean $bHumanReadableString Optional, default=FALSE
	 * @return mixed Current log level in integer or string form
	 */
	public function getLogLevel($bHumanReadableString = FALSE) {
		if ( $bHumanReadableString ) {
			return $this->aLogLevelNames[$this->nLogLevel];
		}
		return $this->nLogLevel;
	}

	/**
	 * Check if current log level is at least X.
	 * So if current=Logger::INFO, and $mLogLevel=Logger::DEBUG (lower level)
	 * is passed in, this will return FALSE.
	 *
	 * @param mixed $mLogLevel A level from this class, Logger::INFO or 'INFO'
	 * @returns boolean TRUE  = at least that log level or more severe,
	 *                  FALSE = less severe log level,
	 *                  NULL  = invalid log level passed in
	 */
	public function isLogLevelAtLeast($mLogLevel) {
		// Convert to integer, and validate log level
		$nLogLevel = $this->_stringLogLevel2Int($mLogLevel);
		if ( is_null($nLogLevel) ) {
			return NULL;
		}

		// "Lower level" is really a greater integer in how we defined the
		// class constants above
		if ( $this->nLogLevel >= $nLogLevel ) {
			return TRUE;
		}
		return FALSE;
	}

	/**
	 * @param string $sTimeZoneName Timezone string for DateTimeZone class
	 * @return Logger
	 */
	public function setTimeZoneName($sTimeZoneName) {
		$this->sTimeZoneName = $sTimeZoneName;
		$this->oDateTimeZoneDesired = new DateTimeZone($this->sTimeZoneName);
		return $this;
	}

	/**
	 * @return string Name of logger's current timezone
	 */
	public function getTimeZoneName() {
		return $this->sTimeZoneName;
	}

	/**
	 * Write a log line at named log level
	 * @param mixed $sMsg String, or print_r() will be used for object/array
	 */
	public function emerg($sMsg)  { $this->_write(self::EMERG,  $sMsg); }
	/**
	 * Write a log line at named log level
	 * @param mixed $sMsg String, or print_r() will be used for object/array
	 */
	public function alert($sMsg)  { $this->_write(self::ALERT,  $sMsg); }
	/**
	 * Write a log line at named log level
	 * @param mixed $sMsg String, or print_r() will be used for object/array
	 */
	public function crit($sMsg)   { $this->_write(self::CRIT,   $sMsg); }
	/**
	 * Write a log line at named log level
	 * @param mixed $sMsg String, or print_r() will be used for object/array
	 */
	public function error($sMsg)  { $this->_write(self::ERROR,  $sMsg); }
	/**
	 * Write a log line at named log level
	 * @param mixed $sMsg String, or print_r() will be used for object/array
	 */
	public function warn($sMsg)   { $this->_write(self::WARN,   $sMsg); }
	/**
	 * Write a log line at named log level
	 * @param mixed $sMsg String, or print_r() will be used for object/array
	 */
	public function notice($sMsg) { $this->_write(self::NOTICE, $sMsg); }
	/**
	 * Write a log line at named log level
	 * @param mixed $sMsg String, or print_r() will be used for object/array
	 */
	public function info($sMsg)   { $this->_write(self::INFO,   $sMsg); }
	/**
	 * Write a log line at named log level
	 * @param mixed $sMsg String, or print_r() will be used for object/array
	 */
	public function debug($sMsg)  { $this->_write(self::DEBUG,  $sMsg); }
	/**
	 * Write a log line at named log level
	 * @param mixed $sMsg String, or print_r() will be used for object/array
	 */
	public function debug2($sMsg) { $this->_write(self::DEBUG2, $sMsg); }
	/**
	 * Write a log line at named log level
	 * @param mixed $sMsg String, or print_r() will be used for object/array
	 */
	public function daemon($sMsg) { $this->_write(self::DAEMON, $sMsg); }

	//////////////////////////////////////////////////////
	// Private helper methods:

	/**
	 * Main writing method: composes full log line and writes it out
	 *
	 * @param int $nLogLevel
	 * @param string $sMsg
	 */
	private function _write($nLogLevel, &$sMsg) {
		// See if we should actually write anything
		if ( $nLogLevel > $this->nLogLevel ) {
			return;
		}

		// Get current UNIX time + 6 digits for microseconds
		list($sMicroSec, $sUnixSec) = explode(' ', microtime(), 2);

		// MUST set the time with UTC, and THEN convert to desired timezone.
		$oDate = new DateTime('@'.$sUnixSec, $this->oDateTimeZoneUTC);
		$oDate->setTimezone($this->oDateTimeZoneDesired);

		$sPrefix = sprintf('%s.%s %5d %s: ',
				$oDate->format('Y-m-d H:i:s'),
				substr((string) $sMicroSec, 2, 6),
				posix_getpid(),
				key_exists($nLogLevel, $this->aLogLevelNames) ?
					$this->aLogLevelNames[$nLogLevel] : 'LOG'
		);

		// Prep full string to write out
		if ( is_object($sMsg) || is_array($sMsg) ) {
			$sMsg = print_r($sMsg, 1);
		}

		// Suffix: [file:line] if show backtrace enabled
		if ( $this->bShowBacktrace ) {
			// debug_Backtrace for where the log was called
			$aDebugBacktrace = debug_backtrace();
			$sSuffix = sprintf(" [%s:%s]\n",
					basename($aDebugBacktrace[1]['file']),
					$aDebugBacktrace[1]['line']
			);
		} else {
			$sSuffix = "\n";
		}

		// Write & flush in one "transaction"
		fwrite($this->rLogStream, $sPrefix.$sMsg.$sSuffix);
		fflush($this->rLogStream);
	}

	/**
	 * Take a string or integer log level and return a valid corresponding
	 * log level, or self::DEFAULT_LOGLEVEL if input was invalid
	 *
	 * @param mixed $sLogLevel
	 * @return int Log level
	 */
	private function _stringLogLevel2Int($sLogLevel) {
		// First validate if it's a valid integer log level
		$nLogLevel = (int) $sLogLevel;
		if ( $nLogLevel===$sLogLevel &&
				$nLogLevel>=self::EMERG && $nLogLevel<=self::DAEMON ) {
			return $nLogLevel;
		}

		// Try string representations
		$sLogLevel = strtoupper(trim($sLogLevel));
		$nLogLevel = array_search($sLogLevel, $this->aLogLevelNames);
		if ( $nLogLevel===FALSE ) {
			// Invalid!
			return NULL;
		}
		return $nLogLevel;
	}

	/**
	 * Try the Unix "date" command to pull out a timezone string, and test
	 * if the timezone string is supported by the installed version of PHP.
	 * If any of the above fails, use self::DEFAULT_TIME_ZONE
	 *
	 * @return string Valid PHP timezone string
	 */
	private function _discoverTimeZone() {
		$sCmd = 'date +%Z';
		exec($sCmd, $aOutput, $nExitStatus);
		if ( $nExitStatus==0 && count($aOutput)>0 ) {
			$sTZ = trim($aOutput[0]);
			$oTestTZ = @timezone_open($sTZ);
			if ( is_object($oTestTZ) ) {
				return $sTZ;
			}
		}
		return self::DEFAULT_TIME_ZONE;
	}
}
