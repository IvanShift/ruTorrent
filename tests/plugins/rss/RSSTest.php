<?php

declare(strict_types=1);

@define('HISTORY_MAX_COUNT', 100, true);
@define('HISTORY_MAX_TRY', 3, true);
@define('WAIT_AFTER_LOADING', 0, true);

require_once(__DIR__ . '/../../php/TestCase.php');

$minInterval = 2;	// in minutes

$feedsWithIncorrectTimes = array(
	"iptorrents.",
	"torrentday.",
);

$rss_debug_enabled = true;
require_once(__DIR__ . '/../../../plugins/rss/rss.php');

class SnoopyMock
{
	public $status = 200, $results = NULL, $headers = array(), $error = "";
	public function get_filename() { return false; }
}

final class RSSTest extends TestCase
{
	private function assertSameFields(array $expected, array $actual): void
	{
		ksort($expected);
		ksort($actual);
		$this->assertSame($expected, $actual);
	}
	public function testConditionalValidatorsKeepResponseValueCase(): void
	{
		$requests = 0;
		$feed = new rRSS('https://tracker.example/feed',
			function ($url, $cookies, $headers) use (&$requests) {
				$requests++;
				$client = new SnoopyMock();
				if($requests === 1)
				{
					$client->headers = array('ETag: "AbC"',
						'Last-Modified: Wed, 21 Oct 2015 07:28:00 GMT');
					$client->results = file_get_contents(__DIR__ . '/atom-sample.xml');
				}
				else
				{
					$this->assertEquals(array('If-None-Match' => '"AbC"',
						'If-Modified-Since' => 'Wed, 21 Oct 2015 07:28:00 GMT'), $headers,
						'validators sent back with their original value bytes');
					$client->status = 304;
				}
				return $client;
			});
		$this->assertTrue($feed->fetch(new rRSSHistory()), 'first response parsed');
		$this->assertEquals('"AbC"', $feed->etag, 'ETag case retained');
		$this->assertEquals('Wed, 21 Oct 2015 07:28:00 GMT', $feed->lastModified,
			'Last-Modified case retained');
		$this->assertTrue($feed->fetch(new rRSSHistory()), 'second request accepts 304');
	}

	public function testRestoredFeedResetsItsSerializedFetcher(): void
	{
		$stored = new rRSS(null, 'passthru');
		$property = new ReflectionProperty(rRSS::class, 'fetchURL');
		if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
		$this->assertEquals('passthru', $property->getValue($stored),
			'the constructor still accepts the caller-provided fetcher');

		$loaded = unserialize(serialize($stored));
		$this->assertEquals('rssFetchURL', $property->getValue($loaded),
			'a cache file cannot choose the function that receives feed URLs and cookies');
	}

	public function testIncorrectTimeExceptionUsesTrackerLabelAndPublicSuffix(): void
	{
		$href = 'https://downloads.example/item.torrent';
		$xml = '<rss><channel><title>Updates</title><item><title>New edition</title>'
			. '<link>' . $href . '</link><guid>new-guid</guid>'
			. '<pubDate>Wed, 21 Oct 2015 07:28:00 GMT</pubDate></item></channel></rss>';
		$cases = array(
			'https://iptorrents.com/feed' => true,
			'https://iptorrents.me/feed' => true,
			'https://tracker.iptorrents.co.uk/feed' => true,
			'https://iptorrents.a.ck/feed' => true,
			'https://www.torrentday.me/feed' => true,
			'https://IPTORRENTS.ME./feed' => true,
			'https://notiptorrents.example/feed' => false,
			'https://iptorrents.com.evil.test/feed' => false,
			'https://iptorrents.me.evil.test/feed' => false,
			'https://iptorrents.ck/feed' => false,
			'https://xn--bad.iptorrents.com/feed' => false,
			'https://evil.test/path/iptorrents.me/feed' => false,
		);
		foreach($cases as $url => $isTracker)
		{
			$history = new rRSSHistory();
			$history->add($href, 'previous-hash', 100, 'old-guid');
			$feed = new rRSS($url, function() use($xml) {
				$client = new SnoopyMock();
				$client->results = $xml;
				return $client;
			});
			$this->assertTrue($feed->fetch($history), $url . ' parses the feed');
			$this->assertSame($isTracker ? 'previous-hash' : '', $history->getHash($href),
				$url . ' applies the GUID correction only outside the configured tracker');
		}
	}

	public function testCustomIncorrectTimePatternsKeepAlternateTldsAndExactHosts(): void
	{
		global $feedsWithIncorrectTimes;
		$previous = $feedsWithIncorrectTimes;
		$href = 'https://downloads.example/custom.torrent';
		$xml = '<rss><channel><title>Updates</title><item><title>New edition</title>'
			. '<link>' . $href . '</link><guid>new-guid</guid>'
			. '<pubDate>Wed, 21 Oct 2015 07:28:00 GMT</pubDate></item></channel></rss>';
		try {
			$cases = array(
				array('customtracker.', 'https://customtracker.com/feed', true),
				array('customtracker.', 'https://customtracker.me/feed', true),
				array('customtracker.', 'https://customtracker.com.evil.test/feed', false),
				array('customtracker.com', 'https://customtracker.com/feed', true),
				array('customtracker.com', 'https://sub.customtracker.com/feed', true),
				array('customtracker.com', 'https://customtracker.me/feed', false),
				array('customtracker.com', 'https://customtracker.com.evil.test/feed', false),
				array('iptorrents.ck', 'https://iptorrents.ck/feed', false),
			);
			foreach($cases as $case)
			{
				list($pattern, $url, $isTracker) = $case;
				$feedsWithIncorrectTimes = array($pattern);
				$history = new rRSSHistory();
				$history->add($href, 'previous-hash', 100, 'old-guid');
				$feed = new rRSS($url, function() use($xml) {
					$client = new SnoopyMock();
					$client->results = $xml;
					return $client;
				});
				$this->assertTrue($feed->fetch($history), $url . ' parses the feed');
				$this->assertSame($isTracker ? 'previous-hash' : '', $history->getHash($href),
					$url . ' keeps the custom host boundary');
			}
		} finally {
			$feedsWithIncorrectTimes = $previous;
		}
	}

