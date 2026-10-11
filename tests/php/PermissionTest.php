<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/SCGITransportFixture.php');
require_once(__DIR__ . '/../../php/utility/permission.php');

class PermissionTest extends TestCase
{
	protected $fixtureDir = null;
	protected $dirs = [];

	public function setUp()
	{
		// Not __DIR__ . '/fixtures': these directories were built inside the
		// repository, which left an untracked tests/php/fixtures in git status
		// after every run -- cleanDirs() removes what it made, never the parent
		// it made them in -- and, worse, gave two suite runs over one checkout
		// the same path to fight over. A run as root then owned the fixtures,
		// and the next run as an ordinary user failed on "unlink: Permission
		// denied" instead of on the posix extension it is actually missing,
		// which is a wrong answer that reads exactly like a real one.
		//
		// The pid and a unique suffix keep concurrent runs apart even inside
		// one temporary directory.
		$this->fixtureDir = sys_get_temp_dir() . '/rutorrent-permission-'
			. getmypid() . '-' . uniqid('', true);
		$this
			->addDir('dir1', '/dir1')
			->addDir('dir2', '/dir2')
			->addDir('dir3', '/dir1/dir3', $this->fixtureDir . '/dir2')
			->addDir('dir4', '/dir1/dir4', '../dir2')
			->createDirs();
	}

	public function tearDown()
	{
		$this->cleanDirs();
		// The directory this run made is this run's to remove. rmdir() only
		// succeeds on an empty one, so anything cleanDirs() could not remove is
		// left in place to be seen rather than silently discarded.
		if (is_dir($this->fixtureDir)) @rmdir($this->fixtureDir);
	}

	protected function addDir($name, $dir, $symlink = null)
	{
		$this->dirs[$name] = [$this->fixtureDir . $dir, $symlink];

		return $this;
	}

	protected function getDir($name)
	{
		return isset($this->dirs[$name]) ? $this->dirs[$name][0] : null;
	}

	protected function createDirs()
	{
		if (!is_dir($this->fixtureDir)) mkdir($this->fixtureDir, 0777, true);
		foreach ($this->dirs as [$dir, $symlink]) {
			if ($symlink) {
				if (is_link($dir)) {
					unlink($dir);
				}
				symlink($symlink, $dir);
			} else {
				if (!is_dir($dir)) {
					mkdir($dir, 0777, true);
				}
			}
		}

		return $this;
	}

	protected function cleanDirs()
	{
		foreach (array_reverse($this->dirs) as [$dir, $symlink]) {
			if ($symlink) {
				if (is_link($dir)) {
					unlink($dir);
				}
			} else {
				if (is_dir($dir)) {
					rmdir($dir);
				}
			}
		}

		return $this;
	}

	public function testEffectiveUidDescribesTheRunningProcess()
	{
		$uid = Permission::effectiveUid();
		if (function_exists('posix_geteuid')) {
			$this->assertSame(posix_geteuid(), $uid, 'the effective process UID owns cleanup work');
		} else {
			$status = @file_get_contents('/proc/self/status');
			$expected = is_string($status) && preg_match('/^Uid:[ \t]*[0-9]+[ \t]+([0-9]+)/m', $status, $match)
				? (int)$match[1] : false;
			$this->assertSame($expected, $uid, 'Linux supplies the effective UID without ext-posix');
		}
	}

	public function testEffectiveUidWithoutOptionalExtensions()
	{
		$script = 'require '.var_export(realpath(__DIR__.'/../../php/utility/permission.php'), true).';'
			.'echo json_encode(array(Permission::effectiveUid(), function_exists("posix_geteuid")));';
		$child = SCGITransportFixture::runPhpChild(array('-n', '-r', $script));
		$this->assertSame(0, $child['exitCode'], 'the UID leaf loads without filesystem dependencies: '.$child['stderr']);
		$this->assertSame('', $child['stderr'], 'the UID leaf emits no diagnostics');
		$result = json_decode($child['stdout'], true);
		$this->assertTrue(is_array($result) && count($result) === 2, 'the child reports its actual transport-free UID');
		$this->assertSame(Permission::effectiveUid(), $result[0] ?? null,
			'the no-extensions child retains the effective UID of its parent');
		if (!empty($result[1])) echo "NOT CHECKED: ext-posix is built into this PHP; no-posix fallback needs a shared-extension build\n";
	}

