<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/CollectorFixture.php');
require_once(__DIR__.'/../../php/SourceDefinitionFixture.php');
require_once(__DIR__.'/../../../plugins/erasedata/collector.php');

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
	private $temporary = null;

	public function setUp(): void
	{
		if(!in_array('onelink', stream_get_wrappers(), true))
			stream_wrapper_register('onelink', 'ErasedataOneLinkDirectoryStream');
	}

	public function tearDown(): void
	{
		if($this->temporary !== null) $this->removeTree($this->temporary);
		$this->temporary = null;
		ErasedataOneLinkDirectoryStream::$tree = array();
	}

	private function removeTree($path)
	{
		if(is_link($path) || is_file($path)) { unlink($path); return; }
		if(!is_dir($path)) return;
		foreach(array_diff(scandir($path), array('.', '..')) as $name)
			$this->removeTree($path.'/'.$name);
		rmdir($path);
	}

	private function completedCapturedRoot($signed)
	{
		if($this->temporary === null)
		{
			$this->temporary = sys_get_temp_dir().'/ed-leaf-'.bin2hex(random_bytes(6));
			mkdir($this->temporary, 0700);
			mkdir($this->temporary.'/queue', 0700);
			mkdir($this->temporary.'/payload', 0700);
		}
		$reservation = $signed ? $this->temporary.'/queue/reservation' : 'manifest-consumption';
		$public = $this->temporary.($signed ? '/payload/' : '/queue/').'public-'.bin2hex(random_bytes(6));
		file_put_contents($public, 'current public bytes');
		$root = erasedataCreateCapturedEntryRoot($public, $reservation, lstat($public), new ErasedataFilesystemOps());
		if(!is_string($root)) throw new RuntimeException('captured-root fixture refused');
		$marker = erasedataPrivateMarkerPath($root);
		$failure = new ErasedataCollectorFixture(array('unlink:*' => array('path' => $marker, 'result' => false)));
		if(erasedataRemoveCapturedEntryRoot($root, $failure)
			|| file_exists(erasedataCapturedEntryNamePath($root)) || !is_file($marker))
			throw new RuntimeException('the actual name-unlinked marker-retained teardown cut was not reached');
		return(array($root, $reservation, $public, $marker));
	}

	// Reuse the function-source seam used by NativeCompletionTest. Only the
	// root's lstat link count changes: signatures, marker reads, listings and
	// destructive operations use the real filesystem and the current function.
	private function discardCapturedRootWithLinks($root, $reservation, $links)
	{
		$namespace = 'ErasedataOneLinkCapturedRoot';
		$function = $namespace.'\\erasedataDiscardCompletedCapturedRoot';
		if(!function_exists($function))
		{
			eval('namespace '.$namespace.'; use \\ErasedataFilesystemOps; use \\ErasedataManifestCodec;'
				.'class StatCut { public static $root; public static $links; public static $calls; }'
				.'function lstat($path) { $stat=\\lstat($path);'
				.'if($path===StatCut::$root && is_array($stat)) { '
				.'$stat["nlink"]=is_array(StatCut::$links) ? array_shift(StatCut::$links) : StatCut::$links; StatCut::$calls++; }'
				.'return $stat; }'
				.loadFunctionDefinition(__DIR__.'/../../../plugins/erasedata/filesystem.php',
					'erasedataDiscardCompletedCapturedRoot'));
		}
		$state = $namespace.'\\StatCut';
		$state::$root = $root;
		$state::$links = $links;
		$state::$calls = 0;
		$result = $function($root, $reservation, new ErasedataFilesystemOps());
		return(array($result, $state::$calls));
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

	public function testCompletedCapturedRootOnOneLinkFilesystemRecoversItsExactMetadataShell()
	{
		foreach(array(false, true) as $signed)
		{
			list($root, $reservation, $public) = $this->completedCapturedRoot($signed);
			list($result, $reads) = $this->discardCapturedRootWithLinks($root, $reservation, 1);
			$this->assertSame(true, $result, 'one-link completed roots recover with the exact queue or payload marker');
			$this->assertTrue($reads >= 2, 'both admission and final reinspection observe the one-link root');
			$this->assertSame(false, erasedataPathExists($root), 'the completed root and marker are removed');
			$this->assertSame('current public bytes', file_get_contents($public), 'terminal metadata recovery preserves public bytes');
		}
	}

	public function testCompletedCapturedRootOnOneLinkFilesystemStillRefusesUnknownOrUnsafeState()
	{
		foreach(array(false, true) as $signed)
		{
			foreach(array('zero-links', 'subdirectory-links', 'late-links', 'payload', 'subdirectory', 'bridge',
				'marker-hardlink', 'marker-symlink', 'marker-mode', 'root-mode', 'marker-content', 'marker-size') as $case)
			{
				list($root, $reservation, $public, $marker) = $this->completedCapturedRoot($signed);
				$label = ($signed ? 'signed ' : 'manifest-consumption ').$case;
				$links = $case === 'zero-links' ? 0 : ($case === 'subdirectory-links' ? 3 : 1);
				if($case === 'late-links') $links = array(1, 2);
				if($case === 'payload') file_put_contents($root.'/entry', 'captured bytes');
				if($case === 'subdirectory') mkdir($root.'/unknown', 0700);
				if($case === 'bridge') symlink(basename($root).'/entry', $public.'-bridge');
				if($case === 'marker-hardlink') link($marker, $public.'-marker');
				if($case === 'marker-symlink')
				{
					rename($marker, $public.'-marker');
					symlink($public.'-marker', $marker);
				}
				if($case === 'marker-mode') chmod($marker, 0644);
				if($case === 'root-mode') chmod($root, 0755);
				// A signed marker needs the exact HMAC; the unsigned queue marker
				// must be empty. Neither may borrow the other format's contents.
				if($case === 'marker-content') file_put_contents($marker, str_repeat('0', 64));
				if($case === 'marker-size') file_put_contents($marker, $signed ? '' : 'x');
				$stored = file_get_contents($marker);
				list($result, $reads) = $this->discardCapturedRootWithLinks($root, $reservation, $links);
				if($case === 'late-links') $this->assertSame(2, $reads, $label.' reaches final reinspection');
				$this->assertSame(false, $result, $label.' still refuses completed-root recovery');
				$this->assertTrue(is_dir($root) && is_file($marker), $label.' retains the private root and marker');
				$this->assertSame($stored, file_get_contents($marker), $label.' preserves exact marker bytes');
				$this->assertSame('current public bytes', file_get_contents($public), $label.' preserves public bytes');
			}
		}
	}

	// V4-ERA-1: the queue's manifest-consumption marker is exactly empty. Even
	// the authentic HMAC of this very root under the queue's private key is the
	// other format and must not authenticate the unsigned shell.
	public function testManifestConsumptionRootRefusesAnAuthenticSignedMarker()
	{
		list($root, $reservation, $public, $marker) = $this->completedCapturedRoot(false);
		$this->assertSame('manifest-consumption', $reservation, 'the fixture builds the unsigned queue format');
		$signature = erasedataPrivateRootSignature($root, $this->temporary.'/queue/reservation', true);
		$this->assertTrue(is_string($signature) && strlen($signature) === 64, 'an authentic signature of this root exists');
		file_put_contents($marker, $signature);
		list($result, $reads) = $this->discardCapturedRootWithLinks($root, $reservation, 1);
		$this->assertSame(false, $result, 'an unsigned shell carrying a genuine signed marker is refused');
		$this->assertTrue(is_dir($root) && is_file($marker) && file_get_contents($marker) === $signature,
			'the private root and its exact marker bytes are retained');
		$this->assertSame('current public bytes', file_get_contents($public), 'public bytes are preserved');
	}
}
