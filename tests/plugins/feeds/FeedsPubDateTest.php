<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/SCGITransportFixture.php');

/**
 * plugins/feeds/action.php builds an RSS item per torrent from one
 * d.multicall. Field 6 is the seedingtime custom value, which becomes the
 * item's pubDate; field 7, present only when item descriptions are shown, is
 * the torrent's comment. The pubDate has to follow field 6 alone, and each
 * torrent has to be read from its own row whether or not the description
 * fields are asked for.
 *
 * action.php is a top level script, so it runs in a process of its own
 * against SCGITransportFixture, from a copy of the tree in which
 * plugins/feeds/conf.local.php can switch the descriptions off.
 */
class FeedsPubDateTest extends TestCase
{
	private $base;
	private $daemon;
	// session.name, which the channel title is made of
	private $sessionName = 'fake';

	// hash, name, seedingtime, comment[, tracker message, label, peer counts]
	const TORRENTS = array(
		array('1111111111111111111111111111111111111111', 'no comment', '1700000000', ''),
		array('2222222222222222222222222222222222222222', 'with comment', '1710000000', 'VRS24mrkera%20comment'),
		array('3333333333333333333333333333333333333333', 'not finished', '', ''),
	);
	private $torrents;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-feeds-pubdate-' . getmypid();
		// One instance runs every method; a case may change both.
		$this->torrents = self::TORRENTS;
		$this->sessionName = 'fake';
		@mkdir($this->base . '/profile/settings', 0700, true);
	}

	public function tearDown()
	{
		if ($this->daemon !== null) {
			$this->daemon->close();
			$this->daemon = null;
		}
		$this->removeTree($this->base);
	}

	private function removeTree($path)
	{
		if (is_link($path) || is_file($path)) {
			@unlink($path);
			return;
		}
		if (!is_dir($path)) {
			return;
		}
		foreach (scandir($path) as $entry) {
			if ($entry !== '.' && $entry !== '..') {
				$this->removeTree($path . '/' . $entry);
			}
		}
		@rmdir($path);
	}

	private function copyTree($from, $to)
	{
		@mkdir($to, 0700, true);
		foreach (scandir($from) as $entry) {
			if ($entry === '.' || $entry === '..' || $entry === 'config.local.php' || $entry === 'conf.local.php') {
				continue;
			}
			if (is_dir($from . '/' . $entry)) {
				$this->copyTree($from . '/' . $entry, $to . '/' . $entry);
			} else {
				copy($from . '/' . $entry, $to . '/' . $entry);
			}
		}
	}

	/** One d.multicall row, as action.php asks for it. */
	private function row($torrent, $descriptions)
	{
		list($hash, $name, $seedingtime, $comment) = $torrent;
		$row = array($hash, $name, $torrent[4] ?? '', '0', '0', $torrent[5] ?? '', $seedingtime);
		if ($descriptions) {
			$peers = $torrent[6] ?? '0';
			$row = array_merge($row, array($comment, '4', '4', '65536', '65536', '0', '0',
				'0', '0', '16384', '0', '0', $peers, $peers));
		}
		return $row;
	}

	/**
	 * Runs action.php and returns [title => pubDate or null] for every item
	 * in the feed it prints.
	 */
	private function feed($descriptions, $server = array(), $public = null, $version = '0.16.25')
	{
		$root = realpath(__DIR__ . '/../../..');
		$tree = $this->base . '/tree-' . ($descriptions ? 'on' : 'off');
		foreach (array('php', 'conf', 'plugins/feeds') as $dir) {
			$this->copyTree($root . '/' . $dir, $tree . '/' . $dir);
		}
		if (!$descriptions) {
			file_put_contents($tree . '/plugins/feeds/conf.local.php',
				"<?php\n\$showItemDescription = false;\n");
		}

		$values = array();
		foreach ($this->torrents as $torrent) {
			$values = array_merge($values, $this->row($torrent, $descriptions));
		}
		if ($this->daemon !== null) {
			$this->daemon->close();
		}
		$xml = '<?xml version="1.0" encoding="UTF-8"?><methodResponse><params><param>'
			. '<value><array><data>';
		// Byte-wise, as both daemons write it: only & < > become entities, and
		// a quote, a backslash or a byte that is not UTF-8 arrives as it is.
		foreach($values as $value)
			$xml .= '<value><string>' . strtr((string)$value, array('&' => '&amp;', '<' => '&lt;', '>' => '&gt;'))
				. '</string></value>';
		$xml .= '</data></array></value></param></params></methodResponse>';
		$this->daemon = SCGITransportFixture::start(
			"Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: "
			. strlen($xml) . "\r\n\r\n" . $xml);

		// Seed the singleton so this peer sees the feed request, not settings probes.
		$driver = $this->base . '/drive-feeds.php';
		file_put_contents($driver, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($tree . '/conf/config.php', true) . ");\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . $this->daemon->port() . ";\n"
			. '$rpcTimeOut = 10;' . "\n"
			. '$_SERVER[\'HTTP_HOST\'] = \'example.test\';' . "\n"
			. '$_SERVER[\'PHP_SELF\'] = \'/plugins/feeds/action.php\';' . "\n"
			. '$_SERVER[\'SCRIPT_NAME\'] = \'/plugins/feeds/action.php\';' . "\n"
			. '$_SERVER = array_merge($_SERVER, '.var_export($server, true).');' . "\n"
			. 'putenv("RU_PUBLIC_URL"); $publicURL = '.var_export($public, true).';' . "\n"
			. '$_REQUEST[\'mode\'] = \'all\';' . "\n"
			. 'chdir(' . var_export($tree . '/plugins/feeds', true) . ");\n"
			. 'require_once(' . var_export($tree . '/php/util.php', true) . ");\n"
			. 'require_once(' . var_export($tree . '/php/settings.php', true) . ");\n"
			. '$r = new ReflectionClass("rTorrentSettings");' . "\n"
			. '$settings = $r->newInstanceWithoutConstructor();' . "\n"
			. '$p = $r->getProperty("theSettings"); $p->setAccessible(true);' . "\n"
			. '$p->setValue(null, $settings);' . "\n"
			. '$settings->linkExist = true;' . "\n"
			. '$settings->iVersion = '.($version === '0.9.8' ? '0x908' : '0x1019').';' . "\n"
			. '$settings->version = $settings->libVersion = '.var_export($version, true).';' . "\n"
			. '$settings->server = '.var_export($this->sessionName, true).';' . "\n"
			. 'require(' . var_export($tree . '/plugins/feeds/action.php', true) . ");\n");

		$output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($driver));
		$items = array();
		preg_match_all('`<item>(.*)</item>`Us', (string)$output, $matches);
		foreach ($matches[1] as $item) {
			preg_match('`<title>(.*)</title>`U', $item, $title);
			$items[$title[1]] = preg_match('`<pubDate>(.*)</pubDate>`U', $item, $date) ? $date[1] : null;
		}
		ksort($items);
		return array($items, (string)$output);
	}

	public function testPublicBasePreservesProxyHostRewriteAndPrefixOnBothVersions()
	{
		foreach (array('0.9.8', '0.16.25') as $version) {
			list($items, $rss) = $this->feed(false, array(
				'HTTP_HOST' => 'internal:8080', 'HTTPS' => 'off',
				'HTTP_X_FORWARDED_HOST' => 'untrusted.invalid',
				'PHP_SELF' => '/internal/plugins/feeds/action.php',
			), 'https://public.example:9443/torrents/', $version);
			$this->assertTrue(strpos($rss, '<link>https://public.example:9443/torrents/</link>') !== false,
				$version.' feed uses the configured public origin and app prefix');
			$this->assertTrue(strpos($rss, '<url>https://public.example:9443/torrents/images/logo.png</url>') !== false,
				'the image URL uses the same public base');
			$this->assertTrue(strpos($rss, 'internal:8080') === false && strpos($rss, 'untrusted.invalid') === false,
				'neither the internal Host nor forwarded host enters the public feed');
		}
	}

	public function testDirectOriginUsesForwardedSchemeAndExactScriptPrefix()
	{
		list($items, $rss) = $this->feed(false, array(
			'HTTP_HOST' => 'public.example:9443', 'HTTPS' => 'off',
			'HTTP_X_FORWARDED_PROTO' => 'https',
			'SCRIPT_NAME' => '/torrents/plugins/feeds/action.php',
			'PHP_SELF' => '/internal/plugins/feeds/action.php/extra',
		));
		$this->assertTrue(strpos($rss, '<link>https://public.example:9443/torrents/</link>') !== false,
			'a direct public Host uses the single forwarded scheme and server script name');
	}

	private function expected()
	{
		$items = array(
			'no comment' => 'Tue, 14 Nov 2023 22:13:20 GMT',
			'not finished' => null,
			'with comment' => 'Sat, 09 Mar 2024 16:00:00 GMT',
		);
		ksort($items);
		return $items;
	}

	public function testPubDateIsTheSeedingTimeWhetherOrNotThereIsAComment()
	{
		list($items, $output) = $this->feed(true);
		$this->assertEquals($this->expected(), $items,
			'each finished torrent is dated by its seeding time, with or without a comment: '
			. json_encode($items) . ' ' . substr($output, 0, 300));
		$request = $this->daemon->request();
		$this->assertEquals(true,
			strpos($request['payload'], '<methodName>d.multicall</methodName>') !== false,
			'the feed comes from d.multicall');
	}

	const AUD = 'aud "q" \b \'a\' &c';
	// The probe name plus the pairs an extra unescape would collapse.
	const AUD_PAIRS = 'aud "q" \b \'a\' &c \\\\x \\"y';

	/** The feed as XML; null when it is not well-formed. */
	private function parsedFeed($descriptions)
	{
		list(, $output) = $this->feed($descriptions);
		$previous = libxml_use_internal_errors(true);
		$xml = simplexml_load_string($output);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return array($xml === false ? null : $xml, $output);
	}

	/**
	 * AUD-ESC-16: an item's title and category are the torrent's name and
	 * label in the daemon's own bytes, escaped once, for XML.
	 */
	public function testAudTitleAndCategoryAreTheDaemonsOwnBytes()
	{
		$this->torrents = array(array('4444444444444444444444444444444444444444', self::AUD_PAIRS,
			'1700000000', '', '', self::AUD));
		list($xml, $output) = $this->parsedFeed(false);
		$this->assertTrue($xml !== null, 'the feed is well-formed XML: ' . substr($output, 0, 400));
		if ($xml === null) return;
		$this->assertSame(self::AUD_PAIRS, (string)$xml->channel->item[0]->title,
			'the title is the name the daemon reported');
		$this->assertSame(self::AUD, (string)$xml->channel->item[0]->category,
			'the category is the label another client wrote, unencoded');
	}

	/**
	 * S5: a tracker message is shown in the item description. The feed read
	 * "mesage", a key nothing writes, so the fieldset never appeared.
	 */
	public function testAudTrackerMessageIsShownInTheDescription()
	{
		$message = 'Tracker: [' . self::AUD . ' <unregistered>]';
		$this->torrents = array(array('5555555555555555555555555555555555555555', 'with message',
			'1700000000', '', $message));
		list($xml, $output) = $this->parsedFeed(true);
		$this->assertTrue($xml !== null, 'the feed is well-formed XML: ' . substr($output, 0, 400));
		if ($xml === null) return;
		$description = (string)$xml->channel->item[0]->description;
		$this->assertTrue(strpos($description, htmlspecialchars($message, ENT_COMPAT, 'UTF-8')) !== false,
			'the description shows the tracker message, escaped once: ' . $description);
	}

	/**
	 * S5: a name that is not UTF-8 keeps a title. htmlspecialchars() without
	 * ENT_SUBSTITUTE answers '' for it, which left the item untitled.
	 */
	public function testAudNameThatIsNotUtf8KeepsItsTitle()
	{
		$this->torrents = array(array('6666666666666666666666666666666666666666', "caf\xe9 " . self::AUD,
			'1700000000', ''));
		list($xml, $output) = $this->parsedFeed(false);
		$this->assertTrue($xml !== null, 'the feed is well-formed XML: ' . substr($output, 0, 400));
		if ($xml === null) return;
		$this->assertSame("caf\u{FFFD} " . self::AUD, (string)$xml->channel->item[0]->title,
			'the invalid byte is substituted and the rest of the name is kept');
	}

	/**
	 * AUD-ESC-16: the channel is named after session.name, which the daemon
	 * reports as configured; it is escaped for XML like every other value.
	 */
	public function testAudSessionNameKeepsTheChannelWellFormed()
	{
		$this->sessionName = 'host <' . self::AUD . '>:1234';
		list($xml, $output) = $this->parsedFeed(false);
		$this->assertTrue($xml !== null, 'the feed is well-formed XML: ' . substr($output, 0, 400));
		if ($xml === null) return;
		$this->assertSame('host <' . self::AUD . '>: ', substr((string)$xml->channel->title, 0, strlen('host <' . self::AUD . '>: ')),
			'the channel title is the session name, escaped once');
	}

	/**
	 * R5-REV-02: every daemon value is XML 1.0 text. A code point XML 1.0
	 * does not allow -- a C0 control other than TAB, LF and CR, U+FFFE,
	 * U+FFFF -- becomes U+FFFD, as a byte that is not UTF-8 does. One such
	 * byte in a tracker message (shown since the "mesage" fix) or in a name
	 * made the whole feed ill-formed ("CData section not finished",
	 * "PCDATA invalid Char value 1"). DEL and TAB are allowed and kept.
	 */
	public function testAudControlBytesInDaemonValuesKeepTheFeedWellFormed()
	{
		$this->sessionName = "host\x02name:1234";
		$this->torrents = array(array("77777777777777777777777777777777777777\x01<",
			"ctl\x01name \xef\xbf\xbe\x7f\tend \xef\xbf\xbf", '1700000000',
			'VRS24mrker' . rawurlencode("comment\x1fend"), "Tracker: [bad\x0bthing]",
			rawurlencode("label\x1b\x7f"), "3\x01<"));
		list($xml, $output) = $this->parsedFeed(true);
		$this->assertTrue($xml !== null, 'the feed is well-formed XML: ' . addcslashes(substr($output, 0, 600), "\0..\37"));
		if ($xml === null) return;
		$item = $xml->channel->item[0];
		$this->assertSame("ctl\u{FFFD}name \u{FFFD}\x7f\tend \u{FFFD}", (string)$item->title,
			'C0 controls, U+FFFE and U+FFFF in the name become U+FFFD; DEL and TAB are kept');
		$this->assertSame("label\u{FFFD}\x7f", (string)$item->category,
			'a control byte in the label becomes U+FFFD');
		$this->assertSame("77777777777777777777777777777777777777\u{FFFD}<", (string)$item->guid,
			'the guid is the daemon hash, escaped like every other value');
		$this->assertSame("host\u{FFFD}name: ", substr((string)$xml->channel->title, 0, strlen("host\u{FFFD}name: ")),
			'a control byte in session.name becomes U+FFFD in the channel title');
		$description = (string)$item->description;
		$this->assertTrue(strpos($description, "Tracker: [bad\u{FFFD}thing]") !== false,
			'the tracker message is shown with U+FFFD for its vertical tab: ' . $description);
		$this->assertTrue(strpos($description, "comment\u{FFFD}end") !== false,
			'the comment is shown with U+FFFD for its control byte: ' . $description);
		$this->assertTrue(substr_count($description, "3\u{FFFD}&lt; (0)") === 2,
			'the peer and seed counts are escaped like every other value: ' . $description);
	}

	public function testEveryTorrentIsReadFromItsOwnRowWithoutDescriptions()
	{
		list($items, $output) = $this->feed(false);
		$this->assertEquals($this->expected(), $items,
			'without item descriptions every torrent is still listed and dated from its own row: '
			. json_encode($items) . ' ' . substr($output, 0, 300));
	}
}
