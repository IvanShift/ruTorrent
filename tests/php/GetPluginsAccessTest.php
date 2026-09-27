<?php

require_once(__DIR__ . '/TestCase.php');

class GetPluginsAccessTest extends TestCase
{
	private function check($headers)
	{
		$server = array_merge(array('REQUEST_METHOD' => 'GET',
			'HTTP_HOST' => '127.0.0.1:19082', 'REQUEST_SCHEME' => 'http'), $headers);
		$code = '$_SERVER = json_decode(base64_decode($argv[1]), true);'
			. 'require_once ' . var_export(__DIR__ . '/../../php/utility/requests.php', true) . ';'
			. 'Requests::requirePluginBootstrapRequest(); echo "allowed";';
		$command = array(PHP_BINARY, '-d', 'display_errors=0', '-r', $code,
			base64_encode(json_encode($server)));
		$process = proc_open($command, array(1 => array('pipe', 'w'),
			2 => array('pipe', 'w')), $pipes);
		if(!is_resource($process))
			throw new RuntimeException('Could not start isolated bootstrap request');
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		return array($out, $err, $status);
	}

	public function testCrossOriginClassicScriptCannotReadBootstrap()
	{
		list($out, $err, $status) = $this->check(array(
			'HTTP_SEC_FETCH_SITE' => 'cross-site',
			'HTTP_SEC_FETCH_DEST' => 'script',
			'HTTP_REFERER' => 'http://127.0.0.1:19083/'));
		$this->assertSame('Forbidden', $out);
		$this->assertSame(0, $status);
		$this->assertTrue(strpos($err, 'getplugins: refused') !== false);
	}

	public function testForeignOriginWinsEvenWithAjaxHeader()
	{
		list($out) = $this->check(array(
			'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
			'HTTP_SEC_FETCH_SITE' => 'same-origin',
			'HTTP_ORIGIN' => 'http://127.0.0.1:19083',
			'HTTP_REFERER' => 'http://127.0.0.1:19082/'));
		$this->assertSame('Forbidden', $out);
	}

	public function testOpaqueOriginIsDenied()
	{
		list($out) = $this->check(array(
			'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
			'HTTP_ORIGIN' => 'null'));
		$this->assertSame('Forbidden', $out);
	}

	public function testSameSiteDifferentPortIsDenied()
	{
		list($out) = $this->check(array(
			'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
			'HTTP_SEC_FETCH_SITE' => 'same-site',
			'HTTP_REFERER' => 'http://127.0.0.1:19083/'));
		$this->assertSame('Forbidden', $out);
	}

	public function testSameOriginAjaxIsAllowed()
	{
		list($out, $err, $status) = $this->check(array(
			'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
			'HTTP_SEC_FETCH_SITE' => 'same-origin',
			'HTTP_REFERER' => 'http://127.0.0.1:19082/'));
		$this->assertSame('allowed', $out);
		$this->assertSame('', $err);
		$this->assertSame(0, $status);
	}

	public function testLegacySameOriginAjaxIsAllowed()
	{
		list($out) = $this->check(array(
			'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'));
		$this->assertSame('allowed', $out);
	}

	public function testInternalHealthFlagIsAllowedWithoutBrowserHeaders()
	{
		list($out) = $this->check(array('RUTORRENT_INTERNAL_PLUGIN_HEALTH' => '1'));
		$this->assertSame('allowed', $out);
	}

	public function testClientHeaderCannotImpersonateInternalHealth()
	{
		list($out) = $this->check(array('HTTP_RUTORRENT_INTERNAL_PLUGIN_HEALTH' => '1'));
		$this->assertSame('Forbidden', $out);
	}

	public function testInternalHealthFlagCannotUsePost()
	{
		list($out) = $this->check(array('REQUEST_METHOD' => 'POST',
			'RUTORRENT_INTERNAL_PLUGIN_HEALTH' => '1'));
		$this->assertSame('Forbidden', $out);
	}

	public function testHeaderlessExternalRequestIsDenied()
	{
		list($out) = $this->check(array());
		$this->assertSame('Forbidden', $out);
	}

	public function testGuardRunsBeforePluginLoading()
	{
		$source = file_get_contents(__DIR__ . '/../../php/getplugins.php');
		$this->assertTrue(strpos($source, 'Requests::requirePluginBootstrapRequest()') !== false);
		$this->assertTrue(strpos($source, 'Requests::requirePluginBootstrapRequest()') <
			strpos($source, "require_once( 'which.php' )"));
	}
}