	public function testInvalidSuffixHostRefusalIsClassifiedInLog(): void
	{
		global $log_file;
		$previous = $log_file;
		$log_file = tempnam(sys_get_temp_dir(), 'rss-host-');
		$href = 'https://downloads.example/invalid-host.torrent';
		$xml = '<rss><channel><title>Updates</title><item><title>New edition</title>'
			. '<link>' . $href . '</link><guid>new-guid</guid>'
			. '<pubDate>Wed, 21 Oct 2015 07:28:00 GMT</pubDate></item></channel></rss>';
		try {
			$history = new rRSSHistory();
			$history->add($href, 'previous-hash', 100, 'old-guid');
			$feed = new rRSS('https://iptorrents.xn--tst-qla/feed', function() use($xml) {
				$client = new SnoopyMock();
				$client->results = $xml;
				return $client;
			});
			$this->assertTrue($feed->fetch($history), 'a feed with an unsafe hostname still parses');
			$this->assertSame('', $history->getHash($href), 'unsafe hostname does not inherit an exception');
			$lines = file_get_contents($log_file);
			$this->assertTrue(strpos($lines, 'RSS: incorrect-times host classification refused: invalid-host') !== false,
				'the failed suffix lookup has a classified log reason');
			$this->assertTrue(strpos($lines, 'iptorrents.xn--tst-qla') === false,
				'the log does not include the feed URL');
		} finally {
			@unlink($log_file);
			$log_file = $previous;
		}
	}

	public function testCookieSuffixUsesSharedParserForValuesContainingEquals(): void
	{
		$feed = new rRSS('https://tracker.example/feed:COOKIE:token=abc=def;broken;sid=2');
		$this->assertEquals(array('token' => 'abc=def', 'sid' => '2'), $feed->cookies,
			'URL cookie values keep equals and malformed pairs are ignored');
		$this->assertEquals('https://tracker.example/feed', $feed->url,
			'cookie suffix is removed from request URL');
	}
	public function testLabelOnlyEditKeepsLegacyCookieHashAndGroup(): void
	{
		$source = 'https://tracker.example/feed:COOKIE:uid=1; pass=abc';
		$legacyHash = md5($source);
		$normalizedURL = (new rRSS($source))->srcURL;
		$this->assertTrue($legacyHash !== (new rRSS($source))->hash,
			'the fixture represents a hash from before cookie normalization');
		$manager = (new ReflectionClass(rRSSManager::class))->newInstanceWithoutConstructor();
		$manager->rssList = new rRSSMetaList();
		$manager->rssList->lst[$legacyHash] = array(
			'label' => 'Old', 'auto' => 1, 'enabled' => 0, 'url' => $source,
		);
		$manager->groups = new rRSSGroupList();
		$group = new rRSSGroup('Saved group', 'group-1');
		$group->lst = array($legacyHash);
		$manager->groups->add($group);
		$manager->cache = new class {
			public $saved = 0;
			public function set($object, $mergeErrorsOnly = false) { $this->saved++; }
			public function remove($object) { throw new RuntimeException('feed cache was removed'); }
		};

		try {
			$changed = $manager->change($legacyHash, $normalizedURL, 'Renamed', 1);
		} catch (RuntimeException $error) {
			$changed = false;
		}
		$this->assertEquals(true, $changed,
			'a label edit succeeds without deleting or fetching the feed');
		$this->assertEquals(array($legacyHash), array_keys($manager->rssList->lst),
			'the persisted identity stays stable');
		if(!array_key_exists($legacyHash, $manager->rssList->lst))
			return;
		$this->assertEquals('Renamed', $manager->rssList->lst[$legacyHash]['label'],
			'the label changes under the old hash');
		$this->assertEquals(1, $manager->rssList->lst[$legacyHash]['auto'],
			'a label-only edit keeps the automatic download choice');
		$this->assertEquals(0, $manager->rssList->lst[$legacyHash]['enabled'],
			'the enabled state survives the edit');
		$this->assertEquals(array($legacyHash), $manager->groups->get('group-1')->lst,
			'group membership continues to point at the feed');
		$this->assertEquals(1, $manager->cache->saved, 'the updated metadata is persisted once');
		$this->assertEquals($normalizedURL, $manager->rssList->lst[$legacyHash]['url'],
			'the stored URL follows the current cookie format');
		$this->assertEquals(true, $manager->change($legacyHash, $normalizedURL, 'Again', 0),
			'a second edit from the UI still preserves the old hash');
		$this->assertEquals(array($legacyHash), array_keys($manager->rssList->lst),
			'normalizing metadata does not force a later rehash');
		$this->assertEquals(0, $manager->rssList->lst[$legacyHash]['auto'],
			'a later automatic-download change stays under the old hash');
		$this->assertEquals(2, $manager->cache->saved, 'each metadata edit is persisted once');
	}

