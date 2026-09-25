<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/settings.php');

class SettingsDirectoryTest extends TestCase
{
	private $previousTopDirectory;
	private $hadTopDirectory;

	public function setUp()
	{
		$this->hadTopDirectory = array_key_exists('topDirectory', $GLOBALS);
		$this->previousTopDirectory = $this->hadTopDirectory ? $GLOBALS['topDirectory'] : null;
		$GLOBALS['topDirectory'] = '/';
	}

	public function tearDown()
	{
		if ($this->hadTopDirectory)
			$GLOBALS['topDirectory'] = $this->previousTopDirectory;
		else
			unset($GLOBALS['topDirectory']);
	}

	private function settings($home)
	{
		$settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
		$settings->directory = '/downloads';
		$settings->home = $home;
		return $settings;
	}

	public function testUnknownHomeCannotRedirectTildePathToFilesystemRoot()
	{
		$path = '~/private';
		$this->assertTrue(!$this->settings('')->correctDirectory($path),
			'a tilde path with no known daemon home is refused');
		$this->assertEquals('~/private', $path,
			'a refused path is not changed to a different absolute path');
	}

	public function testKnownHomeStillExpandsTildePath()
	{
		$path = '~/private';
		$this->assertTrue($this->settings('/home/rtorrent')->correctDirectory($path),
			'a known daemon home permits the tilde path');
		$this->assertEquals('/home/rtorrent/private', $path,
			'the path expands against the daemon home');
	}

	public function testUnresolvedOrRelativeHomeCannotExpandTilde()
	{
		foreach (array('~', 'relative') as $home)
		{
			$path = '~/private';
			$this->assertTrue(!$this->settings($home)->correctDirectory($path),
				'an unresolved or relative daemon home is refused');
			$this->assertEquals('~/private', $path,
				'an unresolved daemon home does not change the requested path');
		}
	}

	public function testNamedUserTildeCannotBeJoinedToDaemonHome()
	{
		$path = '~another/private';
		$this->assertTrue(!$this->settings('/home/rtorrent')->correctDirectory($path),
			'a named user is not the daemon home');
		$this->assertEquals('~another/private', $path,
			'an unsupported named-user path is not rewritten');
	}

	public function testUnknownHomeCannotMakeRemoteTildeDefaultRelativeToWebServer()
	{
		$settings = $this->settings('');
		$settings->directory = '~/downloads';
		$path = 'private';
		$this->assertTrue(!$settings->correctDirectory($path),
			'a relative path with an unresolved daemon default is refused');
		$this->assertEquals('private', $path,
			'a refused relative path is not rewritten under the web process cwd');
	}

	public function testKnownHomeExpandsTildeDefaultForRelativePath()
	{
		$settings = $this->settings('/home/rtorrent');
		$settings->directory = '~/downloads';
		$path = 'private';
		$this->assertTrue($settings->correctDirectory($path),
			'a known daemon home resolves its default directory');
		$this->assertEquals('/home/rtorrent/downloads/private', $path,
			'the relative path stays relative to the daemon default');
	}

	public function testRelativePathStillUsesDaemonDefaultDirectory()
	{
		$path = 'private';
		$this->assertTrue($this->settings('')->correctDirectory($path),
			'a relative path does not require a known home');
		$this->assertEquals('/downloads/private', $path,
			'the path remains relative to the daemon default directory');
	}
}
