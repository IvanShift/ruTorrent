<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/TorrentSequenceFixtures.php');
require_once(__DIR__ . '/../../php/rtorrent.php');

/** Directory identity is independent of whether the metainfo is new. */
class TorrentLoadDirectoryProvenanceTest extends TestCase
{
	use TorrentSequenceFixtures;

	private $previousSettings;
	private $previousTop;

	public function setUp()
	{
		$this->previousTop = $GLOBALS['topDirectory'];
		$GLOBALS['topDirectory'] = '/load-provenance-top/';
		$settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
		$settings->directory = '/load-provenance-top/';
		$settings->home = '/load-provenance-top/home';
		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		if(PHP_VERSION_ID < 80100) $property->setAccessible(true);
		$this->previousSettings = $property->getValue();
		$property->setValue(null, $settings);
	}

	public function tearDown()
	{
		$property = new ReflectionProperty('rTorrentSettings', 'theSettings');
		if(PHP_VERSION_ID < 80100) $property->setAccessible(true);
		$property->setValue(null, $this->previousSettings);
		$GLOBALS['topDirectory'] = $this->previousTop;
	}

	public function testNewMetainfoKeepsTheReportedDirectoryAndClearsOldSessionData()
	{
		$torrent = new Torrent($this->sessionTorrent());
		$prepared = rTorrent::preflightTorrentLoad($torrent, false, '~/existing ', false,
			true, null, $failure, null, true);
		$this->assertTrue(is_array($prepared), 'a reported path can accompany new metainfo');
		$this->assertSame('/load-provenance-top/home/existing ', $prepared['directory'],
			'the daemon path is expanded without interpreting its home marker as a typed name');
		$this->assertTrue(!isset($prepared['torrent']->rtorrent), 'new metainfo loses old session state');
		$this->assertTrue(!isset($prepared['torrent']->libtorrent_resume), 'new metainfo loses old resume state');
	}

	public function testReportedPathOutsideTheTopIsRefusedInsteadOfMoved()
	{
		$prepared = rTorrent::preflightTorrentLoad(new Torrent($this->announceOnlyTorrent()),
			false, '/outside/existing', false, true, null, $failure, null, true);
		$this->assertSame(false, $prepared, 'an existing path is never rebased into another directory');
		$this->assertSame('invalid-directory', $failure, 'preflight refuses before an erase');
		$this->assertTrue(rTorrent::refusedDirectory(), 'the directory refusal remains visible to callers');
	}

	public function testOmittedDirectoryUsesTheReportedDefaultWithoutRebasing()
	{
		rTorrentSettings::get()->directory = '/outside/default';
		$prepared = rTorrent::preflightTorrentLoad(new Torrent($this->announceOnlyTorrent()),
			false, '', false, true, null, $failure);
		$this->assertSame(false, $prepared, 'an omitted directory retains the daemon default identity');
		$this->assertSame('invalid-directory', $failure, 'an invalid default is reported, not relocated');
	}

	public function testReloadKeepsTheReportedContractWithoutAnExtraFlag()
	{
		$prepared = rTorrent::preflightTorrentLoad(new Torrent($this->sessionTorrent()),
			false, '~/existing ', false, false, null, $failure);
		$this->assertTrue(is_array($prepared), 'existing reload callers retain their directory contract');
		$this->assertSame('/load-provenance-top/home/existing ', $prepared['directory']);
		$this->assertTrue(isset($prepared['torrent']->rtorrent), 'a reload retains session metadata');
	}
}
