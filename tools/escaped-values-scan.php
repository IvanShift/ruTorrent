<?php
require_once(dirname(__DIR__) . '/php/cli-only.php');
rutorrent_require_cli(__FILE__);

// Read-only inventory of possible historical escaping. Literal backslashes
// are legal filenames; every result is an ambiguous candidate, never proof of
// corruption or duplicate payload. No automatic repair is offered.
//
// php tools/escaped-values-scan.php [--user=NAME] [--fail-on-candidates]
//
// Download fields and unreferenced empty escaped directories immediately in
// or beside each download directory are inspected (no recursive traversal or
// symlink following). Values are percent-encoded only for %, control and DEL.
// Exit 0: scan completed, including ambiguous candidates; 1: candidates with
// explicit --fail-on-candidates; 2: usage/daemon/answer/filesystem failure.
// Settings bootstrap reads aliases without setters, execute or cache writes.

final class EscapedValuesScan
{
	const MAX_LEVELS = 8;
	// d.hash, d.name, d.custom1, d.directory, d.base_path, per download.
	const COLUMNS = 5;

	/** Whether a value read before round 5 and written back would look like this. */
	public static function looksEscaped($value)
	{
		return(is_string($value) && ((strpos($value, '\\"') !== false) || (strpos($value, '\\\\') !== false)));
	}

	/** Bytes for one output field: '%', control bytes and DEL percent-encoded. */
	public static function printable($value)
	{
		return(preg_replace_callback('/[\x00-\x1F\x7F%]/', function ($byte) {
			return(sprintf('%%%02X', ord($byte[0])));
		}, (string)$value));
	}

	/** Undo one round of the old escaping, as rXMLRPCRequest::unescapeValue() did. */
	public static function unescapeOnce($value)
	{
		$out = '';
		$length = strlen($value);
		for ($i = 0; $i < $length; $i++) {
			if (($value[$i] === '\\') && (($i + 1) < $length) &&
				(($value[$i + 1] === '\\') || ($value[$i + 1] === '"')))
				$i++;
			$out .= $value[$i];
		}
		return($out);
	}

	private static function present($path)
	{
		return(($path !== '') && (@file_exists($path) || @is_link($path)));
	}

	/**
	 * The findings for one download, each array(class, field, levels, value,
	 * candidate). $exists answers whether a path exists (a seam for tests).
	 */
	public static function classify($label, $directory, $basePath, $exists = null)
	{
		if ($exists === null)
			$exists = array(__CLASS__, 'present');
		$findings = array();
		$decoded = rawurldecode((string)$label);
		if (self::looksEscaped($decoded)) {
			$levels = 0;
			$candidate = $decoded;
			while (self::looksEscaped($candidate) && ($levels < self::MAX_LEVELS)) {
				$candidate = self::unescapeOnce($candidate);
				$levels++;
			}
			$findings[] = array('label-escaped', 'label', $levels, $decoded, $candidate);
		}
		$seen = array();
		foreach (array('directory' => $directory, 'base_path' => $basePath) as $field => $value) {
			if (!is_string($value) || ($value === '') || !self::looksEscaped($value) || isset($seen[$value]))
				continue;
			$seen[$value] = true;
			$stored = call_user_func($exists, $value);
			$candidate = $value;
			$found = null;
			for ($levels = 1; $levels <= self::MAX_LEVELS; $levels++) {
				$next = self::unescapeOnce($candidate);
				if ($next === $candidate)
					break;
				$candidate = $next;
				if (call_user_func($exists, $candidate)) {
					$found = array($levels, $candidate);
					break;
				}
			}
			if ($found === null)
				$found = array(max(1, $levels - 1), $candidate);
			$unescaped = ($found[1] !== $value) && call_user_func($exists, $found[1]);
			$class = $stored
				? ($unescaped ? 'escaped-path-both-exist' : 'escaped-path-legitimate-or-escaped')
				: ($unescaped ? 'escaped-path-missing' : 'escaped-path-unresolved');
			$findings[] = array($class, $field, $found[0], $value, $found[1]);
		}
		return($findings);
	}

	/** Empty neighboring directories may survive after their torrent was fixed. */
	public static function emptyDirectories($directories, $referenced)
	{
		$parents = array();
		foreach($directories as $directory)
			foreach(array($directory, dirname($directory)) as $parent)
				if($parent !== '' && is_dir($parent) && !is_link($parent)) $parents[$parent] = true;
		$result = array();
		foreach(array_keys($parents) as $parent)
		{
			$entries = @scandir($parent);
			if($entries === false) throw new RuntimeException('directory-unreadable');
			foreach($entries as $entry)
			{
				if(!self::looksEscaped($entry)) continue;
				$path = rtrim($parent, '/').'/'.$entry;
				if(isset($referenced[$path]) || isset($result[$path]) || is_link($path) || !is_dir($path)) continue;
				$children = @scandir($path);
				if($children === false) throw new RuntimeException('directory-unreadable');
				if(count($children) === 2) $result[$path] = self::unescapeOnce($path);
			}
		}
		return $result;
	}

