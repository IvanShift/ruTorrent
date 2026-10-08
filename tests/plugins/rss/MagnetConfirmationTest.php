<?php

declare(strict_types=1);

@define('HISTORY_MAX_COUNT', 100, true);
@define('HISTORY_MAX_TRY', 3, true);
@define('WAIT_AFTER_LOADING', 0, true);

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');

$rss_debug_enabled = false;
require_once(__DIR__ . '/../../../plugins/rss/rss.php');

/**
 * An RSS item that is a magnet is recorded as loaded only once rTorrent shows
 * its stub (rTorrent::magnetShown(), R4-NF-1).
 *
 * rTorrent drops some malformed magnets without making a stub, and the load
 * answers 0 all the same. rRSSManager::getTorrents() used to write the hash of
 * such a magnet into the RSS history, so the item showed as loaded and the
 * filters never tried it again. Now it is Pending without a receipt: the
 * history keeps it from being sent again at once and lets it be retried after
 * the pending timeout (rRSSHistory::PENDING_RETRY_AFTER).
 */
final class RSSMagnetConfirmationTest extends TestCase
{
	const HASH = '4444444444444444444444444444444444444444';

	private $daemon;
	private $base;
	private $settingsProperty;
	private $settingsBefore;
	private $globalsBefore = array();

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-rss-magnet-' . getmypid();
		@mkdir($this->base, 0700, true);
		$this->settingsProperty = self::makeAccessible(
			new ReflectionProperty('rTorrentSettings', 'theSettings'));
		$this->settingsBefore = $this->settingsProperty->getValue();
		$settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
		$settings->iVersion = 0x1018;
		$settings->aliases = array();
		$settings->directory = '/';
		$settings->linkExist = true;
		$this->settingsProperty->setValue(null, $settings);
		foreach (array('scgi_host', 'scgi_port', 'log_file', 'rpcTimeOut') as $name)
			$this->globalsBefore[$name] = array_key_exists($name, $GLOBALS) ? $GLOBALS[$name] : null;
		$GLOBALS['log_file'] = $this->base . '/app.log';
		$GLOBALS['rpcTimeOut'] = 10;
	}

	public function tearDown()
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
		foreach ($this->globalsBefore as $name => $value) {
			if ($value === null) unset($GLOBALS[$name]);
			else $GLOBALS[$name] = $value;
		}
		foreach (glob($this->base . '/*') ?: array() as $entry)
			@unlink($entry);
		@rmdir($this->base);
		$this->settingsProperty->setValue(null, $this->settingsBefore);
	}

	/** Load one magnet item of a feed against a daemon described by $daemon. */
	private function load(string $magnet, array $daemon = array()): rRSSManager
	{
		$this->daemon = new FakeRtorrentDaemon(
			new FakeRtorrentAddTorrentReplies(array(), array(), false, $daemon),
			$this->base . '/calls.log');
		$GLOBALS['scgi_host'] = '127.0.0.1';
		$GLOBALS['scgi_port'] = $this->daemon->port();
		$feed = new rRSS('https://tracker.example/rss');
		$feed->items[$magnet] = array('timestamp' => 100, 'guid' => 'item-magnet');
		$manager = (new ReflectionClass(rRSSManager::class))->newInstanceWithoutConstructor();
		$manager->rssList = new rRSSMetaList();
		$manager->history = new rRSSHistory();
		$manager->getTorrents($feed, $magnet, true, true, '', '', '', '', false);
		return $manager;
	}

	public function testADroppedMagnetIsPendingAndCanBeRetriedLater(): void
	{
		$magnet = 'magnet:?xt=urn:btih:' . self::HASH . '&dn';
		$history = $this->load($magnet, array('dropMagnets' => array(self::HASH)))->history;
		$this->assertSame('Pending', $history->getHash($magnet),
			'a magnet whose stub never shows is not recorded as loaded: ' . json_encode($history->lst));
		$this->assertTrue($history->wasLoaded($magnet, 'item-magnet', function () { return 'missing'; }),
			'it is not sent again at once');
		$history->lst[$magnet]['submittedAt'] = time() - rRSSHistory::PENDING_RETRY_AFTER - 1;
		$this->assertTrue(!$history->wasLoaded($magnet, 'item-magnet', function () { return 'missing'; }),
			'and it can be retried after the pending timeout');
		$this->assertTrue(strpos((string)@file_get_contents($this->base . '/app.log'),
			'rss: magnet unconfirmed: ' . self::HASH) !== false,
			'the unconfirmed magnet is named in the log');
	}

	public function testAMagnetWithAStubIsRecordedAsLoaded(): void
	{
		$magnet = 'magnet:?xt=urn:btih:' . strtolower(self::HASH) . '&dn=item';
		$history = $this->load($magnet, array('stubAfter' => 2))->history;
		$this->assertSame(self::HASH, $history->getHash($magnet),
			'a stub that shows within the window is recorded as loaded: ' . json_encode($history->lst));
	}
}
