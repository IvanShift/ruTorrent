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
	public function testTopDirectoryPrefixDoesNotAllowSiblingDirectory()
	{
		$root = sys_get_temp_dir().'/rutorrent-settings-prefix-'.bin2hex(random_bytes(6));
		mkdir($root, 0700);
		mkdir($root.'/allowed', 0700);
		mkdir($root.'/allowed-extra', 0700);
		$GLOBALS['topDirectory'] = $root.'/allowed';
		try
		{
			$path = $root.'/allowed-extra';
			$this->assertTrue(!$this->settings('')->correctDirectory($path, true),
				'a sibling with the same string prefix is outside topDirectory');
			$inside = $root.'/allowed';
			$this->assertTrue($this->settings('')->correctDirectory($inside, true),
				'topDirectory itself remains valid');
		}
		finally
		{
			rmdir($root.'/allowed-extra');
			rmdir($root.'/allowed');
			rmdir($root);
		}
	}

	public function testPhysicalDirectoryCheckRejectsSymlinkEscapeAndAllowsMissingChild()
	{
		$root = sys_get_temp_dir().'/rutorrent-settings-dir-'.bin2hex(random_bytes(6));
		mkdir($root, 0700);
		mkdir($root.'/allowed', 0700);
		mkdir($root.'/outside', 0700);
		symlink($root.'/outside', $root.'/allowed/link');
		$GLOBALS['topDirectory'] = $root.'/allowed/';
		try
		{
			$settings = $this->settings('');
			$outside = $root.'/allowed/link/missing/child';
			$this->assertTrue(!$settings->correctDirectory($outside, true),
				'a missing child below an outside symlink is refused');
			$inside = $root.'/allowed/new/missing/child';
			$this->assertTrue($settings->correctDirectory($inside, true),
				'nested missing descendants under the allowed root remain valid');
			$this->assertSame($root.'/allowed/new/missing/child', $inside,
				'the missing destination keeps its canonical allowed ancestry');
		}
		finally
		{
			unlink($root.'/allowed/link');
			rmdir($root.'/outside');
			rmdir($root.'/allowed');
			rmdir($root);
		}
	}

	public function testPhysicalCheckAcceptsAliasedTopAndPickerPathPassesLexicalConsumer()
	{
		$root = sys_get_temp_dir().'/rutorrent-settings-alias-'.bin2hex(random_bytes(6));
		mkdir($root.'/physical/inside', 0700, true);
		symlink($root.'/physical', $root.'/logical');
		$GLOBALS['topDirectory'] = $root.'/logical/';
		try
		{
			$settings = $this->settings('');
			$path = $root.'/logical/inside';
			$this->assertTrue($settings->correctDirectory($path, true),
				'a physical check resolves an aliased top as well as the requested directory');
			$this->assertSame($root.'/physical/inside', $path,
				'existing physical-path callers still receive the resolved directory');
			$this->assertTrue($settings->correctDirectory($path, true, true),
				'the picker can map a physical default to the configured top spelling');
			$this->assertSame($root.'/logical/inside', $path,
				'the picker returns the configured logical top');
			$this->assertTrue($settings->correctDirectory($path),
				'the returned picker path passes the same lexical boundary as addtorrent');
		}
		finally
		{
			unlink($root.'/logical');
			rmdir($root.'/physical/inside');
			rmdir($root.'/physical');
			rmdir($root);
		}
	}

	/**
	 * An empty $topDirectory has no spelling to preserve. realpath('') is the
	 * working directory, so the physical check resolved the empty top to it,
	 * and the picker mapping then cut that prefix off: a directory under it
	 * came back at the filesystem root, and the top itself as "/".
	 */
	public function testPickerWithAnEmptyTopKeepsThePhysicalPath()
	{
		$root = realpath(sys_get_temp_dir()).'/rutorrent-settings-empty-top-'.bin2hex(random_bytes(6));
		mkdir($root.'/inside', 0700, true);
		$cwd = getcwd();
		$GLOBALS['topDirectory'] = '';
		try
		{
			chdir($root);
			$settings = $this->settings('');
			$path = $root.'/inside';
			$this->assertTrue($settings->correctDirectory($path, true, true),
				'the directory passes the physical check');
			$this->assertSame($root.'/inside', $path,
				'the picker returns the directory it approved, not one at the root');
			$path = $root;
			$this->assertTrue($settings->correctDirectory($path, true, true),
				'the resolved top itself passes too');
			$this->assertSame($root, $path, 'and keeps its own path rather than "/"');
		}
		finally
		{
			chdir($cwd);
			rmdir($root.'/inside');
			rmdir($root);
		}
	}
}
