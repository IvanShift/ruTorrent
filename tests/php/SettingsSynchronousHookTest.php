<?php

require_once(__DIR__.'/TestCase.php');

class SettingsSynchronousHookTest extends TestCase
{
	public function testStaleHookCacheIsUsedWithoutDaemonCallsAndCannotBeRestamped()
	{
		$this->child(true);
	}

	public function testMissingHookCacheRefusesWithoutDaemonCalls()
	{
		$this->child(false);
	}

	private function child($cached)
	{
		$root = realpath(__DIR__.'/../..');
		$path = sys_get_temp_dir().'/hook-cache-'.getmypid().'-'.($cached ? 'stale' : 'missing');
		mkdir($path, 0700);
		$driver = $path.'/driver.php';
		file_put_contents($driver, '<?php '.
			'$_ENV["RU_PROFILE_PATH"]='.var_export($path, true).';'.
			'require '.var_export($root.'/php/settings.php', true).';'.
			'$scgi_host="127.0.0.1";$scgi_port=1;$rpcTimeOut=1;$rpcLogCalls=true;$log_file='.var_export($path.'/rpc.log', true).';'.
			($cached ? '$s=(new ReflectionClass("rTorrentSettings"))->newInstanceWithoutConstructor();'.
				'$s->linkExist=true;$s->directory="/old";$s->valueFormat=0;(new rCache())->set($s);' : '').
			'define("RUTORRENT_SYNCHRONOUS_HOOK",true);'.
			'try {$s=rTorrentSettings::get();echo json_encode(array($s->directory,$s->valueFormat,$s->store()), JSON_UNESCAPED_SLASHES);}'.
			'catch(RuntimeException $e){echo $e->getMessage();}');
		$out = array();
		exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($driver).' 2>&1', $out, $code);
		$text = implode("\n", $out);
		$this->assertSame(0, $code, 'the hook bootstrap exits without a timeout');
		$this->assertSame($cached ? '["/old",0,false]' : 'settings-cache-unavailable-in-synchronous-hook', $text,
			'the hook uses its stale cache or visibly refuses a missing cache: '.$text);
		$this->assertTrue(!file_exists($path.'/rpc.log') || strpos(file_get_contents($path.'/rpc.log'), '<methodCall>') === false,
			'no synchronous callback asks its own blocked daemon');
		foreach(glob($path.'/settings/*') ?: array() as $file) unlink($file);
		@rmdir($path.'/settings');
		foreach(glob($path.'/*') ?: array() as $file) if(is_file($file)) unlink($file);
		rmdir($path);
	}
}
