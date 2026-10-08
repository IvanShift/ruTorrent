<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/RealParserDriver.php');

/**
 * erasedata's path collectors through the real php/xmlrpc.php parser, which
 * hands every daemon string back in the daemon's own bytes (AUD-ESC, round 5).
 * Every case names a directory and files that carry a double quote, a
 * backslash and the two-byte sequences backslash-quote and backslash-backslash,
 * and the names exist on disk. Beside each one stands a neighbour spelled the
 * way one unescape of it would read (`odd "name" \x` next to
 * `odd \"name\" \x`): a collector that unescaped the daemon's bytes again
 * named the neighbour, and a manifest naming it deletes the wrong file.
 *
 * Provenance of the rule these cases keep:
 * - Removal (erasedataCollectPaths()): a download named `AccRwd "q" httprpcd`
 *   holding `a "x".bin` was removed with its data through both HTTP doors and
 *   answered `published`, but its data stayed on disk and nothing was logged:
 *   the deletion manifest named the escaped spelling, which no file has
 *   (measured on a real daemon in the round-4 lab; AUD-ESC-03).
 * - The exact payload plan (erasedataCollectPhysicalPaths()) compared the
 *   escaped answers with the session copy, so the replacement cleanup's owner
 *   scan never confirmed such a download and the successor's cleanup was held
 *   (ACC-OWNB-04, AUD-ESC-09 F2a); the daemon file list claimed instead named
 *   files nobody has (F2b) and left the real ones unclaimed (F3).
 * - L4b repaired those by unescaping the answers (erasedataDaemonString());
 *   once the parser stopped escaping, that second unescape dropped a byte of
 *   every backslash-quote and backslash-backslash, which these names pin.
 * - A backslash in a metainfo name made that plan refuse the copy outright,
 *   and the refusal was logged as session-copy-unreadable with a session save
 *   requested although the copy was there (N1, AUD-ESC S4).
 */
class DaemonPathParserTest extends TestCase
{
	const HASH = 'ABCDEF0123456789ABCDEF0123456789ABCDEF01';
	const OLD = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
	const SUCCESSOR = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
	const MARKER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	// A directory and file names with a quote, a backslash, a backslash-quote
	// and a backslash-backslash (bytes: aud "q" \\b 'a' &c, odd \"name\" \x,
	// say "one" \\ \"two\".bin).
	const DIRECTORY = 'aud "q" \\\\b \'a\' &c';
	const NAME = 'odd \\"name\\" \\x';
	const SINGLE = 'say "one" \\\\ \\"two\\".bin';

