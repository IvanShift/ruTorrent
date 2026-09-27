<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/SCGITransportFixture.php');

/** Drive the shipped httprpc action through the shared SCGI transport fixture. */
class SetpropsTest extends TestCase
{
	private $base;
	private $peer;
	const HASH = '0123456789ABCDEF0123456789ABCDEF01234567';

	public function setUp()
	{
		$this->base = sys_get_temp_dir().'/rutorrent-setprops-'.getmypid();
		@mkdir($this->base.'/profile/settings', 0700, true);
	}

	public function tearDown()
	{
		if($this->peer !== null)
		{
			$this->peer->close();
			$this->peer = null;
		}
		foreach(array('driver.php', 'refusals.log', 'profile/settings/rtorrent.dat') as $name)
			@unlink($this->base.'/'.$name);
		@rmdir($this->base.'/profile/settings');
		@rmdir($this->base.'/profile');
		@rmdir($this->base);
	}

	private function post($body)
	{
		$root = realpath(__DIR__.'/../../..');
		$xml = '<?xml version="1.0"?><methodResponse><params><param><value>'
			.'<array><data><value><i8>0</i8></value></data></array>'
			.'</value></param></params></methodResponse>';
		$response = 'Content-Length: '.strlen($xml)."\r\nContent-Type: text/xml\r\n\r\n".$xml;
		$this->peer = SCGITransportFixture::start($response);
		$driver = $this->base.'/driver.php';
		file_put_contents($driver, "<?php\n"
			.'$_ENV["RU_PROFILE_PATH"] = '.var_export($this->base.'/profile', true).";\n"
			.'require_once('.var_export($root.'/conf/config.php', true).");\n"
			// Keep this one-shot SCGI peer for the action; use the shipped 0.9.8 alias map.
			.'require_once('.var_export($root.'/php/settings.php', true).");\n"
			.'$settings = (new ReflectionClass("rTorrentSettings"))->newInstanceWithoutConstructor();'."\n"
			.'$settings->linkExist = true; $settings->version = "0.9.8"; $settings->iVersion = 0x0908;'."\n"
			.'$settings->aliases = array('.
				'"d.set_peer_exchange" => array("name" => "d.peer_exchange.set", "prm" => 0),'.
				'"d.set_connection_seed" => array("name" => "d.connection_seed.set", "prm" => 0));'."\n"
			.'(function($map) { require $map; })->call($settings, '.
				var_export($root.'/php/methods-0.9.4.php', true).");\n"
			.'$settings->store();'."\n"
			.'$scgi_host = "127.0.0.1";' . "\n"
			.'$scgi_port = '.$this->peer->port().";\n"
			.'$rpcTimeOut = 3;' . "\n"
			.'$log_file = '.var_export($this->base.'/refusals.log', true).";\n"
			.'$HTTP_RAW_POST_DATA = '.var_export($body, true).";\n"
			.'chdir('.var_export($root.'/plugins/httprpc', true).");\n"
			.'require('.var_export($root.'/plugins/httprpc/action.php', true).");\n");
		$output = array();
		exec(escapeshellarg(PHP_BINARY).' -d error_reporting=0 '
			.escapeshellarg($driver).' 2>&1', $output);
		return implode("\n", $output);
	}

	public function testUnlistedSetterIsRefusedBeforeTrustedRpc()
	{
		$out = $this->post('mode=setprops&hash='.self::HASH
			.'&s=directory&v='.rawurlencode($this->base.'/outside'));
		$this->assertTrue(strpos($out, 'Refused: unsupported property') !== false,
			'the request receives a classified refusal: '.$out);
		$this->assertTrue(!$this->peer->accepted(),
			'the unlisted setter never opens an SCGI connection');
		$this->assertTrue(strpos((string)@file_get_contents($this->base.'/refusals.log'),
			'httprpc: setprops refused: unsupported property') !== false,
			'the refusal is recorded with its classified reason');
	}

	public function testMixedBatchIsValidatedBeforeAnyRpc()
	{
		$out = $this->post('mode=setprops&hash='.self::HASH
			.'&s=peers_max&v=20&s=directory&v='.rawurlencode($this->base.'/outside'));
		$this->assertTrue(strpos($out, 'Refused: unsupported property') !== false,
			'the mixed request receives a classified refusal: '.$out);
		$this->assertTrue(!$this->peer->accepted(),
			'no setter is sent before the entire batch is validated');
	}

