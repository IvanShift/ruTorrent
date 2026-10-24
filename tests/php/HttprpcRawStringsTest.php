<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/HttprpcListFixture.php');

/**
 * plugins/httprpc/action.php answers the WebUI's list, file, tracker and
 * save-path requests with JSON built from rXMLRPCRequest::$val (AUD-ESC-05).
 *
 * While run() escaped every quote and backslash it read, json_encode() then
 * escaped them again, so the browser showed `aud \"q\" \\b` for a torrent
 * named `aud "q" \b`, and the datadir dialog prefilled -- and on OK moved the
 * torrent into -- a directory of that escaped name. No JavaScript undid it.
 * And because run() called any answer holding the word "faultCode" a fault,
 * one tracker message reading "quota faultCode reached" turned the whole
 * list into HTTP 500 (AUD-ESC-01). Both answers are pinned here against the
 * real script and parser.
 */
/** Serializable tracker array response used by the common process daemon. */
class HttprpcNestedTrackerReplies extends FakeRtorrentMethodReplies
{
	private $trackerReply;

	public function __construct($values, $trackerReply = null)
	{
		parent::__construct($values);
		$this->trackerReply = $trackerReply;
	}

	public function __invoke($request, $methods)
	{
		if(in_array('d.multicall', $methods, true) || in_array('d.multicall2', $methods, true))
		{
			if($this->trackerReply !== null)
				return $this->trackerReply;
			return new FakeRtorrentRawReply('<?xml version="1.0"?><methodResponse><params><param><value><array><data>'
				.'<value><array><data><value><string>'.HttprpcRawStringsTest::HASH.'</string></value>'
				.'<value><array><data><value><array><data><value><string>https://tracker.test/announce#fragment</string></value>'
				.'<value><i8>1</i8></value><value><i8>1</i8></value><value><i8>0</i8></value>'
				.'<value><i8>5</i8></value><value><i8>6</i8></value><value><i8>7</i8></value>'
				.'</data></array></value></data></array></value></data></array></value>'
				.'</data></array></value></param></params></methodResponse>');
		}
		return parent::__invoke($request, $methods);
	}
}

class HttprpcNestedListReplies extends FakeRtorrentMethodReplies
{
	private $row;
	public function __construct($values, $row) { parent::__construct($values); $this->row = $row; }
	public function __invoke($request, $methods)
	{
		if($methods === array('d.multicall', 'd.multicall', 'session.path'))
			return HttprpcListFixture::reply(array($this->row));
		if($methods === array('f.multicall', 'session.path', 'd.tied_to_file'))
			return new FakeRtorrentRawReply(HttprpcListFixture::body(array(
				array(array(parent::__invoke($request, array('f.multicall')))), array(''), array(''))));
		return parent::__invoke($request, $methods);
	}
}