	public function testDuplicateFeedErrorsKeepLocalizableKeys(): void
	{
		$first = new rRSS('https://tracker.example/first');
		$second = new rRSS('https://tracker.example/second');
		$manager = (new ReflectionClass(rRSSManager::class))->newInstanceWithoutConstructor();
		$manager->rssList = new rRSSMetaList();
		$manager->rssList->add($first, 'First', 0, 1);
		$manager->rssList->add($second, 'Second', 0, 1);
		$this->assertSame(false, $manager->add($first->srcURL), 'duplicate add is refused');
		$this->assertSame(false, $manager->change($first->hash, $second->srcURL, 'Renamed'),
			'duplicate edit is refused');
		$errors = $manager->rssList->formatErrors();
		$this->assertSame('rssAlreadyExist', $errors[0]['key'], 'add error uses a language key');
		$this->assertSame('rssAlreadyExist', $errors[1]['key'], 'edit error uses the same language key');
	}

	public function testFeedFailureKeepsExternalDetailSeparateFromLanguageKey(): void
	{
		// Snoopy preserves the status token from an HTTP response line.
		$external = "500'+(window.__rssErrorEval='RAN')+'";
		$feed = new rRSS('https://tracker.example/feed', function () use ($external) {
			$client = new SnoopyMock();
			$client->status = $external;
			return $client;
		});
		$manager = (new ReflectionClass(rRSSManager::class))->newInstanceWithoutConstructor();
		$manager->rssList = new rRSSMetaList();
		$manager->history = new rRSSHistory();
		$method = new ReflectionMethod(rRSSManager::class, 'tryFetch');
		if(PHP_VERSION_ID < 80100) $method->setAccessible(true);
		$this->assertSame(false, $method->invoke($manager, $feed), 'external HTTP failure refuses fetch');
		$errors = $manager->rssList->formatErrors();
		$this->assertSame('cantFetchRSS', $errors[0]['key'], 'error uses a language key');
		$this->assertSame('[RSS-HTTP-Error] Status: ' . $external, $errors[0]['detail'],
			'external text remains a separate inert detail');
		$this->assertSame('https://tracker.example/feed', $errors[0]['prm'],
			'the feed address identifies the failure');
	}

	public function testRefusedCredentialRedirectNamesTheReason(): void
	{
		$feed = new rRSS('https://tracker.example/feed', function ($url, $cookies, $headers) {
			$client = new SnoopyMock();
			$client->status = 302;
			$client->error = Snoopy::CREDENTIAL_REDIRECT_REFUSED;
			return $client;
		});
		$this->assertEquals(false, $feed->fetch(new rRSSHistory()), 'a refused redirect is not a feed');
		$this->assertEquals(array('[RSS-HTTP-Error] Status: 302; credential-redirect-refused'),
			$feed->lastErrorMsgs, 'the user can distinguish policy refusal from an HTTP failure');
	}

	public function testRefusedCredentialRedirectWith2xxLocationIsNotAFeed(): void
	{
		$feed = new rRSS('https://tracker.example/feed', function () {
			$client = new SnoopyMock();
			$client->status = 200;
			$client->results = file_get_contents(__DIR__ . '/atom-sample.xml');
			$client->error = Snoopy::CREDENTIAL_REDIRECT_REFUSED;
			return $client;
		});
		$this->assertEquals(false, $feed->fetch(new rRSSHistory()),
			'a 2xx source response with a refused Location is not a feed');
		$this->assertEquals(array('[RSS-HTTP-Error] Status: 200; credential-redirect-refused'),
			$feed->lastErrorMsgs, 'the classified refusal remains visible');
	}

	public function testTorrentDownloadRefusalAppearsInRssUiError(): void
	{
		$href = 'https://tracker.example/dl.php?id=5&passkey=private';
		$feed = new rRSS('https://tracker.example/rss.php:COOKIE:uid=1',
			function ($url, $cookies) use ($href) {
				$this->assertEquals($href, $url);
				$this->assertEquals(array('uid' => '1'), $cookies);
				$client = new SnoopyMock();
				$client->status = 302;
				$client->error = Snoopy::CREDENTIAL_REDIRECT_REFUSED;
				return $client;
			});
		$feed->items[$href] = array('timestamp' => 0, 'guid' => 'item-5');
		$manager = (new ReflectionClass(rRSSManager::class))->newInstanceWithoutConstructor();
		$manager->rssList = new rRSSMetaList();
		$manager->history = new rRSSHistory();
		$manager->getTorrents($feed, $href, false, false, '', '', '', '', false);
		$errors = $manager->rssList->formatErrors();
		$this->assertSame('rssCantLoadTorrent', $errors[0]['key'],
			'torrent item failure uses a language key');
		$this->assertSame(Snoopy::CREDENTIAL_REDIRECT_REFUSED, $errors[0]['detail'],
			'torrent item failure identifies the redirect policy');
		$this->assertEquals('Failed', $manager->history->lst[$href]['hash']);
	}

	public function testTorrentDownloadRefusalWith2xxLocationCannotBecomeAFile(): void
	{
		$feed = new rRSS('https://tracker.example/feed', function () {
			$client = new class extends SnoopyMock {
				public function get_filename() { throw new RuntimeException('refused body was treated as a torrent'); }
			};
			$client->status = 200;
			$client->results = 'a 2xx response body with Location';
			$client->error = Snoopy::CREDENTIAL_REDIRECT_REFUSED;
			return $client;
		});
		// Snoopy parses Location independently of status, so this is a real
		// response shape. Catch the old write path before it creates a file.
		try {
			$result = $feed->getTorrent('https://tracker.example/dl.php?id=6');
		} catch (RuntimeException $error) {
			$result = $error->getMessage();
		}
		$this->assertEquals(false, $result, 'a refused redirect cannot save the source response');
		$this->assertEquals(Snoopy::CREDENTIAL_REDIRECT_REFUSED, $feed->lastTorrentError,
			'the classified reason remains visible');
	}

