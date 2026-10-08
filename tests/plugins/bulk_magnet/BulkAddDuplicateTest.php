<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');

/**
 * plugins/bulk_magnet/action.php counts a torrent rtorrent already has as a
 * duplicate, not as an addition.
 *
 * rtorrent answers load.start for an info hash it has already loaded with 0
 * and no fault, and drops the new copy. So the answer to the load cannot tell
 * the two apart; the script has to know what was loaded before it sent it.
 *
 * action.php is a top level script that reads php://input, so it is driven
 * where it lives, in a process of its own, with FakeRtorrentDaemon on the
 * other end of its SCGI socket answering each load as rtorrent does.
 *
 * rTorrent also drops some malformed magnets without making a stub, and the
 * load answers 0 all the same. A magnet counts as an addition only once d.hash
 * shows its stub (rTorrent::magnetShown(), R4-NF-1); otherwise it is pending.
 */
class BulkAddDuplicateTest extends TestCase
{
	const LOADED = '1111111111111111111111111111111111111111';
	const FRESH = '2222222222222222222222222222222222222222';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-bulk-duplicate-' . getmypid();
		@mkdir($this->base . '/profile/settings', 0700, true);
	}

	/** The application log of the driven script, private to this test. */
	private function log()
	{
		return $this->base . '/app.log';
	}

	/** How many d.hash reads asked about this hash. */
	private function presenceReads($hash)
	{
		$reads = 0;
		foreach ($this->daemon->bodies() as $body) {
			if (strpos($body, 'd.hash') !== false && strpos($body, $hash) !== false) {
				$reads++;
			}
		}
		return $reads;
	}

	public function tearDown()
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
		foreach (glob($this->base . '/*') as $entry) {
			is_dir($entry) ? @rmdir($entry) : @unlink($entry);
		}
		@rmdir($this->base . '/profile/settings');
		@rmdir($this->base . '/profile');
		@rmdir($this->base);
	}

	/**
	 * Runs action.php against a daemon that has LOADED, answers every load
	 * with 0 and d.hash as rTorrent does, and returns what the script printed.
	 * $daemon is FakeRtorrentAddTorrentReplies' description of what d.hash
	 * sees, such as magnets rTorrent drops without a stub.
	 */
	private function bulkAdd($items, $snapshotFails = false, $rawPost = null, $daemon = array())
	{
		$root = realpath(__DIR__ . '/../../..');
		$this->daemon = new FakeRtorrentDaemon(
			new FakeRtorrentAddTorrentReplies(array(self::LOADED), array(), $snapshotFails, $daemon),
			$this->base . '/calls.log');

		$post = '';
		foreach ($items as $item) {
			$post .= '&torrent=' . rawurlencode($item);
		}
		if($rawPost !== null) $post = $rawPost;
		$driver = $this->base . '/drive-bulk.php';
		file_put_contents($driver, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. 'require_once(' . var_export($root . '/php/settings.php', true) . ');' . "\n"
			. '$r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
			. ' $s->iVersion = 0x1018; $s->aliases = array(); $s->directory = "/";'
			. ' $s->linkExist = true; $p = $r->getProperty("theSettings");'
			. ' $p->setAccessible(true); $p->setValue(null, $s);' . "\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . $this->daemon->port() . ";\n"
			. '$rpcTimeOut = 10;' . "\n"
			. '$rpcLogCalls = false;' . "\n"
			. '$log_file = ' . var_export($this->log(), true) . ";\n"
			. '$HTTP_RAW_POST_DATA = ' . var_export($post, true) . ";\n"
			. 'chdir(' . var_export($root . '/plugins/bulk_magnet', true) . ");\n"
			. 'require(' . var_export($root . '/plugins/bulk_magnet/action.php', true) . ");\n");

		$output = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' .
			escapeshellarg($driver) . ' 2>&1', $output);
		return json_decode(implode("\n", $output), true);
	}

	public function testFailedSnapshotCountsAnErrorAndSendsNoLoad()
	{
		$result = $this->bulkAdd(array('magnet:?xt=urn:btih:' . self::FRESH), true);
		$this->assertEquals(array('error' => 1, 'success' => 0, 'pending' => 0, 'duplicate' => 0),
			$result, 'a failed download_list cannot be treated as an empty session');
		$this->assertEquals(array('download_list'), $this->daemon->calls(),
			'no load follows the failed snapshot');
	}

	public function testRawEqualsAndLiteralPlusReachMagnetInDuplicateOrder()
	{
		$loaded = 'magnet:?xt=urn:btih:' . self::LOADED;
		$fresh = 'magnet:?xt=urn:btih:' . self::FRESH . '&dn=x+y';
		$post = 'torrent=' . $loaded . '&torrent=' . str_replace('%2B', '+', rawurlencode($fresh))
			. '&torrent=&torrent';
		$result = $this->bulkAdd(array($loaded, $fresh), false, $post);
		$this->assertEquals(array('error' => 0, 'success' => 1, 'pending' => 0, 'duplicate' => 1),
			$result, 'raw equals, empty and missing fields keep the two valid entries');
		$bodies = $this->daemon->bodies();
		$this->assertTrue(isset($bodies[0]) && strpos($bodies[0], 'download_list') !== false,
			'the snapshot comes first');
		$loads = array_values(array_filter($bodies, function ($body) {
			return strpos($body, 'magnet:') !== false;
		}));
		$this->assertEquals(2, count($loads), 'exactly two ordered loads follow the snapshot');
		$this->assertTrue(isset($loads[0]) && strpos($loads[0], $loaded) !== false,
			'first raw-equals magnet reaches the daemon whole');
		$this->assertTrue(isset($loads[1]) && strpos($loads[1], 'dn=x+y') !== false,
			'encoded equals and literal plus retain the browser-compatible value');
	}

	public function testFailedBodyReadStillTakesTheSnapshot()
	{
		$result = $this->bulkAdd(array(), false, false);
		$this->assertEquals(array('error' => 0, 'success' => 0, 'pending' => 0, 'duplicate' => 0),
			$result, 'a failed body read leaves the empty result');
		$this->assertEquals(array('download_list'), $this->daemon->calls(),
			'a failed body read still follows the old snapshot path');
	}

	public function testATorrentAlreadyLoadedIsADuplicateNotAnAddition()
	{
		$result = $this->bulkAdd(array(
			'magnet:?xt=urn:btih:' . self::LOADED . '&dn=loaded',
			strtolower(self::FRESH),
			'magnet:?xt=urn:btih:' . self::FRESH,
			'not a link',
		));
		$this->assertEquals(array('error' => 1, 'success' => 1, 'pending' => 0, 'duplicate' => 2), $result,
			'one new torrent is added, the loaded one and the second copy of the new one are '
			. 'duplicates, and the line that is not a link is an error; the script answered '
			. json_encode($result) . '; calls: ' . implode(', ', $this->daemon->calls()));
	}

	public function testADroppedMagnetIsPendingNotAnAddition()
	{
		$result = $this->bulkAdd(array('magnet:?xt=urn:btih:' . self::FRESH . '&dn'), false, null,
			array('dropMagnets' => array(self::FRESH)));
		$this->assertEquals(array('error' => 0, 'success' => 0, 'pending' => 1, 'duplicate' => 0),
			$result, 'a magnet whose stub never shows is not counted as added: ' . json_encode($result));
		$this->assertTrue($this->presenceReads(self::FRESH) > 1,
			'its stub was looked for through the settle window');
		$this->assertTrue(strpos((string)@file_get_contents($this->log()),
			'bulk_magnet: magnet unconfirmed: ' . self::FRESH) !== false,
			'the unconfirmed magnet is named in the log');
	}

	public function testAMagnetWhoseStubShowsLateIsStillAnAddition()
	{
		$result = $this->bulkAdd(array('magnet:?xt=urn:btih:' . self::FRESH), false, null,
			array('stubAfter' => 3));
		$this->assertEquals(array('error' => 0, 'success' => 1, 'pending' => 0, 'duplicate' => 0),
			$result, 'a stub that shows within the window is an addition: ' . json_encode($result));
		$this->assertEquals(4, $this->presenceReads(self::FRESH), 'three misses, then the stub');
	}
}
