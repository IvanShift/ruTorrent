<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/CollectorFixture.php');
require_once(__DIR__ . '/../../../plugins/erasedata/removewithdata.php');
require_once(__DIR__ . '/../../../plugins/erasedata/collector.php');

/**
 * Payload manifests from a release that did not yet mark its paths hold each
 * path as php/xmlrpc.php handed it over: every '"' as '\"' and every '\' as
 * '\\' (AUD-ESC-03). The producer now writes the daemon's own bytes and, when
 * a path has a backslash, says so ("path_bytes":"daemon"). An unmarked
 * manifest still pending at the upgrade may name a sibling literally spelled
 * like the escaped name (AUD-ESC-09 F3: `a\"b` next to the payload `a"b`), and
 * nothing tells such a path from a real backslash, so it is retained and
 * reported. Without a backslash both spellings are the same bytes.
 *
 * Three cases are about the locale instead: non-ASCII names in C, where PHP
 * 7.4's basename() drops them.
 */
class ManifestPathBytesTest extends TestCase
{
	const OLD = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
	const MULTI = 'BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB';
	const PLAIN = 'CCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCCC';
	const QUOTED = 'DDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDDD';
	const DOUBLED = 'EEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEEE';

	private $dir;
	private $queue;
	private $data;

	public function setUp()
	{
		$this->dir = sys_get_temp_dir() . '/erasedata-path-bytes-' . getmypid() . '-' . uniqid();
		mkdir($this->dir, 0700);
		$this->queue = $this->dir . '/erasedata';
		mkdir($this->queue, 0700);
		$this->data = $this->dir . '/data';
		mkdir($this->data, 0700);
		FileUtil::$settingsPath = $this->dir;
		FileUtil::$log = array();
	}