	public function testTorrentDownloadChecksBodyAndKeepsTrailingLineBreak(): void
	{
		$root = tempnam(sys_get_temp_dir(), 'rss-torrent-');
		unlink($root);
		mkdir($root);
		mkdir($root . '/torrents');
		$profile = new ReflectionProperty(FileUtil::class, 'profilePathInstance');
		if(PHP_VERSION_ID < 80100) $profile->setAccessible(true);
		$previous = $profile->getValue();
		$profile->setValue(null, $root);
		$body = 'd4:infod6:lengthi1e4:name4:test12:piece lengthi1e6:pieces20:abcdefghijklmnopqrstee';
		try
		{
			foreach(array(
				'HTML with HTTP 200' => array('<html>Login required</html>', false),
				'valid metainfo' => array($body, true),
				'valid metainfo with a line break' => array($body . "\n", true),
			) as $label => $case)
			{
				$feed = new rRSS('https://tracker.example/feed', function () use ($case) {
					$client = new SnoopyMock();
					$client->results = $case[0];
					return $client;
				});
				$result = $feed->getTorrent('https://tracker.example/download?id=1');
				$files = glob($root . '/torrents/*');
				if($case[1])
				{
					$this->assertTrue(is_string($result) && is_file($result), $label . ' becomes a file');
					$this->assertEquals($case[0], file_get_contents($result), $label . ' keeps response bytes');
					unlink($result);
				}
				else
				{
					$this->assertEquals(false, $result, $label . ' is rejected');
					$this->assertEquals(array(), $files, $label . ' does not create a file');
				}
			}
		}
		finally
		{
			$profile->setValue(null, $previous);
			foreach(glob($root . '/torrents/*') as $file) unlink($file);
			rmdir($root . '/torrents');
			rmdir($root);
		}
	}

	public function testAtom(): void
	{
		$exp_url = 'https://example.org/rss';
		$exp_etag = 'some etag';
		$exp_lastModified = 'some date';
		$rssFetchURL = function ($url, $cookies, $headers) use ($exp_url, $exp_etag, $exp_lastModified) {
			$this->assertEquals($exp_url, $url);
			$this->assertEquals(['key' => 'value', 'key2'=> 'value2'], $cookies);
			$this->assertEquals(['If-None-Match' => $exp_etag, 'If-Modified-Since' => $exp_lastModified], $headers);
			$cliMock = new SnoopyMock();
			$cliMock->results = file_get_contents(__DIR__ . '/atom-sample.xml');
			return $cliMock;
		};

		$rRSS = new rRSS($exp_url.':COOKIE:key=value;key2=value2', $rssFetchURL);
		$rRSS->etag = $exp_etag;
		$rRSS->lastModified = $exp_lastModified;
		$history = new rRSSHistory();
		$succ = $rRSS->fetch($history);
		$this->assertEquals(0, count($rRSS->lastErrorMsgs));
		$this->assertTrue($succ, 'fetch success');

		// check channel
		$this->assertEquals('Example Feed', $rRSS->channel['title']);
		$this->assertEquals(strtotime('2003-12-13T20:30:02Z'), $rRSS->channel['timestamp']);
		$this->assertEquals('https://example.org/', $rRSS->channel['link']);

		// check items
		$this->assertEquals(2, count($rRSS->items));
		$this->assertSameFields(array(
			"timestamp" => strtotime('2003-12-13T18:30:02Z'),
			"title" => 'Title <1>',
			"link" => 'https://example.org/2003/12/13/atom03',
			"guid" => 'https://example.org/2003/12/13/atom03',
			"description" => 'Some text.',
		), $rRSS->items['https://example.org/2003/12/13/atom03']);
		$this->assertSameFields(array(
			"timestamp" => strtotime('2003-12-13T19:30:02Z'),
			"title" => 'Title <2>',
			"link" => 'https://example.org/2003/12/13/atom04',
			"guid" => 'https://example.org/2003/12/13/atom04',
			"description" => 'Some other text.'."\n\n[Content]\n".'Some content',
		), $rRSS->items['https://example.org/2003/12/13/atom04']);

		// check contents
		$contents =  $rRSS->getContents("label", "1", "1", $history);
		$this->assertEquals(array(
			"time" => strtotime('2003-12-13T18:30:02Z'),
			"title" => 'Title <1>',
			"href" => 'https://example.org/2003/12/13/atom03',
			"guid" => 'https://example.org/2003/12/13/atom03',
			"errcount" => 0,
			"hash" => ""
		), $contents['items'][0]);
		$this->assertEquals(array(
			"time" => strtotime('2003-12-13T19:30:02Z'),
			"title" => 'Title <2>',
			"href" => 'https://example.org/2003/12/13/atom04',
			"guid" => 'https://example.org/2003/12/13/atom04',
			"errcount" => 0,
			"hash" => ""
		), $contents['items'][1]);
	}

