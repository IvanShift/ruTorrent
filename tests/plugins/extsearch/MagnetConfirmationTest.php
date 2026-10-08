<?php

$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir().'/rutorrent-extsearch-magnet-'.getmypid();
$_SERVER['REMOTE_USER'] = 'extsearch_magnet_test';
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__.'/../../../php/rtorrent.php');
require_once(__DIR__.'/../../../plugins/extsearch/engines.php');

/**
 * A magnet added from the search results is answered as added only once
 * rTorrent shows its stub (rTorrent::magnetShown(), R4-NF-1).
 *
 * rTorrent drops some malformed magnets without making a stub, and the load
 * answers 0 all the same. engineManager::getTorrents() used to answer such a
 * magnet with its hash and write it into the search history as loaded, so the
 * result list showed it as added and offered no retry. Now it is pending (the
 * UI's null) and the history does not record it, so it can be added again.
 */
class ExtsearchMagnetConfirmationTest extends TestCase
{
	const HASH = '3333333333333333333333333333333333333333';

	private $daemon;
	private $settingsProperty;
	private $settingsBefore;
	private $globalsBefore = array();
	private $log;

	public function setUp()
	{
		$this->settingsProperty = self::makeAccessible(
			new ReflectionProperty('rTorrentSettings', 'theSettings'));
		$this->settingsBefore = $this->settingsProperty->getValue();
		$settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
		$settings->iVersion = 0x1018;
		$settings->aliases = array();
		$settings->plugins = array();
		$settings->directory = '/';
		$settings->linkExist = true;
		$this->settingsProperty->setValue(null, $settings);
		foreach(array('scgi_host', 'scgi_port', 'log_file', 'rpcTimeOut') as $name)
			$this->globalsBefore[$name] = array_key_exists($name, $GLOBALS) ? $GLOBALS[$name] : null;
		$this->log = tempnam(sys_get_temp_dir(), 'extsearch-magnet-log-');
		$GLOBALS['log_file'] = $this->log;
		$GLOBALS['rpcTimeOut'] = 10;
		$history = engineManager::loadHistory();
		$history->lst = array();
		$history->changed = true;
		engineManager::saveHistory($history);
	}

	public function tearDown()
	{
		if($this->daemon !== null)
		{
			$this->daemon->stop();
			$this->daemon = null;
		}
		foreach($this->globalsBefore as $name => $value)
		{
			if($value === null) unset($GLOBALS[$name]);
			else $GLOBALS[$name] = $value;
		}
		@unlink($this->log);
		$settings = FileUtil::getSettingsPath();
		foreach(glob($settings.'/*') ?: array() as $entry)
			is_dir($entry) ? @rmdir($entry) : @unlink($entry);
		@unlink($_ENV['RU_PROFILE_PATH'].'/calls.log');
		@unlink($_ENV['RU_PROFILE_PATH'].'/calls.log.bodies');
		@unlink($_ENV['RU_PROFILE_PATH'].'/calls.log.server.err');
		$this->settingsProperty->setValue(null, $this->settingsBefore);
	}

	public function tearDownClass()
	{
		@rmdir(FileUtil::getSettingsPath());
		@rmdir(FileUtil::getProfilePath().'/torrents');
		@rmdir(FileUtil::getProfilePath().'/tmp');
		@rmdir(FileUtil::getProfilePath());
		@rmdir($_ENV['RU_PROFILE_PATH'].'/users');
		@rmdir($_ENV['RU_PROFILE_PATH']);
	}

	/** Add one magnet from the search results against a daemon described by $daemon. */
	private function add($magnet, $daemon = array())
	{
		@mkdir($_ENV['RU_PROFILE_PATH'], 0700, true);
		$this->daemon = new FakeRtorrentDaemon(
			new FakeRtorrentAddTorrentReplies(array(), array(), false, $daemon),
			$_ENV['RU_PROFILE_PATH'].'/calls.log');
		$GLOBALS['scgi_host'] = '127.0.0.1';
		$GLOBALS['scgi_port'] = $this->daemon->port();
		$manager = new engineManager();
		return $manager->getTorrents(array('any'), array($magnet), true, true, '', '', false);
	}

	public function testADroppedMagnetIsPendingAndNotRecordedAsLoaded()
	{
		$magnet = 'magnet:?xt=urn:btih:'.self::HASH.'&dn';
		$answer = $this->add($magnet, array('dropMagnets' => array(self::HASH)));
		$this->assertSame(array(null), $answer,
			'a magnet whose stub never shows is pending, not added: '.json_encode($answer));
		$this->assertSame('', engineManager::loadHistory()->getHash($magnet),
			'and the history does not record it as loaded, so it can be added again');
		$this->assertTrue(strpos((string)file_get_contents($this->log),
			'extsearch: magnet unconfirmed: '.self::HASH) !== false,
			'the unconfirmed magnet is named in the log');
	}

	public function testAMagnetWithAStubIsAddedAndRecorded()
	{
		$magnet = 'magnet:?xt=urn:btih:'.strtolower(self::HASH).'&dn=found';
		$answer = $this->add($magnet, array('stubAfter' => 2));
		$this->assertSame(array(self::HASH), $answer,
			'a stub that shows within the window is an addition: '.json_encode($answer));
		$this->assertSame(self::HASH, engineManager::loadHistory()->getHash($magnet),
			'and the history records its hash');
		$this->assertTrue(in_array('load_start', $this->daemon->calls(), true)
			|| in_array('load.start', $this->daemon->calls(), true),
			'the magnet was sent: '.json_encode($this->daemon->calls()));
	}
}
