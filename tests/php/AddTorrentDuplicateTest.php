<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/FakeRtorrentDaemon.php');

/**
 * php/addtorrent.php reports a known duplicate magnet as a duplicate.
 * A .torrent load needs its deferred proof before claiming Success; when
 * rtorrent drops a duplicate, that proof does not run and the result remains
 * Pending with its uploaded source available for recovery.
 *
 * addtorrent.php answers with a redirect whose result[] values are the
 * outcome, so it is served by PHP's built-in server, through a router that
 * points it at FakeRtorrentDaemon, and the Location header is what is read.
 */
class AddTorrentDuplicateTest extends TestCase
{
	const LOADED = '1111111111111111111111111111111111111111';
	const FRESH = '2222222222222222222222222222222222222222';

	private $base;
	private $daemon;
	private $server;

	public function setUp()
	{
		$this->base = sys_get_temp_dir() . '/rutorrent-addtorrent-duplicate-' . getmypid();
		@mkdir($this->base . '/profile/torrents', 0700, true);
		@mkdir($this->base . '/profile/settings', 0700, true);
	}

	public function tearDown()
	{
		$this->stop();
		$this->deleteTree($this->base);
	}

	private function stop()
	{
		if ($this->daemon !== null) {
			$this->daemon->stop();
			$this->daemon = null;
		}
		if (is_resource($this->server)) {
			@proc_terminate($this->server);
			@proc_close($this->server);
		}
		$this->server = null;
	}

	private function deleteTree($path)
	{
		if (!is_dir($path)) {
			return;
		}
		foreach (scandir($path) as $entry) {
			if (($entry !== '.') && ($entry !== '..')) {
				$child = $path . '/' . $entry;
				is_dir($child) ? $this->deleteTree($child) : @unlink($child);
			}
		}
		@rmdir($path);
	}

	/** A single file torrent whose info dictionary hashes to the return value. */
	private function torrent(&$hash)
	{
		$info = 'd6:lengthi1e4:name5:a.bin12:piece lengthi16384e6:pieces20:'
			. str_repeat("\x01", 20) . 'e';
		$hash = strtoupper(sha1($info));
		$announce = 'http://127.0.0.1:1/announce';
		return 'd8:announce' . strlen($announce) . ':' . $announce . '4:info' . $info . 'e';
	}

	private function reservePort()
	{
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if ($socket === false) {
			throw new Exception('could not reserve a local port: ' . $error);
		}
		$name = stream_socket_get_name($socket, false);
		fclose($socket);
		return (int)substr($name, strrpos($name, ':') + 1);
	}

