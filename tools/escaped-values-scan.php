<?php
require_once(dirname(__DIR__) . '/php/cli-only.php');
rutorrent_require_cli(__FILE__);

// List the downloads whose stored values still carry damage from the
// escaping rXMLRPCRequest applied before round 5 (AUD-ESC). The scan only
// reads; a settings refresh may run before it (see below).
//
//   php tools/escaped-values-scan.php [--user=NAME]
//
// Until then every string ruTorrent read from rTorrent came back with each
// '"' as '\"' and each '\' as '\\'. Code that wrote such a value back stored
// the damage in the daemon, and the fix cannot undo it:
//  - a label (d.custom1) read and saved again -- AutoTools' {DIR} label, an
//    edit, a replacement -- holds %5C%22 or %5C%5C;
//  - a download directory or base path set from a read value -- a
//    rutracker_check replacement, a datadir or AutoTools Move, the datadir
//    dialog -- names a directory with literal '\"' or doubled '\\' that the
//    owner never chose; mkdir created it, so the payload may be there, under
//    the name without the escaping, or in both.
//
// One line per finding, tab-separated key=value fields:
//   <HASH> class=<class> field=<field> levels=<n> value=<stored> unescaped=<candidate> name=<d.name>
// value, unescaped and name are printed byte for byte, except '%', control
// bytes and DEL, which are percent-encoded; '"' and '\' appear as they are.
// The classes:
//   label-escaped           the label holds an escaped quote or backslash;
//   escaped-path-missing    the stored path does not exist, the unescaped one does;
//   escaped-path-duplicate  both exist: data may be held twice;
//   escaped-path-only       only the stored path exists (a real name may hold '\"');
//   escaped-path-unresolved neither exists.
// levels is how many rounds of escaping separate the candidate from the
// stored value (each rutracker_check replacement added one).
//
// Run it where rTorrent's paths resolve, as the daemon's user, for example
// inside the Docker image:
//   docker exec -u torrent <container> php85 /rutorrent/app/tools/escaped-values-scan.php
// --user=NAME selects a per-user ruTorrent configuration (another SCGI
// endpoint) when $forbidUserSettings is false; the Docker image needs none.
// The scan reads the downloads read-only: its own request is one d.multicall
// of every download, then it stats paths. It changes no download: repair each
// finding by hand (datadir, the label dialog), after checking where the
// payload really is.
// ruTorrent's own settings refresh may run first, as for any entry point.
// The settings cache (settings/rtorrent.dat in the profile) stays missing or
// in the pre-round-5 format until some entry point has refreshed it on the
// new code, so a run right after the upgrade may be the one that does. That
// refresh sends what rTorrentSettings::obtain() sends, before the scan: the
// version, API version and, from 0.16.21, socket-limit reads; convert.kb;
// network.xmlrpc.size_limit.set 16777216 -- the one daemon setting it
// changes, the same request every WebUI load sends; the directory, session,
// library version, name and network reads; with a local daemon,
// execute.capture of `sh -c "id -u ; id -G ; echo ~ "`. It then rewrites
// that cache. tests/php/EscapedValuesScanToolTest.php pins this list.
// Exit status: 0 nothing found; 1 findings listed; 2 usage, an unreachable
// daemon or an unreadable answer.

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
				? ($unescaped ? 'escaped-path-duplicate' : 'escaped-path-only')
				: ($unescaped ? 'escaped-path-missing' : 'escaped-path-unresolved');
			$findings[] = array($class, $field, $found[0], $value, $found[1]);
		}
		return($findings);
	}

	public static function main($args, $out, $err)
	{
		foreach ($args as $arg) {
			if (strncmp($arg, '--user=', 7) !== 0) {
				fwrite($err, "usage: escaped-values-scan.php [--user=NAME]\n");
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
		$downloads = 0;
		$findings = 0;
		$affected = 0;
		for ($i = 0; $i < count($req->val); $i += self::COLUMNS) {
			$downloads++;
			list($hash, $name, $label, $directory, $basePath) = array_slice($req->val, $i, self::COLUMNS);
			$rows = self::classify($label, $directory, $basePath);
			if (count($rows))
				$affected++;
			foreach ($rows as $row) {
				$findings++;
				fwrite($out, self::printable($hash) . "\tclass=" . $row[0] . "\tfield=" . $row[1]
					. "\tlevels=" . $row[2] . "\tvalue=" . self::printable($row[3])
					. "\tunescaped=" . self::printable($row[4]) . "\tname=" . self::printable($name) . "\n");
			}
		}
		fwrite($out, 'escaped-values-scan: ' . $downloads . ' downloads read; ' . $findings
			. ' findings on ' . $affected . " downloads; nothing was changed\n");
		return($findings ? 1 : 0);
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
require_once(dirname(__DIR__) . '/php/xmlrpc.php');
if (!defined('ESCAPED_VALUES_SCAN_IMPORT_ONLY'))
	exit(EscapedValuesScan::main($args, STDOUT, STDERR));