	public function testOwnerPermissionsDoNotFallThroughToGroupOrOther()
	{
		$uid = Permission::effectiveUid();
		if ($uid === false || $uid === 0) {
			echo "NOT CHECKED: owner permission precedence needs a known non-root process UID\n";
			return;
		}
		$file = $this->fixtureDir . '/permission-class';
		file_put_contents($file, 'owned');
		foreach (array(0004, 0040, 0044) as $mode) {
			chmod($file, $mode);
			clearstatcache(true, $file);
			$this->assertSame(false, Permission::doesUserHave($uid, array(filegroup($file)), $file, 4),
				'the owner cannot acquire read permission from another permission class');
		}
		chmod($file, 0400);
		clearstatcache(true, $file);
		$this->assertSame(true, Permission::doesUserHave($uid, array(filegroup($file)), $file, 4),
			'the owner read bit still grants access');
		unlink($file);
	}

	public function testMatchingGroupPermissionsDoNotFallThroughToOther()
	{
		$file = $this->fixtureDir . '/group-permission-class';
		file_put_contents($file, 'owned');
		$foreignUid = fileowner($file) === 12345 ? 12346 : 12345;
		chmod($file, 0004);
		clearstatcache(true, $file);
		$this->assertSame(false, Permission::doesUserHave($foreignUid, array(filegroup($file)), $file, 4),
			'a matching group selects its bits even when others can read');
		chmod($file, 0040);
		clearstatcache(true, $file);
		$this->assertSame(true, Permission::doesUserHave($foreignUid, array(filegroup($file)), $file, 4),
			'the matching group read bit grants access');
		unlink($file);
	}

	public function testBareRelativeSymlinkUsesItsOwnDirectory()
	{
		$file = $this->fixtureDir . '/relative-leaf';
		$link = $this->fixtureDir . '/relative-link';
		file_put_contents($file, 'owned');
		symlink('relative-leaf', $link);
		try {
			$this->assertSame('owned', file_get_contents($link), 'the real relative symlink resolves');
			$this->assertSame(true, Permission::doesUserHave(Permission::effectiveUid(), array(filegroup($file)), $link, 4),
				'a bare relative target resolves beside the link rather than in PHP cwd');
		} finally {
			unlink($link);
			unlink($file);
		}
	}

	public function testPermission()
	{
		// doesUserHave() answers "would this uid/gids have rwx on the file" from
		// the file's stat. Probe with the running process's own uid/gids -- it
		// owns the fixtures -- rather than a hardcoded 1000, so the test does not
		// depend on which uid runs it.
		$uid = posix_getuid();
		$gids = array_merge([posix_getgid()], posix_getgroups());
		// A symlink target is resolved against the link's own directory, so a
		// fixture built from a relative base points nowhere and every answer
		// below would be about a broken link rather than about permissions.
		$this->assertTrue(is_dir($this->getDir('dir3')), 'Symlinked fixture resolves');
		$this->assertTrue(is_dir($this->getDir('dir4')), 'Relative symlinked fixture resolves');
		$this->assertTrue(Permission::doesUserHave($uid, $gids, $this->getDir('dir1'), 0x0007), 'User has permission for directory');
		$this->assertTrue(Permission::doesUserHave($uid, $gids, $this->getDir('dir3'), 0x0007), 'User has permission for symlinked directory');
		$this->assertTrue(Permission::doesUserHave($uid, $gids, $this->getDir('dir4'), 0x0007), 'User has permission for relative symlinked directory');
	}
}