	public function testRSS(): void
	{
		$exp_url = 'https://onerous.me/rss';
		$rssFetchURL = function ($url, $cookies, $headers) use ($exp_url) {
			$this->assertEquals($exp_url, $url);
			$this->assertEquals([], $cookies);
			$this->assertEquals([], $headers);
			$cliMock = new SnoopyMock();
			$cliMock->results = file_get_contents(__DIR__ . '/rss-sample-erroneous.xml');
			return $cliMock;
		};
		$rRSS = new rRSS($exp_url, $rssFetchURL);
		$history = new rRSSHistory();
		$succ = $rRSS->fetch($history);
		$this->assertEquals(1, count($rRSS->lastErrorMsgs));
		$this->assertTrue($succ);

		$this->assertEquals('Vexatious torrent channel©', $rRSS->channel['title']);
		$this->assertEquals(strtotime('Fri, 31 Dec 2021 12:00:00 +0000'), $rRSS->channel['timestamp']);
		$contents =  $rRSS->getContents("label", "1", "1", $history);
		$this->assertEquals(3, count($contents['items']));

		$this->assertEquals(array(
			"time" => strtotime('Sat, 1 Jan 2022 12:00:00 +0000'),
			"title" => 'The best title',
			"href" => 'https://onerous.me/path/to/torrent?guid=ABCD&torr',
			"guid" => 'https://onerous.me/path/to/torrent?guid=ABCD&perm',
			"errcount" => 0,
			"hash" => ""
		), $contents['items'][0]);
		$this->assertEquals(array(
			"time" => strtotime('Sat, 1 Jan 2022 13:00:00 +0000'),
			"title" => '<No Title>',
			"href" => 'https://onerous.me/no_tags_allowed/path/to/torrent?guid=ABCE',
			"guid" => 'https://onerous.me/no_tags_allowed/path/to/torrent?guid=ABCE',
			"errcount" => 0,
			"hash" => ""
		), $contents['items'][1]);
		$this->assertEquals(array(
			"time" => strtotime('Sat, 1 Jan 2022 14:00:00 +0000'),
			"title" => 'Wonders of <pubDate>Sat, 1 Jan 2022 14:30:00 +0000</pubDate> wow',
			"href" => 'https://onerous.me/path/to/torrent?guid=ABCF',
			"guid" => 'https://onerous.me/path/to/torrent?guid=ABCF',
			"errcount" => 0,
			"hash" => ""
		), $contents['items'][2]);
	}
	public function testRSS2(): void
	{
		$exp_url = 'https://example.jp/rss';
		$rssFetchURL = function ($url, $cookies, $headers) use ($exp_url) {
			$this->assertEquals($exp_url, $url);
			$this->assertEquals([], $cookies);
			$this->assertEquals([], $headers);
			$cliMock = new SnoopyMock();
			$cliMock->results = file_get_contents(__DIR__ . '/rss-jp-sample.xml');
			return $cliMock;
		};
		$rRSS = new rRSS($exp_url, $rssFetchURL);
		$history = new rRSSHistory();
		$succ = $rRSS->fetch($history);
		$this->assertEquals(0, count($rRSS->lastErrorMsgs));
		$this->assertTrue($succ);

		$this->assertEquals('アニメ 放送©', $rRSS->channel['title']);
		$this->assertEquals(strtotime('Fri, 31 Dec 2021 12:00:00 +0000'), $rRSS->channel['timestamp']);
		$contents =  $rRSS->getContents("label", "1", "1", $history);
		$this->assertEquals(1, count($contents['items']));

		$this->assertEquals(array(
			"time" => strtotime('Sat, 1 Jan 2022 12:00:00 +0000'),
			"title" => '完璧な映画',
			"href" => 'https://example.jp/path/to/torrent?guid=ABCD&torr',
			"guid" => 'https://example.jp/path/to/torrent?guid=ABCD&perm',
			"errcount" => 0,
			"hash" => ""
		), $contents['items'][0]);
	}

	public function testRSSWin1251(): void
	{
		// The fixture deliberately starts with a blank line before the XML
		// declaration: real-world feeds emit such padding, and it makes
		// libxml ignore the declared windows-1251 encoding and read the
		// bytes as if they were UTF-8, garbling every non-ASCII title.
		$exp_url = 'https://example.ru/rss';
		$rssFetchURL = function ($url, $cookies, $headers) use ($exp_url) {
			$this->assertEquals($exp_url, $url);
			$this->assertEquals([], $cookies);
			$this->assertEquals([], $headers);
			$cliMock = new SnoopyMock();
			$cliMock->results = file_get_contents(__DIR__ . '/rss-win1251-sample.xml');
			return $cliMock;
		};
		$rRSS = new rRSS($exp_url, $rssFetchURL);
		$history = new rRSSHistory();
		$succ = $rRSS->fetch($history);
		// libxml still reports the misplaced XML declaration, nothing else
		$this->assertEquals(1, count($rRSS->lastErrorMsgs));
		$this->assertTrue($succ);

		$this->assertEquals('Русский торрент канал', $rRSS->channel['title']);
		$this->assertEquals(strtotime('Fri, 31 Dec 2021 12:00:00 +0000'), $rRSS->channel['timestamp']);
		$contents =  $rRSS->getContents("label", "1", "1", $history);
		$this->assertEquals(1, count($contents['items']));

		$this->assertEquals(array(
			"time" => strtotime('Sat, 1 Jan 2022 12:00:00 +0000'),
			"title" => 'Новый фильм — четвёртый сезон',
			"href" => 'https://example.ru/path/to/torrent?torr=ABCD',
			"guid" => 'https://example.ru/path/to/torrent?perm=ABCD',
			"errcount" => 0,
			"hash" => ""
		), $contents['items'][0]);
	}

