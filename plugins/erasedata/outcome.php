<?php

// The durable outcome of a datadir or AutoTools operation on a download
// (ACC-OWNB-01). rTorrent sets d.message to an empty string on the next
// successful tracker answer -- measured 0.44 s after a datadir warning -- so
// an outcome the user has to act on is also kept in a custom key of the
// download, which tracker traffic does not touch and the session saves.
// Every WebUI session lists the kept outcomes once (each plugin's init.php);
// d.message stays the immediate hint. An outcome is no longer listed once the
// download's directory has changed (the user or a later operation acted) or,
// when it was recorded for a stopped download, once the download runs again;
// a later success of the same plugin clears it. The caller loads
// php/xmlrpc.php.
//
// The kept value is stored URL-encoded, which leaves no quote or backslash
// in it. While rXMLRPCRequest escaped each backslash and double quote of an
// answer, a JSON value read back from 0.9.8 (which answers JSON with literal
// quotes, captured 2026-10-08, L4 evidence lab-autolabel/outcome-debug)
// never decoded. The strings it is compared with or shows are the daemon's
// own bytes as php/xmlrpc.php hands them out, never unescaped again: that
// dropped a byte of every backslash-quote and backslash-backslash (AUD-ESC).
final class FileOperationOutcome
{
	const KEYS = array('autotools' => 'x-autotools-outcome', 'datadir' => 'x-datadir-outcome');
	const LABELS = array('autotools' => 'AutoTools', 'datadir' => 'DataDir');
	// Notices beyond this many are counted, not listed.
	const NOTICE_LIMIT = 20;

	// Keeps $message for the download and shows it as d.message; true when the
	// daemon confirmed both. $whileStopped limits the notice to the time the
	// download stays stopped.
	public static function record($plugin, $hash, $reason, $message, $whileStopped = false)
	{
		if(!isset(self::KEYS[$plugin]) || !is_string($hash) || preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1
			|| !is_string($reason) || !is_string($message))
			return(false);
		$read = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.get_directory'), $hash));
		$read->important = false;
		if(!$read->success() || !is_array($read->val) || count($read->val) !== 1 || !is_string($read->val[0]))
			return(false);
		$value = json_encode(array('at' => time(), 'reason' => $reason, 'message' => $message,
			'directory' => $read->val[0], 'while_stopped' => (bool)$whileStopped),
			JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		if(!is_string($value))
			return(false);
		$value = rawurlencode($value);
		$write = new rXMLRPCRequest(array(
			new rXMLRPCCommand(getCmd('d.set_custom'), array($hash, self::KEYS[$plugin], $value)),
			new rXMLRPCCommand(getCmd('d.message.set'), array($hash, $message))));
		$write->important = false;
		if(!$write->success())
			return(false);
		// The custom key survives a restart once the session is saved.
		$save = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.save_full_session'), $hash));
		$save->important = false;
		$save->success();
		return(true);
	}

	public static function clear($plugin, $hash)
	{
		if(!isset(self::KEYS[$plugin]) || !is_string($hash) || preg_match('/^[0-9A-F]{40}$/D', $hash) !== 1)
			return(false);
		$request = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.set_custom'),
			array($hash, self::KEYS[$plugin], '')));
		$request->important = false;
		return($request->success());
	}

	// The kept outcomes that still apply, as array(hash, name, outcome) rows;
	// false when the daemon does not answer.
	public static function pending($plugin)
	{
		if(!isset(self::KEYS[$plugin]))
			return(false);
		$request = new rXMLRPCRequest(new rXMLRPCCommand('d.multicall', array('main',
			getCmd('d.get_hash='), getCmd('d.get_name='), getCmd('d.get_directory='),
			getCmd('d.get_state='), getCmd('d.get_custom=').self::KEYS[$plugin])));
		$request->important = false;
		if(!$request->success() || !is_array($request->val) || count($request->val) % 5 !== 0)
			return(false);
		$rows = array();
		for($index = 0; $index < count($request->val); $index += 5)
		{
			list($hash, $name, $directory, $state, $value) = array_slice($request->val, $index, 5);
			if(!is_string($value) || $value === '')
				continue;
			$outcome = json_decode(rawurldecode($value), true);
			if(!is_array($outcome) || !isset($outcome['message'], $outcome['directory'])
				|| !is_string($outcome['message']) || $outcome['directory'] !== $directory
				|| (!empty($outcome['while_stopped']) && (string)$state !== '0'))
				continue;
			$rows[] = array((string)$hash, (string)$name, $outcome);
		}
		return($rows);
	}

	// JavaScript for one WebUI session init: one notice per kept outcome that
	// still applies; '' when there is none or the daemon does not answer, in
	// which case the next session reads again. noty() escapes the text.
	public static function sessionNotice($plugin)
	{
		$rows = self::pending($plugin);
		if(!is_array($rows) || !count($rows))
			return('');
		$script = '';
		foreach(array_slice($rows, 0, self::NOTICE_LIMIT) as $row)
			$script .= 'noty('.json_encode(self::LABELS[$plugin].': '.$row[1].' ('.$row[0].'): '
				.$row[2]['message'], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG)
				.",'error');\n";
		if(count($rows) > self::NOTICE_LIMIT)
			$script .= 'noty('.json_encode(self::LABELS[$plugin].': '.(count($rows) - self::NOTICE_LIMIT)
				.' more downloads keep an outcome (custom key '.self::KEYS[$plugin].')').",'error');\n";
		return($script);
	}
}