	public static function main($args, $out, $err)
	{
		foreach ($args as $arg) {
			if (strncmp($arg, '--user=', 7) !== 0 && $arg !== '--fail-on-candidates') {
				fwrite($err, "usage: escaped-values-scan.php [--user=NAME] [--fail-on-candidates]\n");
				return(2);
			}
		}
		$cmd = new rXMLRPCCommand('d.multicall', 'main');
		$cmd->addParameters(array_map('getCmd',
			array('d.get_hash=', 'd.get_name=', 'd.get_custom1=', 'd.get_directory=', 'd.get_base_path=')));
		$req = new rXMLRPCRequest($cmd);
		$req->important = false;
		if (!$req->run() || $req->fault) {
			fwrite($err, 'escaped-values-scan: cannot read the download list from rTorrent ('
				. ($req->fault ? 'fault' : 'no answer') . "); nothing was checked\n");
			return(2);
		}
		if ((count($req->val) % self::COLUMNS) !== 0) {
			fwrite($err, "escaped-values-scan: the download list answer is malformed; nothing was checked\n");
			return(2);
		}
		foreach($req->val as $index => $value)
			if(!is_string($value) || (($index % self::COLUMNS) === 0 && !preg_match('/^[0-9A-Fa-f]{40}\z/', $value)))
			{
				fwrite($err, "escaped-values-scan: the download list answer is malformed; nothing was checked\n");
				return(2);
			}
		$downloads = 0;
		$findings = 0;
		$affected = 0;
		$directories = array();
		$referenced = array();
		for ($i = 0; $i < count($req->val); $i += self::COLUMNS) {
			$downloads++;
			list($hash, $name, $label, $directory, $basePath) = array_slice($req->val, $i, self::COLUMNS);
			$rows = self::classify($label, $directory, $basePath);
			if(is_string($directory) && $directory !== '') $directories[] = $directory;
			foreach(array($directory, $basePath) as $path)
				if(is_string($path) && $path !== '') $referenced[$path] = true;
			foreach($rows as $row) $referenced[$row[4]] = true;
			if (count($rows))
				$affected++;
			foreach ($rows as $row) {
				$findings++;
				fwrite($out, self::printable($hash) . "\tclass=" . $row[0] . "\tfield=" . $row[1]
					. "\tlevels=" . $row[2] . "\tvalue=" . self::printable($row[3])
					. "\tunescaped=" . self::printable($row[4]) . "\tname=" . self::printable($name) . "\n");
			}
		}
		try {
			$empty = self::emptyDirectories($directories, $referenced);
		} catch(RuntimeException $error) {
			fwrite($err, 'escaped-values-scan: '.$error->getMessage()."; neighboring directory scan incomplete\n");
			return(2);
		}
		foreach($empty as $path => $candidate)
			fwrite($out, "-\tclass=escaped-empty-directory\tfield=neighbor\tlevels=1\tvalue="
				.self::printable($path)."\tunescaped=".self::printable($candidate)."\tname=\n");
		$findings += count($empty);
		fwrite($out, 'escaped-values-scan: ' . $downloads . ' downloads read; ' . $findings
			. ' ambiguous candidates on ' . $affected . ' downloads; '.count($empty)
			. " empty neighboring directories; no corruption or duplicate payload proven; nothing was changed\n");
		return($findings && in_array('--fail-on-candidates', $args, true) ? 1 : 0);
	}
}

if (!defined('ESCAPED_VALUES_SCAN_IMPORT_ONLY')) {
	$args = array_slice(isset($argv) && is_array($argv) ? $argv : array(), 1);
	$user = '';
	foreach ($args as $arg)
		if (strncmp($arg, '--user=', 7) === 0)
			$user = substr($arg, 7);
	// Before the configuration loads: a per-user conf/users/<user>/config.php
	// may name another SCGI endpoint. User::getUser() folds the name the way
	// it folds an HTTP login, so the tool picks the profile the WebUI uses.
	$_SERVER['REMOTE_USER'] = $user;
}
if(!defined('RUTORRENT_SETTINGS_READ_ONLY')) define('RUTORRENT_SETTINGS_READ_ONLY', true);
require_once(dirname(__DIR__) . '/php/xmlrpc.php');
if (!defined('ESCAPED_VALUES_SCAN_IMPORT_ONLY'))
	exit(EscapedValuesScan::main($args, STDOUT, STDERR));
