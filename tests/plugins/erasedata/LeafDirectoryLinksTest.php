<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/CollectorFixture.php');

// A filesystem that does not count subdirectory links: every directory reports
// st_nlink=1, as btrfs does and as the owner's Docker Desktop drvfs/9p volumes
// did on 2026-10-11. The suite's own filesystem cannot produce that metadata.
class ErasedataOneLinkDirectoryStream
{
	public static $tree = array();
	public $context;
	private $entries = array();
	private $position = 0;

	private static function node($path)
	{
		$path = rtrim($path, '/');
		return(isset(self::$tree[$path]) ? self::$tree[$path] : null);
	}

	public function url_stat($path, $flags)
	{
		$node = self::node($path);
		if($node === null) return(false);
		$type = $node['type'] === 'dir' ? 0040000 : 0100000;
		return(array('dev' => 1, 'ino' => crc32($path), 'mode' => $type | $node['mode'],
			'nlink' => $node['nlink'], 'uid' => $node['uid'], 'gid' => $node['uid'], 'rdev' => 0,
			'size' => $node['size'], 'atime' => 0, 'mtime' => 0, 'ctime' => 0,
			'blksize' => -1, 'blocks' => -1));
	}

	public function dir_opendir($path, $options)
	{
		$path = rtrim($path, '/');
		$node = self::node($path);
		if($node === null || $node['type'] !== 'dir') return(false);
		$this->entries = array('.', '..');
		foreach(array_keys(self::$tree) as $candidate)
			if(dirname($candidate) === $path) $this->entries[] = basename($candidate);
		$this->position = 0;
		return(true);
	}

	public function dir_readdir()
	{
		return($this->position < count($this->entries) ? $this->entries[$this->position++] : false);
	}

	public function dir_rewinddir()
	{
		$this->position = 0;
		return(true);
	}

	public function dir_closedir()
	{
		return(true);
	}
}

class LeafDirectoryLinksTest extends TestCase
{
	const UID = 4242;
	const ROOT = 'onelink://queue/.rutorrent-rename-probe-11682-06ade481acbaf577';

	public function setUp(): void
	{
		if(!in_array('onelink', stream_get_wrappers(), true))
			stream_wrapper_register('onelink', 'ErasedataOneLinkDirectoryStream');
	}

	private function workspace($directoryLinks)
	{
		$dir = function($nlink) { return(array('type' => 'dir', 'mode' => 0700, 'nlink' => $nlink, 'uid' => self::UID, 'size' => 4096)); };
		$file = array('type' => 'file', 'mode' => 0600, 'nlink' => 1, 'uid' => self::UID, 'size' => 0);
		ErasedataOneLinkDirectoryStream::$tree = array(
			self::ROOT => $dir(1),
			self::ROOT.'/.lock' => $file,
			self::ROOT.'/from-file' => $file,
			self::ROOT.'/to-file' => $file,
			self::ROOT.'/from-dir' => $dir($directoryLinks),
			self::ROOT.'/to-dir' => $dir($directoryLinks),
		);
	}

	public function testLeafDirectoryLinkCountRule()
	{
		$this->assertSame(true, erasedataLeafDirectoryLinks(2), 'ordinary Linux filesystems count . and the parent entry');
		$this->assertSame(true, erasedataLeafDirectoryLinks(1), 'btrfs and drvfs over 9p report one link');
		foreach(array(0, 3, '2', null) as $links)
			$this->assertSame(false, erasedataLeafDirectoryLinks($links), 'not a directory without subdirectories: '.var_export($links, true));
	}

	// The captured production residue: a probe that completed every rename and
	// collision check, left only because its cleanup demanded nlink=2.
	public function testProbeResidueOnOneLinkFilesystemIsRecognisedAsProbeWorkspace()
	{
		$this->workspace(1);
		$this->assertSame(true, erasedataRenameProbeWorkspaceValid(self::ROOT, self::UID),
			'one-link empty directories are the fixed probe entries, not unknown contents');
	}

	public function testProbeWorkspaceWithASubdirectoryLinkCountIsStillRefused()
	{
		$this->workspace(3);
		$this->assertSame(false, erasedataRenameProbeWorkspaceValid(self::ROOT, self::UID),
			'a directory link count that implies subdirectories is not a fixed probe entry');
	}

	public function testProbeWorkspaceWithANonEmptyOneLinkDirectoryIsRefused()
	{
		$this->workspace(1);
		ErasedataOneLinkDirectoryStream::$tree[self::ROOT.'/from-dir/payload'] =
			array('type' => 'file', 'mode' => 0600, 'nlink' => 1, 'uid' => self::UID, 'size' => 5);
		$this->assertSame(false, erasedataRenameProbeWorkspaceValid(self::ROOT, self::UID),
			'one link no longer proves emptiness, so the listing must still refuse contents');
	}
}
