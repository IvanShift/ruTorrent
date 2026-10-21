<?php

$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir() . '/rutorrent-cache-test-' . getmypid();

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/cache.php');
require_once(__DIR__ . '/CacheMergePayloadFixture.php');
require_once(__DIR__ . '/PermissionsBiteFixture.php');

// Its own class rather than an instance with a reassigned hash: when set()
// reconciles a concurrent write it reloads the file through a freshly
// constructed instance of the same class, so a merge-capable payload has to
// carry its identity in the class, the way rHistoryData does.
class CacheGhostLoadPayload extends CacheMergePayload
{
	public $hash = 'ghost-load-test.dat';
}

// Keyed by its owner only after construction, the way privateData is: the
// instance set() reads a newer copy into is built without an owner.
class CacheOwnerKeyedPayload extends CacheMergePayload
{
	public $mergedFrom = array();

	public function __construct($owner = 'ownerless')
	{
		$this->hash = $owner . '-owner-cache-test.dat';
	}

	public function merge($instance, $arg)
	{
		$this->mergedFrom[] = $instance->hash;
		return parent::merge($instance, $arg);
	}
}

// Emptied by its own change, the way a forgotten session is: what it dropped
// stays dropped in a merge, and set() removes the document once it is empty.
class CacheEmptiablePayload
{
	public $hash = 'emptiable-cache-test.dat';
	public $modified = false;
	public $rows = array();
	protected $added = array();
	protected $dropped = array();

	public function __sleep()
	{
		return(array('hash', 'modified', 'rows'));
	}

	public function add($key)
	{
		$this->rows[$key] = $key;
		$this->added[$key] = $key;
		unset($this->dropped[$key]);
	}

	public function drop($key)
	{
		unset($this->rows[$key], $this->added[$key]);
		$this->dropped[$key] = $key;
	}

	public function merge($newer, $arg)
	{
		$rows = is_array($newer->rows) ? $newer->rows : array();
		foreach($this->dropped as $key)
			unset($rows[$key]);
		foreach($this->added as $key)
			$rows[$key] = $key;
		$this->rows = $rows;
		return(true);
	}

	public function isEmpty()
	{
		return(!$this->rows);
	}
}

// Fork-only: throws out of __wakeup() so the guard's restore path is testable.
class CacheThrowingPayload
{
	public $hash = 'throwing-cache-test.dat';

	public function __wakeup()
	{
		throw new Exception('wakeup failed');
	}
}

// A harmless stand-in for an arbitrary class named by a cache file.
class CacheUnexpectedPayload
{
    public $hash = 'unexpected-cache-test.dat';
    public static $wakeups = 0;

    public function __wakeup()
    {
        self::$wakeups++;
    }
}

class CacheAllowedItem
{
    public $hash = 'allowed-list-cache-test.dat';
    public $name = 'nested';
    public static $wakeups = 0;

    public function __wakeup()
    {
        self::$wakeups++;
    }
}

class CacheAllowedList
{
    public $hash = 'allowed-list-cache-test.dat';
    public $items = array();

    public static function cacheClasses()
    {
        return array('CacheAllowedItem');
    }
}

class CacheWarningPayload
{
    public $hash = 'warning-cache-test.dat';

    public function __wakeup()
    {
        trigger_error('cache-wakeup-warning', E_USER_WARNING);
    }
}

class CacheNestedWarningPayload
{
    public $hash = 'nested-warning-cache-test.dat';

    public function __wakeup()
    {
        @unserialize('broken');
    }
}

// A payload that refuses every merge and counts the attempts. It tells a
// store that went straight through from one that re-read and re-merged the
// file first: the second fails on the refusal.
class CacheMergeRefusingPayload extends CacheMergePayload
{
	public $hash = 'merge-refusing-test.dat';
	public static $merges = 0;

	public function merge($instance, $arg)
	{
		self::$merges++;
		return(false);
	}
}

class CacheTest extends TestCase
{
	private $profilePath;

	public function setUp()
	{
		$this->profilePath = $_ENV['RU_PROFILE_PATH'];
		if (is_dir($this->profilePath)) {
			$this->removeDir($this->profilePath);
		}
		FileUtil::makeDirectory(FileUtil::getSettingsPath());
	}

	public function tearDown()
	{
		if (is_dir($this->profilePath)) {
			$this->removeDir($this->profilePath);
		}
	}