	public function testRSSUtf8(): void
	{
		// Same document as testRSSWin1251 (blank line before the XML
		// declaration included), but already encoded in UTF-8: it must pass
		// through unconverted and parse with unchanged titles.
		$exp_url = 'https://example.ru/rss-utf8';
		$rssFetchURL = function ($url, $cookies, $headers) use ($exp_url) {
			$this->assertEquals($exp_url, $url);
			$this->assertEquals([], $cookies);
			$this->assertEquals([], $headers);
			$cliMock = new SnoopyMock();
			$cliMock->results = file_get_contents(__DIR__ . '/rss-utf8-sample.xml');
			return $cliMock;
		};
		$rRSS = new rRSS($exp_url, $rssFetchURL);
		$history = new rRSSHistory();
		$succ = $rRSS->fetch($history);
		// libxml still reports the misplaced XML declaration, nothing else
		$this->assertEquals(1, count($rRSS->lastErrorMsgs));
		$this->assertTrue($succ);

		$this->assertEquals('Русский торрент канал', $rRSS->channel['title']);
		$this->assertEquals(strtotime('Fri, 31 Dec 2021 12:00:00 +0000'), $rRSS->channel['timestamp']);
		$contents =  $rRSS->getContents("label", "1", "1", $history);
		$this->assertEquals(1, count($contents['items']));

		$this->assertEquals(array(
			"time" => strtotime('Sat, 1 Jan 2022 12:00:00 +0000'),
			"title" => 'Новый фильм — четвёртый сезон',
			"href" => 'https://example.ru/path/to/torrent?torr=ABCD',
			"guid" => 'https://example.ru/path/to/torrent?perm=ABCD',
			"errcount" => 0,
			"hash" => ""
		), $contents['items'][0]);
	}

	public function testRSSMislabeledWin1251(): void
	{
		// Same document again (blank line before the XML declaration
		// included), but this time the declaration lies: it claims
		// windows-1251 while the bytes are already UTF-8, as misconfigured
		// aggregators commonly emit. The declared encoding must not trigger
		// a second cp1251-to-UTF-8 conversion, or every non-ASCII title
		// turns into mojibake.
		$exp_url = 'https://example.ru/rss-mislabeled';
		$rssFetchURL = function ($url, $cookies, $headers) use ($exp_url) {
			$this->assertEquals($exp_url, $url);
			$this->assertEquals([], $cookies);
			$this->assertEquals([], $headers);
			$cliMock = new SnoopyMock();
			$cliMock->results = file_get_contents(__DIR__ . '/rss-mislabeled-win1251-sample.xml');
			return $cliMock;
		};
		$rRSS = new rRSS($exp_url, $rssFetchURL);
		$history = new rRSSHistory();
		$succ = $rRSS->fetch($history);
		// libxml still reports the misplaced XML declaration, nothing else
		$this->assertEquals(1, count($rRSS->lastErrorMsgs));
		$this->assertTrue($succ);

		$this->assertEquals('Русский торрент канал', $rRSS->channel['title']);
		$this->assertEquals(strtotime('Fri, 31 Dec 2021 12:00:00 +0000'), $rRSS->channel['timestamp']);
		$contents =  $rRSS->getContents("label", "1", "1", $history);
		$this->assertEquals(1, count($contents['items']));

		$this->assertEquals(array(
			"time" => strtotime('Sat, 1 Jan 2022 12:00:00 +0000'),
			"title" => 'Новый фильм — четвёртый сезон',
			"href" => 'https://example.ru/path/to/torrent?torr=ABCD',
			"guid" => 'https://example.ru/path/to/torrent?perm=ABCD',
			"errcount" => 0,
			"hash" => ""
		), $contents['items'][0]);
	}
	public function testPendingLoadHistoryCanConfirmItsExactReceipt(): void
	{
		$url = 'https://tracker.example/download?id=12';
		$receipt = array('hash' => str_repeat('A', 40), 'key' => 'ru-load-proof-' . str_repeat('a', 32));
		$history = new rRSSHistory();
		$history->add($url, 'Pending', 100, 'item-12', $receipt);
		$this->assertTrue($history->wasLoaded($url, 'item-12', function ($given) use ($receipt) {
			$this->assertEquals($receipt, $given, 'the persisted receipt is checked');
			return 'ours';
		}), 'a confirmed pending load remains suppressed');
		$this->assertEquals($receipt['hash'], $history->getHash($url),
			'only the daemon-confirmed hash is shown as loaded');
	}

	public function testPendingLoadHistoryRetriesOnlyAfterGrace(): void
	{
		$url = 'https://tracker.example/download?id=13';
		$receipt = array('hash' => str_repeat('B', 40), 'key' => 'ru-load-proof-' . str_repeat('b', 32));
		$history = new rRSSHistory();
		$history->add($url, 'Pending', 100, 'item-13', $receipt);
		$this->assertTrue($history->wasLoaded($url, 'item-13', function () { return 'missing'; }),
			'a recent pending load cannot overlap with a second dispatch');
		$history->lst[$url]['submittedAt'] = time() - 301;
		$this->assertTrue(!$history->wasLoaded($url, 'item-13', function () { return 'missing'; }),
			'an unconfirmed load becomes retryable even with the same GUID');
		$this->assertEquals('Failed', $history->getHash($url), 'unconfirmed is not loaded');
		$this->assertEquals(1, $history->getCounter($url), 'one failure is recorded');
	}

	public function testChangedGuidDoesNotOverlapPendingLoad(): void
	{
		$url = 'https://tracker.example/download?id=15';
		$history = new rRSSHistory();
		$history->add($url, 'Pending', 100, 'old-guid',
			array('hash' => str_repeat('D', 40), 'key' => 'ru-load-proof-' . str_repeat('d', 32)));
		$history->correct($url, 101, 'new-guid');
		$this->assertTrue($history->wasLoaded($url, 'new-guid', function () { return 'missing'; }),
			'a new GUID cannot dispatch over a pending load of the same URL');
		$this->assertEquals('Pending', $history->getHash($url), 'the original receipt remains checkable');
		$history->wasLoaded($url, 'new-guid', function () { return 'ours'; });
		$history->correct($url, 101, 'new-guid');
		$this->assertTrue(!$history->wasLoaded($url, 'new-guid'),
			'a new GUID becomes eligible after the prior load is confirmed');
	}

