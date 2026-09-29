<?php

$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir().'/rutorrent-extsearch-pending-'.getmypid();
$_SERVER['REMOTE_USER'] = 'extsearch_pending_test';
$_SERVER['REQUEST_METHOD'] = 'GET';
define('EXTSEARCH_SUBMISSION_LOCK_TRIES', 1);

require_once(__DIR__.'/../../php/TestCase.php');

class rTorrent
{
	public static $calls = 0;
	public static $probes = 0;
	public static $status = 'missing';
	public static $hash = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';
	public static $result = null;
	public static $raw = true;
	public static $afterDispatch = null;
	public static $beforeReserve = null;

	public static function sendTorrent($torrent, $start, $addPath, $directory,
		$label, $save, $fast, $isNew, $addition, &$receipt, $beforeDispatch = null)
	{
		$candidate = array('hash'=>self::$hash,
			'key'=>'ru-load-proof-'.str_repeat('b', 32), 'raw'=>self::$raw);
		if(self::$beforeReserve !== null) call_user_func(self::$beforeReserve);
		if($beforeDispatch !== null && !$beforeDispatch($candidate)) return false;
		self::$calls++;
		$receipt = $candidate;
		if(self::$afterDispatch !== null) call_user_func(self::$afterDispatch);
		return self::$result;
	}

	public static function pendingLoadStatus($receipt)
	{
		self::$probes++;
		return self::$status;
	}
}

require_once(__DIR__.'/../../../plugins/extsearch/engines.php');

class ExtsearchPendingEngine extends commonEngine
{
	public static $url;

	public function getTorrent($url)
	{
		$path = FileUtil::getProfilePath().'/tmp/extsearch-pending.torrent';
		file_put_contents($path, 'the fake sender does not parse this source');
		return $path;
	}

	public function action($what, $cat, &$items, $limit, $useGlobalCats)
	{
		$items[self::$url] = array('name'=>'pending torrent');
	}
}

class ExtsearchPendingHistoryTest extends TestCase
{
	private $settingsProperty;
	private $settingsBefore;

	public function setUpClass()
	{
		$this->settingsProperty = self::makeAccessible(
			new ReflectionProperty('rTorrentSettings', 'theSettings'));
		$this->settingsBefore = $this->settingsProperty->getValue();
		$settings = (new ReflectionClass('rTorrentSettings'))
			->newInstanceWithoutConstructor();
		$settings->plugins = array();
		$this->settingsProperty->setValue(null, $settings);
	}

	private function nextRequest()
	{
		ExtsearchHistoryProbeCursor::resetRequestBudget();
	}

	private function manager($url)
	{
		ExtsearchPendingEngine::$url = $url;
		$manager = new engineManager();
		$manager->engines['Pending'] = array('path'=>__FILE__,
			'object'=>'ExtsearchPendingEngine');
		return $manager;
	}

	public function setUp()
	{
		$GLOBALS['saveUploadedTorrents'] = false;
		rTorrent::$calls = 0;
		rTorrent::$probes = 0;
		if(class_exists('ExtsearchHistoryProbeCursor', false))
			ExtsearchHistoryProbeCursor::resetRequestBudget();
		rTorrent::$status = 'missing';
		rTorrent::$result = null;
		rTorrent::$raw = true;
		rTorrent::$afterDispatch = null;
		rTorrent::$beforeReserve = null;
		$history = engineManager::loadHistory();
		$history->lst = array();
		$history->changed = true;
		engineManager::saveHistory($history);
	}

	public function tearDown()
	{
		$settings = FileUtil::getSettingsPath();
		@unlink($settings.'/extsearch_history.dat');
		@rmdir($settings.'/extsearch_history.dat.lock');
		@unlink($settings.'/extsearch_history.dat.lock');
		@unlink($settings.'/extsearch_history_probe_cursor.dat');
		@unlink($settings.'/extsearch_history_probe_cursor.dat.lock');
		foreach(glob($settings.'/extsearch-submit-*.lock') ?: array() as $lock)
			@unlink($lock);
		@unlink(FileUtil::getProfilePath().'/tmp/extsearch-pending.torrent');
		unset($GLOBALS['saveUploadedTorrents']);
	}

