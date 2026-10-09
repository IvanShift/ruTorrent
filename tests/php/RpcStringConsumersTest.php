<?php

require_once(__DIR__ . '/TestCase.php');

/**
 * Every shipped PHP file that reads rXMLRPCRequest's string accessors has
 * been reviewed for what it does with the daemon's bytes (AUD-ESC, rounds 4
 * and 5), and nothing in the tree escapes or unescapes them again.
 *
 * Until round 5, run() handed out every string with its quotes and
 * backslashes escaped. Twenty-odd consumers compared such a value with a
 * path, quoted it a second time or put it into JSON, and a few undid the
 * escaping with rXMLRPCRequest::unescapeValue() -- which, now that ->val
 * carries the daemon's own bytes, would drop a backslash the daemon reported.
 *
 * So this file pins four things over the non-test PHP in conf/, php/,
 * plugins/, tools/ and the top level:
 *  - the files that read ->val or ->strings, keyed by file and by the number
 *    of accesses (not by line). A new file, or a changed count, means code
 *    that reads daemon strings has changed: review what it does with them,
 *    then update REVIEWED_ACCESS_COUNTS from the failure message;
 *  - the typed string-table owner and its shared Move inventory callers;
 *  - no file but php/xmlrpc.php names unescapeValue, as a call or as a
 *    callable string;
 *  - no file but php/xmlrpc.php names the deprecated $escapeForScript flag,
 *    which exists for external plugins only.
 *
 * Typed accessor calls, raw semantic properties and Move inventory edges have separate reviewed maps.
 * Accesses through a helper (rTorrent::getFilePath(), getSourcePath(),
 * rTorrentSettings' directory, session and home, AutoTools' rpc() wrapper)
 * are counted where the helper reads ->val, not where its result is used.
 */
class RpcStringConsumersTest extends TestCase
{
	/**
	 * Files reading ->val / ->strings, with their access counts, as reviewed
	 * on the round-5 integration of all four lanes (E1 core, E2 erasedata/
	 * autotools/datadir, E3 rutracker_check, E4 the other plugins). data and
	 * unpack now read file paths through rTorrent::getFilePath().
	 */
	const REVIEWED_ACCESS_COUNTS = array(
		'php/rtorrent.php'                               => 20,
		'php/settings.php'                               => 13,
		'php/utility/hashfault.php'                      => 6,
		'php/xmlrpc.php'                                 => 4,
		'plugins/_getdir/info.php'                       => 2,
		'plugins/_task/task.php'                         => 2,
		'plugins/autotools/label.php'                    => 5,
		'plugins/autotools/move_job_lib.php'             => 3,
		'plugins/autotools/move_queue.php'               => 5,
		'plugins/autotools/util_rt.php'                  => 4,
		'plugins/check_port/action.php'                  => 2,
		'plugins/datadir/util_setdir.php'                => 31,
		'plugins/edit/action.php'                        => 18,
		'plugins/erasedata/outcome.php'                  => 8,
		'plugins/erasedata/removewithdata.php'           => 50,
		'plugins/extratio/rules.php'                     => 5,
		'plugins/feeds/action.php'                       => 27,
		'plugins/httprpc/action.php'                     => 41,
		'plugins/ratio/ratio.php'                        => 9,
		'plugins/retrackers/update.php'                  => 2,
		'plugins/rutracker_check/check.php'              => 66,
		'plugins/rutracker_check/forumindex.php'         => 16,
		'plugins/rutracker_check/metafetch.php'          => 48,
		'plugins/rutracker_check/runstate.php'           => 9,
		'plugins/rutracker_check/trackers/rutracker.php' => 7,
		'plugins/rutracker_check/update.php'             => 1,
		'plugins/rutracker_check/updatepass.php'         => 67,
		'plugins/scheduler/scheduler.php'                => 4,
		'plugins/throttle/throttle.php'                  => 10,
		'plugins/trafic/ratios.php'                      => 3,
		'plugins/trafic/update.php'                      => 4,
		'plugins/unpack/unpack.php'                      => 5,
		'tools/escaped-values-scan.php'                  => 4,
	);

	// Move's three inventory consumers delegate to the one typed RPC owner.
	// Keep these edges visible even though they no longer read flat ->val.
	const REVIEWED_TABLE_COUNTS = array('plugins/autotools/move_queue.php' => 1);
	const REVIEWED_MOVE_ROW_COUNTS = array(
		'plugins/autotools/move_queue.php' => 1,
		'tools/autotools-move-readiness.php' => 1,
		'tools/autotools-move-reset.php' => 1,
	);

	// Raw semantic values retain XML provenance; these consumers must not flatten it.
	const REVIEWED_SEMANTIC_COUNTS = array(
		'php/xmlrpc.php' => 14,
		'plugins/httprpc/action.php' => 3,
		'tools/autotools-move-readiness.php' => 4,
	);