	public function testNewHashWithoutMarkerStaysPendingDuringShortSettleWindow(): void
	{
		$url = 'https://tracker.example/download?id=16';
		$history = new rRSSHistory();
		$history->add($url, 'Pending', 100, 'item-16',
			array('hash' => str_repeat('E', 40), 'key' => 'ru-load-proof-' . str_repeat('e', 32)));
		$this->assertTrue($history->wasLoaded($url, 'item-16', function () { return 'foreign'; }),
			'a newly visible hash still waits for its deferred marker command');
		$this->assertEquals('Pending', $history->getHash($url),
			'transient marker absence does not become a permanent conflict');
		$history->lst[$url]['submittedAt'] = time() - 11;
		$history->wasLoaded($url, 'item-16', function () { return 'foreign'; });
		$this->assertEquals('Conflict', $history->getHash($url),
			'a settled hash without our marker is a visible conflict');
	}

	public function testForeignPendingHashIsVisibleAsConflict(): void
	{
		$url = 'https://tracker.example/download?id=14';
		$history = new rRSSHistory();
		$history->add($url, 'Pending', 100, 'item-14',
			array('hash' => str_repeat('C', 40), 'key' => 'ru-load-proof-' . str_repeat('c', 32)));
		$history->lst[$url]['submittedAt'] = time() - 11;
		$this->assertTrue($history->wasLoaded($url, 'item-14', function () { return 'foreign'; }),
			'an existing hash is not repeatedly reloaded');
		$this->assertEquals('Conflict', $history->getHash($url),
			'a foreign torrent is never shown as this RSS load success');
	}


	// An item's link and guid are handed to window.open() by
	// plugins/rss/init.js, so anything the feed puts there that is not an
	// http(s) address has to be dropped while the feed is parsed.
	private function feedItems(string $xml): array
	{
		$rRSS = new rRSS('https://example.org/rss', function () use ($xml) {
			$cliMock = new SnoopyMock();
			$cliMock->results = $xml;
			return $cliMock;
		});
		$this->assertTrue($rRSS->fetch(new rRSSHistory()), 'fetch success');
		return $rRSS->items;
	}

	public function testRSSItemsWithoutAnHttpLinkAreDropped(): void
	{
		$items = $this->feedItems(
			'<?xml version="1.0"?><rss version="2.0"><channel>'.
			'<title>C</title><link>https://example.org/</link>'.
			'<item><title>good</title><link>https://example.org/ok</link></item>'.
			'<item><title>js</title><link>javascript:window.x=1</link>'.
				'<guid>javascript:window.x=1</guid></item>'.
			'<item><title>data</title><link>data:text/html,&lt;b&gt;x&lt;/b&gt;</link></item>'.
			'<item><title>file</title><link>file:///etc/passwd</link></item>'.
			'<item><title>text</title><link>not a url at all</link></item>'.
			'</channel></rss>');
		$this->assertEquals(array('https://example.org/ok'), array_keys($items));
	}

	public function testRSSItemFallsBackToThePermalinkWhenOnlyItIsALink(): void
	{
		$items = $this->feedItems(
			'<?xml version="1.0"?><rss version="2.0"><channel>'.
			'<title>C</title><link>https://example.org/</link>'.
			'<item><title>t</title><link>javascript:window.x=1</link>'.
				'<guid>https://example.org/perma</guid></item>'.
			'</channel></rss>');
		$this->assertEquals(array('https://example.org/perma'), array_keys($items));
		$this->assertEquals('https://example.org/perma', $items['https://example.org/perma']['guid']);
	}

	public function testAtomEntriesWithoutAnHttpLinkAreDropped(): void
	{
		$items = $this->feedItems(
			'<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom">'.
			'<title>C</title><link href="https://example.org/"/><updated>2003-12-13T20:30:02Z</updated>'.
			'<entry><title>good</title><link href="https://example.org/ok"/>'.
				'<updated>2003-12-13T18:30:02Z</updated></entry>'.
			'<entry><title>js</title><link href="javascript:window.x=1"/>'.
				'<updated>2003-12-13T18:30:02Z</updated></entry>'.
			'<entry><title>data</title><link href="data:text/html,x"/>'.
				'<updated>2003-12-13T18:30:02Z</updated></entry>'.
			'</feed>');
		$this->assertEquals(array('https://example.org/ok'), array_keys($items));
	}

	// An item link is handed to openExternalURL() by plugins/rss/init.js, so
	// what a feed may carry is what isExternalURL() in js/common.js will open.
	// Every address here is one a real indexer publishes, and dropping one
	// loses the download with nothing shown to say so.
	public static function openableLinks(): array
	{
		return array(
			'magnet' => 'magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567',
			'ftp' => 'ftp://ftp.example.org/pub/a.torrent',
			'ftps' => 'ftps://ftp.example.org/pub/b.torrent',
			'userinfo' => 'https://user:passkey@tracker.example.org/dl/c.torrent',
			'scheme relative' => '//tracker.example.org/dl/d.torrent',
			'underscore in host' => 'http://my_tracker.example.org/dl/e.torrent',
			'ipv6 literal host' => 'http://[2001:db8::1]/dl/f.torrent',
			'query and no path' => 'https://tracker.example.org?id=8',
			'trailing dot host' => 'https://tracker.example.org./dl/g.torrent',
		);
	}

