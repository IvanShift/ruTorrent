<?php

declare(strict_types=1);

@define('HISTORY_MAX_COUNT', 100, true);
@define('HISTORY_MAX_TRY', 3, true);
@define('WAIT_AFTER_LOADING', 0, true);

require_once(__DIR__ . '/../../php/TestCase.php');

$minInterval = 2;
$feedsWithIncorrectTimes = array();
$rss_debug_enabled = false;
require_once(__DIR__ . '/../../../plugins/rss/rss.php');

final class RSSChangeTest extends TestCase
{
	public function testFailedUrlChangeKeepsTheOldFeedCacheAndReferences(): void
	{
		$this->withLocalFixture(function ($manager, $base) {
			$oldURL = $base . '/old.xml';
			$newURL = $base . '/missing.xml';
			$oldHash = (new rRSS($oldURL))->hash;
			$newHash = (new rRSS($newURL))->hash;
			$this->prepareFeed($manager, $oldURL, $oldHash);

			$this->assertTrue($manager->change($oldHash, $newURL, 'New feed', 0) === false,
				'an unavailable replacement URL fails visibly');
			$this->assertTrue($manager->rssList->isExist($oldHash), 'failed edit keeps old metadata');
			$this->assertTrue(!$manager->rssList->isExist($newHash), 'failed edit adds no new metadata');
			$cachedOld = new rRSS();
			$cachedOld->hash = $oldHash;
			$this->assertTrue($manager->cache->get($cachedOld), 'failed edit keeps the old parsed feed');
			$this->assertEquals(array($oldHash), reset($manager->groups->lst)->lst,
				'failed edit keeps the group reference');
			$savedFilters = new rRSSFilterList();
			$manager->cache->get($savedFilters);
			$this->assertEquals($oldHash, $savedFilters->lst[0]->rssHash,
				'failed edit keeps the filter reference');
		});
	}

	public function testSuccessfulUrlChangeMovesGroupsFiltersAndFeedCache(): void
	{
		$this->withLocalFixture(function ($manager, $base, $root) {
			$oldURL = $base . '/old.xml';
			$newURL = $base . '/new.xml';
			$oldHash = (new rRSS($oldURL))->hash;
			$newHash = (new rRSS($newURL))->hash;
			$this->prepareFeed($manager, $oldURL, $oldHash);
			file_put_contents($root . '/http/request.count', '');

			$this->assertTrue($manager->change($oldHash, $newURL, 'New feed', 0) === true,
				'a locally fetched replacement changes the feed');
			$this->assertTrue(!$manager->rssList->isExist($oldHash), 'successful edit retires old metadata');
			$this->assertTrue($manager->rssList->isExist($newHash), 'successful edit publishes new metadata');
			$this->assertEquals(0, $manager->rssList->lst[$newHash]['enabled'], 'edit keeps enabled state');
			$this->assertEquals(array($newHash), reset($manager->groups->lst)->lst,
				'group follows the replacement feed');
			$savedGroups = new rRSSGroupList();
			$manager->cache->get($savedGroups);
			$this->assertEquals(array($newHash), reset($savedGroups->lst)->lst,
				'group change is persisted');
			$savedFilters = new rRSSFilterList();
			$manager->cache->get($savedFilters);
			$this->assertEquals($newHash, $savedFilters->lst[0]->rssHash,
				'filter follows the replacement feed');
			$cachedNew = new rRSS();
			$cachedNew->hash = $newHash;
			$this->assertTrue($manager->cache->get($cachedNew), 'new parsed feed is cached');
			$cachedOld = new rRSS();
			$cachedOld->hash = $oldHash;
			$this->assertTrue(!$manager->cache->get($cachedOld), 'old parsed feed is retired');
			$savedList = new rRSSMetaList();
			$manager->cache->get($savedList);
			$this->assertEquals(array($newHash), array_keys($savedList->lst),
				'only the replacement feed remains in persisted metadata');
			$reloaded = new rRSSManager();
			$this->assertEquals(array($newHash), array_keys($reloaded->rssList->lst),
				'a new manager sees only the replacement feed');
			$this->assertEquals(array($newHash), reset($reloaded->groups->lst)->lst,
				'a new manager sees the moved group reference');
			$this->assertEquals(1, strlen(file_get_contents($root . '/http/request.count')),
				'the replacement URL is fetched exactly once');
		});
	}

