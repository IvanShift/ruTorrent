<?php

require_once(__DIR__ . '/../../php/TestCase.php');

/**
 * Run the production action.php in a disposable tree. All dependencies below
 * the entrypoint are stubs; constructing an RPC request stops the child before
 * any socket can be opened.
 */
class HttprpcSettingsEntrypointTest extends TestCase
{
	private function runRequest($body)
	{
		$root = sys_get_temp_dir().'/httprpc-settings-'.getmypid().'-'.bin2hex(random_bytes(6));
		mkdir($root.'/plugins/httprpc', 0700, true);
		mkdir($root.'/php', 0700, true);
		$source = __DIR__.'/../../../plugins/httprpc/';
		copy($source.'action.php', $root.'/plugins/httprpc/action.php');
		copy($source.'settingspolicy.php', $root.'/plugins/httprpc/settingspolicy.php');
		file_put_contents($root.'/php/xmlrpc.php', '<?php
class FileUtil { public static function toLog($message) {} }
class CachedEcho { public static function send($body, $type = null) { echo http_response_code()."|".$body; exit(0); } }
class rXMLRPCRequest { public function __construct() { echo "RPC-CONSTRUCTED"; exit(72); } }
');
		foreach(array('xmlrpc_proxy.php', 'xmlrpc_path.php') as $file)
			file_put_contents($root.'/php/'.$file, '<?php');
		file_put_contents($root.'/plugins/httprpc/rpccache.php', '<?php');

		$script = '$HTTP_RAW_POST_DATA = '.var_export($body, true).'; include "action.php";';
		$process = proc_open(array(PHP_BINARY, '-r', $script),
			array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$pipes, $root.'/plugins/httprpc');
		if(!is_resource($process))
			throw new Exception('could not start isolated action.php fixture');
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);

		foreach(array('plugins/httprpc/action.php', 'plugins/httprpc/settingspolicy.php',
			'plugins/httprpc/rpccache.php', 'php/xmlrpc.php', 'php/xmlrpc_proxy.php',
			'php/xmlrpc_path.php') as $file)
			unlink($root.'/'.$file);
		rmdir($root.'/plugins/httprpc');
		rmdir($root.'/plugins');
		rmdir($root.'/php');
		rmdir($root);
		return array('status' => $status, 'output' => $output, 'error' => $error);
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