	// The one file allowed to define unescapeValue and to name the flag.
	const PARSER = 'php/xmlrpc.php';
	const PARSER_FLAG_MENTIONS = 2;

	/**
	 * Count, in PHP source, the property reads of ->val / ->strings (also
	 * inside interpolated strings and through ?->, never a method call, a
	 * comment or a plain string), typed stringTable/shared Move downloadRows
	 * calls, and mentions of unescapeValue (excluding its definition) and
	 * escapeForScript.
	 */
	public static function scan($source)
	{
		$tokens = token_get_all($source);
		$count = count($tokens);
		$operators = array(T_OBJECT_OPERATOR);
		if (defined('T_NULLSAFE_OBJECT_OPERATOR')) {
			$operators[] = constant('T_NULLSAFE_OBJECT_OPERATOR');
		}
		$next = function ($from, $step) use ($tokens, $count) {
			for ($at = $from + $step; $at >= 0 && $at < $count; $at += $step) {
				if (!is_array($tokens[$at]) || $tokens[$at][0] !== T_WHITESPACE) {
					return $at;
				}
			}
			return -1;
		};
		$result = array('access' => 0, 'unescape' => 0, 'flag' => 0, 'table' => 0, 'moveRows' => 0, 'semantic' => 0);
		foreach ($tokens as $at => $token) {
			if (!is_array($token)) {
				continue;
			}
			if (in_array($token[0], $operators, true)) {
				$name = $next($at, 1);
				$after = $name >= 0 ? $next($name, 1) : -1;
				if ($name >= 0 && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING
					&& $tokens[$name][1] === 'stringTable' && $after >= 0 && $tokens[$after] === '(') {
					$result['table']++;
				}
				if ($name >= 0 && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING
					&& in_array($tokens[$name][1], array('responseTypes', 'responseValues'), true)
					&& ($after < 0 || $tokens[$after] !== '(')) $result['semantic']++;
				if ($name >= 0 && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING
					&& in_array($tokens[$name][1], array('val', 'strings'), true)) {
					$after = $next($name, 1);
					if ($after < 0 || $tokens[$after] !== '(') {
						$result['access']++;
					}
				}
			} elseif ($token[0] === T_DOUBLE_COLON) {
				$owner = $next($at, -1);
				$name = $next($at, 1);
				$after = $name >= 0 ? $next($name, 1) : -1;
				if ($owner >= 0 && is_array($tokens[$owner])
					&& in_array($tokens[$owner][1], array('AutoToolsMoveQueue', 'self'), true)
					&& $name >= 0 && is_array($tokens[$name]) && $tokens[$name][0] === T_STRING
					&& $tokens[$name][1] === 'downloadRows' && $after >= 0 && $tokens[$after] === '(') {
					$result['moveRows']++;
				}
			} elseif (($token[0] === T_STRING && $token[1] === 'unescapeValue')
				|| ($token[0] === T_CONSTANT_ENCAPSED_STRING && strpos($token[1], 'unescapeValue') !== false)) {
				$before = $next($at, -1);
				if ($before < 0 || !is_array($tokens[$before]) || $tokens[$before][0] !== T_FUNCTION) {
					$result['unescape']++;
				}
			} elseif (($token[0] === T_STRING && $token[1] === 'escapeForScript')
				|| ($token[0] === T_VARIABLE && $token[1] === '$escapeForScript')
				|| ($token[0] === T_CONSTANT_ENCAPSED_STRING && strpos($token[1], 'escapeForScript') !== false)) {
				$result['flag']++;
			}
		}
		return $result;
	}