	public function testExistingDestinationDoesNotDeleteTheSourceFeed(): void
	{
		$this->withLocalFixture(function ($manager, $base) {
			$oldURL = $base . '/old.xml';
			$newURL = $base . '/new.xml';
			$oldHash = (new rRSS($oldURL))->hash;
			$newHash = (new rRSS($newURL))->hash;
			$this->prepareFeed($manager, $oldURL, $oldHash);
			$manager->add($newURL, 'Other feed', 0, 1);

			$this->assertTrue($manager->change($oldHash, $newURL, 'Edited', 0) === false,
				'an existing destination URL refuses the edit');
			$this->assertTrue($manager->rssList->isExist($oldHash), 'source metadata is preserved');
			$this->assertTrue($manager->rssList->isExist($newHash), 'destination metadata is preserved');
			$this->assertEquals(array($oldHash), reset($manager->groups->lst)->lst,
				'group stays with its source feed');
			$cachedOld = new rRSS();
			$cachedOld->hash = $oldHash;
			$this->assertTrue($manager->cache->get($cachedOld), 'source cache is preserved');
		});
	}

	private function prepareFeed($manager, $oldURL, $oldHash): void
	{
		$manager->add($oldURL, 'Old feed', 1, 0);
		$this->assertTrue($manager->rssList->isExist($oldHash), 'the old feed was fetched locally');
		$manager->addGroup('Saved group', array($oldHash));
		$filters = new rRSSFilterList();
		$filters->add(new rRSSFilter('Saved filter', '', '', 0, $oldHash));
		$manager->cache->set($filters);
	}

	private function withLocalFixture($check): void
	{
		$root = sys_get_temp_dir() . '/rss-change-' . bin2hex(random_bytes(8));
		mkdir($root . '/http', 0777, true);
		mkdir($root . '/profile/settings/rss/cache', 0777, true);
		copy(__DIR__ . '/atom-sample.xml', $root . '/http/old.xml');
		copy(__DIR__ . '/atom-sample.xml', $root . '/http/new.xml');
		file_put_contents($root . '/http/router.php',
			'<?php file_put_contents(dirname(__FILE__)."/request.count", "x", FILE_APPEND | LOCK_EX); return false;');
		$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if($listener === false)
			throw new RuntimeException('cannot reserve local RSS fixture port: ' . $error);
		$address = stream_socket_get_name($listener, false);
		$port = (int) substr($address, strrpos($address, ':') + 1);
		fclose($listener);
		$profile = new ReflectionProperty(FileUtil::class, 'profilePathInstance');
		if(PHP_VERSION_ID < 80100) $profile->setAccessible(true);
		$previousProfile = $profile->getValue();
		$profile->setValue(null, $root . '/profile');
		$previousBlock = $GLOBALS['httpBlockPrivateNetworks'] ?? null;
		$GLOBALS['httpBlockPrivateNetworks'] = false;
		$process = null;
		try
		{
			$process = proc_open(array(PHP_BINARY, '-S', '127.0.0.1:' . $port,
				'-t', $root . '/http', $root . '/http/router.php'), array(
				0 => array('pipe', 'r'),
				1 => array('file', $root . '/server.log', 'a'),
				2 => array('file', $root . '/server.log', 'a'),
			), $pipes);
			if(!is_resource($process))
				throw new RuntimeException('cannot start local RSS fixture');
			fclose($pipes[0]);
			$ready = false;
			for($attempt = 0; $attempt < 100; $attempt++)
			{
				$socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);
				if($socket !== false)
				{
					fclose($socket);
					$ready = true;
					break;
				}
				usleep(10000);
			}
			if(!$ready)
				throw new RuntimeException('local RSS fixture did not start');
			$check(new rRSSManager(), 'http://127.0.0.1:' . $port, $root);
		}
		finally
		{
			if(is_resource($process))
			{
				proc_terminate($process);
				proc_close($process);
			}
			$profile->setValue(null, $previousProfile);
			if($previousBlock === null) unset($GLOBALS['httpBlockPrivateNetworks']);
			else $GLOBALS['httpBlockPrivateNetworks'] = $previousBlock;
			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
				FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
			foreach($files as $entry)
				$entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
			rmdir($root);
		}
	}
}
