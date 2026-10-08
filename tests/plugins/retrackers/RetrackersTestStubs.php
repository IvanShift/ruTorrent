<?php

// The one copy of the ruTorrent stubs that the queue suites need when they load
// plugins/retrackers/update.php without the application bootstrap. The quoting
// escapes the same two characters as rTorrent::quoteCommandArg() in
// php/rtorrent.php, and rTorrent::isMetadataStubName() is that file's own.
// FileUtil::toLog() records lines so a suite can assert them.

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/RetrackersCoreImport.php');

if (!function_exists('getCmd')) {
	function getCmd($command)
	{
		return($command);
	}
}

retrackersTestRtorrentDouble();

if (!class_exists('FileUtil', false)) {
	class FileUtil
	{
		public static $messages = array();

		public static function toLog($message)
		{
			self::$messages[] = $message;
		}
	}
}

// An rCache stand-in for RetrackersQueueDiagnostics: one object per name.
class RetrackersMemoryCache
{
	public $state = null;

	public function get(&$state)
	{
		if ($this->state === null) return(false);
		$state = clone $this->state;
		return(true);
	}

	public function set($state)
	{
		$this->state = clone $state;
		return(true);
	}
}
