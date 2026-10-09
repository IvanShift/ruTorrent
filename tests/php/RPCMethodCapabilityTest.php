<?php

require_once(__DIR__.'/TestCase.php');
require_once(__DIR__.'/../../php/xmlrpc.php');
require_once(__DIR__.'/FakeRtorrentDaemon.php');

class RPCMethodCapabilityTest extends TestCase
{
	private $directory;
	private $settingsProperty;
	private $previousSettings;
	private $daemon;
	private $globals = array();

	public function setUp()
	{
		$this->directory = sys_get_temp_dir().'/rpc-capability-'.getmypid();
		mkdir($this->directory, 0700);
		$this->settingsProperty = self::makeAccessible(new ReflectionProperty('rTorrentSettings', 'theSettings'));
		$this->previousSettings = $this->settingsProperty->getValue();
		$settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
		$settings->iVersion = 0x1019; $settings->apiVersion = 11; $settings->aliases = array();
		$this->settingsProperty->setValue(null, $settings);
		foreach(array('scgi_host', 'scgi_port', 'rpcTimeOut', 'rpcLogCalls', 'rpcLogFaults', 'log_file') as $key)
			$this->globals[$key] = array_key_exists($key, $GLOBALS) ? array(true, $GLOBALS[$key]) : array(false, null);
		$GLOBALS['scgi_host'] = '127.0.0.1';
		$GLOBALS['rpcTimeOut'] = 3;
		$GLOBALS['rpcLogCalls'] = false;
		$GLOBALS['rpcLogFaults'] = false;
		$GLOBALS['log_file'] = $this->directory.'/app.log';
	}

	public function tearDown()
	{
		if($this->daemon !== null) $this->daemon->stop();
		$this->settingsProperty->setValue(null, $this->previousSettings);
		foreach($this->globals as $key => $value)
			if($value[0]) $GLOBALS[$key] = $value[1]; else unset($GLOBALS[$key]);
		foreach(glob($this->directory.'/*') ?: array() as $file) unlink($file);
		rmdir($this->directory);
	}

	private function serve($reply)
	{
		if($this->daemon !== null) $this->daemon->stop();
		$this->daemon = new FakeRtorrentDaemon(array($reply), $this->directory.'/daemon.log');
		$GLOBALS['scgi_port'] = $this->daemon->port();
	}

	public function testKnownListsExposeTheWholeValidatedSnapshotIncludingPartialSupport()
	{
		$list = array('system.listMethods', 'd.autotools.move.enqueue', 'execute.argv');
		$this->serve($list);
		$available = null;
		$this->assertSame('available', rpcMethodCapability(array('execute.argv'), $available), 'a known complete ABI is supported');
		$this->assertSame($list, $available, 'the same single read exposes all validated names');
		$this->assertSame(array('system.listMethods'), $this->daemon->calls(), 'capability consumers share one daemon read');
		$this->serve(array('system.listMethods', '123', '123'));
		$this->assertSame('available', rpcMethodCapability(array('123'), $available), 'numeric command names use the same command-name owner');
		$this->assertSame(array('system.listMethods', '123'), $available, 'deduplication retains exact string method names');
		$this->serve($list);
		$this->assertSame('unsupported', rpcMethodCapability(array('d.autotools.move.enqueue', 'd.stop_lease.acquire'), $available),
			'a partial native ABI stays unsupported');
		$this->assertSame($list, $available, 'the complete known list allows a caller to distinguish partial native from absent native');
		$this->serve(array('system.listMethods', 'execute.argv'));
		$this->assertSame('unsupported', rpcMethodCapability(array('d.autotools.move.enqueue'), $available), 'known absence remains unsupported');
		$this->assertSame(array('system.listMethods', 'execute.argv'), $available, 'known absent native methods can select the upstream path');
	}

	public function testMalformedOrUnknownRepliesNeverPublishAPartialSnapshot()
	{
		foreach(array(array('system.listMethods', 'd.autotools.move.enqueue', ''),
			array('system.listMethods', 'bad name'), array('execute.argv'), array(), null,
			new FakeRtorrentRawReply('<methodResponse><params><param><value><string>system.listMethods</string></value></param></params></methodResponse>'),
			new FakeRtorrentRawReply('<methodResponse><params><param><value><array><data><value><array><data><value><string>system.listMethods</string></value></data></array></value></data></array></value></param></params></methodResponse>'),
			new FakeRtorrentRawReply('<methodResponse><params><param><value><struct><member><name>anything</name><value><string>system.listMethods</string></value></member></struct></value></param></params></methodResponse>'),
			new FakeRtorrentRawReply('<methodResponse><params><param><value><struct><member><name>0</name><value><string>system.listMethods</string></value></member></struct></value></param></params></methodResponse>'),
			new FakeRtorrentRawReply('<methodResponse>')) as $reply)
		{
			$this->serve($reply);
			$available = array('stale-actor-snapshot');
			$this->assertSame('unconfirmed', rpcMethodCapability(array('d.autotools.move.enqueue'), $available),
				'unknown or malformed lists cannot decide a fallback');
			$this->assertSame(null, $available, 'no stale or partially validated method list survives');
		}
	}

	public function testEveryCallReadsItsOwnCurrentSnapshotAndOldCallersRemainCompatible()
	{
		$this->serve(array('system.listMethods', 'execute.argv'));
		$this->assertSame('available', rpcMethodCapability(array('execute.argv')), 'the original one-argument signature stays available');
		$this->serve(array('system.listMethods'));
		$available = null;
		$this->assertSame('unsupported', rpcMethodCapability(array('execute.argv'), $available), 'a later actor gets a fresh read');
		$this->assertSame(array('system.listMethods'), $available, 'no earlier capability snapshot is cached');
	}
}