	public function tearDownClass()
	{
		$settings = FileUtil::getSettingsPath();
		@rmdir($settings);
		@rmdir(FileUtil::getProfilePath().'/tmp');
		@rmdir(FileUtil::getProfilePath().'/torrents');
		@rmdir(FileUtil::getProfilePath());
		@rmdir($_ENV['RU_PROFILE_PATH'].'/users');
		@rmdir($_ENV['RU_PROFILE_PATH']);
		$this->settingsProperty->setValue(null, $this->settingsBefore);
	}

	public function testSearchPresentationBoundsPendingReceiptProbes()
	{
		$history = engineManager::loadHistory();
		for($i=0; $i<6; $i++)
			$history->addPending('https://example.invalid/search/'.$i,
				array('hash'=>str_repeat((string)$i, 40),
					'key'=>'ru-load-proof-'.str_repeat('a', 32)));
		$this->assertTrue(engineManager::saveHistory($history),
			'six durable receipts are available before search');
		rTorrent::$status = 'unknown';
		$manager = $this->manager('https://example.invalid/search/0');
		$manager->action('Pending', 'needle');
		$this->assertSame(array(null), $manager->getTorrents(array('Pending'),
			array('https://example.invalid/search/0'), true, false, '', '', false),
			'a same-request add still sees the accepted receipt');
		$this->assertSame(4, rTorrent::$probes,
			'search and add share four daemon probes in one request');
		$this->assertSame(6, count(engineManager::loadHistory()->lst),
			'unresolved receipts remain durable after the bounded search');
		ExtsearchHistoryProbeCursor::resetRequestBudget();
		rTorrent::$status = 'ours';
		$manager->action('Pending', 'needle');
		$next = engineManager::loadHistory();
		$this->assertTrue(!$next->isPending('https://example.invalid/search/4')
			&& !$next->isPending('https://example.invalid/search/5'),
			'the next search request eventually selects both previously unprobed receipts');
	}

	public function testSameUrlAndFullCapacityShareOneProbeBudget()
	{
		global $searchHistoryMaxCount;
		$previous = $searchHistoryMaxCount;
		$searchHistoryMaxCount = 6;
		try {
			$history = engineManager::loadHistory();
			for($i=0; $i<6; $i++)
				$history->addPending('https://example.invalid/capacity/'.$i,
					array('hash'=>str_repeat((string)$i, 40),
						'key'=>'ru-load-proof-'.str_repeat('b', 32)));
			$this->assertTrue(engineManager::saveHistory($history),
				'capacity fixture is persisted before submissions');
			rTorrent::$status = 'unknown';
			$urls = array_fill(0, 5, 'https://example.invalid/capacity/0');
			$urls[] = 'https://example.invalid/capacity/new';
			$urls[] = 'https://example.invalid/capacity/newer';
			$manager = $this->manager($urls[0]);
			$answer = $manager->getTorrents(array_fill(0, count($urls), 'Pending'),
				$urls, true, false, '', '', false);
			$this->assertSame(array(null, null, null, null, null, false, false),
				$answer, 'same URL stays pending and full capacity refuses new loads');
			$this->assertSame(1, rTorrent::$probes,
				'repeated URL and capacity checks probe one receipt once per request');
			$this->assertSame(0, rTorrent::$calls,
				'RPC uncertainty never dispatches another load');
			$this->assertSame(6, count(engineManager::loadHistory()->lst),
				'all six original receipts survive the full-capacity request');
		} finally {
			$searchHistoryMaxCount = $previous;
		}
	}

	public function testDelayedOwnedLoadPersistsAndReconcilesWithoutResubmission()
	{
		$url = 'https://example.invalid/pending-owned';
		$manager = $this->manager($url);
		$first = $manager->getTorrents(array('Pending'), array($url),
			true, false, '', '', false);
		$this->assertSame(array(null), $first, 'bounded load remains pending');
		$entry = engineManager::loadHistory()->lst[$url] ?? null;
		$this->assertSame(rTorrent::$hash, $entry['receipt']['hash'] ?? null,
			'pending receipt survives the request in history cache');

		$again = $manager->getTorrents(array('Pending'), array($url),
			true, false, '', '', false);
		$this->assertSame(array(null), $again, 'pending retry still reports pending');
		$this->assertSame(1, rTorrent::$calls, 'pending request is not submitted twice');

		rTorrent::$status = 'ours';
		$this->nextRequest();
		$result = $manager->action('Pending', 'pending-owned');
		$this->assertSame(rTorrent::$hash, $result['data'][0]['hash'] ?? null,
			'later owned confirmation appears in search results');
		$this->assertSame(rTorrent::$hash,
			engineManager::loadHistory()->getHash($url),
			'confirmed hash is durable across requests');
		$this->assertSame(1, rTorrent::$calls, 'reconciliation does not resubmit load');
	}