	// The other half of the same rule: an address the browser would refuse
	// must not reach it, whatever else the item holds.
	public static function refusedLinks(): array
	{
		return array(
			'javascript' => 'javascript:window.x=1',
			'data' => 'data:text/html,<b>x</b>',
			'file' => 'file:///etc/passwd',
			'mailto' => 'mailto:someone@example.org',
			'plain text' => 'not a url at all',
			'page relative' => '/rtorrent/plugins/rss/rss.php',
		);
	}

	private function rssFeed(string $link, string $guid): string
	{
		return('<?xml version="1.0"?><rss version="2.0"><channel>'.
			'<title>C</title><link>https://example.org/</link>'.
			'<item><title>t</title><link>'.htmlspecialchars($link).'</link>'.
			'<guid>'.htmlspecialchars($guid).'</guid></item>'.
			'</channel></rss>');
	}

	private function atomFeed(string $link): string
	{
		return('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom">'.
			'<title>C</title><link href="https://example.org/"/>'.
			'<updated>2003-12-13T20:30:02Z</updated>'.
			'<entry><title>t</title><link href="'.htmlspecialchars($link).'"/>'.
			'<updated>2003-12-13T18:30:02Z</updated></entry>'.
			'</feed>');
	}

	public function testRSSItemsKeepEveryAddressTheBrowserMayOpen(): void
	{
		foreach (self::openableLinks() as $what => $link) {
			$items = $this->feedItems($this->rssFeed($link, $link));
			$this->assertEquals(array($link), array_keys($items), $what);
			$this->assertEquals($link, $items[$link]['guid'], $what);
		}
	}

	public function testAtomEntriesKeepEveryAddressTheBrowserMayOpen(): void
	{
		foreach (self::openableLinks() as $what => $link) {
			$items = $this->feedItems($this->atomFeed($link));
			$this->assertEquals(array($link), array_keys($items), $what);
		}
	}

	public function testRSSItemsWithAnAddressTheBrowserRefusesAreDropped(): void
	{
		foreach (self::refusedLinks() as $what => $link) {
			$this->assertEquals(array(), array_keys($this->feedItems(
				$this->rssFeed($link, $link))), $what);
			$this->assertEquals(array(), array_keys($this->feedItems(
				$this->atomFeed($link))), $what);
		}
	}

	// A kept item must not carry a permalink the browser would refuse, so the
	// link stands in for it -- the same substitution an http(s) link gets.
	public function testRSSItemReplacesAPermalinkThatCouldNotBeOpened(): void
	{
		$link = 'magnet:?xt=urn:btih:0123456789abcdef0123456789abcdef01234567';
		$items = $this->feedItems($this->rssFeed($link, 'javascript:window.x=1'));
		$this->assertEquals(array($link), array_keys($items));
		$this->assertEquals($link, $items[$link]['guid']);
	}

	// A whole feed at once, the shape an indexer that has only magnet links
	// publishes: every item has to come through, not just the http one.
	public function testAFeedOfMixedAddressesKeepsEveryOpenableItem(): void
	{
		$xml = '<?xml version="1.0"?><rss version="2.0"><channel>'.
			'<title>C</title><link>https://example.org/</link>';
		$expected = array();
		foreach (array_merge(self::openableLinks(), self::refusedLinks()) as $link) {
			$xml .= '<item><title>t</title><link>'.htmlspecialchars($link).'</link>'.
				'<guid>'.htmlspecialchars($link).'</guid></item>';
		}
		foreach (self::openableLinks() as $link) {
			$expected[] = $link;
		}
		$this->assertEquals($expected, array_keys($this->feedItems($xml.'</channel></rss>')));
	}
	// rss.php asks an item for dc:date when it has no pubDate. A plain RSS 2.0
	// feed has no reason to declare the dc prefix, and an XPath expression
	// naming a prefix the engine does not know raises a warning -- one per
	// item, on every poll of every such feed. php-test.sh looks for fatal and
	// parse errors only, so any number of these is still a green run.
	public function testAFeedWhoseItemsHaveNoPubDateRaisesNoDiagnostic(): void
	{
		$xml = '<?xml version="1.0"?><rss version="2.0"><channel>'.
			'<title>C</title><link>https://example.org/</link>';
		for ($i = 0; $i < 5; $i++) {
			$xml .= '<item><title>t'.$i.'</title>'.
				'<link>https://example.org/'.$i.'</link></item>';
		}
		$raised = array();
		set_error_handler(function ($no, $str, $file, $line) use (&$raised) {
			$raised[] = $str.' in '.basename((string)$file).' on line '.$line;
			return true;
		});
		$items = $this->feedItems($xml.'</channel></rss>');
		restore_error_handler();
		$this->assertEquals(array(), $raised,
			'no diagnostic raised while parsing a feed without dates: '.
			implode('; ', array_unique($raised)));
		$this->assertEquals(5, count($items));
	}

	// And the prefix still resolves for a feed that does declare it, so a date
	// an item carries there is still read.
	public function testAFeedDeclaringTheDcPrefixStillReadsItsDates(): void
	{
		$items = $this->feedItems(
			'<?xml version="1.0"?>'.
			'<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/"><channel>'.
			'<title>C</title><link>https://example.org/</link>'.
			'<item><title>t</title><link>https://example.org/a</link>'.
			'<dc:date>2024-01-02T03:04:05Z</dc:date></item>'.
			'</channel></rss>');
		$this->assertEquals(1, count($items));
		$this->assertEquals(strtotime('2024-01-02T03:04:05Z'),
			$items['https://example.org/a']['timestamp']);
	}
}