	/** Relative path => scan() of every shipped PHP file. */
	private static function tree()
	{
		$root = dirname(__DIR__, 2);
		$files = array();
		foreach (array('conf', 'php', 'plugins', 'tools') as $directory) {
			$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory,
				FilesystemIterator::SKIP_DOTS));
			foreach ($entries as $entry) {
				if ($entry->isFile() && !$entry->isLink() && $entry->getExtension() === 'php') {
					$files[] = substr($entry->getPathname(), strlen($root) + 1);
				}
			}
		}
		foreach (glob($root . '/*.php') as $path) {
			$files[] = basename($path);
		}
		sort($files);
		$scanned = array();
		foreach ($files as $file) {
			$scanned[$file] = self::scan(file_get_contents($root . '/' . $file));
		}
		return $scanned;
	}

	/** The map entries a reviewer would paste, for a failure message. */
	private static function entries($map)
	{
		$lines = array();
		foreach ($map as $file => $count) {
			$lines[] = var_export($file, true) . ' => ' . $count . ',';
		}
		return implode(' ', $lines);
	}

	public function testTheExtractorCountsEveryShapeOfAccessAndNothingElse()
	{
		$fixture = <<<'PHP'
<?php
$a = $req->val[0];
$b = $this->val;
$c = $r->strings[1];
$d = "x {$req->val[2]} y";
$e = "x $req->val y";
$f = $req -> val [3];
$g = $o?->strings;
$h = $o->value;
$i = $o->vals;
$j = '$req->val';
$k = $o->val();
// $req->val[0] and rXMLRPCRequest::unescapeValue($x) in a comment
/* $req->strings */
$l = rXMLRPCRequest::unescapeValue($a);
$m = method_exists('rXMLRPCRequest', 'unescapeValue');
$n = array_map(array('rXMLRPCRequest', 'unescapeValue'), $b);
$req->escapeForScript = true;
class X { static public function unescapeValue($v) { return $v; } public $escapeForScript = false;
 public function stringTable($columns) {} public static function downloadRows() {} }
$req->stringTable(3);
$req?->stringTable(2);
AutoToolsMoveQueue::downloadRows();
self :: downloadRows();
Other::downloadRows();
$req->stringTable;
$req->responseTypes; $req?->responseValues;
$req->responseValues(); $plainTypes = '$req->responseTypes';
// $req->responseValues;
$plain = '$req->stringTable(1); AutoToolsMoveQueue::downloadRows();';
// $req->stringTable(1); AutoToolsMoveQueue::downloadRows();
PHP;
		$this->assertSame(array('access' => 7, 'unescape' => 3, 'flag' => 2, 'table' => 2, 'moveRows' => 2, 'semantic' => 2), self::scan($fixture),
			'seven reads (plain, $this, strings, both interpolations, spaced, ?->), three unescapeValue '
			. 'mentions besides the definition, two flags, two typed calls, two Move edges and two semantic property accesses: ' . json_encode(self::scan($fixture)));
	}

	public function testEveryFileReadingDaemonStringsHasBeenReviewed()
	{
		$tree = self::tree();
		$found = array();
		foreach ($tree as $file => $counts) {
			if ($counts['access'] > 0) {
				$found[$file] = $counts['access'];
			}
		}
		$this->assertTrue(count($tree) >= 300, 'the scan reads the shipped PHP tree: ' . count($tree) . ' files');
		$this->assertTrue(array_sum($found) > 0 && isset($found[self::PARSER], $found['php/rtorrent.php']),
			'the extractor finds accesses, the parser\'s own among them; a zero count means it is broken');
		$this->assertSame(array_sum(self::REVIEWED_ACCESS_COUNTS), array_sum($found),
			'the tree reads ->val/->strings as often as reviewed: ' . array_sum($found)
			. ' against ' . array_sum(self::REVIEWED_ACCESS_COUNTS));
		$changed = array();
		foreach ($found + self::REVIEWED_ACCESS_COUNTS as $file => $unused) {
			$now = $found[$file] ?? 0;
			$then = self::REVIEWED_ACCESS_COUNTS[$file] ?? 0;
			if ($now !== $then) {
				$changed[] = $file . ' ' . $then . ' -> ' . $now;
			}
		}
		$this->assertSame(array(), $changed, 'every file reading daemon strings is reviewed at its current '
			. 'count; review what the changed files do with the daemon\'s bytes, then set: '
			. self::entries($found));
	}

	public function testTypedMoveInventoryKeepsOneReviewedOwnerAndThreeConsumerEdges()
	{
		$tables = $consumers = $semantic = array();
		foreach (self::tree() as $file => $counts) {
			if ($counts['semantic'] > 0) $semantic[$file] = $counts['semantic'];
			if ($counts['table'] > 0) $tables[$file] = $counts['table'];
			if ($counts['moveRows'] > 0) $consumers[$file] = $counts['moveRows'];
		}
		$this->assertSame(self::REVIEWED_SEMANTIC_COUNTS, $semantic,
			'raw semantic values and their XML types have reviewed consumers');
		$this->assertSame(self::REVIEWED_TABLE_COUNTS, $tables,
			'typed string-table consumers are reviewed at their canonical RPC owner');
		$this->assertSame(self::REVIEWED_MOVE_ROW_COUNTS, $consumers,
			'all three Move inventory callers retain the reviewed shared-owner edge');
	}

	public function testOnlyTheParserNamesUnescapeValue()
	{
		$found = array();
		foreach (self::tree() as $file => $counts) {
			if ($counts['unescape'] > 0 && $file !== self::PARSER) {
				$found[$file] = $counts['unescape'];
			}
		}
		$this->assertSame(array(), $found,
			'no shipped file unescapes a daemon string: a value is the daemon\'s own bytes and '
			. 'unescaping it drops a backslash. Now: ' . self::entries($found));
	}

	public function testOnlyTheParserNamesTheDeprecatedEscapeFlag()
	{
		$found = array();
		foreach (self::tree() as $file => $counts) {
			if ($counts['flag'] > 0) {
				$found[$file] = $counts['flag'];
			}
		}
		$this->assertSame(array(self::PARSER => self::PARSER_FLAG_MENTIONS), $found,
			'$escapeForScript is declared and read in php/xmlrpc.php and set by no shipped code: '
			. self::entries($found));
	}
}