	public function testForeignHashIsNotAdoptedAndExpiredMissingLoadCanRetry()
	{
		$url = 'https://example.invalid/pending-foreign';
		$manager = $this->manager($url);
		$manager->getTorrents(array('Pending'), array($url),
			true, false, '', '', false);
		rTorrent::$status = 'foreign';
		$result = $manager->action('Pending', 'pending-foreign');
		$this->assertSame('', $result['data'][0]['hash'] ?? null,
			'a same-hash foreign torrent is not adopted');

		$history = engineManager::loadHistory();
		$history->lst[$url]['time'] = time() - 301;
		$history->changed = true;
		engineManager::saveHistory($history);
		rTorrent::$status = 'missing';
		$this->nextRequest();
		$again = $manager->getTorrents(array('Pending'), array($url),
			true, false, '', '', false);
		$this->assertSame(array(null), $again, 'retry can enter a new pending state');
		$this->assertSame(2, rTorrent::$calls,
			'an expired missing receipt does not stall retries forever');
	}

	public function testUnknownDaemonStatusKeepsAgedProofUntilOwnershipReturns()
	{
		$url = 'https://example.invalid/pending-unknown';
		$manager = $this->manager($url);
		$this->assertSame(array(null), $manager->getTorrents(array('Pending'),
			array($url), true, false, '', '', false),
			'first load leaves a durable pending receipt');
		$history = engineManager::loadHistory();
		$history->lst[$url]['time'] = time() - 301;
		$history->changed = true;
		$this->assertTrue(engineManager::saveHistory($history),
			'fixture ages the durable receipt past the retry interval');
		rTorrent::$status = 'unknown';
		$this->assertSame(array(null), $manager->getTorrents(array('Pending'),
			array($url), true, false, '', '', false),
			'RPC uncertainty does not grant a second submission');
		$this->assertSame(1, rTorrent::$calls,
			'unknown status never resubmits the same torrent');
		$this->assertTrue(engineManager::loadHistory()->isPending($url),
			'the original proof remains durable after the retry interval');
		rTorrent::$status = 'ours';
		$this->nextRequest();
		$this->assertSame(rTorrent::$hash, $manager->action('Pending', 'pending-unknown')['data'][0]['hash'] ?? null,
			'known ownership later resolves the original proof');
	}

	public function testConcurrentPendingWritesKeepBothReceipts()
	{
		$first = engineManager::loadHistory();
		$second = engineManager::loadHistory();
		$first->addPending('https://example.invalid/one', array(
			'hash'=>str_repeat('B', 40),
			'key'=>'ru-load-proof-'.str_repeat('c', 32), 'raw'=>true));
		$second->addPending('https://example.invalid/two', array(
			'hash'=>str_repeat('C', 40),
			'key'=>'ru-load-proof-'.str_repeat('d', 32), 'raw'=>true));
		$this->assertTrue(engineManager::saveHistory($first), 'first pending receipt is stored');
		$this->assertTrue(engineManager::saveHistory($second), 'second pending receipt is stored');
		$loaded = engineManager::loadHistory();
		$this->assertTrue($loaded->isPending('https://example.invalid/one'),
			'a concurrent save preserves the first receipt');
		$this->assertTrue($loaded->isPending('https://example.invalid/two'),
			'a concurrent save preserves the second receipt');
	}

	public function testStaleSaveDoesNotResurrectDeletedHistory()
	{
		$removed = 'https://example.invalid/removed';
		$kept = 'https://example.invalid/kept';
		$seed = engineManager::loadHistory();
		$seed->add($removed, str_repeat('D', 40));
		engineManager::saveHistory($seed);
		$stale = engineManager::loadHistory();
		$fresh = engineManager::loadHistory();
		$fresh->del($removed);
		engineManager::saveHistory($fresh);
		$stale->addPending($kept, array('hash'=>str_repeat('E', 40),
			'key'=>'ru-load-proof-'.str_repeat('e', 32), 'raw'=>true));
		engineManager::saveHistory($stale);
		$loaded = engineManager::loadHistory();
		$this->assertSame('', $loaded->getHash($removed),
			'a stale writer does not resurrect a concurrent deletion');
		$this->assertTrue($loaded->isPending($kept),
			'the stale writer still publishes its separate receipt');
	}

