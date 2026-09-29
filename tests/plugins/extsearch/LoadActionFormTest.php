<?php

require_once(__DIR__.'/../../php/TestCase.php');

class ExtsearchLoadActionFormTest extends TestCase
{
	private $root;

	public function setUp()
	{
		$this->root = sys_get_temp_dir().'/rutorrent-extsearch-form-'
			.getmypid().'-'.bin2hex(random_bytes(4));
		mkdir($this->root.'/plugins/extsearch', 0700, true);
		mkdir($this->root.'/php', 0700, true);
		copy(__DIR__.'/../../../plugins/extsearch/action.php',
			$this->root.'/plugins/extsearch/action.php');
		$utility = var_export(__DIR__.'/../../../php/utility/utility.php', true);
		file_put_contents($this->root.'/php/util.php', '<?php '
			.'require_once('.$utility.'); '
			.'class Requests { public static function requirePost() { '
			.'if($_SERVER["REQUEST_METHOD"] !== "POST") exit("wrong method"); }} '
			.'class CachedEcho { public static function send($body, $type) { '
			.'echo $body; exit; }} '
			.'class JSON { public static function safeEncode($value) { return json_encode($value); }} '
			.'class FileUtil { public static function toLog($message) { file_put_contents('.var_export($this->root.'/log.txt', true).', $message); }}');
		file_put_contents($this->root.'/php/rtorrent.php', '<?php');
		$capture = var_export($this->root.'/args.json', true);
		file_put_contents($this->root.'/plugins/extsearch/engines.php', '<?php '
			.'class engineManager { public static function load() { return new self(); } '
			.'public function getTorrents($engs,$urls,$start,$addPath,$dir,$label,$fast) { '
			.'file_put_contents('.$capture.', json_encode(array("engs"=>$engs, '
			.'"urls"=>$urls,"start"=>$start,"addPath"=>$addPath,"dir"=>$dir, '
			.'"label"=>$label,"fast"=>$fast))); '
			.'return array_fill(0,count($urls),null); }}');
	}

	public function tearDown()
	{
		foreach(array('args.json','bootstrap.php','log.txt') as $file) @unlink($this->root.'/'.$file);
		foreach(array('action.php','engines.php') as $file)
			@unlink($this->root.'/plugins/extsearch/'.$file);
		@unlink($this->root.'/php/util.php');
		@unlink($this->root.'/php/rtorrent.php');
		@rmdir($this->root.'/plugins/extsearch');
		@rmdir($this->root.'/plugins');
		@rmdir($this->root.'/php');
		@rmdir($this->root);
	}

	public function testOrderedFormPairsPreserveEqualsPlusAndIgnoreMissingValues()
	{
		$body = 'mode=loadtorrents&dir_edit=A=B&label=x+y%2Bz'
			.'&url=https://example.invalid/file?a=b+c%2Bd&eng=Example&ndx=7'
			.'&url=https%3A%2F%2Fexample.invalid%2Fsecond%3Fx%3D1%3D2'
			.'&eng=Example&ndx=8&url&label';
		file_put_contents($this->root.'/bootstrap.php', '<?php '
			.'$_SERVER["REQUEST_METHOD"]="POST"; $_REQUEST["mode"]="loadtorrents"; '
			.'$HTTP_RAW_POST_DATA='.var_export($body,true).';');
		$process = proc_open(array(PHP_BINARY,
			'-d', 'auto_prepend_file='.$this->root.'/bootstrap.php',
			'-f', $this->root.'/plugins/extsearch/action.php'),
			array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),
				2=>array('pipe','w')), $pipes, $this->root.'/plugins/extsearch');
		$this->assertTrue(is_resource($process), 'load action child starts');
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), 'load action exits cleanly: '.$error);
		$this->assertSame('', $error, 'missing value segments produce no warnings');
		$args = json_decode((string)@file_get_contents($this->root.'/args.json'), true);
		$this->assertSame(array('https://example.invalid/file?a=b c+d',
			'https://example.invalid/second?x=1=2'), $args['urls'] ?? null,
			'ordered URLs preserve raw equals and decode raw plus only once');
		$this->assertSame(array('Example','Example'), $args['engs'] ?? null,
			'duplicate engine fields stay aligned with URL order');
		$this->assertSame('A=B', $args['dir'] ?? null,
			'raw equals in directory survives the first delimiter');
		$this->assertSame('x y+z', $args['label'] ?? null,
			'form plus and encoded plus keep distinct meanings');
		$this->assertSame(array('teg'=>'','data'=>array(
			array('hash'=>null,'ndx'=>'7'), array('hash'=>null,'ndx'=>'8'))),
			json_decode($output,true), 'ordered indices align with both URLs');
	}

	public function testMalformedParallelFieldsAreRejectedBeforeLoad()
	{
		$body = 'mode=loadtorrents&url=https%3A%2F%2Fexample.invalid%2Fone&ndx=3';
		file_put_contents($this->root.'/bootstrap.php', '<?php '
			.'$_SERVER["REQUEST_METHOD"]="POST"; $_REQUEST["mode"]="loadtorrents"; '
			.'$HTTP_RAW_POST_DATA='.var_export($body,true).';');
		$process = proc_open(array(PHP_BINARY,
			'-d', 'auto_prepend_file='.$this->root.'/bootstrap.php',
			'-f', $this->root.'/plugins/extsearch/action.php'),
			array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),
				2=>array('pipe','w')), $pipes, $this->root.'/plugins/extsearch');
		$this->assertTrue(is_resource($process), 'malformed load child starts');
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), 'malformed load exits cleanly: '.$error);
		$this->assertSame('', $error, 'malformed parallel fields produce no warnings');
		$this->assertSame(array('error'=>'invalid request'), json_decode($output, true),
			'missing engine rejects the entire batch before loading');
		$this->assertTrue(!file_exists($this->root.'/args.json'),
			'malformed load never calls the torrent manager');
		$this->assertTrue(strpos((string)@file_get_contents($this->root.'/log.txt'),
			'extsearch: load refused: parallel fields mismatch') !== false,
			'the refusal has a classified log reason');
	}
}
