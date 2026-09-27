<?php

require_once(__DIR__ . '/../../php/TestCase.php');

/**
 * Run the production action.php in a disposable tree. All dependencies below
 * the entrypoint are stubs; constructing an RPC request stops the child before
 * any socket can be opened.
 */
class HttprpcSettingsEntrypointTest extends TestCase
{
	private function runRequest($body, $rpcFailure = null)
	{
		$root = sys_get_temp_dir().'/httprpc-settings-'.getmypid().'-'.bin2hex(random_bytes(6));
		mkdir($root.'/plugins/httprpc', 0700, true);
		mkdir($root.'/php', 0700, true);
		$source = __DIR__.'/../../../plugins/httprpc/';
		copy($source.'action.php', $root.'/plugins/httprpc/action.php');
		copy($source.'settingspolicy.php', $root.'/plugins/httprpc/settingspolicy.php');
		copy(__DIR__.'/../../../php/xmlrpc_proxy.php', $root.'/php/xmlrpc_proxy.php');
		copy(__DIR__.'/../../../php/xmlrpc_proxy_native.php', $root.'/php/xmlrpc_proxy_native.php');
		file_put_contents($root.'/php/xmlrpc.php', '<?php
class FileUtil { public static function toLog($message) {} }
class CachedEcho { public static function send($body, $type = null) { echo http_response_code()."|".$body; exit(0); } }
class rTorrentSettings {
    public $aliases = array();
    public $iVersion = 0x1018;
    public static function get() { return new self(); }
    public function getSocketAllocCategory($name) {
        return $name === "nmax_open_files" ? "files" : null;
    }
}
function getCmd($command) { return $command; }
class rXMLRPCCommand {
    public $method;
    public function __construct($method, $parameters = null) { $this->method = $method; }
    public function addParameters($parameters) {}
}
class rXMLRPCRequest {
    public static function send($data, $trusted = false) {
        if($trusted || strpos($data, "<methodName>system.client_version</methodName>") === false) {
            fwrite(STDERR, "unexpected version probe"); exit(73);
        }
        $body = "<?xml version=\"1.0\"?><methodResponse><params><param><value><string>0.16.24</string></value></param></params></methodResponse>";
        return "Content-Length: ".strlen($body)."\r\n\r\n".$body;
    }
    public $fault = false;
    public $faultString = "";
    public $transportFailure = null;
    public $important = false;
    public $val = array();
    private $commands = array();
    public function __construct() {
        if(!getenv("HTTPRPC_TEST_FAILURE")) { echo "RPC-CONSTRUCTED"; exit(72); }
    }
    public function addCommand($command) { $this->commands[] = $command->method; }
    public function getCommandsCount() { return count($this->commands); }
    public function success($trusted = true) {
        if(getenv("HTTPRPC_TEST_FAILURE") === "socket-rollback") {
            file_put_contents(getenv("HTTPRPC_TEST_TRACE"),
                "RPC[".implode(",", $this->commands)."];", FILE_APPEND);
            if($this->commands === array("system.sockets.files.min_alloc", "system.sockets.files.max_alloc")) {
                $this->val = array(131, 131); return true;
            }
            if($this->commands === array("get_max_open_files")) {
                $this->val = array(131); return true;
            }
            if(in_array("system.sockets.adjust_alloc", $this->commands, true)) {
                static $adjustAttempts = 0;
                if(++$adjustAttempts === 1) {
                    $this->fault = true;
                    $this->faultString = "over budget";
                    return false;
                }
                $this->val = array(0, 0, 0);
                return true;
            }
            fwrite(STDERR, "unexpected socket transaction call"); exit(74);
        }
        $this->transportFailure = getenv("HTTPRPC_TEST_FAILURE");
        return false;
    }
}
');
		file_put_contents($root.'/php/xmlrpc_path.php', '<?php');
		file_put_contents($root.'/plugins/httprpc/rpccache.php', '<?php');

		$script = '$HTTP_RAW_POST_DATA = '.var_export($body, true).'; include "action.php";';
		$process = proc_open(array(PHP_BINARY, '-r', $script),
			array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$pipes, $root.'/plugins/httprpc',
			array_merge($_ENV, array('HTTPRPC_TEST_FAILURE' => (string)$rpcFailure,
				'HTTPRPC_TEST_TRACE' => $root.'/trace')));
		if(!is_resource($process))
			throw new Exception('could not start isolated action.php fixture');
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		$trace = is_file($root.'/trace') ? file_get_contents($root.'/trace') : '';
		if(is_file($root.'/trace'))
			unlink($root.'/trace');

		foreach(array('plugins/httprpc/action.php', 'plugins/httprpc/settingspolicy.php',
			'plugins/httprpc/rpccache.php', 'php/xmlrpc.php', 'php/xmlrpc_proxy.php',
			'php/xmlrpc_proxy_native.php',
			'php/xmlrpc_path.php') as $file)
			unlink($root.'/'.$file);
		rmdir($root.'/plugins/httprpc');
		rmdir($root.'/plugins');
		rmdir($root.'/php');
		rmdir($root);
		return array('status' => $status, 'output' => $output,
			'error' => $error, 'trace' => $trace);
	}

	public function testForgedSizeSetterIsRefusedBeforeRpcConstruction()
	{
		$result = $this->runRequest('mode=setsettings&s=nxmlrpc_size_limit&v=1');
		$this->assertEquals(0, $result['status'], 'the isolated door returns a refusal');
		$this->assertEquals('403|Refused: unsupported setting.', $result['output'],
			'the size bypass is refused before an RPC request exists');
		$this->assertEquals('', $result['error'], 'the refusal has no PHP diagnostic');
	}

	public function testOtherForgedNamesAndMalformedBatchesAreRefused()
	{
		foreach(array('mode=setsettings&s=nexecute&v=1',
			'mode=setsettings&s=nmax_open_files') as $body)
		{
			$result = $this->runRequest($body);
			$this->assertEquals(0, $result['status'], 'invalid form returns a refusal');
			$this->assertEquals('403|Refused: unsupported setting.', $result['output'],
				'invalid form cannot construct an RPC request');
		}
	}

	public function testDangerousCmdValuesAreRefusedByRealFormEntrypoint()
	{
		foreach(array('list', 'ttl', 'prp') as $mode)
			foreach(array('import=/tmp/commands.rc',
				'method.insert=x,simple,"d.name="', 'system.shutdown') as $command)
			{
				// Keep mode after cmd: action.php must finish parsing before choosing policy.
				$body = 'cmd='.rawurlencode($command).'&mode='.$mode
					.'&hash='.str_repeat('a', 40);
				$result = $this->runRequest($body);
				$this->assertEquals(0, $result['status'], $mode.' refuses '.$command);
				$this->assertTrue(strpos($result['output'], '403|Refused: this server does not allow ') === 0,
					$mode.' returns HTTP 403 without constructing an RPC request');
				$this->assertEquals('', $result['error'], $mode.' has no PHP diagnostic');
			}
	}

	public function testOrdinaryModesStillReachTheStubbedRpcBoundary()
	{
		foreach(array('list', 'ttl', 'prp') as $mode)
		{
			$result = $this->runRequest('mode='.$mode.'&hash='.str_repeat('a', 40));
			$this->assertEquals(72, $result['status'], $mode.' reaches the fake RPC boundary');
			$this->assertEquals('RPC-CONSTRUCTED', $result['output'],
				$mode.' remains available without a real RPC connection');
			$this->assertEquals('', $result['error'], $mode.' has no PHP diagnostic');
		}
	}

	public function testListModeExplainsHeaderlessEofButKeepsConnectFailureMessage()
	{
		$closed = $this->runRequest('mode=list', 'closed-before-headers');
		$this->assertEquals(0, $closed['status'], 'list mode returns a classified error');
		$this->assertEquals('500|rTorrent closed the SCGI connection before sending response headers. '
			.'A long-running request may have hit the daemon\'s SCGI timeout.', $closed['output'],
			'list mode explains an accepted connection that closed before headers');
		$this->assertEquals('', $closed['error'], 'headerless EOF emits no PHP diagnostic');

		$connect = $this->runRequest('mode=list', 'connect-failed');
		$this->assertEquals(0, $connect['status'], 'connect failure still returns an error');
		$this->assertEquals('500|Could not reach rTorrent over XMLRPC. Is rTorrent running?',
			$connect['output'], 'a refused connection keeps its established message');
	}

	public function testRejectedSocketTransactionRestoresItsSnapshot()
	{
		$result = $this->runRequest('mode=setsettings&s=nmax_open_files&v=100000', 'socket-rollback');
		$this->assertEquals(0, $result['status'], 'the real action.php completes its refusal path');
		$this->assertEquals('', $result['error'], 'the rollback fixture raises no PHP diagnostic');
		preg_match_all('/RPC\[([^]]+)\];/', $result['trace'], $calls);
		$this->assertEquals(array(
			'system.sockets.files.min_alloc,system.sockets.files.max_alloc',
			'get_max_open_files',
			'system.sockets.files.min_alloc.set,system.sockets.files.max_alloc.set,system.sockets.adjust_alloc',
			'system.sockets.files.min_alloc.set,system.sockets.files.max_alloc.set,system.sockets.adjust_alloc',
			'get_max_open_files',
		), $calls[1], 'a failed allocation is followed by one restore and read-back');
		$this->assertTrue(strpos($result['output'], '500|over budget') !== false,
			'the original rTorrent refusal reaches the caller after rollback');
	}

	public function testOrdinarySettingReachesTheStubbedRpcBoundary()
	{
		foreach(array('mode=setsettings&s=nmax_open_files&v=20000',
			'mode=setsettings&s=ndht&v=1') as $body)
		{
			$result = $this->runRequest($body);
			$this->assertEquals(72, $result['status'], 'allowed setting reaches the fake RPC boundary');
			$this->assertEquals('RPC-CONSTRUCTED', $result['output'],
				'the allowed path remains available without a real RPC connection');
		}
	}
}