	/**
	 * Sends one request to addtorrent.php. The daemon reports existing hashes
	 * and confirms only the loads selected by $proofs. A duplicate .torrent
	 * cannot run its deferred proof command, so its result remains Pending.
	 */
	private function add($loaded, $contentType, $body, $proofs = array(), $snapshotFails = false)
	{
		$this->stop();
		$root = realpath(__DIR__ . '/../..');
		$this->daemon = new FakeRtorrentDaemon(
			new FakeRtorrentAddTorrentReplies($loaded, $proofs, $snapshotFails),
			$this->base . '/calls.log');

		$router = $this->base . '/router.php';
		file_put_contents($router, "<?php\n"
			. '$_ENV[\'RU_PROFILE_PATH\'] = ' . var_export($this->base . '/profile', true) . ";\n"
			. 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
			. '$scgi_host = "127.0.0.1";' . "\n"
			. '$scgi_port = ' . $this->daemon->port() . ";\n"
			. '$rpcTimeOut = 10;' . "\n"
			. '$rpcLogCalls = false;' . "\n"
			. '$saveUploadedTorrents = true;' . "\n"
			. 'chdir(' . var_export($root . '/php', true) . ");\n"
			. 'require(' . var_export($root . '/php/addtorrent.php', true) . ");\n"
			. "return true;\n");

		$port = $this->reservePort();
		$this->server = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -d display_errors=0 -S 127.0.0.1:' . $port
			. ' ' . escapeshellarg($router), array(
				0 => array('pipe', 'r'),
				1 => array('file', $this->base . '/server.out', 'a'),
				2 => array('file', $this->base . '/server.err', 'a'),
			), $pipes, $this->base);
		fclose($pipes[0]);
		$socket = false;
		for ($i = 0; ($i < 100) && ($socket === false); $i++) {
			usleep(25000);
			$socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.05);
		}
		if ($socket === false) {
			throw new Exception('PHP server did not start: ' . @file_get_contents($this->base . '/server.err'));
		}
		stream_set_timeout($socket, 10);
		fwrite($socket, "POST /php/addtorrent.php HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n"
			. 'Content-Type: ' . $contentType . "\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
		$response = stream_get_contents($socket);
		fclose($socket);

		if (!preg_match('`^Location: [^?\r\n]*\?([^\r\n]*)`mi', (string)$response, $location)) {
			return array('no redirect: ' . $response);
		}
		parse_str($location[1], $query);
		return isset($query['result']) ? $query['result'] : array();
	}

	private function loadCalls()
	{
		$calls = $this->daemon->calls();
		$listPos = array_search('download_list', $calls, true);
		$firstLoad = null;
		foreach($calls as $position=>$call)
			if(strpos($call, 'load_') === 0 || strpos($call, 'load.') === 0)
			{
				$firstLoad = $position;
				break;
			}
		$this->assertTrue($listPos !== false && $firstLoad !== null && $listPos < $firstLoad,
			'the loaded hashes are read before the first load: ' . implode(', ', $calls));
		return count(array_filter($calls, function($call) {
			return strpos($call, 'load_') === 0 || strpos($call, 'load.') === 0;
		}));
	}

	public function testFailedSnapshotRefusesTheWholeAddBeforeAnyLoad()
	{
		$magnet = 'magnet:?xt=urn:btih:' . self::FRESH;
		$results = $this->add(array(), 'application/x-www-form-urlencoded',
			'url=' . rawurlencode($magnet), array(), true);
		$this->assertEquals(array('Failed'), $results,
			'failed download_list is visible as a failed add');
		$this->assertTrue(in_array('download_list', $this->daemon->calls(), true),
			'the snapshot was attempted');
		$this->assertTrue(count(array_filter($this->daemon->calls(), function($method) {
			return strpos($method, 'load_') === 0 || strpos($method, 'load.') === 0;
		})) === 0, 'no load is sent after the failed snapshot');
	}

	public function testAMagnetAlreadyLoadedIsADuplicateNotAnAddition()
	{
		$urls = 'magnet:?xt=urn:btih:' . self::LOADED . '&dn=loaded' . "\n"
			. 'magnet:?xt=urn:btih:' . self::FRESH . "\n"
			. 'magnet:?xt=urn:btih:' . strtolower(self::FRESH);
		$results = $this->add(array(self::LOADED), 'application/x-www-form-urlencoded',
			'url=' . rawurlencode($urls));
		$this->assertEquals(array('Duplicate', 'Success', 'Duplicate'), $results,
			'the loaded magnet and the second copy of the new one are duplicates: ' . json_encode($results));
		$this->assertEquals(3, $this->loadCalls(), 'each magnet was still sent');
	}

	private function upload($loaded, $names)
	{
		foreach ($this->kept() as $file) {
			unlink($this->base . '/profile/torrents/' . $file);
		}
		$torrent = $this->torrent($hash);
		$boundary = 'dup' . md5($torrent);
		$body = '';
		foreach ($names as $name) {
			$body .= '--' . $boundary . "\r\n"
				. 'Content-Disposition: form-data; name="torrent_file[]"; filename="' . $name . '"' . "\r\n"
				. "Content-Type: application/x-bittorrent\r\n\r\n" . $torrent . "\r\n";
		}
		$body .= '--' . $boundary . "--\r\n";
		$proofs = $loaded ? array(false) : array(true, false);
		return $this->add($loaded ? array(self::LOADED, $hash) : array(self::LOADED),
			'multipart/form-data; boundary=' . $boundary, $body, $proofs);
	}

	private function kept()
	{
		return array_values(array_diff(scandir($this->base . '/profile/torrents'), array('.', '..')));
	}

	public function testAnUploadedTorrentAlreadyLoadedRemainsPendingWithItsSource()
	{
		$results = $this->upload(true, array('one.torrent'));
		$this->assertEquals(array('Pending'), $results,
			'a duplicate upload without its proof key is not reported as added: ' . json_encode($results));
		$this->assertEquals(1, $this->loadCalls(), 'the upload was still sent');
		$this->assertTrue(in_array('d.custom', $this->daemon->calls(), true),
			'the deferred load proof was checked');
		$this->assertEquals(array('one.torrent'), $this->kept(),
			'the unconfirmed source remains for recovery');
	}

	public function testTheSecondCopyInOneUploadRemainsPending()
	{
		$results = $this->upload(false, array('one.torrent', 'two.torrent'));
		$this->assertEquals(array('Success', 'Pending'), $results,
			'the second copy has no matching proof key: ' . json_encode($results));
		$this->assertEquals(2, $this->loadCalls(), 'both uploads were sent');
		$this->assertTrue(in_array('d.custom', $this->daemon->calls(), true),
			'the deferred load proof was checked');
		$this->assertEquals(array('one.torrent', 'two.torrent'), $this->kept(),
			'the confirmed and unconfirmed sources remain: ' . json_encode($this->kept()));
	}

	public function testTheResultPageShowsADuplicateAsAnAlert()
	{
		$root = realpath(__DIR__ . '/../..');
		$driver = $this->base . '/drive-result.php';
		file_put_contents($driver, "<?php\n"
			. '$_REQUEST = array("result" => array("Duplicate"), "name" => array("x.torrent"));' . "\n"
			. 'chdir(' . var_export($root . '/php', true) . ");\n"
			. 'require(' . var_export($root . '/php/addtorrent.php', true) . ");\n");
		$output = shell_exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($driver));
		$this->assertEquals('noty("x.torrent - "+theUILang["addTorrent"+"Duplicate"],"alert");', $output,
			'a duplicate is named with its own wording in the alert style');
	}
}
