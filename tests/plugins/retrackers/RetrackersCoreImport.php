<?php

// Test doubles of rTorrent (php/rtorrent.php) import its pure predicates
// verbatim: loading that file pulls in the whole application, and a copy in
// a double would let a suite pin a rule that production does not run.
if (!function_exists('retrackersTestCoreMethod')) {
	function retrackersTestCoreMethod($name)
	{
		$source = file_get_contents(__DIR__ . '/../../../php/rtorrent.php');
		if (!is_string($source) || preg_match('/\n(\tstatic public function ' . preg_quote($name, '/') .
			'\(.*?\n\t\})\n/s', $source, $method) !== 1) {
			throw new RuntimeException('php/rtorrent.php no longer declares rTorrent::' . $name .
				' in the form this import reads');
		}
		return($method[1]);
	}

	// The double the queue suites share: rTorrent::quoteCommandArg() escapes
	// the same two characters, and isMetadataStubName() is the shipped one.
	function retrackersTestRtorrentDouble()
	{
		if (class_exists('rTorrent', false)) return;
		eval(<<<'DOUBLE'
class rTorrent
{
	public static function quoteCommandArg($value)
	{
		return('"' . addcslashes((string)$value, "\\\"") . '"');
	}
DOUBLE
			. "\n" . retrackersTestCoreMethod('isMetadataStubName') . "\n}\n");
	}
}
