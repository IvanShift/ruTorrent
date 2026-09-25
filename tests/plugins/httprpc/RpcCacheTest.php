<?php

// The cache constructor creates a profile directory before useDirectory().
$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir().'/rpccache-profile-'.getmypid().'-'.bin2hex(random_bytes(4));

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../../plugins/httprpc/rpccache.php');

// The cache keeps its state files in a directory taken from the profile path.
// Point it at a scratch directory, where a test can remove state files
// to stand in for an evicted cache.
class TestRpcCache extends rpcCache
{
	public function useDirectory($dir)
	{
		$this->dir = $dir;
		@mkdir($dir, 0777, true);
	}
}

class RpcCacheTest extends TestCase
{
	private $dir;
	private $cache;
	private $profile;

	public function setUp()
	{
		$this->profile = $_ENV['RU_PROFILE_PATH'];
		$this->dir = $this->profile.'/test-cache';
		$this->cache = new TestRpcCache();
		$this->cache->useDirectory($this->dir);
	}

	public function tearDown()
	{
		if (is_dir($this->profile)) {
			FileUtil::deleteDirectory($this->profile);
		}
	}

	public function testConstructorKeepsItsDirectoryInTheScratchProfile()
	{
		$this->assertEquals($this->profile.'/settings/httprpc',
			FileUtil::getSettingsPath().'/httprpc',
			'The constructor uses the isolated profile before useDirectory');
		$this->assertEquals(true, is_dir($this->profile.'/settings/httprpc'),
			'The constructor created its directory under the scratch profile');
	}

	private function torrents($hashes)
	{
		$ret = array();
		foreach($hashes as $hash)
			$ret[$hash] = array("1", "name of ".$hash, "0");
		return($ret);
	}

	private function difference($cid, $hashes)
	{
		$torrents = $this->torrents($hashes);
		$deleted = array();
		$hadPrevious = $this->cache->calcDifference($cid, $torrents, $deleted);
		return(array("cid"=>$cid, "changed"=>$torrents, "deleted"=>$deleted, "hadPrevious"=>$hadPrevious));
	}

	public function testFirstAnswerIsFullAndSaysSo()
	{
		$first = $this->difference(0, array("A", "B"));
		$this->assertTrue(!$first["hadPrevious"], 'nothing to diff against on the first request');
		$this->assertEquals(array("A","B"), array_keys($first["changed"]), 'the whole list is answered');
		$this->assertEquals(array(), $first["deleted"], 'and it carries no deletions');
	}

	public function testDeletionIsReportedAgainstThePreviousState()
	{
		$first = $this->difference(0, array("A", "B"));
		$second = $this->difference($first["cid"], array("A"));
		$this->assertTrue($second["hadPrevious"], 'the previous state was found');
		$this->assertEquals(array("B"), $second["deleted"], 'B is reported as deleted');
	}

	// The state files are a ring of ten, so a client that polls slowly while
	// another one polls fast can come back with a cid that is gone -- as can any
	// client if the directory cannot be written at all.
	public function testALostStateIsAnnouncedAsFullRatherThanLosingTheDeletion()
	{
		$first = $this->difference(0, array("A", "B"));
		foreach(glob($this->dir.'/*') as $f)
			@unlink($f);

		$second = $this->difference($first["cid"], array("A"));

		$this->assertTrue(!$second["hadPrevious"], 'the state the client named is gone');
		$this->assertEquals(array(), $second["deleted"], 'so no deletion can be derived');
		$this->assertEquals(array("A"), array_keys($second["changed"]),
			'and the answer is the whole list, which is what lets the client drop B itself');
	}
}
