<?php
require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/../../php/FakeRtorrentDaemon.php');

class ColdOperatorBootstrapTest extends TestCase
{
	public function testActualColdToolsRefuseUsageAndLegacyWithoutCreatingProfilesOrMutatingDaemon()
	{
		foreach (['apply-existing','retry-reset'] as $tool) { foreach ([true,false] as $shared) {
			foreach ([['--bad'],['--user=BAD'],['--user=alice','--apply'],['--user=alice']] as $args) {
				// Legacy has no native queue. Invalid arguments may stop even before this read.
				$result = $this->runCold($tool,$args,[$this->reply(['system.client_version','d.hash'],true)],$shared);
				list($code,$out,$err,$requests,$created) = $result;
				$this->assertTrue($code===2 && !$created && count($requests)<=1 &&
					(count($requests)===0 || strpos($requests[0],'<methodName>system.listMethods</methodName>')!==false),
					$tool.' cold usage/legacy refuses before cache mkdir, execute, setters or enqueue: '.json_encode($result));
			}
		} }
	}
	public function testColdSharedBootstrapSettingsReadsNeverSetExecuteOrStore()
	{
		// Serialized startup plan: version, API, conversion, seven getters and port.
		$plan = [$this->reply('0.9.8'),$this->reply(1),$this->reply(1048576),
			$this->reply([['/downloads'],[''],['0.13.8'],['fake'],['6881-6881'],['127.0.0.1'],['127.0.0.1']]),$this->reply(6881)];
		list($code,$out,$err,$requests,$created) = $this->runCold('bootstrap',[],$plan);
		$this->assertTrue($code===0 && $out==='' && $err==='' && !$created && count($requests)===5,
			'cold shared settings reads do not create or store a cache: '.json_encode([$code,$out,$err,$created,count($requests)]));
		$this->assertTrue(preg_match('/execute|size_limit\.set|set_xmlrpc_size_limit|enqueue_original|method\.insert/',implode("\n",$requests))===0,
			'cold shared settings never send a daemon setter, shell helper or enqueue');
	}
	public function testActualNativeColdDryRunDoesNotBootstrapAProfileOrEnqueue()
	{
		$methods = ['d.retrackers.enqueue_original','d.retrackers.dequeue_original','system.retrackers.original_claim',
			'system.retrackers.worker_start','system.retrackers.dispatcher_start','system.retrackers.queue_cursor',
			'system.retrackers.queue_cursor.set','d.retrackers.preflight_stage','d.retrackers.defer_original','schedule.if_absent'];
		foreach (['apply-existing','retry-reset'] as $tool) { foreach ([true,false] as $shared) {
			list($code,$out,$err,$requests,$created) = $this->runCold($tool,['--user=alice'],
				[$this->reply($methods),$this->reply([])],$shared);
			$this->assertTrue($code===0 && !$created && count($requests)===2 &&
				strpos($requests[0],'<methodName>system.listMethods</methodName>')!==false &&
				strpos($requests[1],'<methodName>d.multicall2</methodName>')!==false,
				$tool.' native cold dry run only reads capability and torrents: '.json_encode([$code,$out,$err,$created,count($requests)]));
		} }
	}
	private function reply($value, $legacy = false)
	{
		$declaration = $legacy ? '<?xml version="1.0" encoding="UTF-8"?>' : '<?xml version="1.0"?>';
		return(new FakeRtorrentRawReply($declaration.'<methodResponse><params><param><value>'.$this->value($value).'</value></param></params></methodResponse>'));
	}
	private function value($value)
	{
		if (is_array($value)) {
			if ($value === []) { return('<array><data/></array>'); }
			$body = '<array><data>';
			foreach ($value as $item) { $body .= '<value>'.$this->value($item).'</value>'; }
			return($body.'</data></array>');
		}
		return(is_int($value) ? '<i8>'.$value.'</i8>' : '<string>'.htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8').'</string>');
	}
	private function runCold($entry, array $args, array $plan, $shared = true)
	{
		$dir = sys_get_temp_dir().'/rr-cold-'.getmypid().'-'.uniqid();
		mkdir($dir,0700);
		$profile = $dir.'/missing';
		$daemon = new FakeRtorrentDaemon($plan,$dir.'/wire');
		try {
			$root = dirname(__DIR__,3);
			$command = $entry === 'bootstrap' ? [PHP_BINARY,'-r',
				'$argv=array("bootstrap"); require '.var_export($root.'/plugins/retrackers/clitool.php',true).'; rTorrentSettings::get();'] :
				array_merge([PHP_BINARY,$root.'/tools/retrackers-'.$entry.'.php'],$args);
			$env = array_merge(getenv(),['RU_PROFILE_PATH'=>$profile,'RU_FORBID_USER_SETTINGS'=>$shared?'true':'false',
				'RU_SCGI_HOST'=>'127.0.0.1','RU_SCGI_PORT'=>(string)$daemon->port(),'RU_LOG_FILE'=>$dir.'/errors.log']);
			unset($env['RU_HTTP_AUTH']);
			$process = proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
			if (!is_resource($process)) { throw new RuntimeException('cold operator CLI did not start'); }
			$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
			fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
			return([$code,$out,$err,$daemon->bodies(),file_exists($profile)]);
		} finally {
			$daemon->stop();
			$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($entries as $entry) { if ($entry->isDir()) { rmdir($entry->getPathname()); } else { unlink($entry->getPathname()); } }
			rmdir($dir);
		}
	}
}
