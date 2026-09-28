<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');

/**
 * Exercise the raw httprpc door with plugin-style batched additions.
 *
 * The plugins submit their own additions through PHP's direct SCGI route.
 * An external XMLRPC body uses the proxy policy instead: unsupported load
 * tails refuse the whole batch, while permitted tails reach rTorrent.
 */
class HttprpcBatchedAdditionTest extends TestCase
{
	private $base;
	private $daemon;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-httprpc-addition-' . getmypid();
		@mkdir($this->base . '/profile/settings', 0700, true);
	}

	public function tearDown()
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
		$this->removeTree($this->base);
	}

	private function removeTree($path)
	{
		if (!is_dir($path)) {
			return;
		}
		foreach (glob($path . '/*') as $entry) {
			is_dir($entry) ? $this->removeTree($entry) : @unlink($entry);
		}
		@rmdir($path);
	}

	private function repoRoot()
	{
		return realpath(__DIR__ . '/../../..');
	}

	private function torrent()
	{
		return 'd8:announce20:http://tr.invalid/a4:infod6:lengthi12e4:name8:file.txt'
			. '12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee';
	}

	/** Three load.raw_start members, one command parameter each. */
	private function batchedAdd($parameters)
	{
		$members = '';
		foreach ($parameters as $parameter) {
			$members .= '<value><struct>'
				. '<member><name>methodName</name>'
				. '<value><string>load.raw_start</string></value></member>'
				. '<member><name>params</name><value><array><data>'
				. '<value><string></string></value>'
				. '<value><base64>' . base64_encode($this->torrent()) . '</base64></value>'
				. '<value><string>' . htmlspecialchars($parameter, ENT_NOQUOTES, 'UTF-8')
				. '</string></value>'
				. '</data></array></value></member></struct></value>';
		}
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<methodCall><methodName>system.multicall</methodName>'
			. '<params><param><value><array><data>' . $members
			. '</data></array></value></param></params></methodCall>';
	}

	/** Runs plugins/httprpc/action.php against the daemon; returns its output. */
	private function post($body)
	{
		$root = $this->repoRoot();
		if ($this->daemon !== null) {
			$this->daemon->stop();
		}
		$this->daemon = new FakeRtorrentDaemon(array(
			new FakeRtorrentDirectStringReply('0.16.24'), array(0, 0, 0)),
			$this->base . '/calls.log');

		$driver = $this->base . '/drive-httprpc.php';
		file_put_contents($driver, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . $this->daemon->port() . ";\n"
			. '$rpcTimeOut = 10;' . "\n"
			. '$rpcLogCalls = false;' . "\n"
			. '$topDirectory = "/";' . "\n"
			. '$HTTP_RAW_POST_DATA = ' . var_export($body, true) . ";\n"
			. 'chdir(' . var_export($root . '/plugins/httprpc', true) . ");\n"
			. 'require(' . var_export($root . '/plugins/httprpc/action.php', true) . ");\n");

		$output = array();
		exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' .
			escapeshellarg($driver) . ' 2>&1', $output);
		return implode("\n", $output);
	}

	public function testAnExternalBatchWithRatioViewTailsIsRefusedAsAWhole()
	{
		$out = $this->post($this->batchedAdd(array(
			'view.set_visible=rat_1',
			'view.set_visible=rat_1',
			'view.set_visible=rat_1')));

		$this->assertTrue(strpos($out, "The command 'view.set_visible' was rejected by this server") !== false,
			'the door names the refused ratio-view tail: ' . substr($out, 0, 200));
		$this->assertTrue($this->daemon->calls() === array('system.client_version'),
			'no batch member reached rTorrent: ' . json_encode($this->daemon->calls()));
	}

	public function testAnExternalBatchWithConnectionSeedTailIsRefusedAsAWhole()
	{
		$out = $this->post($this->batchedAdd(array(
			'd.throttle_name.set=NULL',
			'd.connection_seed.set=seed',
			'd.custom.set=x-filename,evening catch-up.mkv')));

		$this->assertTrue(strpos($out, "The command 'd.connection_seed.set' was rejected by this server") !== false,
			'the door names the refused connection-setting tail: ' . substr($out, 0, 200));
		$this->assertTrue($this->daemon->calls() === array('system.client_version'),
			'neither allowed sibling was sent: ' . json_encode($this->daemon->calls()));
	}

	public function testAnExternalBatchWithAllowedTailsReachesRtorrent()
	{
		$out = $this->post($this->batchedAdd(array(
			'd.throttle_name.set=NULL',
			'd.custom.set=x-filename,evening catch-up.mkv',
			'd.custom1.set=films')));

		$this->assertTrue(strpos($out, 'rejected by this server') === false,
			'the door permits all three safe tails: ' . substr($out, 0, 200));
		$this->assertTrue($this->daemon->calls() === array(
			'system.client_version', 'load.raw_start', 'load.raw_start', 'load.raw_start'),
			'all three adds reached rTorrent: ' . json_encode($this->daemon->calls()));
	}

	public function testABatchCarryingExecuteIsStillRefusedAtTheDoor()
	{
		$out = $this->post($this->batchedAdd(array(
			'd.custom1.set=tv',
			'execute=/bin/id',
			'd.custom1.set=films')));

		$this->assertTrue(strpos($out, "The command 'execute' was rejected by this server") !== false,
			'the door refuses execute and names it: ' . substr($out, 0, 200));
		$this->assertTrue($this->daemon->calls() === array('system.client_version'),
			'no batch member reached rTorrent: ' . json_encode($this->daemon->calls()));
	}

}