	public function testOverflowKeepsPendingReceiptBeforeOlderConfirmedHistory()
	{
		global $searchHistoryMaxCount;
		$previous = $searchHistoryMaxCount;
		$searchHistoryMaxCount = 2;
		try {
			$pending = 'https://example.invalid/old-pending';
			$history = engineManager::loadHistory();
			$history->addPending($pending, array('hash'=>str_repeat('F', 40),
				'key'=>'ru-load-proof-'.str_repeat('f', 32), 'raw'=>true));
			$history->lst[$pending]['time'] = time() - 100;
			$history->add('https://example.invalid/confirmed-one', str_repeat('1', 40));
			$history->add('https://example.invalid/confirmed-two', str_repeat('2', 40));
			engineManager::saveHistory($history);
			$this->assertTrue(engineManager::loadHistory()->isPending($pending),
				'overflow evicts confirmed history before an unresolved receipt');
			$allPending = engineManager::loadHistory();
			foreach(array('second', 'third') as $name)
				$allPending->addPending('https://example.invalid/'.$name, array(
					'hash'=>str_repeat('A', 40),
					'key'=>'ru-load-proof-'.str_repeat('a', 32), 'raw'=>true));
			engineManager::saveHistory($allPending);
			$this->assertSame(3, count(engineManager::loadHistory()->lst),
				'all-pending overflow retains every proof until reconciliation');
		} finally {
			$searchHistoryMaxCount = $previous;
		}
	}

	public function testStaleOverflowCannotDeleteNewPendingReceipt()
	{
		global $searchHistoryMaxCount;
		$previous = $searchHistoryMaxCount;
		$searchHistoryMaxCount = 256;
		try {
			$url = 'https://example.invalid/replaced-by-pending';
			$seed = engineManager::loadHistory();
			$seed->add($url, str_repeat('D', 40));
			$seed->lst[$url]['time'] = time() - 100;
			for($i=0; $i<255; $i++)
				$seed->add('https://example.invalid/history/'.$i, str_repeat('E', 40));
			engineManager::saveHistory($seed);
			$stale = engineManager::loadHistory();
			$fresh = engineManager::loadHistory();
			$fresh->addPending($url, array('hash'=>str_repeat('F', 40),
				'key'=>'ru-load-proof-'.str_repeat('f', 32), 'raw'=>true));
			engineManager::saveHistory($fresh);
			$extra = 'https://example.invalid/new-history';
			$stale->add($extra, str_repeat('A', 40));
			engineManager::saveHistory($stale);
			$loaded = engineManager::loadHistory();
			$this->assertTrue($loaded->isPending($url),
				'stale compaction cannot delete a concurrent pending receipt');
			$this->assertSame(str_repeat('A', 40), $loaded->getHash($extra),
				'stale writer still publishes its independent history row');
		} finally {
			$searchHistoryMaxCount = $previous;
		}
	}

	public function testPendingCapacityRefusesBeforeDispatchAndRecovers()
	{
		global $searchHistoryMaxCount;
		$previous = $searchHistoryMaxCount;
		$searchHistoryMaxCount = 2;
		try {
			$history = engineManager::loadHistory();
			foreach(array('one', 'two') as $name)
				$history->addPending('https://example.invalid/'.$name, array(
					'hash'=>str_repeat('A', 40),
					'key'=>'ru-load-proof-'.str_repeat('a', 32), 'raw'=>true));
			engineManager::saveHistory($history);
			$url = 'https://example.invalid/third';
			$manager = $this->manager($url);
			$this->assertSame(array(false), $manager->getTorrents(
				array('Pending'), array($url), true, false, '', '', false),
				'full pending capacity refuses a new dispatch');
			$this->assertSame(0, rTorrent::$calls,
				'capacity is checked before sending the torrent');
			$history = engineManager::loadHistory();
			$history->lst['https://example.invalid/one']['time'] = time() - 301;
			$history->changed = true;
			engineManager::saveHistory($history);
			$this->nextRequest();
			$this->assertSame(array(null), $manager->getTorrents(
				array('Pending'), array($url), true, false, '', '', false),
				'an expired missing receipt opens a slot for retry');
			$this->assertSame(1, rTorrent::$calls,
				'the recovered slot dispatches exactly once');
		} finally {
			$searchHistoryMaxCount = $previous;
		}
	}