	public function tearDown()
	{
		$this->remove($this->dir);
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

	// Exactly the bytes ErasedataManifestCodec::encode() wrote before it marked
	// a backslash path as the daemon's own bytes.
	private function oldManifest($hash, $base, array $files, $multi)
	{
		return json_encode(array('version' => 2, 'hash' => $hash, 'path_encoding' => 'base64',
			'files' => array_map('base64_encode', $files), 'base' => base64_encode($base),
			'multi' => $multi, 'force' => 1), JSON_UNESCAPED_SLASHES) . "\n";
	}

	private function publish($hash, $bytes)
	{
		file_put_contents($this->queue . '/' . $hash . '.list', $bytes);
		return $this->queue . '/' . $hash . '.list';
	}

	// One ordinary collector pass over the queue, every download already gone
	// from rTorrent. With $sink the pass reports its retentions as the drain
	// does.
	private function collect($sink = null, $force = false)
	{
		$collector = new ErasedataCollector(new ErasedataCollectorFixture(array()),
			function($hash) { return ERASEDATA_TORRENT_ABSENT; },
			function($hash, $physicalOnly = false) { return false; },
			function($line) {}, $force);
		if ($sink !== null)
			$collector->reportRetentionsTo($sink);
		$collector->run($this->queue);
	}

	private static function contents($path)
	{
		clearstatcache(true, $path);
		return is_file($path) ? file_get_contents($path) : null;
	}

	public function testAnOldAmbiguousStagingManifestCannotAuthorizeDrainErase()
	{
		$base = $this->data . '/a\\"b';
		$path = $this->queue . '/' . self::OLD . '.1.token.tmp';
		file_put_contents($path, $this->oldManifest(self::OLD, $base, array($base), false));
		$stat = lstat($path);
		$record = array('path' => $path, 'dev' => $stat['dev'], 'ino' => $stat['ino']);
		$this->assertSame(false, erasedataStagingManifestStillMatches($record, self::OLD, 1),
			'an identity-matched old ambiguous manifest grants no erase authority');
		file_put_contents($path, ErasedataManifestCodec::encode(self::OLD,
			array('base' => $base, 'files' => array($base), 'multi' => false), 1));
		clearstatcache(true, $path);
		$this->assertSame(true, erasedataStagingManifestStillMatches($record, self::OLD, 1),
			'the same raw path explicitly marked by the new writer is eligible');
	}

	public function testAnOldManifestWithAnEscapedPathDeletesNeitherSpellingAndSaysSo()
	{
		// The payload the daemon had, and a sibling named like its escaped spelling.
		$payload = $this->data . '/a"b';
		$sibling = $this->data . '/a\\"b';
		file_put_contents($payload, 'payload');
		file_put_contents($sibling, 'sibling');
		$single = $this->publish(self::OLD, $this->oldManifest(self::OLD, $sibling, array($sibling), false));
		// A multi-file download in a directory named `d"x`, beside `d\"x`.
		mkdir($this->data . '/d"x');
		mkdir($this->data . '/d\\"x');
		file_put_contents($this->data . '/d"x/f.bin', 'payload');
		file_put_contents($this->data . '/d\\"x/f.bin', 'sibling');
		$multi = $this->publish(self::MULTI, $this->oldManifest(self::MULTI, $this->data . '/d\\"x',
			array($this->data . '/d\\"x/f.bin'), true));
		$singleBytes = file_get_contents($single);
		$multiBytes = file_get_contents($multi);

		$this->collect();
		$this->collect();
		$this->assertSame('sibling', self::contents($sibling), 'the sibling spelled like the escaped name survives');
		$this->assertSame('payload', self::contents($payload), 'and so does the payload: no file is deleted');
		$this->assertSame('sibling', self::contents($this->data . '/d\\"x/f.bin'),
			'a multi-file sibling directory keeps its file');
		$this->assertSame('payload', self::contents($this->data . '/d"x/f.bin'), 'and the multi-file payload stays');
		$this->assertSame($singleBytes, self::contents($single), 'the single-file manifest is retained byte for byte');
		$this->assertSame($multiBytes, self::contents($multi), 'the multi-file manifest is retained byte for byte');
		$expected = array();
		foreach (array(self::OLD => $single, self::MULTI => $multi) as $hash => $path)
			$expected[] = 'erasedata: escaped-path-ambiguous ' . basename($path) . ' for ' . $hash
				. ' retained; an unmarked manifest path with a backslash may be an older release\'s escaped'
				. ' spelling, so no file is deleted; remove the payload by hand, then this manifest';
		$logged = FileUtil::$log;
		sort($logged, SORT_STRING);
		$this->assertSame($expected, $logged, 'each manifest is named once a day, with its hash and the'
			. ' consequence: ' . json_encode(FileUtil::$log, JSON_UNESCAPED_SLASHES));

		// The drain reports the same retention through its own memory.
		$notes = array();
		$this->collect(function($kind, $hash, $generation, $reason) use (&$notes) {
			$notes[] = $kind . ' ' . $hash . ' ' . $reason;
		});
		$this->assertTrue(in_array('manifest ' . self::OLD . ' escaped-path-ambiguous', $notes, true)
			&& in_array('manifest ' . self::MULTI . ' escaped-path-ambiguous', $notes, true),
			'the drain is told the classified reason: ' . json_encode($notes));
		$this->assertSame('sibling', self::contents($sibling), 'and a drain pass deletes nothing either');
	}

	public function testAnOldManifestWithoutABackslashStillCompletes()
	{
		$payload = $this->data . '/plain.bin';
		file_put_contents($payload, 'payload');
		$manifest = $this->publish(self::PLAIN, $this->oldManifest(self::PLAIN, $payload, array($payload), false));

		$this->collect();
		$this->assertSame(null, self::contents($payload), 'its payload is deleted as before');
		$this->assertSame(null, self::contents($manifest), 'and the manifest is consumed');
		$this->assertSame(array(), FileUtil::$log, 'with no refusal: ' . json_encode(FileUtil::$log));
	}

	public function testANewManifestDeletesExactlyItsBackslashFiles()
	{
		// Raw names as the daemon now reports them; beside each, the spelling an
		// unescape of it would produce.
		$cases = array(self::QUOTED => array($this->data . '/p\\"q', $this->data . '/p"q'),
			self::DOUBLED => array($this->data . '/r\\\\s', $this->data . '/r\\s'));
		$manifests = array();
		foreach ($cases as $hash => $pair)
		{
			list($payload, $unescaped) = $pair;
			file_put_contents($payload, 'payload');
			file_put_contents($unescaped, 'unescaped');
			$bytes = ErasedataManifestCodec::encode($hash, array('base' => $payload, 'multi' => false,
				'files' => array($payload)), 1);
			$this->assertTrue(is_string($bytes) && strpos($bytes, '"path_bytes":"daemon"') !== false,
				'the producer marks a manifest whose path has a backslash as the daemon\'s own bytes: '
				. json_encode($bytes));
			$manifests[$hash] = $this->publish($hash, (string) $bytes);
		}

		$this->collect();
		foreach ($cases as $hash => $pair)
		{
			list($payload, $unescaped) = $pair;
			$this->assertSame(null, self::contents($payload), 'the file named ' . basename($payload) . ' is deleted');
			$this->assertSame('unescaped', self::contents($unescaped), 'its unescaped spelling '
				. basename($unescaped) . ' survives');
			$this->assertSame(null, self::contents($manifests[$hash]), 'and the manifest is consumed');
		}
		$this->assertSame(array(), FileUtil::$log, 'with no refusal: ' . json_encode(FileUtil::$log));
	}

	public function testOnlyABackslashPathIsMarkedAndTheCodecValidatesTheMark()
	{
		$plain = $this->data . '/plain.bin';
		$escaped = $this->data . '/a\\"b';
		$bytes = ErasedataManifestCodec::encode(self::PLAIN, array('base' => $plain, 'multi' => false,
			'files' => array($plain)), 1);
		$this->assertSame($this->oldManifest(self::PLAIN, $plain, array($plain), false), $bytes,
			'without a backslash the producer writes the layout every collector reads');
		$marked = ErasedataManifestCodec::encode(self::OLD, array('base' => $escaped, 'multi' => false,
			'files' => array($escaped)), 1);
		$record = is_string($marked) ? ErasedataManifestCodec::decodeBytes($marked, self::OLD) : false;
		$this->assertTrue(is_array($record) && $record['files'] === array($escaped)
			&& array_key_exists('paths_ambiguous', $record) && $record['paths_ambiguous'] === false,
			'a marked manifest decodes to its exact bytes, unambiguous');
		$old = ErasedataManifestCodec::decodeBytes($this->oldManifest(self::OLD, $escaped, array($escaped), false),
			self::OLD);
		$this->assertTrue(is_array($old) && $old['files'] === array($escaped)
			&& array_key_exists('paths_ambiguous', $old) && $old['paths_ambiguous'] === true,
			'an unmarked one with a backslash decodes as ambiguous, never unescaped');
		$plainRecord = ErasedataManifestCodec::decodeBytes((string) $bytes, self::PLAIN);
		$this->assertTrue(is_array($plainRecord) && array_key_exists('paths_ambiguous', $plainRecord)
			&& $plainRecord['paths_ambiguous'] === false, 'an unmarked one without a backslash is unambiguous');
		$template = json_decode((string) $marked, true);
		foreach (array('another value' => 'escaped', 'an empty value' => '', 'a number' => 1, 'true' => true)
			as $case => $value)
		{
			$changed = $template;
			$changed['path_bytes'] = $value;
			$this->assertSame(false, ErasedataManifestCodec::decodeBytes(json_encode($changed,
				JSON_UNESCAPED_SLASHES) . "\n", self::OLD), 'a mark with ' . $case . ' is refused');
		}
		$plainMarked = json_decode((string) $bytes, true);
		$plainMarked = array_slice($plainMarked, 0, 3, true) + array('path_bytes' => 'daemon')
			+ array_slice($plainMarked, 3, null, true);
		$this->assertSame(false, ErasedataManifestCodec::decodeBytes(json_encode($plainMarked,
			JSON_UNESCAPED_SLASHES) . "\n", self::PLAIN), 'a mark on paths without a backslash is not this'
			. ' producer\'s and is refused');
	}

	/**
	 * A payload's name must not depend on the locale: the collector runs under
	 * rtorrent, in C when rtorrent runs without LANG, and there PHP 7.4's
	 * basename() drops the non-ASCII bytes a name starts with, so the file it
	 * looked for was " 1.mkv" and the directory the one it is in.
	 */
	public function testNonAsciiPayloadsAreDeletedInTheCLocale()
	{
		$file = $this->data . '/Серия 1.mkv';
		file_put_contents($file, 'payload');
		$directory = $this->data . '/Сериал';
		mkdir($directory, 0700);
		file_put_contents($directory . '/Фильм.mkv', 'payload');
		$single = $this->publish(self::PLAIN, (string) ErasedataManifestCodec::encode(self::PLAIN,
			array('base' => $file, 'files' => array($file), 'multi' => false), 1));
		$multi = $this->publish(self::MULTI, (string) ErasedataManifestCodec::encode(self::MULTI,
			array('base' => $directory, 'files' => array($directory . '/Фильм.mkv'), 'multi' => true), 1));

		$locale = setlocale(LC_CTYPE, '0');
		setlocale(LC_CTYPE, 'C');
		try {
			$this->collect();
		} finally {
			setlocale(LC_CTYPE, $locale);
		}
		$this->assertSame(null, self::contents($file), 'the single file is deleted');
		$this->assertSame(false, file_exists($directory), 'and so is the multi-file directory');
		$this->assertSame(null, self::contents($single), 'its manifest is consumed');
		$this->assertSame(null, self::contents($multi), 'and so is the other one');
		$this->assertSame(array(), FileUtil::$log, 'with no refusal: ' . json_encode(FileUtil::$log));
	}

	/** A forced base that ends in slashes still names its directory, as basename() read it. */
	public function testAForcedBaseEndingInSlashesIsDeleted()
	{
		$base = $this->data . '/Name';
		mkdir($base, 0700);
		file_put_contents($base . '/x', 'payload');
		file_put_contents($this->data . '/sibling', 'sibling');
		$this->publish(self::MULTI, (string) ErasedataManifestCodec::encode(self::MULTI,
			array('base' => $base . '//', 'files' => array($base . '/x'), 'multi' => true), 2));

		$this->collect(null, true);
		$this->assertSame(false, file_exists($base), 'the directory is deleted: ' . json_encode(FileUtil::$log));
		$this->assertSame('sibling', self::contents($this->data . '/sibling'), 'and its sibling is not');
	}

	/**
	 * A captured entry's private root is found by a prefix made from its name.
	 * In C, PHP 7.4's basename() gave Сериал.mkv and Фильм.mkv the same name,
	 * .mkv, so the root of one was taken for one of the other's.
	 */
	public function testACapturedEntryRootBelongsToItsOwnNameInTheCLocale()
	{
		$captured = $this->data . '/Сериал.mkv';
		file_put_contents($captured, 'payload');
		$filesystem = new ErasedataFilesystemOps();
		$key = $this->queue . '/' . self::OLD . '.list';
		$locale = setlocale(LC_CTYPE, '0');
		setlocale(LC_CTYPE, 'C');
		try {
			$root = erasedataCreateCapturedEntryRoot($captured, $key,
				$filesystem->entryIdentity($captured), $filesystem);
			$roots = erasedataCapturedEntryRoots($this->data . '/Фильм.mkv', $key, $filesystem);
		} finally {
			setlocale(LC_CTYPE, $locale);
		}
		$this->assertTrue(is_string($root), 'a private root is made for the captured file');
		$this->assertSame(array(), $roots, 'and it is not one of the other file\'s');
	}

	/**
	 * Directory recovery looks for the directory a record was written for.
	 * In C, PHP 7.4's basename() turned .../data/Сериал into data, so a
	 * prepared record no longer found its own directory.
	 */
	public function testADirectoryIntentFindsItsNonAsciiDirectoryInTheCLocale()
	{
		$directory = $this->data . '/Сериал';
		mkdir($directory, 0700);
		$item = $this->queue . '/' . self::MULTI . '.list';
		$digest = str_repeat('a', 64);
		$parent = stat($this->data);
		$target = stat($directory);
		$collector = new ErasedataCollector(new ErasedataCollectorFixture(array()),
			function($hash) { return ERASEDATA_TORRENT_ABSENT; },
			function($hash, $physicalOnly = false) { return false; },
			function($line) {}, false);
		$intentPath = new ReflectionMethod('ErasedataCollector', 'directoryIntentPath');
		$mark = new ReflectionMethod('ErasedataCollector', 'markDirectoryIntentPhase');
		if (PHP_VERSION_ID < 80100) {
			$intentPath->setAccessible(true);
			$mark->setAccessible(true);
		}
		file_put_contents($intentPath->invoke($collector, $item), json_encode(array('version' => 2,
			'manifest' => $digest, 'logical' => $directory, 'parent' => realpath($this->data),
			'parentDev' => (string) $parent['dev'], 'parentIno' => (string) $parent['ino'],
			'targetDev' => (string) $target['dev'], 'targetIno' => (string) $target['ino'],
			'phase' => 'prepared', 'reservation' => null), JSON_UNESCAPED_SLASHES));
		$locale = setlocale(LC_CTYPE, '0');
		setlocale(LC_CTYPE, 'C');
		try {
			$found = $mark->invoke($collector, $item, $digest, $directory, null, 'verify-missing');
		} finally {
			setlocale(LC_CTYPE, $locale);
		}
		$this->assertSame(true, $found, 'the prepared record still finds the directory it names');
	}
}
