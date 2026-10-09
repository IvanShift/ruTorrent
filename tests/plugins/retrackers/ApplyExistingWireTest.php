<?php
require_once(__DIR__.'/RetrackersTestStubs.php');
require_once(__DIR__.'/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__.'/../../../php/scgitransport.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_DISPATCH_IMPORT_ONLY', true);
require_once(__DIR__.'/../../../plugins/retrackers/dispatch.php');
require_once(__DIR__.'/../../../plugins/retrackers/applyexisting.php');

/** Serializable SCGI plans exercise production codecs and the sealed CAS. */
class ApplyExistingWireTest extends TestCase
{
	private function value($value)
	{
		if (is_array($value)) {
			$result = '<array><data>';
			foreach ($value as $item) $result .= '<value>' . $this->value($item) . '</value>';
			return($result . '</data></array>');
		}
		if (is_int($value)) return('<i8>' . $value . '</i8>');
		if (preg_match('//u', $value) !== 1 || strpos($value, "\r") !== false)
			return('<base64>' . base64_encode($value) . '</base64>');
		return('<string>' . strtr($value, array('&'=>'&amp;', '<'=>'&lt;', '>'=>'&gt;')) . '</string>');
	}
	private function reply($value)
	{
		return(new FakeRtorrentRawReply('<?xml version="1.0"?><methodResponse><params><param><value>' .
			$this->value($value) . '</value></param></params></methodResponse>'));
	}
	private function methods()
	{
		return(array('d.retrackers.enqueue_original', 'd.retrackers.dequeue_original', 'system.retrackers.original_claim',
			'system.retrackers.worker_start', 'system.retrackers.dispatcher_start', 'system.retrackers.queue_cursor',
			'system.retrackers.queue_cursor.set', 'd.retrackers.preflight_stage', 'd.retrackers.defer_original',
			'schedule.if_absent', 'system.autotools.move_state', 'd.stop_close_claim_held'));
	}
	private function runTool(array $plan, array $args, $capture = null, $repeatHash = null)
	{
		global $scgi_host, $scgi_port;
		$log = sys_get_temp_dir() . '/apply-wire-' . getmypid() . '-' . uniqid();
		$daemon = new FakeRtorrentDaemon($plan, $log);
		$scgi_host = '127.0.0.1'; $scgi_port = $daemon->port();
		$out = fopen('php://memory','w+'); $err = fopen('php://memory','w+');
		try {
			$code = $capture === null ? RetrackersApplyExisting::main($args, new RetrackersQueueRpcAdapter(), $out, $err,
				array('trackers'=>array('https://add.test/announce'), 'dont_add_private'=>true)) :
				(new RetrackersStableSourceSnapshot(new RetrackersQueueRpcAdapter()))->capture($capture[0], $capture[1]);
			rewind($out); rewind($err);
			$output = stream_get_contents($out); $error = stream_get_contents($err);
			$repeat = null;
			if ($repeatHash !== null) {
				ftruncate($out, 0); rewind($out); ftruncate($err, 0); rewind($err);
				$repeatCode = RetrackersApplyExisting::main(array('--apply', $repeatHash), new RetrackersQueueRpcAdapter(), $out, $err,
					array('trackers'=>array('https://add.test/announce'), 'dont_add_private'=>true));
				rewind($out); rewind($err);
				$repeat = array($repeatCode, stream_get_contents($out), stream_get_contents($err));
			}
			return(array($code, $output, $error, $daemon->bodies(), $repeat));
		} finally {
			$daemon->stop(); fclose($out); fclose($err);
			foreach (array($log, $log.'.bodies', $log.'.server.err') as $file) @unlink($file);
		}
	}
	private function plan($name, $answer)
	{
		$hash = str_repeat('A',40); $id = str_repeat('B',40);
		return(array($this->reply($this->methods()), $this->reply(array(array($hash,$id))),
			$this->reply($this->methods()),
			$this->reply(array(array($id),array(0),array(0),array($name),array(''),array(''),array(''))),
			$this->reply('none'), $this->reply(''), $this->reply('none'),
			$this->reply(array(array('https://original.test/a',0,1,0,1))),
			$this->reply($this->methods()), $answer));
	}
	public function testProductionSnapshotPreservesBinaryNamesAndStoppedEnqueue()
	{
		foreach (array("\xcf\xf0\xe8\xe2\xe5\xf2", "a\rb") as $name) {
			$handoff = 'v2:original:0:'.str_repeat('B',40).':'.hash('sha256','').':'.str_repeat('c',32);
			list($code,$out,,$requests) = $this->runTool($this->plan($name,$this->reply($handoff)),array('--apply'));
			$this->assertTrue($code===0 && $out===str_repeat('A',40)." queued\n", 'binary torrent name is not a malformed initial snapshot');
			$branch = end($requests);
			$this->assertTrue(strpos($branch,'system.autotools.move_state=')!==false &&
				strpos($branch,'d.local_id=')!==false && strpos($branch,'d.state=')!==false &&
				substr_count($branch,'d.retrackers.enqueue_original=')===1 && strpos($branch,'retrackers-recovery-ack')!==false,
				'one guarded callback pins Move, generation, run and outstanding ownership before enqueue');
		}
	}
	public function testStableSourceCaptureAcceptsBinaryNameWithoutCanonicalizingIt()
	{
		$hash = str_repeat('A',40); $id = str_repeat('B',40);
		$handoff = 'v2:original:0:'.$id.':'.hash('sha256','').':'.str_repeat('c',32);
		foreach (array("\xcf\xf0\xe8\xe2\xe5\xf2", "a\rb") as $name) {
			$scalar = array();
			$values = array('local_id'=>$id, 'name'=>$name, 'recovery_marker'=>$handoff,
				'recovery_ack'=>$handoff, 'session_path'=>'/session/', 'trackers_use_udp'=>'1');
			foreach (retrackersSourceScalarSchema($hash) as $field) {
				$value = isset($values[$field['name']]) ? $values[$field['name']] : ($field['type']==='string' ? '' : '0');
				$scalar[] = array($field['type']==='string' ? $value : (int)$value);
			}
			$map = '<struct>';
			foreach (array('retrackers-recovery', 'retrackers-recovery-ack') as $key)
				$map .= '<member><name>'.$key.'</name><value>'.$this->value($handoff).'</value></member>';
			$map .= '</struct>';
			$mapReply = new FakeRtorrentRawReply('<?xml version="1.0"?><methodResponse><params><param><value>'.$map.'</value></param></params></methodResponse>');
			$trackers = $this->reply(array(array('https://original.test/a',0,1,0,1)));
			list($result) = $this->runTool(array($this->reply($scalar),$mapReply,$mapReply,$trackers,$trackers,$this->reply($scalar)),array(),array($hash,$handoff));
			$this->assertTrue(isset($result['ok'],$result['snapshot']['scalar']['name']) && $result['ok']===true &&
				$result['snapshot']['scalar']['name']===$name, 'the complete worker source capture preserves its binary name without a futile retry');
		}
	}

	public function testLostOrGuardRefusedEnqueueNeverReplays()
	{
		foreach (array(array(null,2,'transport-unconfirmed'),array($this->reply('CHANGED'),1,'refused:changed')) as $case) {
			list($code,$out,,$requests) = $this->runTool($this->plan('torrent',$case[0]),array('--apply'));
			$this->assertTrue($code===$case[1] && strpos($out,$case[2])!==false && count($requests)===10,
				'one enqueue callback remains one invocation after an unknown or refused reply');
		}
	}
	public function testFaultAfterDurableQueueAndPreclaimStopsBatchWithoutReplay()
	{
		$hash = str_repeat('A',40); $id = str_repeat('B',40);
		$queued = 'q1:'.hash('sha256','').':'.str_repeat('d',32);
		$fault = new FakeRtorrentRawReply('<?xml version="1.0"?><methodResponse><fault><value><struct>'.
			'<member><name>faultCode</name><value><i8>-500</i8></value></member>'.
			'<member><name>faultString</name><value><string>RR original preclaim directory fsync failed.</string></value></member>'.
			'</struct></value></fault></methodResponse>');
		// Native rr_enqueue_original saves Q before rr_begin_original writes
		// preclaim and fsyncs its directory. Either this fault or a dropped reply
		// can therefore follow side effects. A later explicit run sees that Q.
		foreach (array($fault, null) as $answer) {
			$plan = $this->plan('torrent',$answer);
			$plan[1] = $this->reply(array(array($hash,$id),array(str_repeat('C',40),str_repeat('E',40))));
			$repeat = array($this->reply($this->methods()), $this->reply($this->methods()),
				$this->reply(array(array($id),array(0),array(0),array('torrent'),array($queued),array(''),array(''))),
				$this->reply('held'), $this->reply(''), $this->reply('none'),
				$this->reply(array(array('https://original.test/a',0,1,0,1))));
			list($code,$out,,$requests,$again) = $this->runTool(array_merge($plan,$repeat),array('--apply'),null,$hash);
			$this->assertTrue($code===2 && $out===$hash." transport-unconfirmed\n",
				'a fault or lost reply after durable admission is unknown and stops before the second candidate');
			$this->assertTrue(count($requests)===17 && substr_count(implode("\n",$requests),'<methodName>branch</methodName>')===1,
				'the mutation is sent once; only an explicit later run reads the durable queue again');
			$this->assertTrue($again===array(0,$hash." queued\n",''),
				'the explicit repeat preserves durable Q and preclaim ownership without another enqueue');
		}
	}

	public function testTheActualCliLoadsTheSelectedProfileAndKeepsDryRunReadOnly()
	{
		$root = dirname(__DIR__,3);
		$dir = sys_get_temp_dir().'/apply-cli-'.getmypid().'-'.uniqid();
		mkdir($dir,0700); mkdir($dir.'/settings',0700);
		// A minimal rRetrackers cache input, serialized with PHP rather than a
		// hand-counted body. Production supplies the class during CLI bootstrap.
		$settings = (object)array('hash'=>'retrackers.dat', 'list'=>array(array('https://add.test/announce')), 'dontAddPrivate'=>1);
		file_put_contents($dir.'/settings/retrackers.dat',str_replace('O:8:"stdClass":','O:11:"rRetrackers":',serialize($settings)));
		$plan = $this->plan('torrent',null);
		$plan = array_slice($plan,0,8);
		$log = $dir.'/wire'; $daemon = new FakeRtorrentDaemon($plan,$log);
		try {
			$env = array_merge(getenv(),array('RU_PROFILE_PATH'=>$dir,'RU_FORBID_USER_SETTINGS'=>'true',
				'RU_SCGI_HOST'=>'127.0.0.1','RU_SCGI_PORT'=>(string)$daemon->port(),'RU_LOG_FILE'=>$dir.'/errors.log'));
			$process = proc_open(array(PHP_BINARY,$root.'/tools/retrackers-apply-existing.php','--user=alice'),
				array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,$env);
			if (!is_resource($process)) throw new RuntimeException('operator CLI did not start');
			$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
			fclose($pipes[1]); fclose($pipes[2]); $code = proc_close($process);
			$this->assertTrue($code===0 && $out===str_repeat('A',40)." candidate\n" && $err==='',
				'the real CLI uses shared profile settings and dry run does not enqueue: '.$code.' '.$out.$err);
			$this->assertTrue(count($daemon->bodies())===8,'CLI bootstrap sends only the planned read-only requests');
		} finally {
			$daemon->stop();
			$entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($entries as $entry) { if ($entry->isDir()) rmdir($entry->getPathname()); else unlink($entry->getPathname()); }
			rmdir($dir);
		}
	}

	public function testLegacyHasNoNativeAdmissionAndNoMutation()
	{
		list($code,,$err,$requests) = $this->runTool(array($this->reply(array('d.hash','system.client_version'))),array('--apply'));
		$this->assertTrue($code===2 && count($requests)===1 && strpos($err,'native retrackers queue')!==false,
			'legacy capability refusal stops before reading torrents or sending a mutation');
	}
}