	public function testSameUrlSubmissionLockRefusesACompetingDispatch()
	{
		$url = 'https://example.invalid/locked';
		$lockPath = FileUtil::getSettingsPath().'/extsearch-submit-'
			.substr(hash('sha256', $url), 0, 4).'.lock';
		$lock = fopen($lockPath, 'c');
		$this->assertTrue($lock !== false && flock($lock, LOCK_EX),
			'fixture holds the same URL shard lock');
		try {
			$manager = $this->manager($url);
			$this->assertSame(array(false), $manager->getTorrents(
					array('Pending'), array($url), true, false, '', '', false),
				'a competing request refuses when the reservation is held');
			$this->assertSame(0, rTorrent::$calls,
					'lock refusal sends nothing to the daemon');
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
			@unlink($lockPath);
		}
		$this->assertSame(array(null), $manager->getTorrents(
			array('Pending'), array($url), true, false, '', '', false),
			'first available request creates a pending reservation');
		$this->assertSame(array(null), $manager->getTorrents(
			array('Pending'), array($url), true, false, '', '', false),
			'later same-URL request observes that reservation');
		$this->assertSame(1, rTorrent::$calls,
			'the competing request never creates a second proof key');
	}

	public function testFirstSubmissionCreatesMissingSettingsDirectoryBeforeLock()
	{
		$settings = FileUtil::getSettingsPath();
		@unlink($settings.'/extsearch_history.dat');
		@unlink($settings.'/extsearch_history.dat.lock');
		$this->assertTrue(@rmdir($settings), 'fixture starts with no settings directory');
		$url = 'https://example.invalid/fresh-profile';
		$manager = $this->manager($url);
		$this->assertSame(array(null), $manager->getTorrents(
			array('Pending'), array($url), true, false, '', '', false),
			'first submission creates cache and lock directories before dispatch');
		$this->assertSame(1, rTorrent::$calls,
			'fresh profile dispatches exactly one torrent');
	}

	public function testReservationSaveFailurePreventsDispatch()
	{
		$cacheLock = FileUtil::getSettingsPath().'/extsearch_history.dat.lock';
		@unlink($cacheLock);
		$this->assertTrue(mkdir($cacheLock, 0700), 'fixture blocks the cache key lock');
		$url = 'https://example.invalid/cache-refused';
		$manager = $this->manager($url);
		try {
			$this->assertSame(array(false), $manager->getTorrents(
				array('Pending'), array($url), true, false, '', '', false),
				'failed durable reservation is reported as a refused load');
			$this->assertSame(0, rTorrent::$calls,
				'failed reservation does not dispatch an untrackable load');
		} finally {
			@rmdir($cacheLock);
		}
		$this->assertSame(array(null), $manager->getTorrents(
			array('Pending'), array($url), true, false, '', '', false),
			'load may proceed after cache storage recovers');
		$this->assertSame(1, rTorrent::$calls,
			'only the durably reserved attempt is dispatched');
	}

	public function testLaterSaveFailureRetainsPreDispatchProof()
	{
		$cacheLock = FileUtil::getSettingsPath().'/extsearch_history.dat.lock';
		$url = 'https://example.invalid/late-cache-refused';
		rTorrent::$result = rTorrent::$hash;
		rTorrent::$afterDispatch = function () use ($cacheLock) {
			@unlink($cacheLock);
			mkdir($cacheLock, 0700);
		};
		$manager = $this->manager($url);
		try {
			$this->assertSame(array(rTorrent::$hash), $manager->getTorrents(
				array('Pending'), array($url), true, false, '', '', false),
				'daemon confirmation still returns its exact hash');
			$this->assertTrue(engineManager::loadHistory()->isPending($url),
				'pre-dispatch proof survives the later cache write refusal');
		} finally {
			@rmdir($cacheLock);
			rTorrent::$afterDispatch = null;
		}
		rTorrent::$status = 'ours';
		$result = $manager->action('Pending', 'late-cache-refused');
		$this->assertSame(rTorrent::$hash, $result['data'][0]['hash'] ?? null,
			'later read reconciles the retained proof');
		$this->assertSame(1, rTorrent::$calls,
			'cache recovery never resubmits the confirmed load');
	}