	public function testInvalidNumberIsRefusedBeforeTrustedRpc()
	{
		$out = $this->post('mode=setprops&hash='.self::HASH.'&s=peers_max&v=20xyz');
		$this->assertTrue(strpos($out, 'Refused: invalid property value') !== false,
			'the malformed value receives a classified refusal: '.$out);
		$this->assertTrue(!$this->peer->accepted(),
			'the malformed value never reaches rtorrent');
	}

	public function testShippedPropertiesStillReachRtorrent()
	{
		$out = $this->post('mode=setprops&hash='.self::HASH
			.'&s=peers_max&v=20&s=peers_min&v=5&s=tracker_numwant&v=-1'
			.'&s=ulslots&v=4&s=pex&v=1&s=superseed&v=1');
		$this->assertTrue(strpos($out, 'Refused:') === false,
			'the shipped property update is accepted: '.$out);
		$this->assertTrue($this->peer->accepted(),
			'the property batch opens an SCGI connection');
		$request = $this->peer->request();
		foreach(array('d.peers_max.set', 'd.peers_min.set',
			'd.tracker_numwant.set', 'd.uploads_max.set',
			'd.peer_exchange.set', 'branch') as $method)
			$this->assertTrue(strpos($request['payload'], $method) !== false,
				'the shipped property method reaches rtorrent: '.$method);
		$this->assertTrue(strpos($request['payload'], 'initial_seed') !== false,
			'superseed=1 selects the initial_seed branch');
	}

	public function testDisablingSuperseedUsesTheSeedBranch()
	{
		$this->post('mode=setprops&hash='.self::HASH.'&s=superseed&v=0');
		$request = $this->peer->request();
		$this->assertTrue(strpos($request['payload'], 'branch') !== false
			&& strpos($request['payload'], 'initial_seed') === false
			&& strpos($request['payload'], 'seed') !== false,
			'superseed=0 uses the normal seed branch');
	}

	public function testRecheckRejectsMixedHashBatchBeforeTrustedRpc()
	{
		$out = $this->post('mode=recheck&hash='.self::HASH.'&hash=not-a-hash');
		$this->assertTrue(strpos($out, 'Refused: missing or invalid torrent hash') !== false,
			'the mixed recheck receives a classified refusal: '.$out);
		$this->assertTrue(!$this->peer->accepted(),
			'no recheck starts before every hash is validated');
		$this->assertTrue(strpos((string)@file_get_contents($this->base.'/refusals.log'),
			'httprpc: recheck refused: missing or invalid torrent hash') !== false,
			'the refusal is recorded');
	}

	public function testRemoveRejectsEmptyHashBatchBeforeTrustedRpc()
	{
		$out = $this->post('mode=remove');
		$this->assertTrue(strpos($out, 'Refused: missing or invalid torrent hash') !== false,
			'the empty removal receives a classified refusal: '.$out);
		$this->assertTrue(!$this->peer->accepted(),
			'empty removal sends no trusted RPC');
		$this->assertTrue(strpos((string)@file_get_contents($this->base.'/refusals.log'),
			'httprpc: remove refused: missing or invalid torrent hash') !== false,
			'the refusal is recorded');
	}

	public function testRecheckAndRemoveKeepMultiHashDelivery()
	{
		foreach(array('recheck' => 'd.check_hash', 'remove' => 'd.erase') as $mode => $method)
		{
			$this->post('mode='.$mode.'&hash='.self::HASH.'&hash='.str_repeat('B', 40));
			$request = $this->peer->request();
			$this->assertTrue(strpos($request['header'], "UNTRUSTED_CONNECTION\0".'0') !== false,
				$mode.' uses the trusted server connection');
			$this->assertTrue(strpos($request['payload'], $method) !== false
				&& strpos($request['payload'], self::HASH) !== false
				&& strpos($request['payload'], str_repeat('B', 40)) !== false,
				$mode.' sends both requested hashes');
			$this->peer->close();
			$this->peer = null;
		}
	}
}