	private function removeDir($dir)
	{
		foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
			$path = $dir . '/' . $entry;
			if (is_dir($path) && !is_link($path)) {
				$this->removeDir($path);
			} else {
				unlink($path);
			}
		}
		rmdir($dir);
	}

	// Writes a helper that does what a second update.php does -- load the
	// cache, record one row, store it -- as a genuinely separate process,
	// with statics of its own, the way a second update.php has them.
	private function makeWriterScript()
	{
		$path = FileUtil::getSettingsPath() . '/concurrent-writer.php';
		$code = "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->profilePath, true) . ";\n"
			. 'require_once(' . var_export(realpath(__DIR__ . '/../../php/cache.php'), true) . ");\n"
			. 'require_once(' . var_export(realpath(__DIR__ . '/CacheMergePayloadFixture.php'), true) . ");\n"
			. "\$payload = new CacheMergePayload();\n"
			. "(new rCache())->get(\$payload);\n"
			. "exit(\$payload->addRow(\$argv[1]) ? 0 : 1);\n";
		file_put_contents($path, $code);
		return $path;
	}

	// Writes the helper for the overlap test: load the cache, then rendezvous
	// with the peer writer -- announce readiness through one file, spin until
	// the peer's file appears -- so both processes enter set() at the same
	// moment, then store one row.
	private function makeBarrierWriterScript()
	{
		$path = FileUtil::getSettingsPath() . '/overlapping-writer.php';
		$code = "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->profilePath, true) . ";\n"
			. 'require_once(' . var_export(realpath(__DIR__ . '/../../php/cache.php'), true) . ");\n"
			. 'require_once(' . var_export(realpath(__DIR__ . '/CacheMergePayloadFixture.php'), true) . ");\n"
			. "list(, \$row, \$readyMine, \$readyOther) = \$argv;\n"
			. "\$payload = new CacheMergePayload();\n"
			. "(new rCache())->get(\$payload);\n"
			. "touch(\$readyMine);\n"
			. "\$deadline = microtime(true) + 10;\n"
			. "for (;;) {\n"
			. "\tclearstatcache(true, \$readyOther);\n"
			. "\tif (file_exists(\$readyOther)) break;\n"
			. "\tif (microtime(true) > \$deadline) exit(2);\n"
			. "\tusleep(200);\n"
			. "}\n"
			. "// Both writers saw each other; spinning to the same clock boundary\n"
			. "// puts their set() calls within microseconds of each other.\n"
			. "\$go = ceil((microtime(true) + 0.05) * 4) / 4;\n"
			. "while (microtime(true) < \$go);\n"
			. "exit(\$payload->addRow(\$row) ? 0 : 1);\n";
		file_put_contents($path, $code);
		return $path;
	}

	// Writes the helper for the repeated-stamp test: another process that
	// changes one row to a value of the same length, rewrites the file in
	// place under the key lock and puts the mtime back. The inode, the mtime
	// and the size are then exactly what this process loaded -- the stat
	// tuple that two rename-published stores leave behind on an idle ext4,
	// where the next temporary file is handed the inode the first store
	// freed -- forced here so the test does not depend on the allocator.
	private function makeSameStampWriterScript()
	{
		$path = FileUtil::getSettingsPath() . '/same-stamp-writer.php';
		$code = "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->profilePath, true) . ";\n"
			. 'require_once(' . var_export(realpath(__DIR__ . '/../../php/cache.php'), true) . ");\n"
			. 'require_once(' . var_export(realpath(__DIR__ . '/CacheMergePayloadFixture.php'), true) . ");\n"
			. "\$payload = new CacheMergePayload();\n"
			. "\$name = FileUtil::getSettingsPath() . '/' . \$payload->hash;\n"
			. "\$lock = fopen(\$name . '.lock', 'c');\n"
			. "if (\$lock === false || !flock(\$lock, LOCK_EX)) exit(2);\n"
			. "\$before = stat(\$name);\n"
			. "if (!(new rCache())->get(\$payload)) exit(3);\n"
			. "\$payload->rows['slot'] = \$argv[1];\n"
			. "\$bytes = serialize(\$payload);\n"
			. "if (strlen(\$bytes) !== \$before['size']) exit(4);\n"
			. "if (file_put_contents(\$name, \$bytes) !== strlen(\$bytes)) exit(5);\n"
			. "if (!touch(\$name, \$before['mtime'])) exit(6);\n"
			. "clearstatcache(true, \$name);\n"
			. "\$after = stat(\$name);\n"
			. "exit(\$after['ino'] === \$before['ino'] && \$after['mtime'] === \$before['mtime']\n"
			. "\t&& \$after['size'] === \$before['size'] ? 0 : 7);\n";
		file_put_contents($path, $code);
		return $path;
	}

	// The live failure this guards: replacing a torrent fires three update.php
	// processes inside one second, and filemtime only resolves to the second,
	// so a writer that reloaded after a same-second write saw an unchanged
	// timestamp, skipped merging and republished the file without the other
	// process's row. On a live fleet the row that vanished was the "added" one
	// of every replacement.
	public function testRowFromAnotherProcessSurvivesAWriteInTheSameSecond()
	{
		// Start near the top of a second so the seed, this process's load and
		// the other process's write all share one mtime.
		usleep((int) ((1 - fmod(microtime(true), 1)) * 1000000));

		$seed = new CacheMergePayload();
		$this->assertTrue($seed->addRow('seed'), 'the seeding write succeeds');

		// This process loads the file; rCache records how the file looked.
		$mine = new CacheMergePayload();
		(new rCache())->get($mine);

		// Another process records its own row in the very same second.
		$writer = $this->makeWriterScript();
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($writer) . ' other-process', $output, $code);
		$this->assertEquals(0, $code, 'the second writer process stores its row');

		// Only now does this process store its own row.
		$this->assertTrue($mine->addRow('mine'), 'this process stores its row');

		$check = new CacheMergePayload();
		(new rCache())->get($check);
		$this->assertTrue(isset($check->rows['mine']), 'this process keeps its own row');
		$this->assertTrue(isset($check->rows['other-process']),
			'the row another process wrote in the same second is not overwritten');
		$this->assertTrue(isset($check->rows['seed']), 'the pre-existing row is untouched');
	}

	// Unlike the sequential test above, here the two writers overlap inside
	// set(): both have loaded the same file when they start storing. Checking
	// the stamp outside the writer lock loses a row here -- both compare
	// against the pre-write state, both skip merging, and whichever publishes
	// last erases the other's row. Only a check-merge-publish critical
	// section makes this deterministic, which is why the rounds are asserted
	// individually instead of retried.
	public function testTwoOverlappingWritersBothKeepTheirRows()
	{
		$writer = $this->makeBarrierWriterScript();
		$settings = FileUtil::getSettingsPath();
		$cacheFile = $settings . '/' . (new CacheMergePayload())->hash;
		$readyFirst = $settings . '/first-writer.ready';
		$readySecond = $settings . '/second-writer.ready';

		// One round can miss the race by scheduling luck; ten in a row do not.
		for ($round = 1; $round <= 10; $round++) {
			@unlink($cacheFile);
			@unlink($readyFirst);
			@unlink($readySecond);

			$seed = new CacheMergePayload();
			$this->assertTrue($seed->addRow('seed'), "round {$round}: the seeding write succeeds");

			$php = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($writer);
			$first = proc_open($php . ' first '
				. escapeshellarg($readyFirst) . ' ' . escapeshellarg($readySecond), [], $pipesFirst);
			$second = proc_open($php . ' second '
				. escapeshellarg($readySecond) . ' ' . escapeshellarg($readyFirst), [], $pipesSecond);
			$this->assertEquals(0, proc_close($first), "round {$round}: the first writer stores its row");
			$this->assertEquals(0, proc_close($second), "round {$round}: the second writer stores its row");

			$check = new CacheMergePayload();
			(new rCache())->get($check);
			$this->assertTrue(
				isset($check->rows['first']) && isset($check->rows['second']) && isset($check->rows['seed']),
				"round {$round}: both overlapping writers' rows and the seed survive");
		}
	}

	// A writer whose get() found nothing -- the file did not exist yet -- has
	// no stamp to compare against. It must still notice a file that appeared
	// before its own store and merge with it instead of clobbering it.
	public function testWriterThatLoadedNothingMergesAFileThatAppearedMeanwhile()
	{
		// This writer asks for a cache entry that does not exist yet.
		$mine = new CacheGhostLoadPayload();
		$this->assertEquals(false, (new rCache())->get($mine), 'nothing to load yet');

		// Before it stores, another writer publishes the file.
		$other = new CacheGhostLoadPayload();
		$this->assertTrue($other->addRow('other'), 'the other writer stores its row');

		// The first writer's store must merge with the file that appeared.
		$this->assertTrue($mine->addRow('mine'), 'this writer stores its row');

		$check = new CacheGhostLoadPayload();
		(new rCache())->get($check);
		$this->assertTrue(isset($check->rows['mine']), 'this writer keeps its own row');
		$this->assertTrue(isset($check->rows['other']),
			'the row that appeared while this writer held nothing is merged, not clobbered');
	}

	public function testTwoObjectsOfSameKeyKeepTheirDistinctLoadedVersions()
	{
		$cache = new rCache();
		$seed = new CacheMergePayload();
		$this->assertTrue($seed->addRow('seed'), 'the initial row is stored');

		$old = new CacheMergePayload();
		$latest = new CacheMergePayload();
		$this->assertTrue($cache->get($old), 'the first object loads the initial version');
		$this->assertTrue($cache->get($latest), 'the second object loads the initial version');
		$this->assertTrue($latest->addRow('other'), 'the second object stores its own row');
		$this->assertTrue($cache->get($latest), 'the second object refreshes its version');
		$this->assertTrue($old->addRow('mine'), 'the first object stores after its peer');

		$check = new CacheMergePayload();
		$this->assertTrue($cache->get($check), 'the resulting cache file loads');
		$this->assertTrue(isset($check->rows['seed'], $check->rows['other'], $check->rows['mine']),
			'an unrelated load of the same key cannot replace the first object stamp');
	}

	// A delete/recreate ABA may reuse the same inode, second-resolution mtime
	// and size. Rewrite in place here to force that identical stat tuple without
	// depending on the CI filesystem's inode allocation.
	public function testSameStatTupleWithDifferentBytesStillMerges()
	{
		$cache = new rCache();
		$seed = new CacheMergePayload();
		$seed->rows['slot'] = 'aaaa';
		$this->assertTrue($cache->set($seed), 'the initial cache file is stored');
		$old = new CacheMergePayload();
		$this->assertTrue($cache->get($old), 'the first object loads old contents');
		$path = FileUtil::getSettingsPath() . '/' . $seed->hash;
		$before = stat($path);
		$current = new CacheMergePayload();
		$current->rows['slot'] = 'bbbb';
		$bytes = serialize($current);
		$this->assertSame(strlen(file_get_contents($path)), strlen($bytes),
			'the replacement has the same serialized size');
		$this->assertSame(strlen($bytes), file_put_contents($path, $bytes),
			'the different contents are written at the same inode');
		$this->assertTrue(touch($path, $before['mtime']), 'the previous second-level mtime is restored');
		clearstatcache(true, $path);
		$after = stat($path);
		$this->assertSame(array($before['ino'], $before['mtime'], $before['size']),
			array($after['ino'], $after['mtime'], $after['size']),
			'the fast cache stamp cannot distinguish the two contents');

		$this->assertTrue($old->addRow('mine'), 'the stale object stores its own change');
		$check = new CacheMergePayload();
		$this->assertTrue($cache->get($check), 'the merged result loads');
		$this->assertSame('bbbb', $check->rows['slot'],
			'different bytes trigger a merge even when inode, mtime and size match');
		$this->assertSame('mine', $check->rows['mine'], 'the stale object keeps its own new row');
	}

	// A writer keyed per owner after construction must be merged with the
	// copy under its own key, not with the one an ownerless instance names.
	public function testMergeReceivesTheNewerCopyUnderTheObjectsOwnKey()
	{
		$cache = new rCache();
		$ownerless = new CacheOwnerKeyedPayload();
		$ownerless->rows = array('ownerless' => 'ownerless');
		$this->assertTrue($cache->set($ownerless), 'a document exists under the key of an instance without an owner');
		$ownerlessFile = FileUtil::getSettingsPath() . '/' . $ownerless->hash;
		$ownerlessBytes = file_get_contents($ownerlessFile);

		$seed = new CacheOwnerKeyedPayload('alice');
		$this->assertTrue($seed->addRow('seed'), 'the owner document is stored');
		$mine = new CacheOwnerKeyedPayload('alice');
		$other = new CacheOwnerKeyedPayload('alice');
		$this->assertTrue($cache->get($mine), 'this writer loads the owner document');
		$this->assertTrue($cache->get($other), 'another writer loads it too');
		$this->assertTrue($other->addRow('other'), 'the other writer stores its row first');
		$this->assertTrue($mine->addRow('mine'), 'this writer stores after it');

		$this->assertSame(array('alice-owner-cache-test.dat'), $mine->mergedFrom,
			'the merge was handed the newer copy stored under the owner key');
		$check = new CacheOwnerKeyedPayload('alice');
		$this->assertTrue($cache->get($check), 'the merged owner document loads');
		ksort($check->rows);
		$this->assertSame(array('mine' => 'mine', 'other' => 'other', 'seed' => 'seed'), $check->rows,
			'neither writer loses its row');
		$this->assertSame($ownerlessBytes, file_get_contents($ownerlessFile),
			'the document under the ownerless key is neither merged in nor rewritten');
	}

	public function testAnEmptiedObjectRemovesItsDocumentInsteadOfStoringIt()
	{
		global $log_file;
		$cache = new rCache();
		$seed = new CacheEmptiablePayload();
		$seed->add('only');
		$this->assertTrue($cache->set($seed), 'the document is stored');
		$file = FileUtil::getSettingsPath() . '/' . $seed->hash;
		$previousLog = $log_file;
		$log_file = $this->profilePath . '/emptied-cache.log';
		try {
			$mine = new CacheEmptiablePayload();
			$this->assertTrue($cache->get($mine), 'this writer loads the document');
			$mine->drop('only');
			$this->assertSame(true, $cache->set($mine), 'storing the emptied object succeeds');
			clearstatcache(true, $file);
			$this->assertSame(false, file_exists($file), 'the emptied document is removed, not stored empty');
			$this->assertSame(true, file_exists($file . '.lock'), 'the key lock sidecar stays for waiting writers');
			$check = new CacheEmptiablePayload();
			$this->assertSame(false, $cache->get($check), 'nothing is left to load');

			$empty = new CacheEmptiablePayload();
			$this->assertSame(true, $cache->set($empty), 'an empty object with no document to remove succeeds');
			clearstatcache(true, $file);
			$this->assertSame(false, file_exists($file), 'and creates no empty document');
			$this->assertSame('', is_file($log_file) ? file_get_contents($log_file) : '',
				'neither removal is a refusal');
		} finally {
			$log_file = $previousLog;
		}
	}

	// The emptiness of what this writer holds is not the emptiness of the
	// document: a row another writer stored meanwhile is merged in first.
	public function testANewerCopyMergedFirstKeepsTheDocumentAnEmptiedObjectWouldRemove()
	{
		$cache = new rCache();
		$seed = new CacheEmptiablePayload();
		$seed->add('seed');
		$this->assertTrue($cache->set($seed), 'the document is stored');
		$mine = new CacheEmptiablePayload();
		$other = new CacheEmptiablePayload();
		$this->assertTrue($cache->get($mine), 'this writer loads the document');
		$this->assertTrue($cache->get($other), 'another writer loads it too');
		$other->add('other');
		$this->assertTrue($cache->set($other), 'the other writer stores its row first');
		$mine->drop('seed');
		$this->assertTrue($mine->isEmpty(), 'by itself this writer would leave nothing');
		$this->assertSame(true, $cache->set($mine), 'this writer stores after it');

		$check = new CacheEmptiablePayload();
		$this->assertTrue($cache->get($check), 'the document is kept');
		$this->assertSame(array('other' => 'other'), $check->rows,
			'the other writer\'s row is kept and the dropped row stays dropped');
	}

	// For a document whose emptiness means absence, another writer's removal
	// is a newer copy with nothing in it: what this writer merely read must
	// not come back with its store.
	public function testAnAbsentDocumentIsAnEmptyNewerCopyForAnObjectWithIsEmpty()
	{
		$cache = new rCache();
		$file = FileUtil::getSettingsPath() . '/' . (new CacheEmptiablePayload())->hash;
		$seed = new CacheEmptiablePayload();
		$seed->add('seed');
		$this->assertTrue($cache->set($seed), 'the document is stored');
		$mine = new CacheEmptiablePayload();
		$other = new CacheEmptiablePayload();
		$this->assertTrue($cache->get($mine), 'this writer loads the document');
		$this->assertTrue($cache->get($other), 'another writer loads it too');
		$other->drop('seed');
		$this->assertSame(true, $cache->set($other), 'the other writer empties it');
		clearstatcache(true, $file);
		$this->assertSame(false, file_exists($file), 'and the document is removed');
		$mine->add('mine');
		$this->assertSame(true, $cache->set($mine), 'this writer stores after the removal');
		$check = new CacheEmptiablePayload();
		$this->assertTrue($cache->get($check), 'this writer\'s own row is stored');
		$this->assertSame(array('mine' => 'mine'), $check->rows, 'the row it only read does not come back');

		$this->assertTrue($cache->remove($check), 'the document is cleared');
		$again = new CacheEmptiablePayload();
		$again->add('seed');
		$this->assertTrue($cache->set($again), 'the document is stored again');
		$stale = new CacheEmptiablePayload();
		$other = new CacheEmptiablePayload();
		$this->assertTrue($cache->get($stale), 'a writer with no change of its own loads it');
		$this->assertTrue($cache->get($other), 'another writer loads it too');
		$this->assertTrue($cache->remove($other), 'the other writer removes it');
		$this->assertSame(true, $cache->set($stale), 'the stale writer stores');
		clearstatcache(true, $file);
		$this->assertSame(false, file_exists($file), 'a store without changes does not recreate the document');
	}

	// RSS, history and extsearch-style documents have no isEmpty(): an absent
	// document stays what it always was for them, a write of the full state.
	public function testAnAbsentDocumentIsNotMergedForAnObjectWithoutIsEmpty()
	{
		$cache = new rCache();
		$seed = new CacheOwnerKeyedPayload('bob');
		$this->assertTrue($seed->addRow('seed'), 'the document is stored');
		$mine = new CacheOwnerKeyedPayload('bob');
		$this->assertTrue($cache->get($mine), 'this writer loads it');
		$this->assertTrue($cache->remove(new CacheOwnerKeyedPayload('bob')), 'another writer removes it');
		$this->assertTrue($mine->addRow('mine'), 'this writer stores after the removal');
		$this->assertSame(array(), $mine->mergedFrom, 'no merge is asked for an absent document');
		$check = new CacheOwnerKeyedPayload('bob');
		$this->assertTrue($cache->get($check), 'the document is stored');
		ksort($check->rows);
		$this->assertSame(array('mine' => 'mine', 'seed' => 'seed'), $check->rows, 'the writer\'s full state is stored');
	}

	// Only absence is an empty newer copy. A newer document that exists but
	// cannot be loaded keeps the previous outcome: no merge, the full state.
	public function testAnUnloadableNewerDocumentIsNotTakenForAnAbsentOne()
	{
		global $log_file;
		$cache = new rCache();
		$file = FileUtil::getSettingsPath() . '/' . (new CacheEmptiablePayload())->hash;
		$seed = new CacheEmptiablePayload();
		$seed->add('seed');
		$this->assertTrue($cache->set($seed), 'the document is stored');
		$mine = new CacheEmptiablePayload();
		$this->assertTrue($cache->get($mine), 'this writer loads it');
		$this->assertTrue(file_put_contents($file, serialize(new CacheUnexpectedPayload())) !== false,
			'the document is replaced by one that names another class');
		$previousLog = $log_file;
		$log_file = $this->profilePath . '/unloadable-newer-cache.log';
		try {
			$mine->add('mine');
			$this->assertSame(true, $cache->set($mine), 'this writer stores');
			$lines = is_file($log_file) ? file_get_contents($log_file) : '';
			$this->assertSame(1, substr_count($lines,
				'does not hold CacheEmptiablePayload at its root (found class CacheUnexpectedPayload); not loaded'),
				'the unloadable newer document was read and refused visibly');
		} finally {
			$log_file = $previousLog;
		}
		$check = new CacheEmptiablePayload();
		$this->assertTrue($cache->get($check), 'the document is stored');
		ksort($check->rows);
		$this->assertSame(array('mine' => 'mine', 'seed' => 'seed'), $check->rows,
			'the writer\'s full state is stored, not a merge with an empty copy');
	}

	public function testAnEmptiedDocumentIsRemovedOnlyUnderTheKeyLock()
	{
		global $log_file;
		$cache = new rCache();
		$seed = new CacheEmptiablePayload();
		$seed->add('only');
		$this->assertTrue($cache->set($seed), 'the document is stored');
		$file = FileUtil::getSettingsPath() . '/' . $seed->hash;
		$mine = new CacheEmptiablePayload();
		$this->assertTrue($cache->get($mine), 'this writer loads the document');
		$mine->drop('only');
		$previousLog = $log_file;
		$log_file = $this->profilePath . '/emptied-locked-cache.log';
		$lock = fopen($file . '.lock', 'c');
		$this->assertTrue(flock($lock, LOCK_EX), 'another writer holds the key lock');
		try {
			$this->assertSame(false, $cache->set($mine), 'the removal waits for the key lock and refuses without it');
			clearstatcache(true, $file);
			$this->assertSame(true, is_file($file), 'the document stays while another writer holds the lock');
			$lines = is_file($log_file) ? file_get_contents($log_file) : '';
			$this->assertSame(1, substr_count($lines, 'rCache: set refused: key lock unavailable'),
				'the refused removal names its reason');
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
			$log_file = $previousLog;
		}
	}

	public function testAnEmptiedDocumentThatCannotBeUnlinkedIsRefusedVisibly()
	{
		if (testSkipUnlessPermissionsBite('an emptied cache document that cannot be unlinked is refused visibly')) return;
		global $log_file;
		$cache = new rCache();
		$seed = new CacheEmptiablePayload();
		$seed->add('only');
		$this->assertTrue($cache->set($seed), 'the document and its lock sidecar exist');
		$settings = FileUtil::getSettingsPath();
		$mine = new CacheEmptiablePayload();
		$this->assertTrue($cache->get($mine), 'this writer loads the document');
		$mine->drop('only');
		$previousLog = $log_file;
		$log_file = $this->profilePath . '/emptied-unwritable-cache.log';
		try {
			$this->assertTrue(chmod($settings, 0555), 'the cache directory no longer permits unlink');
			$this->assertSame(false, $cache->set($mine), 'a document that cannot be removed is not reported removed');
			$this->assertTrue(is_file($settings . '/' . $seed->hash), 'the refused document stays intact');
			$lines = is_file($log_file) ? file_get_contents($log_file) : '';
			$this->assertSame(1, substr_count($lines, 'rCache: set refused: cache unlink failed'),
				'the refusal is logged once');
		} finally {
			chmod($settings, 0700);
			$log_file = $previousLog;
		}
	}

	// What rCache remembers about a load belongs to the object that was
	// loaded, not to its cache key. One process can hold two objects of one
	// key: an rss update run loads "info" in its rRSSManager, and with
	// rssurlrewrite enabled each feed fetched with items fires the
	// RSSFetched hook, which builds a second rRSSManager that loads "info"
	// again. A load through the second object must not stand for the first,
	// or the first stores as if it had seen a version it never merged and
	// erases what that version added.
	public function testTwoObjectsOfOneKeyKeepTheirOwnLoadedVersions()
	{
		$path = FileUtil::getSettingsPath() . '/' . (new CacheMergePayload())->hash;
		@unlink($path);
		$seed = new CacheMergePayload();
		$this->assertTrue($seed->addRow('seed'), 'the seeding write succeeds');

		$cache = new rCache();
		$first = new CacheMergePayload();
		$this->assertTrue($cache->get($first), 'the first object loads the seeded file');

		// Another process stores a row; then a second object of the same key
		// is loaded in this process and sees it.
		$writer = $this->makeWriterScript();
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($writer) . ' other-process', $output, $code);
		$this->assertEquals(0, $code, 'the other process stores its row');
		$second = new CacheMergePayload();
		$this->assertTrue($cache->get($second), 'a second object of the same key loads the newer file');

		// The first object still holds the seeded version.
		$this->assertTrue($first->addRow('mine'), 'the first object stores its row');

		$check = new CacheMergePayload();
		$this->assertTrue($cache->get($check), 'the stored file loads');
		$this->assertTrue(isset($check->rows['other-process']),
			'the row the first object never loaded is merged, not erased by its stale copy');
		$this->assertTrue(isset($check->rows['seed'], $check->rows['mine']),
			'the seed row and the first object\'s row are both in the file');
	}

	// An object that loaded nothing merges whatever is on disk when it
	// stores, whether or not another object of its key was loaded -- also
	// when that object has been freed and PHP hands its object id to the
	// next object it creates.
	public function testAnObjectThatLoadedNothingIsNotTakenForOneThatDid()
	{
		$path = FileUtil::getSettingsPath() . '/' . (new CacheMergePayload())->hash;
		@unlink($path);
		$seed = new CacheMergePayload();
		$this->assertTrue($seed->addRow('seed'), 'the seeding write succeeds');
		$cache = new rCache();

		$peer = new CacheMergePayload();
		$this->assertTrue($cache->get($peer), 'an object of this key loads the file');
		$fresh = new CacheMergePayload();
		$this->assertTrue($fresh->addRow('fresh'), 'a fresh object of the same key stores its row');
		$check = new CacheMergePayload();
		$cache->get($check);
		$this->assertTrue(isset($check->rows['seed'], $check->rows['fresh']),
			'a fresh object merges the file another live object loaded');

		$gone = new CacheMergePayload();
		$this->assertTrue($cache->get($gone), 'another object of this key loads the file');
		$id = spl_object_id($gone);
		unset($gone);
		$reused = new CacheMergePayload();
		$this->assertEquals($id, spl_object_id($reused),
			'the next object takes the freed object id (precondition)');
		$this->assertTrue($reused->addRow('reused'), 'that object stores its row');
		$check = new CacheMergePayload();
		$cache->get($check);
		$this->assertTrue(isset($check->rows['seed'], $check->rows['fresh'], $check->rows['reused']),
			'an object that inherits a freed object id does not inherit its load');
	}

	// The stamp is (inode, mtime, size), and all three can repeat for
	// different bytes: mtime resolves to the second, size repeats whenever a
	// row changes to a value of the same length, and the inode comes back
	// because set() publishes by rename(), which frees the loaded inode, and
	// the next writer's temporary file is handed that number again (12 of 12
	// times on an idle ext4). A writer whose stamp still matched skipped the
	// merge and published its stale copy over both newer ones.
	public function testSameStampWithDifferentBytesFromAnotherProcessStillMerges()
	{
		// setUp() empties the profile before each case; the unlink keeps
		// this case independent of that.
		$path = FileUtil::getSettingsPath() . '/' . (new CacheMergePayload())->hash;
		@unlink($path);
		$seed = new CacheMergePayload();
		$seed->rows['slot'] = 'aaaa';
		$this->assertTrue((new rCache())->set($seed), 'the seeding write succeeds');

		// This process loads the file; rCache records how it looked.
		$mine = new CacheMergePayload();
		$this->assertTrue((new rCache())->get($mine), 'this process loads the file');
		$before = stat($path);
		$bytesBefore = file_get_contents($path);

		// Another process changes the row under exactly that stamp.
		$writer = $this->makeSameStampWriterScript();
		exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($writer) . ' bbbb', $output, $code);
		$this->assertEquals(0, $code, 'the other process rewrites the row under the loaded stamp (exit ' . $code . ')');
		clearstatcache(true, $path);
		$after = stat($path);
		$this->assertEquals([$before['ino'], $before['mtime'], $before['size']],
			[$after['ino'], $after['mtime'], $after['size']],
			'inode, mtime and size are the ones this process loaded');
		$this->assertTrue($bytesBefore !== file_get_contents($path), 'and the bytes are not');

		// Only now does this process store its own row.
		$this->assertTrue($mine->addRow('mine'), 'this process stores its row');

		$check = new CacheMergePayload();
		(new rCache())->get($check);
		$this->assertEquals('bbbb', isset($check->rows['slot']) ? $check->rows['slot'] : null,
			'the row another process changed under a repeated stamp is merged, not overwritten');
		$this->assertTrue(isset($check->rows['mine']), 'this process keeps its own row');
	}

	// The other half of the same decision: a stamp that repeats over the
	// bytes this process loaded means the file is still the one it loaded,
	// and the store goes through without re-reading and re-merging it.
	public function testSameStampWithTheSameBytesStoresWithoutReMerging()
	{
		$path = FileUtil::getSettingsPath() . '/' . (new CacheMergeRefusingPayload())->hash;
		@unlink($path);
		$seed = new CacheMergeRefusingPayload();
		$seed->rows['seed'] = 'seed';
		$this->assertTrue((new rCache())->set($seed), 'the seeding write succeeds');
		$mine = new CacheMergeRefusingPayload();
		$this->assertTrue((new rCache())->get($mine), 'this process loads the file');
		CacheMergeRefusingPayload::$merges = 0;

		// Nothing touches the file in between.
		$this->assertTrue($mine->addRow('mine'), 'a store over the unchanged file goes through');
		$this->assertEquals(0, CacheMergeRefusingPayload::$merges,
			'the unchanged file is not re-merged (merge() was called ' . CacheMergeRefusingPayload::$merges . ' times)');

		$check = new CacheMergeRefusingPayload();
		$this->assertTrue((new rCache())->get($check), 'the stored file loads');
		$this->assertTrue(isset($check->rows['seed'], $check->rows['mine']),
			'the seed row and this process\'s row are both in the file');
	}

	// With the stamp repeated, the bytes decide. When they cannot be read,
	// this process cannot tell whether the file is still the one it loaded,
	// and publishing would silently erase a version it could not compare:
	// the store is refused, and the refusal is logged.
	public function testFileThatCannotBeReadUnderTheSameStampIsNotOverwritten()
	{
		global $log_file;

		$path = FileUtil::getSettingsPath() . '/' . (new CacheMergePayload())->hash;
		@unlink($path);
		$seed = new CacheMergePayload();
		$seed->rows['slot'] = 'aaaa';
		$this->assertTrue((new rCache())->set($seed), 'the seeding write succeeds');
		$mine = new CacheMergePayload();
		$this->assertTrue((new rCache())->get($mine), 'this process loads the file');
		$bytes = file_get_contents($path);

		// chmod changes the ctime only: the (inode, mtime, size) stamp stays.
		$this->assertTrue(chmod($path, 0000), 'the file is made unreadable');
		$this->assertEquals(false, @file_get_contents($path),
			'the file really cannot be read (not running as root)');
		$log = $this->profilePath . '/cache-refusal.log';
		$previousLog = $log_file;
		$log_file = $log;
		try {
			$stored = $mine->addRow('mine');
		} finally {
			$log_file = $previousLog;
			chmod($path, 0600);
		}
		$lines = is_file($log) ? file_get_contents($log) : '';

		$this->assertEquals(false, $stored,
			'a store that cannot compare the file with what it loaded is refused');
		$this->assertEquals($bytes, file_get_contents($path), 'the file is left exactly as it was');
		// The fork logs a classified refusal rather than upstream's
		// '<key> cannot be read' sentence.
		$this->assertTrue(strpos($lines, 'rCache: set refused: cache digest unreadable; key=' . $mine->hash) !== false,
			'the refusal is logged: ' . json_encode($lines));
	}

	public function testSetAppliesProfileMaskToLockFile()
	{
		global $profileMask;

		$oldMask = umask(0077);
		$oldProfileMask = $profileMask;
		$profileMask = 0666;
		$payload = new CacheMergePayload();
		$payload->hash = 'masked-cache-test.dat';

		try {
			$stored = (new rCache())->set($payload);
		} finally {
			umask($oldMask);
			$profileMask = $oldProfileMask;
		}

		$lockFile = FileUtil::getSettingsPath() . '/' . $payload->hash . '.lock';
		clearstatcache(true, $lockFile);

		$this->assertTrue($stored, 'Cache set succeeds with a restrictive process umask');
		$this->assertEquals(0666, fileperms($lockFile) & 0666, 'Cache lock file follows the configured profile mask');
	}

	public function testRemoveLeavesStableLockFile()
	{
		$payload = new CacheMergePayload();
		$payload->hash = 'remove-cache-test.dat';
		$cache = new rCache();
		$cacheFile = FileUtil::getSettingsPath() . '/' . $payload->hash;
		$lockFile = $cacheFile . '.lock';

		$this->assertTrue($cache->set($payload), 'Cache set creates the cache file');
		$this->assertTrue(file_exists($lockFile), 'Cache set creates the lock file');

		$this->assertTrue($cache->remove($payload), 'Cache remove deletes an existing entry');
		clearstatcache(true, $cacheFile);
		clearstatcache(true, $lockFile);
		$this->assertEquals(false, file_exists($cacheFile), 'Cache remove deletes the cache file');
		$this->assertTrue(file_exists($lockFile), 'Cache remove keeps the lock for waiting writers');
	}

	public function testRemovingAnAbsentKeySucceedsWithoutARefusal()
	{
		global $log_file;
		$payload = new CacheMergePayload();
		$payload->hash = 'absent-remove-cache-test.dat';
		$previousLog = $log_file;
		$log_file = $this->profilePath . '/absent-remove.log';
		try {
			$cache = new rCache();
			$this->assertSame(true, $cache->remove($payload), 'an absent cache key is already removed');
			$this->assertSame(true, $cache->remove($payload), 'a repeated removal remains successful');
			$this->assertSame('', is_file($log_file) ? file_get_contents($log_file) : '',
				'an absent key produces no refusal or cache contents in the log');
		} finally {
			$log_file = $previousLog;
		}
	}

	public function testRemovingAnEntryFromAnUnwritableDirectoryStillRefuses()
	{
		if (testSkipUnlessPermissionsBite('cache remove must report a real unlink refusal')) return;
		global $log_file;
		$payload = new CacheMergePayload();
		$payload->hash = 'unwritable-remove-cache-test.dat';
		$cache = new rCache();
		$this->assertTrue($cache->set($payload), 'the entry and writable lock sidecar exist');
		$settings = FileUtil::getSettingsPath();
		$previousLog = $log_file;
		$log_file = $this->profilePath . '/unwritable-remove.log';
		try {
			$this->assertTrue(chmod($settings, 0555), 'the cache directory no longer permits unlink');
			$this->assertSame(false, $cache->remove($payload), 'a present entry cannot be declared absent');
			$this->assertTrue(is_file($settings . '/' . $payload->hash), 'the refused entry stays intact');
			$lines = is_file($log_file) ? file_get_contents($log_file) : '';
			$this->assertSame(1, substr_count($lines, 'rCache: remove refused: cache unlink failed'),
				'a real unlink failure is logged once');
		} finally {
			chmod($settings, 0700);
			$log_file = $previousLog;
		}
	}

    public function testUnlinkRefusalDoesNotBecomeAbsenceWhenParentTurnsUnreadable()
    {
        if (testSkipUnlessPermissionsBite('cache remove must not infer absence from an unreadable parent')) return;
        global $log_file;
        $payload = new CacheMergePayload();
        $payload->hash = 'unreadable-parent-cache-test.dat';
        $cache = new rCache();
        $this->assertTrue($cache->set($payload), 'the real cache entry and stable lock sidecar exist');
        $settings = FileUtil::getSettingsPath();
        $previousLog = $log_file;
        $log_file = $_ENV['RU_PROFILE_PATH'] . '/unreadable-parent.log';
        $cacheSource = realpath(__DIR__ . '/../../php/cache.php');
        $intercepted = 0;
        $statAfter = null;
        set_error_handler(function ($errno, $message, $file) use ($settings, $cacheSource, &$intercepted, &$statAfter) {
            if ($file === $cacheSource
                && strpos($message, 'unlink(') === 0) {
                ++$intercepted;
                chmod($settings, 0000);
                clearstatcache(true, $settings . '/unreadable-parent-cache-test.dat');
                $statAfter = @lstat($settings . '/unreadable-parent-cache-test.dat');
            }
            return true;
        });
        try {
            chmod($settings, 0555);
            $removed = $cache->remove($payload);
        } finally {
            chmod($settings, 0700);
            restore_error_handler();
            $lines = is_file($log_file) ? file_get_contents($log_file) : '';
            $log_file = $previousLog;
        }
        $this->assertSame(1, $intercepted, 'the exact unlink refusal triggered the permission transition');
        $this->assertSame(false, $statAfter, 'the later lstat fails while the parent cannot be searched');
        $this->assertSame(false, $removed, 'unreadable state cannot prove an absent key');
        $this->assertTrue(is_file($settings . '/' . $payload->hash), 'the refused entry survives');
        $this->assertSame(1, substr_count($lines, 'rCache: remove refused: cache unlink failed'), 'the refusal stays visible');
    }

	public function testRemoveRefusesWhileKeyLockIsHeld()
	{
		global $log_file;
		$payload = new CacheMergePayload();
		$payload->hash = 'held-remove-cache-test.dat';
		$cache = new rCache();
		$cacheFile = FileUtil::getSettingsPath() . '/' . $payload->hash;
		$this->assertTrue($cache->set($payload), 'the cache entry exists');
		$previousLog = $log_file;
		$log_file = FileUtil::getSettingsPath() . '/cache-remove-refusal.log';
		$lock = fopen($cacheFile . '.lock', 'c');
		$this->assertTrue(flock($lock, LOCK_EX), 'the competing writer holds the key lock');
		try {
			$removed = $cache->remove($payload);
			clearstatcache(true, $cacheFile);
			$this->assertSame(false, $removed, 'remove refuses when the key lock cannot be acquired');
			$this->assertTrue(is_file($cacheFile), 'refused remove leaves the cache entry intact');
			$lines = is_file($log_file) ? file_get_contents($log_file) : '';
			$this->assertTrue(strpos($lines, 'rCache: remove refused: key lock unavailable') !== false,
				'the refused deletion names its reason in the log');
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
			$log_file = $previousLog;
		}
	}

	public function testSetPublishRefusalIsLoggedWithoutCacheData()
	{
		global $log_file;
		$payload = new CacheMergePayload();
		$payload->hash = 'publish-refusal-cache-test.dat';
		$payload->rows['secret'] = 'never-log-payload-value';
		$cacheFile = FileUtil::getSettingsPath() . '/' . $payload->hash;
		$this->assertTrue(mkdir($cacheFile, 0700), 'a directory occupies the cache key');
		$previousLog = $log_file;
		$log_file = FileUtil::getSettingsPath() . '/cache-publish-refusal.log';
		try {
			$this->assertSame(false, (new rCache())->set($payload),
				'publish refuses to replace a directory with a cache file');
			$lines = is_file($log_file) ? file_get_contents($log_file) : '';
			$this->assertTrue(strpos($lines, 'rCache: set refused: cache publish failed') !== false,
				'the publish refusal names its reason in the log');
			$this->assertSame(false, strpos($lines, 'never-log-payload-value'),
				'the log never includes serialized cache data');
			$this->assertTrue(is_dir($cacheFile), 'the failed publish leaves the cache key untouched');
		} finally {
			$log_file = $previousLog;
		}
	}

	public function testSetLockRefusalIsLoggedWithoutCacheData()
	{
		global $log_file;
		$payload = new CacheMergePayload();
		$payload->hash = 'held-set-cache-test.dat';
		$payload->rows['secret'] = 'never-log-payload-value';
		$cache = new rCache();
		$cacheFile = FileUtil::getSettingsPath() . '/' . $payload->hash;
		$this->assertTrue($cache->set($payload), 'the initial cache entry exists');
		$previousLog = $log_file;
		$log_file = FileUtil::getSettingsPath() . '/cache-refusal.log';
		$lock = fopen($cacheFile . '.lock', 'c');
		$this->assertTrue(flock($lock, LOCK_EX), 'the competing writer holds the key lock');
		try {
			$this->assertSame(false, $cache->set($payload), 'set refuses without the key lock');
			$lines = is_file($log_file) ? file_get_contents($log_file) : '';
			$this->assertTrue(strpos($lines, 'rCache: set refused: key lock unavailable') !== false,
				'the refused write names its reason in the log');
			$this->assertSame(false, strpos($lines, 'never-log-payload-value'),
				'the log never includes serialized cache data');
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
			$log_file = $previousLog;
		}
	}

	public function testRemoveDoesNotSplitTheKeyLockInode()
	{
		$payload = new CacheMergePayload();
		$payload->hash = 'stable-remove-cache-test.dat';
		$cache = new rCache();
		$cacheFile = FileUtil::getSettingsPath() . '/' . $payload->hash;
		$this->assertTrue($cache->set($payload), 'the cache entry exists');
		$lockName = $cacheFile . '.lock';
		$oldHandle = fopen($lockName, 'c');
		try {
			$oldInode = fstat($oldHandle)['ino'];
			$this->assertTrue($cache->remove($payload), 'remove succeeds with a free key lock');
			$newHandle = fopen($lockName, 'c');
			try {
				$this->assertSame($oldInode, fstat($newHandle)['ino'],
					'a writer that already opened the lock and a new writer use the same inode');
				$this->assertTrue(flock($oldHandle, LOCK_EX | LOCK_NB), 'the old handle acquires the key lock');
				$this->assertSame(false, flock($newHandle, LOCK_EX | LOCK_NB),
					'a new writer cannot acquire its lock while the old handle holds it');
			} finally {
				flock($newHandle, LOCK_UN);
				fclose($newHandle);
			}
		} finally {
			flock($oldHandle, LOCK_UN);
			fclose($oldHandle);
		}
	}

	public function testGetDoesNotInstantiateAnUnexpectedCacheClass()
	{
		$target = new CacheMergePayload();
		$target->hash = 'unexpected-root-cache-test.dat';
		file_put_contents(FileUtil::getSettingsPath() . '/' . $target->hash,
			serialize(new CacheUnexpectedPayload()));
		CacheUnexpectedPayload::$wakeups = 0;

		$this->assertEquals(false, (new rCache())->get($target),
			'a cache file naming another class is a miss');
		$this->assertTrue($target instanceof CacheMergePayload,
			'the caller keeps its own object');
		$this->assertEquals(0, CacheUnexpectedPayload::$wakeups,
			'the unexpected class never runs __wakeup');
	}

	public function testGetRefusesAnUndeclaredNestedClassButKeepsDeclaredItems()
	{
		$cache = new rCache();
		$stored = new CacheAllowedList();
		$stored->items = array(new CacheAllowedItem());
		$this->assertTrue($cache->set($stored), 'a declared nested item is stored');
		$loaded = new CacheAllowedList();
		$this->assertTrue($cache->get($loaded), 'a declared nested item loads');
		$this->assertTrue($loaded->items[0] instanceof CacheAllowedItem,
			'the declared item keeps its class');

		$stored->items = array(new CacheUnexpectedPayload());
		file_put_contents(FileUtil::getSettingsPath() . '/' . $stored->hash, serialize($stored));
		CacheUnexpectedPayload::$wakeups = 0;
		$loaded = new CacheAllowedList();
		$this->assertEquals(false, $cache->get($loaded),
			'an undeclared nested class makes the entire cache load a miss');
		$this->assertEquals(0, CacheUnexpectedPayload::$wakeups,
			'the undeclared nested class never runs __wakeup');
	}

	public function testAllowedNestedClassCannotReplaceTheRootObject()
	{
		$root = new CacheAllowedList();
		file_put_contents(FileUtil::getSettingsPath() . '/' . $root->hash,
			serialize(new CacheAllowedItem()));
		CacheAllowedItem::$wakeups = 0;
		$this->assertEquals(false, (new rCache())->get($root),
			'a permitted nested type cannot become the cache root');
		$this->assertTrue($root instanceof CacheAllowedList,
			'the caller keeps the expected root type');
		$this->assertEquals(0, CacheAllowedItem::$wakeups,
			'a wrong root type is refused before constructing it');
	}

	public function testArrayCacheRefusesObjectsBeforeTheirWakeup()
	{
		$key = 'array-object-cache-test.dat';
		file_put_contents(FileUtil::getSettingsPath() . '/' . $key,
			serialize(array('__hash__' => $key, 'item' => new CacheUnexpectedPayload())));
		CacheUnexpectedPayload::$wakeups = 0;
		$target = array('__hash__' => $key);
		$this->assertEquals(false, (new rCache())->get($target),
			'an array cache containing an object is a miss');
		$this->assertEquals(0, CacheUnexpectedPayload::$wakeups,
			'an array cache cannot instantiate an object');
	}

	public function testCacheKeyCannotReadWriteOrRemoveOutsideItsDirectory()
	{
		$outside = dirname(rtrim(FileUtil::getSettingsPath(), '/')) . '/outside-cache-test.dat';
		$stored = new CacheMergePayload();
		$stored->rows = array('outside' => 'keep');
		file_put_contents($outside, serialize($stored));
		$before = file_get_contents($outside);
		$target = new CacheMergePayload();
		$target->hash = '../outside-cache-test.dat';
		$cache = new rCache();

		$this->assertEquals(false, $cache->get($target), 'a traversing key reads nothing');
		$this->assertEquals(false, $cache->getModified('../outside-cache-test.dat'),
			'a traversing key cannot query an outside file timestamp');
		$target = new CacheMergePayload();
		$target->hash = '../outside-cache-test.dat';
		$this->assertEquals(false, $cache->set($target), 'a traversing key writes nothing');
		$this->assertEquals($before, file_get_contents($outside),
			'a traversing store cannot replace the outside file');
		$target = new CacheMergePayload();
		$target->hash = '../outside-cache-test.dat';
		$this->assertEquals(false, $cache->remove($target), 'a traversing key removes nothing');
		$this->assertEquals($before, @file_get_contents($outside),
			'the outside file survives every cache operation');
	}

	public function testRecursiveArrayCacheIsARefusedMiss()
	{
		$key = 'recursive-array-cache-test.dat';
		file_put_contents(FileUtil::getSettingsPath() . '/' . $key, 'a:1:{i:0;R:1;}');
		$target = array('__hash__' => $key);
		$this->assertEquals(false, (new rCache())->get($target),
			'a tiny self-referential array is refused instead of being traversed forever');
	}

	public function testArrayCacheBeyondTheDepthBudgetIsARefusedMiss()
	{
		$key = 'deep-array-cache-test.dat';
		$value = array('leaf');
		for ($depth = 0; $depth < 66; $depth++) $value = array($value);
		file_put_contents(FileUtil::getSettingsPath() . '/' . $key, serialize($value));
		$target = array('__hash__' => $key);
		$this->assertEquals(false, (new rCache())->get($target),
			'a deeply nested cache file is refused within the walk depth budget');
	}

	public function testSharedArrayGraphBeyondTheNodeBudgetIsARefusedMiss()
	{
		$key = 'shared-array-cache-test.dat';
		$nodes = array(array('leaf'));
		for ($level = 1; $level <= 17; $level++) {
			$nodes[$level] = array(&$nodes[$level - 1], &$nodes[$level - 1]);
		}
		$bytes = serialize($nodes[17]);
		$this->assertTrue(strlen($bytes) < 5000, 'the shared graph fits in a small cache file');
		file_put_contents(FileUtil::getSettingsPath() . '/' . $key, $bytes);
		$target = array('__hash__' => $key);
		$this->assertEquals(false, (new rCache())->get($target),
			'a small file describing too many nodes is refused within the node budget');
	}

	public function testGetPassesWakeupWarningsToTheCallerAndRestoresItsHandler()
	{
		$target = new CacheWarningPayload();
		file_put_contents(FileUtil::getSettingsPath() . '/' . $target->hash, serialize($target));
		$warnings = array();
		set_error_handler(function ($errno, $message) use (&$warnings) {
			$warnings[] = $message;
			return true;
		});
		try {
			$loaded = new CacheWarningPayload();
			$ok = (new rCache())->get($loaded);
			trigger_error('post-cache-warning', E_USER_WARNING);
		} finally {
			restore_error_handler();
		}
		$this->assertTrue($ok, 'the expected cache class still loads');
		$this->assertEquals(array('cache-wakeup-warning', 'post-cache-warning'), $warnings,
			'only parse diagnostics are suppressed; caller warnings are delivered: ' . json_encode($warnings));
	}

	public function testNestedUnserializeWarningReachesTheCaller()
	{
		$target = new CacheNestedWarningPayload();
		file_put_contents(FileUtil::getSettingsPath() . '/' . $target->hash, serialize($target));
		$warnings = array();
		set_error_handler(function ($errno, $message) use (&$warnings) {
			$warnings[] = $message;
			return true;
		});
		try {
			$loaded = new CacheNestedWarningPayload();
			$ok = (new rCache())->get($loaded);
			trigger_error('post-nested-warning', E_USER_WARNING);
		} finally {
			restore_error_handler();
		}
		$this->assertTrue($ok, 'the expected class still loads');
		$this->assertEquals(2, count($warnings),
			'a nested unserialize diagnostic is visible to the caller, along with later warnings');
		$this->assertTrue(isset($warnings[0]) && strpos($warnings[0], 'unserialize():') === 0,
			'the caller sees the nested parser diagnostic');
		$this->assertEquals('post-nested-warning', end($warnings),
			'the caller handler remains installed after the cache load');
	}

	// Fork-only, and the reason php/cache.php wraps unserialize(): a cache
	// file with a readable payload followed by trailing bytes still loads,
	// and the parse diagnostic never reaches the caller's error handler.
	public function testGetDoesNotEmitWarningsForCacheFilesWithExtraData()
	{
		$payload = new CacheMergePayload();
		$payload->hash = 'extra-data-cache-test.dat';
		$cacheFile = FileUtil::getSettingsPath() . '/' . $payload->hash;
		file_put_contents($cacheFile, serialize($payload) . serialize($payload));

		$warnings = [];
		set_error_handler(function ($errno, $errstr) use (&$warnings) {
			$warnings[] = $errstr;
			return true;
		});

		$loaded = new CacheMergePayload();
		$loaded->hash = 'extra-data-cache-test.dat';
		$loadedResult = (new rCache())->get($loaded);
		restore_error_handler();

		$this->assertEquals([], $warnings, 'Corrupt cache tail does not emit PHP warnings: ' . json_encode($warnings));
		$this->assertTrue($loadedResult, 'Cache with a readable first payload still loads');
	}

	// The guard must hand the caller's handler back even when the payload
	// itself throws out of __wakeup(), or every later warning in the request
	// disappears into a handler nobody owns any more.
	public function testGetRestoresErrorHandlerWhenUnserializeThrows()
	{
		$payload = new CacheThrowingPayload();
		$cacheFile = FileUtil::getSettingsPath() . '/' . $payload->hash;
		file_put_contents($cacheFile, serialize($payload));

		$seen = [];
		set_error_handler(function ($errno, $errstr) use (&$seen) {
			$seen[] = $errstr;
			return true;
		});

		// get() takes its payload by reference, so it must be a variable:
		// passing the expression raises a notice of its own.
		$loaded = new CacheThrowingPayload();
		try {
			(new rCache())->get($loaded);
		} catch (Exception $e) {
			// the payload throws on purpose; the handler is what is under test
		}
		trigger_error('after', E_USER_WARNING);
		restore_error_handler();

		$this->assertEquals(['after'], $seen, "The caller's error handler is restored");
	}

	public function testRemoveDoesNotDeleteWhileAnotherProcessHoldsTheKeyLock()
	{
		$payload = new CacheMergePayload();
		$payload->hash = 'held-remove-cache-test.dat';
		$cache = new rCache();
		$cacheFile = FileUtil::getSettingsPath() . '/' . $payload->hash;
		$this->assertTrue($cache->set($payload), 'The cache entry exists before the competing writer');
		$bytes = file_get_contents($cacheFile);
		$worker = '$lock = fopen($argv[1], "c");'
			. 'if ($lock === false || !flock($lock, LOCK_EX)) exit(2);'
			. 'echo "locked\\n"; fflush(STDOUT);'
			. 'fgets(STDIN); flock($lock, LOCK_UN); fclose($lock);';
		$process = proc_open(array(PHP_BINARY, '-r', $worker, $cacheFile . '.lock'),
			array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
		if(!is_resource($process))
			throw new RuntimeException('Could not start the competing cache writer');
		try {
			stream_set_timeout($pipes[1], 5);
			if(fgets($pipes[1]) !== "locked\n")
				throw new RuntimeException('The competing cache writer did not acquire its lock');
			$this->assertEquals(false, $cache->remove($payload),
				'Remove refuses while a real competing writer holds the key lock');
			$this->assertEquals($bytes, @file_get_contents($cacheFile),
				'A refused remove leaves the competing writer\'s cache bytes intact');
		} finally {
			fclose($pipes[0]);
			$errors = stream_get_contents($pipes[2]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			$exit = proc_close($process);
			$this->assertEquals('', $errors, 'The competing cache writer emits no errors');
			$this->assertEquals(0, $exit, 'The competing cache writer exits after the release signal');
		}
		$this->assertTrue($cache->remove($payload), 'Removal succeeds after the competing writer releases the lock');
	}

	public function testRemoveDoesNotDeleteWhenTheLockCannotBeOpened()
	{
		$payload = new CacheMergePayload();
		$payload->hash = 'unopenable-remove-cache-test.dat';
		$cacheFile = FileUtil::getSettingsPath() . '/' . $payload->hash;
		$bytes = serialize($payload);
		file_put_contents($cacheFile, $bytes);
		mkdir($cacheFile . '.lock', 0700);
		$this->assertEquals(false, @(new rCache())->remove($payload),
			'Remove refuses when a directory prevents opening the key lock');
		$this->assertEquals($bytes, @file_get_contents($cacheFile),
			'A lock-open refusal leaves the removable cache entry intact');
	}
}