	public function testFalseReplyAfterAcceptedRawLoadRetainsProofAndReconciles()
	{
		$url = 'https://example.invalid/false-reply-raw';
		rTorrent::$result = false;
		$manager = $this->manager($url);
		$this->assertSame(array(null), $manager->getTorrents(
			array('Pending'), array($url), true, false, '', '', false),
			'a failed reply after reservation is still pending');
		$this->assertTrue(engineManager::loadHistory()->isPending($url),
			'the durable proof remains for an accepted load');
		$this->assertTrue(!is_file(FileUtil::getProfilePath().'/tmp/extsearch-pending.torrent'),
			'a raw dispatched source may be removed using the returned receipt');
		rTorrent::$status = 'ours';
		$result = $manager->action('Pending', 'false-reply-raw');
		$this->assertSame(rTorrent::$hash, $result['data'][0]['hash'] ?? null,
			'the exact marker confirms the load after a failed reply');
		$this->assertSame(1, rTorrent::$calls,
			'reconciliation does not dispatch a duplicate');
	}

	public function testLostRpcReplyRetainsPreDispatchProof()
	{
		$url = 'https://example.invalid/lost-reply';
		rTorrent::$result = false;
		rTorrent::$raw = false;
		$manager = $this->manager($url);
		$this->assertSame(array(null), $manager->getTorrents(
			array('Pending'), array($url), true, false, '', '', false),
			'RPC failure after dispatch is reported as uncertain');
		$this->assertTrue(engineManager::loadHistory()->isPending($url),
			'uncertain dispatch retains its durable proof');
		$this->assertTrue(is_file(FileUtil::getProfilePath().'/tmp/extsearch-pending.torrent'),
			'ambiguous file-based load keeps its source for the daemon');
		rTorrent::$status = 'ours';
		$result = $manager->action('Pending', 'lost-reply');
		$this->assertSame(rTorrent::$hash, $result['data'][0]['hash'] ?? null,
			'later owned marker resolves the lost transport reply');
		$this->assertSame(1, rTorrent::$calls,
			'recovery does not resubmit a possibly accepted load');
	}

	public function testConcurrentReservationConflictVetoesDispatchAndRetainsNewerProof()
	{
		$url = 'https://example.invalid/reservation-conflict';
		$new = array('hash'=>str_repeat('C', 40),
			'key'=>'ru-load-proof-'.str_repeat('c', 32), 'raw'=>true);
		rTorrent::$beforeReserve = function() use ($url, $new) {
			$other = engineManager::loadHistory();
			$other->addPending($url, $new);
			engineManager::saveHistory($other);
		};
		$manager = $this->manager($url);
		$this->assertSame(array(false), $manager->getTorrents(
			array('Pending'), array($url), true, false, '', '', false),
			'a newer same-URL reservation vetoes this RPC dispatch');
		$this->assertSame(0, rTorrent::$calls,
			'only the winner may dispatch the load');
		$loaded = engineManager::loadHistory();
		$this->assertTrue($loaded->isPending($url),
			'the winning receipt remains pending');
		$this->assertSame($new['key'], $loaded->lst[$url]['receipt']['key'] ?? null,
			'cleanup cannot remove the other writer proof');
	}

	public function testStaleReconciliationCannotOverwriteNewSameUrlReceipt()
	{
		$url = 'https://example.invalid/replaced-pending';
		$old = array('hash'=>str_repeat('B', 40),
			'key'=>'ru-load-proof-'.str_repeat('b', 32), 'raw'=>true);
		$new = array('hash'=>str_repeat('C', 40),
			'key'=>'ru-load-proof-'.str_repeat('c', 32), 'raw'=>true);
		$seed = engineManager::loadHistory();
		$seed->addPending($url, $old);
		$seed->lst[$url]['time'] = time() - 301;
		engineManager::saveHistory($seed);
		$stale = engineManager::loadHistory();
		$fresh = engineManager::loadHistory();
		rTorrent::$status = 'ours';
		$stale->reconcile($url);
		$fresh->addPending($url, $new);
		engineManager::saveHistory($fresh);
		engineManager::saveHistory($stale);
		$loaded = engineManager::loadHistory();
		$this->assertTrue($loaded->isPending($url),
			'stale owned marker cannot replace a newer pending reservation');
		$this->assertSame($new['key'], $loaded->lst[$url]['receipt']['key'] ?? null,
			'newer proof key remains the one to reconcile');
	}
}
