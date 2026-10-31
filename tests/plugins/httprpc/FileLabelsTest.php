<?php

require_once(__DIR__.'/DisplayNamesFixture.php');

class FileLabelsTest extends TestCase
{
	use HttprpcDisplayTestFixture;

	private function files()
	{
		$name = "Dir\xFF";
		$hash = $this->torrent(array('name'=>$name, 'files'=>array(
			array('path'=>array("folder\xFF", "a\xFF.mkv"), 'path.utf-8'=>array('Сезон', 'Файл.mkv'), 'length'=>7),
			array('path'=>array('b.txt'), 'length'=>3))));
		$this->start(array($hash=>array('name'=>$name, 'label'=>'', 'base'=>'/data/'.$name, 'rate'=>'0',
			'files'=>array(array("folder\xFF/a\xFF.mkv", 7), array('b.txt', 3)), 'extra'=>array('plugin-one', 'plugin-two'))));
		return $hash;
	}

	// The claim is about metadata, so only its commands decide. The whole list is
	// in the message because hosted PHP 8.1 twice saw a list other than the exact one.
	private function assertListedWithoutMetadata($label)
	{
		$calls = $this->daemon->calls();
		$this->assertTrue(in_array('f.multicall', $calls, true) && !count(preg_grep('/session|tied/', $calls)),
			$label.': '.json_encode($calls));
	}

	public function testDisplayLabelsAreOptInAndNeverReplaceExistingCells()
	{
		$hash = $this->files();
		$query = 'mode=fls&hash='.$hash.'&cmd=f.priority%3D&cmd=f.priority%3D';
		$original = $this->post($query);
		$this->assertSame(7, count($original[0]), 'ordinary clients receive precisely their requested columns');
		$this->assertListedWithoutMetadata('ordinary clients trigger no metadata lookup');
		$labelled = $this->post($query.'&names=1');
		$this->assertSame($original[0], array_slice($labelled[0], 0, 7), 'every old cell and extra command stays in place');
		$this->assertSame(array('displayName'=>'Сезон/Файл.mkv', 'pathKey'=>'folder%FF/a%FF.mkv'), $labelled[0][7] ?? null, 'optional label follows all requested columns');
		$this->assertSame($original[1], $labelled[1], 'UTF-8 row needs no additional cell');
		$this->assertSame(true, is_string($labelled[0][0]), 'the original path remains a string');
	}

	public function testUnknownMetadataFallsBackToVisibleBytesWithoutChangingCells()
	{
		$hash = $this->files();
		unlink($this->base.'/session/'.$hash.'.torrent');
		$files = $this->post('mode=fls&hash='.$hash.'&cmd=f.priority%3D&cmd=f.priority%3D&names=1');
		$this->assertSame(array('displayName'=>'folder\\xFF/a\\xFF.mkv', 'pathKey'=>'folder%FF/a%FF.mkv'), $files[0][7] ?? null, 'missing optional source leaves visible byte escapes');
		$this->assertSame('plugin-two', $files[0][6], 'fallback cannot consume a plugin slot');
	}

	public function testUtf8AndDisabledLabelsKeepTheExistingWireFormat()
	{
		$hash = str_repeat('A', 40);
		$this->start(array($hash=>array('name'=>'Plain', 'label'=>'', 'base'=>'/data/Plain', 'rate'=>'0', 'files'=>array(array('映画.mkv', 7)))));
		$plain = $this->post('mode=fls&hash='.$hash.'&names=1');
		$this->assertSame(array(array('映画.mkv', '1', '1', '7', '1')), $plain, 'ordinary Unicode rows remain unchanged');
		$this->assertListedWithoutMetadata('ordinary Unicode needs no metadata call');
		$hash = $this->files();
		$off = $this->post('mode=fls&hash='.$hash.'&cmd=f.priority%3D&cmd=f.priority%3D&names=0');
		$this->assertSame(7, count($off[0]), 'an explicit disabled option keeps the old format');
		$this->assertListedWithoutMetadata('disabled names do not load source metadata');
	}
	public function testReaderEscapingIsUndoneOnlyForOptionalMetadataMatching()
	{
		// \\ and \" pairs: unescaping the daemon's bytes again would change the path.
		$directory = "Dir\xFF \"q\" \\b \\\\ \\\"";
		$leaf = "a\xFF.mkv";
		$hash = $this->torrent(array('name'=>'Root', 'files'=>array(array(
			'path'=>array($directory, $leaf), 'path.utf-8'=>array('Folder', 'Файл.mkv'), 'length'=>7))));
		$this->start(array($hash=>array('name'=>'Root', 'label'=>'', 'base'=>'/data/Root', 'rate'=>'0',
			'files'=>array(array($directory.'/'.$leaf, 7)))));
		$raw = $this->post('mode=fls&hash='.$hash);
		$labelled = $this->post('mode=fls&hash='.$hash.'&names=1');
		$this->assertSame($raw[0], array_slice($labelled[0], 0, 5), 'quotes and backslashes keep the original gateway spelling');
		$this->assertSame(array('displayName'=>'Folder/Файл.mkv', 'pathKey'=>'Dir%FF%20%22q%22%20%5Cb%20%5C%5C%20%5C%22/a%FF.mkv'), $labelled[0][5] ?? null,
			'the daemon\'s bytes, pairs included, attest the exact source file');
	}

	public function testOptionalSourceReadWarningDoesNotLeakIntoResponseOrLog()
	{
		list($hash, $legacy) = $this->legacy('', "raw\xFF", 'Alias');
		unlink($this->base.'/session/'.$hash.'.torrent');
		$legacy['tied'] = 'unreadable://source/'.$hash.'.torrent';
		$wrapper = <<<'PHP'
class UnreadableTorrentStream {
    public $context;
    public function url_stat($path, $flags) { return array('mode'=>0100644, 'uid'=>getmyuid(), 'gid'=>getmygid(), 'size'=>1); }
    public function stream_open($path, $mode, $options, &$opened_path) { return false; }
}
stream_wrapper_register('unreadable', 'UnreadableTorrentStream');
PHP;
		$this->start(array($hash=>$legacy), false, $wrapper);
		$files = $this->post('mode=fls&hash='.$hash.'&names=1');
		$this->assertSame(array('displayName'=>'raw\\xFF', 'pathKey'=>'raw%FF'), $files[0][5] ?? null, 'an actual source read failure leaves valid JSON and a byte label');
		$this->assertSame(false, strpos(file_get_contents($this->base.'/server.err'), 'file_get_contents'), 'an optional source read failure does not leak a PHP warning');
	}

}