class HttprpcRawStringsTest extends TestCase
{
	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';
	const NAME = 'aud "q" \\"x\\" \\\\b \'a\' &c';
	const MESSAGE = 'Tracker: [quota faultCode reached]';

	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-httprpc-raw-' . getmypid();
		$this->removeTree($this->base);
		@mkdir($this->base . '/profile/settings', 0700, true);
	}

	public function tearDown()
	{
		$this->stopDaemon();
		$this->removeTree($this->base);
	}

	// Every daemon is stopped when it is replaced and at tearDown: one left
	// running keeps its worker process until the 10 s accept timeout
	// (R5-REV-07).
	private function stopDaemon()
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
	}

	private function removeTree($path)
	{
		if (!is_dir($path) || is_link($path)) {
			@unlink($path);
			return;
		}
		foreach (array_diff(scandir($path), array('.', '..')) as $entry) {
			$this->removeTree($path . '/' . $entry);
		}
		@rmdir($path);
	}

	/** One download row of mode=list, in the order of its 36 commands. */
	private function listRow()
	{
		$row = array_fill(0, 36, 0);
		$row[0] = self::HASH;
		$row[5] = self::NAME;
		$row[15] = 'Rock%20%22n%22%20%5C';
		$row[26] = '/downloads/' . self::NAME;
		$row[30] = self::MESSAGE;
		$row[31] = '';
		return $row;
	}

	/** Runs plugins/httprpc/action.php with this form body; returns its JSON decoded, or its text. */
	private function post($body, $nestedTrackers = false, $trackerReply = null)
	{
		$root = realpath(__DIR__ . '/../..');
		$row = $this->listRow();
		$file = array('dir "x" \\y/file \'z\'.bin', 1, 1, 100, 1);
		$tracker = array('http://tracker.test/ann?x="q"\\', 1, 1, 0, 5, 6, 7, 1800, 0);
		$this->stopDaemon();
		$class = $nestedTrackers ? 'HttprpcNestedTrackerReplies' : 'HttprpcNestedListReplies';
		$values = array(
			'system.client_version' => array('0.16.25'),
			'system.api_version' => array(11),
			'directory.default' => array('/downloads'),
			'd.multicall' => $row, 'd.multicall2' => $row,
			'f.multicall' => $file, 't.multicall' => $tracker,
			'd.base_path' => array('/downloads/' . self::NAME),
			'd.is_open' => array(1),
		);
		$replies = $nestedTrackers ? new $class($values, $trackerReply) : new $class($values, $row);
		$this->daemon = new FakeRtorrentDaemon($replies, $this->base . '/calls.log', false,
			array(__FILE__));
		$driver = $this->base . '/drive-httprpc.php';
		file_put_contents($driver, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . $this->daemon->port() . ";\n"
			. '$rpcTimeOut = 10;' . "\n"
			. '$rpcLogCalls = false;' . "\n"
			. '$log_file = ' . var_export($this->base . '/app.log', true) . ";\n"
			. '$HTTP_RAW_POST_DATA = ' . var_export($body, true) . ";\n"
			. 'chdir(' . var_export($root . '/plugins/httprpc', true) . ");\n"
			. 'require(' . var_export($root . '/plugins/httprpc/action.php', true) . ");\n");
		$output = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($driver) . ' 2>&1', $output);
		$text = implode("\n", $output);
		$json = json_decode($text, true);
		return $json === null ? $text : $json;
	}

	private function same($expected, $actual, $what)
	{
		$this->assertSame($expected, $actual, $what
			. ($expected === $actual ? '' : ': got ' . var_export($actual, true)));
	}

	public function testTheListCarriesTheDaemonsBytesAndSurvivesAFaultCodeMessage()
	{
		$answer = $this->post('mode=list&cid=0');
		$this->assertTrue(is_array($answer) && isset($answer['t'][self::HASH]),
			'mode=list answers the download list even when a tracker message spells faultCode: '
			. (is_string($answer) ? $answer : json_encode($answer)));
		$row = is_array($answer) && isset($answer['t'][self::HASH]) ? $answer['t'][self::HASH] : array();
		$this->same(self::NAME, $row[4] ?? null, 'the name reaches the browser in the daemon\'s bytes');
		$this->same('/downloads/' . self::NAME, $row[25] ?? null, 'so does the base path');
		$this->same('Rock%20%22n%22%20%5C', $row[14] ?? null, 'the label is the stored text');
		$this->same(self::MESSAGE, $row[29] ?? null, 'and the tracker message');
	}

	public function testFilesTrackersAndSavePathCarryTheDaemonsBytes()
	{
		$files = $this->post('mode=fls&hash=' . self::HASH);
		$this->same(array(array('dir "x" \\y/file \'z\'.bin', '1', '1', '100', '1')), $files,
			'mode=fls answers the file path in the daemon\'s bytes');
		$trackers = $this->post('mode=trk&hash=' . self::HASH);
		$this->same('http://tracker.test/ann?x="q"\\', $trackers[0][0] ?? null,
			'mode=trk answers the tracker URL in the daemon\'s bytes');
		$path = $this->post('mode=getsavepath&hash=' . self::HASH);
		$this->same('/downloads/' . self::NAME, is_array($path) ? ($path[1] ?? null) : $path,
			'mode=getsavepath answers the base path the datadir dialog prefills');
		$this->assertTrue(!in_array('d.close', $this->daemon->calls(), true), 'reading an already-open torrent never closes it');
	}
	public function testAllTrackerArraysPreserveHashFragmentsWithoutDelimiterParsing()
	{
		foreach(array('trkall', 'trkall2') as $mode)
		{
			$result = $this->post('mode='.$mode, true);
			$this->same(array(self::HASH => array(array('https://tracker.test/announce#fragment', '1', '1', '0', '5', '6', '7'))),
				$result, $mode.' tracker fields retain their array boundaries and fragment bytes');
			$this->same(1, count(array_filter($this->daemon->calls(), function($method) { return $method === 'd.multicall'; })),
				$mode.' reads all downloads in one structured multicall');
		}
	}

	public function testAllTrackerModesKeepEmptyInventoriesAndRejectInvalidOuterRows()
	{
		$empty = '<array><data/></array>';
		$hash = '<value><string>'.self::HASH.'</string></value>';
		$row = '<array><data><value><array><data>'.$hash.'<value>'.$empty
			.'</value></data></array></value></data></array>';
		$badRow = '<array><data><value><array><data>'.$hash
			.'<value><string>not-tracker-rows</string></value></data></array></value></data></array>';
		foreach(array('trkall', 'trkall2') as $mode)
		{
			foreach(array(
				'empty download view' => array($empty, array()),
				'empty tracker list' => array($row, array(self::HASH => array())),
				'scalar inventory' => array('<string>not-an-inventory</string>', array()),
				'non-array tracker list' => array($badRow, array()),
			) as $name => $case)
			{
				$reply = new FakeRtorrentRawReply('<?xml version="1.0"?><methodResponse><params><param><value>'
					.$case[0].'</value></param></params></methodResponse>');
				$this->same($case[1], $this->post('mode='.$mode, true, $reply), $mode.' '.$name);
			}
			$this->same(array(), $this->post('mode='.$mode, true, FakeRtorrentDaemon::fault('tracker read failed', -500)),
				$mode.' does not publish a fault as tracker rows');
		}
	}

}
