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
		$this->assertEquals("theUILang.rssCantLoadTorrent + '; credential-redirect-refused'",
			$errors[0]['desc'], 'torrent item failure identifies the redirect policy');
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
		$this->assertEquals(array(
			"timestamp" => strtotime('2003-12-13T18:30:02Z'),
			"title" => 'Title <1>',
			"link" => 'https://example.org/2003/12/13/atom03',
			"guid" => 'https://example.org/2003/12/13/atom03',
			"description" => 'Some text.',
		), $rRSS->items['https://example.org/2003/12/13/atom03']);
		$this->assertEquals(array(
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
}
