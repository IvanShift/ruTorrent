<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/FakeRtorrentDaemon.php');

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
	private function post($body)
	{
		$root = realpath(__DIR__ . '/../..');
		$row = $this->listRow();
		$file = array('dir "x" \\y/file \'z\'.bin', 1, 1, 100, 1);
		$tracker = array('http://tracker.test/ann?x="q"\\', 1, 1, 0, 5, 6, 7, 1800, 0);
		$this->stopDaemon();
		$this->daemon = new FakeRtorrentDaemon(new FakeRtorrentMethodReplies(array(
			'system.client_version' => array('0.16.25'),
			'system.api_version' => array(11),
			'directory.default' => array('/downloads'),
			'd.multicall' => $row, 'd.multicall2' => $row,
			'f.multicall' => $file, 't.multicall' => $tracker,
			'd.base_path' => array('/downloads/' . self::NAME),
		)), $this->base . '/calls.log');
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
	}
}