	private $base;
	private $driver;
	// The multi-file download's directory and the single-file download's
	// file, both on disk.
	private $dir;
	private $file;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-path-parser-' . getmypid() . '-' . uniqid();
		mkdir($this->base, 0700);
		$this->driver = new ErasedataRealParserDriver($this->base);
		$this->dir = $this->base . '/dl/' . self::DIRECTORY;
		mkdir($this->dir . '/sub', 0700, true);
		file_put_contents($this->dir . '/' . self::NAME, 'abc');
		file_put_contents($this->dir . '/sub/b.bin', 'abcd');
		$this->file = $this->base . '/dl/' . self::SINGLE;
		file_put_contents($this->file, 'abcde');
		// The neighbours, spelled as one unescape of each name reads.
		$neighbour = $this->base . '/dl/' . self::unescapedOnce(self::DIRECTORY);
		mkdir($neighbour . '/sub', 0700, true);
		file_put_contents($neighbour . '/' . self::unescapedOnce(self::NAME), 'neighbour');
		file_put_contents($neighbour . '/sub/b.bin', 'neighbour');
		file_put_contents($this->dir . '/' . self::unescapedOnce(self::NAME), 'neighbour');
		file_put_contents($this->base . '/dl/' . self::unescapedOnce(self::SINGLE), 'neighbour');
		mkdir($this->base . '/session', 0700);
	}

	// What rXMLRPCRequest::unescapeValue(), now kept only for the deprecated
	// $escapeForScript flag, makes of a string: a backslash before a backslash
	// or a double quote dropped.
	private static function unescapedOnce($value)
	{
		return preg_replace('/\\\\([\\\\"])/', '$1', $value);
	}

	public function tearDown()
	{
		$this->driver->stop();
		$this->remove($this->base);
	}

	private function remove($path)
	{
		if (is_dir($path) && !is_link($path))
		{
			foreach (array_diff(scandir($path), array('.', '..')) as $entry)
				$this->remove($path . '/' . $entry);
			@rmdir($path);
		}
		else
			@unlink($path);
	}

	private static function bencode($value)
	{
		if (is_int($value))
			return 'i' . $value . 'e';
		if (is_string($value))
			return strlen($value) . ':' . $value;
		if (array_keys($value) === range(0, count($value) - 1))
		{
			$out = 'l';
			foreach ($value as $item)
				$out .= self::bencode($item);
			return $out . 'e';
		}
		ksort($value, SORT_STRING);
		$out = 'd';
		foreach ($value as $key => $item)
			$out .= self::bencode((string) $key) . self::bencode($item);
		return $out . 'e';
	}

	// Writes $info as the download's session copy and returns its hash.
	private function sessionCopy(array $info)
	{
		$hash = strtoupper(sha1(self::bencode($info)));
		file_put_contents($this->base . '/session/' . $hash . '.torrent', self::bencode(array('info' => $info)));
		return $hash;
	}

	// A multi-file download: a payload file, a BEP-47 padding file and a
	// payload file in a subdirectory.
	private function multiInfo()
	{
		return array(
			'files' => array(
				array('length' => 3, 'path' => array(self::NAME)),
				array('attr' => 'p', 'length' => 5, 'path' => array('.pad', '0')),
				array('length' => 4, 'path' => array('sub', 'b.bin'))),
			'name' => self::DIRECTORY,
			'piece length' => 16384,
			'pieces' => str_repeat("\x01", 20));
	}

	// What rTorrent answers for the session copy: its session directory and
	// no tied file.
	private function sourceReply()
	{
		return array($this->base . '/session/', '');
	}

	private function plugin()
	{
		return realpath(__DIR__ . '/../../../plugins/erasedata/removewithdata.php');
	}

	private function collect(array $replies)
	{
		return $this->driver->drive($replies, 'require_once(' . var_export($this->plugin(), true) . ");\n"
			. 'echo json_encode(array("paths" => erasedataCollectPaths(' . var_export(self::HASH, true) . ')));');
	}

	// The replacement cleanup's owner scan, seen from a download that is
	// neither side of the replacement.
	private function ownerSnapshot(array $replies)
	{
		return $this->driver->drive($replies, 'require_once(' . var_export($this->plugin(), true) . ");\n"
			. '$s = erasedataCleanupOtherOwnerSnapshot(' . var_export(self::OLD, true) . ', '
			. var_export(self::SUCCESSOR, true) . ', ' . var_export(self::MARKER, true) . ', '
			. var_export(self::OLD . '-started-1787587200', true) . ");\n"
			. 'echo json_encode(array("snapshot" => is_array($s) ? array("paths" => array_keys($s["paths"]),'
			. ' "inodes" => array_keys($s["inodes"])) : $s));');
	}

	private function claims(array $files)
	{
		$paths = array();
		$inodes = array();
		foreach ($files as $file)
		{
			$paths[] = "p\0" . realpath($file);
			$stat = stat($file);
			$inodes[] = 'i:' . $stat['dev'] . ':' . $stat['ino'];
		}
		return array('paths' => $paths, 'inodes' => $inodes);
	}

	private function saveRequested()
	{
		foreach ($this->driver->daemon()->calls() as $call)
			if (strpos($call, 'save_full_session') !== false)
				return true;
		return false;
	}

	private static function show($value)
	{
		return json_encode($value, JSON_UNESCAPED_SLASHES);
	}

	public function testRemovalPathsAreTheDaemonsOwnBytes()
	{
		$dir = $this->dir;
		$multi = array('base' => $dir, 'multi' => '1',
			'files' => array($dir . '/' . self::NAME, $dir . '/sub/b.bin'));
		$single = array('base' => $this->file, 'multi' => '0', 'files' => array($this->file));
		$cases = array(
			// An opened download answers its frozen paths.
			'opened multi-file' => array(array(array($dir, 1, $dir . '/' . self::NAME, $dir . '/sub/b.bin')), $multi),
			// One not opened since rTorrent started answers d.directory and f.path.
			'unopened multi-file' => array(array(array('', 1, '', ''), array($dir, 1, self::NAME, 'sub/b.bin')), $multi),
			'opened single-file' => array(array(array($this->file, 0, $this->file)), $single),
			'unopened single-file' => array(array(array('', 0, ''),
				array(dirname($this->file), 0, self::SINGLE)), $single),
		);
		foreach ($cases as $case => $plan)
		{
			list($replies, $expected) = $plan;
			$answer = $this->collect($replies);
			$this->assertSame($expected, $answer['paths'], 'the deletion manifest names the download in the'
				. ' daemon\'s own bytes (' . $case . '): ' . self::show($answer['paths']));
			$missing = array();
			foreach ($expected['files'] as $file)
				if (!is_file($file))
					$missing[] = $file;
			$this->assertSame(array(), $missing, 'and those are the files on disk (' . $case . ')');
		}
	}

	// The manifest a removal publishes from that answer: the daemon's bytes,
	// marked as such because they hold a backslash, decoded unambiguous, and
	// naming no neighbour. The collector deletes exactly what a marked
	// manifest names (ManifestPathBytesTest).
	public function testTheRemovalManifestCarriesTheDaemonsBytesAndItsMark()
	{
		$dir = $this->dir;
		$answer = $this->driver->drive(array(array($dir, 1, $dir . '/' . self::NAME, $dir . '/sub/b.bin')),
			'require_once(' . var_export($this->plugin(), true) . ");\n"
			. '$h = ' . var_export(self::HASH, true) . '; $paths = erasedataCollectPaths($h);'
			. ' $bytes = is_array($paths) ? ErasedataManifestCodec::encode($h, $paths, 1) : false;'
			. ' $record = is_string($bytes) ? ErasedataManifestCodec::decodeBytes($bytes, $h) : false;'
			. ' echo json_encode(array("marked" => is_string($bytes)'
			. ' && strpos($bytes, \'"path_bytes":"daemon"\') !== false, "record" => is_array($record)'
			. ' ? array("base" => $record["base"], "files" => $record["files"],'
			. ' "ambiguous" => $record["paths_ambiguous"]) : $record));');
		$this->assertSame(true, $answer['marked'], 'the manifest says its paths are the daemon\'s own bytes');
		$this->assertSame(array('base' => $dir, 'files' => array($dir . '/' . self::NAME, $dir . '/sub/b.bin'),
			'ambiguous' => false), $answer['record'], 'it names exactly the download\'s files, unambiguous: '
			. self::show($answer['record']));
	}

	// What cannot be resolved is false, which admission refuses visibly and
	// recovery and the collector retain, rather than a manifest that names
	// something other than the download's files.
	public function testUnresolvableRemovalAnswersNameNothing()
	{
		$dir = $this->dir;
		$cases = array(
			'a multi-file path outside the base' => array(array($dir, 1, $dir . '/' . self::NAME,
				dirname($dir) . '/b.bin')),
			'a multi-file path that is the base itself' => array(array($dir, 1, $dir)),
			'a path that climbs out of the base' => array(array($dir, 1, $dir . '/../' . self::NAME)),
			'a single file that is not the base' => array(array($dir . '/a', 0, $dir . '/b')),
			'a relative base' => array(array(self::DIRECTORY, 1, self::DIRECTORY . '/' . self::NAME)),
			'the root as base' => array(array('/', 1, '/' . self::NAME)),
			'a relative directory' => array(array('', 1, ''), array(self::DIRECTORY, 1, self::NAME)),
			'two files of a single-file download' => array(array('', 0, '', ''),
				array($dir, 0, self::NAME, 'sub/b.bin')),
		);
		foreach ($cases as $case => $replies)
		{
			$answer = $this->collect($replies);
			$this->assertSame(false, $answer['paths'], 'an answer that does not describe one download'
				. ' names nothing: ' . $case . ': ' . self::show($answer['paths']));
		}
	}

	// AUD-ESC S4: a backslash is an ordinary byte of a POSIX name, so a
	// metainfo component holding one is a name below the download; only what
	// cannot be one such component is refused.
	public function testAMetainfoComponentMayHoldABackslashButNotASeparatorOrADotName()
	{
		$cases = array(
			'a backslash' => array(array('a\\b'), 'a\\b'),
			'a backslash-quote and a backslash-backslash' => array(array(self::DIRECTORY, self::NAME),
				self::DIRECTORY . '/' . self::NAME),
			'a trailing backslash' => array(array('dir\\', 'x'), 'dir\\/x'),
			'a lone backslash' => array(array('\\'), '\\'),
			'an empty component' => array(array('a', ''), false),
			'a dot' => array(array('.', 'a'), false),
			'a dot-dot' => array(array('a', '..'), false),
			'a slash inside a component' => array(array('a/b'), false),
			'a NUL' => array(array("a\0b"), false),
			'no component at all' => array(array(), false),
			'a component that is not a string' => array(array(1), false),
		);
		$inputs = array();
		foreach ($cases as $case => $plan)
			$inputs[$case] = $plan[0];
		$answer = $this->driver->drive(array(), 'require_once(' . var_export($this->plugin(), true) . ");\n"
			. '$out = array(); foreach (' . var_export($inputs, true) . ' as $case => $components)'
			. ' $out[$case] = erasedataPhysicalRelativePath($components);'
			. ' echo json_encode(array("paths" => $out));');
		foreach ($cases as $case => $plan)
			$this->assertSame($plan[1], array_key_exists($case, $answer['paths']) ? $answer['paths'][$case] : null,
				'the components with ' . $case . ' give ' . self::show($plan[1]));
	}

	public function testTheExactPayloadPlanNamesTheDaemonsOwnBytes()
	{
		$dir = $this->dir;
		$multiHash = $this->sessionCopy($this->multiInfo());
		$singleHash = $this->sessionCopy(array('length' => 5, 'name' => self::SINGLE,
			'piece length' => 16384, 'pieces' => str_repeat("\x02", 20)));
		$stored = array($dir, 1, self::NAME, '.pad/0', 'sub/b.bin');
		$multi = array('base' => $dir, 'multi' => '1',
			'files' => array($dir . '/' . self::NAME, $dir . '/sub/b.bin'));
		$cases = array(
			'opened multi-file' => array($multiHash, array($this->sourceReply(),
				array($dir, 1, $dir . '/' . self::NAME, $dir . '/.pad/0', $dir . '/sub/b.bin'), $stored), $multi),
			'unopened multi-file' => array($multiHash, array($this->sourceReply(),
				array('', 1, '', '', ''), $stored), $multi),
			'single-file' => array($singleHash, array($this->sourceReply(), array($this->file, 0, $this->file),
				array(dirname($this->file), 0, self::SINGLE)),
				array('base' => $this->file, 'multi' => '0', 'files' => array($this->file))),
		);
		foreach ($cases as $case => $plan)
		{
			list($hash, $replies, $expected) = $plan;
			$answer = $this->driver->drive($replies, 'require_once(' . var_export($this->plugin(), true) . ");\n"
				. '$failure = null; $paths = erasedataCollectPhysicalPaths(' . var_export($hash, true) . ', $failure);'
				. ' echo json_encode(array("paths" => $paths, "failure" => $failure));');
			$this->assertSame($expected, $answer['paths'], 'the session copy and the daemon agree, and the'
				. ' payload is named in the daemon\'s own bytes (' . $case . '): ' . self::show($answer));
		}
	}

	public function testAPresentCopyWithSuchNamesIsClaimedThroughItsExactPlan()
	{
		$dir = $this->dir;
		$hash = $this->sessionCopy($this->multiInfo());
		$answer = $this->ownerSnapshot(array(
			array($hash, '', '', ''),
			$this->sourceReply(),
			array($dir, 1, $dir . '/' . self::NAME, $dir . '/.pad/0', $dir . '/sub/b.bin'),
			array($dir, 1, self::NAME, '.pad/0', 'sub/b.bin')));
		$this->assertSame($this->claims(array($dir . '/' . self::NAME, $dir . '/sub/b.bin')), $answer['snapshot'],
			'its payload files are claimed as they are on disk, and its padding is not: '
			. self::show($answer['snapshot']));
		$this->assertSame(array(), $this->driver->logLines(), 'a copy that is there is not reported as'
			. ' unreadable: ' . self::show($this->driver->logLines()));
		$this->assertSame(false, $this->saveRequested(), 'and no session save is requested for it');
	}

	// A copy that is this download's own -- its info hash matches -- but whose
	// layout gives no exact plan (here a single-file length beside a file
	// list) is claimed through the daemon's file list as before. It is named
	// for what it is, without a save: the daemon would write the same info
	// dictionary again.
	public function testAReadableCopyWithoutAUsableLayoutIsNamedSoWithoutASave()
	{
		$dir = $this->dir;
		$hash = $this->sessionCopy(array(
			'files' => array(array('length' => 3, 'path' => array(self::NAME))),
			'length' => 3,
			'name' => self::DIRECTORY,
			'piece length' => 16384,
			'pieces' => str_repeat("\x03", 20)));
		$answer = $this->ownerSnapshot(array(
			array($hash, '', '', ''),
			// The plan reads the copy, then the metadata-stub check reads it again.
			$this->sourceReply(),
			$this->sourceReply(),
			// d.directory, then f.path and f.frozen_path of each file.
			array($dir, self::NAME, $dir . '/' . self::NAME),
			// The answer to a session save, should one be requested.
			array(0)));
		$this->assertSame($this->claims(array($dir . '/' . self::NAME)), $answer['snapshot'],
			'its daemon file list is claimed: ' . self::show($answer['snapshot']));
		$this->assertSame(array('erasedata: cleanup owner ' . $hash . ' metainfo-paths-unusable; its daemon file'
			. ' list is claimed instead'), $this->driver->logLines(), 'the log says the copy is there and its'
			. ' layout unusable: ' . self::show($this->driver->logLines()));
		$this->assertSame(false, $this->saveRequested(), 'and no session save is requested for it');
	}
}
