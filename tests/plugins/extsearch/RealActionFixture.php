<?php

/** Real extsearch entrypoint with disposable profile and a scripted SCGI peer. */
class ExtsearchRealActionFixture
{
	private $root;

	public function __construct()
	{
		$this->root = sys_get_temp_dir().'/rutorrent-search-real-'.bin2hex(random_bytes(6));
		if(!mkdir($this->root, 0700, true))
			throw new RuntimeException('could not create extsearch action fixture directory');
	}

	public function run($config, $peer)
	{
		$bootstrap = '<?php '
			.'$config = '.var_export($config, true).'; '
			.'$_ENV["RU_PROFILE_PATH"] = '.var_export($this->root.'/profile', true).'; '
			.'$_ENV["RU_TEMP_DIRECTORY"] = '.var_export($this->root.'/tmp', true).'; '
			.'$_ENV["RU_PROFILE_MASK"] = "0700"; '
			.'$_ENV["RU_LOG_FILE"] = '.var_export($this->root.'/errors.log', true).'; '
			.'$_ENV["RU_SCGI_HOST"] = '.var_export($peer->host(), true).'; '
			.'$_ENV["RU_SCGI_PORT"] = '.var_export($peer->port(), true).'; '
			.'$_SERVER["REMOTE_USER"] = "extsearch_real_action_test"; '
			.'$_SERVER["REQUEST_METHOD"] = $config["method"]; '
			.'$_REQUEST = $config["request"]; '
			.'$HTTP_RAW_POST_DATA = $config["body"] ?? ""; '
			.'require_once('.var_export(__DIR__.'/../../../plugins/extsearch/engines.php', true).'); '
			.'$settings = (new ReflectionClass("rTorrentSettings"))->newInstanceWithoutConstructor(); '
			.'$settings->aliases = array("d.get_custom"=>array("name"=>"d.custom", "prm"=>0)); '
			.'$settings->plugins = array(); '
			.'$singleton = new ReflectionProperty("rTorrentSettings", "theSettings"); '
			.'if(PHP_VERSION_ID < 80100) $singleton->setAccessible(true); '
			.'$singleton->setValue(null, $settings); '
			.'if(!empty($config["empty_engines"])) { '
			.'$manager = new engineManager(); $manager->engines = array(); '
			.'if(!$manager->store()) exit("engine seed failed"); } '
			.'$history = engineManager::loadHistory(); '
			.'foreach($config["rows"] as $url=>$receipt) { '
			.'$history->addPending($url,$receipt); $history->lst[$url]["time"] = time()-301; } '
			.'if(!engineManager::saveHistory($history)) exit("history seed failed"); '
			.'register_shutdown_function(function() use ($config) { '
			.'$saved=engineManager::loadHistory(); $cursor=new ExtsearchHistoryProbeCursor(); '
			.'(new rCache())->get($cursor); $rows=array(); '
			.'foreach($config["rows"] as $url=>$receipt) $rows[$url] = array('
			.'"pending"=>$saved->isPending($url), '
			.'"receipt"=>$saved->lst[$url]["receipt"] ?? null); '
			.'file_put_contents('.var_export($this->root.'/result.json', true).', '
			.'json_encode(array("rows"=>$rows,"next"=>$cursor->next))); });';
		file_put_contents($this->root.'/bootstrap.php', $bootstrap);
		$action = __DIR__.'/../../../plugins/extsearch/action.php';
		$process = proc_open(array(PHP_BINARY, '-d',
			'auto_prepend_file='.$this->root.'/bootstrap.php', '-f', $action),
			array(0=>array('file','/dev/null','r'),
				1=>array('pipe','w'),2=>array('pipe','w')),
			$pipes, dirname($action));
		if(!is_resource($process))
			throw new RuntimeException('extsearch action child did not start');
		$start = microtime(true);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return array('exit'=>proc_close($process), 'output'=>$output,
			'error'=>$error, 'elapsed'=>microtime(true)-$start);
	}

	public function snapshot()
	{
		return json_decode((string)file_get_contents($this->root.'/result.json'), true);
	}

	public function log()
	{
		return (string)file_get_contents($this->root.'/errors.log');
	}

	public function close()
	{
		if(!is_dir($this->root)) return;
		$items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
			$this->root, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST);
		foreach($items as $item)
			$item->isDir() && !$item->isLink()
				? @rmdir($item->getPathname()) : @unlink($item->getPathname());
		@rmdir($this->root);
	}
}
