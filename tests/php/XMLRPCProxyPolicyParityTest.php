<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/xmlrpc_proxy.php');

/**
 * One safe-parameter policy for both HTTP entrypoints.
 *
 * The shipped conf calls XMLRPCProxy::defaultSafeParams() when available; the proxy
 * also uses that list when an older persisted conf volume lacks the file.
 * Other shipped overrides must agree with this default if they define a list.
 */
class XMLRPCProxyPolicyParityTest extends TestCase
{
	private $root = null;
	private $reference = null;
	private $scannedPolicyFiles = array();

	public function setUp()
	{
		$this->root = realpath(__DIR__ . '/../..');
		$this->reference = $this->safeParamsOf($this->root . '/conf/xmlrpc_proxy.php');
	}

	public function testShippedConfLoadsWithLegacyProxyClass()
	{
		// Before defaultSafeParams() existed, persisted conf/ could still be
		// shared with a rolled-back application tree.
		$script = 'class XMLRPCProxy {} '
			. 'require ' . var_export($this->root . '/conf/xmlrpc_proxy.php', true) . '; '
			. 'echo json_encode(array($XMLRPCProxy, $XMLRPCProxyLog, '
			. 'isset($XMLRPCProxySafeParams)));';
		list($exit, $output, $errors) = $this->runPhpChild($script);
		$this->assertEquals(0, $exit, 'shipped conf loads with a legacy proxy class: '.$errors);
		if($exit === 0)
			$this->assertEquals(array('sanitize', true, false), json_decode($output, true),
				'legacy proxy keeps the proxy mode and its earlier implicit policy');
	}

	public function testSharedPolicyLoaderAlsoLoadsTheProxyClass()
	{
		$script = 'require ' . var_export($this->root . '/php/xmlrpc_proxy_policy.php', true)
			. '; echo json_encode(array(class_exists("XMLRPCProxy"), '
			. 'isset($XMLRPCProxySafeParams), count($XMLRPCProxySafeParams ?? array())));';
		list($exit, $output, $errors) = $this->runPhpChild($script);
		$this->assertEquals(0, $exit, 'shared policy loader works without a preloaded class: '.$errors);
		if($exit === 0)
			$this->assertEquals(array(true, true, count(XMLRPCProxy::defaultSafeParams())),
				json_decode($output, true), 'the loader supplies the class and shipped policy');
	}

	private function runPhpChild($script)
	{
		$process = proc_open(array(PHP_BINARY, '-c', __DIR__ . '/../php-test.ini', '-r', $script),
			array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
			$pipes);
		if(!is_resource($process))
			throw new RuntimeException('policy child did not start');
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[2]);
		return array(proc_close($process), $output, $errors);
	}

	/**
	 * The list a policy file defines on its own, or null when it defines none.
	 * Evaluated inside a closure so one file's other variables cannot reach the
	 * next.
	 */
	private function safeParamsOf($file)
	{
		$read = function ($f) {
			$XMLRPCProxySafeParams = null;
			require($f);
			return $XMLRPCProxySafeParams;
		};
		return $read($file);
	}

	/** Every shipped conf file that assigns the list, other than the reference. */
	private function otherDefiners()
	{
		$found = array();
		$this->scannedPolicyFiles = array();
		foreach (array('/conf', '/plugins') as $sub) {
			$dir = $this->root . $sub;
			if (!is_dir($dir)) {
				continue;
			}
			$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
				$dir, FilesystemIterator::SKIP_DOTS));
			foreach ($walk as $file) {
				$path = $file->getPathname();
				if (!preg_match('`/(conf|conf\.local|xmlrpc_proxy)\.php$`', $path)) {
					continue;
				}
				if ($path === $this->root . '/conf/xmlrpc_proxy.php') {
					continue;
				}
				$this->scannedPolicyFiles[] = $path;
				$src = @file_get_contents($path);
				if (($src !== false) && preg_match('`\$XMLRPCProxySafeParams\s*=`', $src)) {
					$found[] = $path;
				}
			}
		}
		sort($found);
		return $found;
	}

	public function testTheSharedPolicyCarriesTheViewActions()
	{
		$this->assertTrue(is_array($this->reference) && (count($this->reference) > 0),
			'conf/xmlrpc_proxy.php defines $XMLRPCProxySafeParams');
		// A client sends these as a multicall over a view to stop or resume
		// everything. Left out, the multicall carries a command this side does
		// not rebuild, so the proxy rejects the entire call before transport.
		foreach (array('d.open', 'd.close', 'd.start', 'd.stop') as $action) {
			$this->assertTrue(in_array($action, (array)$this->reference, true),
				"the shared policy allows {$action}");
		}
	}

	/** The shipped configuration must resolve to the proxy's built-in list. */
	public function testTheBuiltInDefaultMatchesTheSharedPolicy()
	{
		$builtIn = XMLRPCProxy::defaultSafeParams();
		$this->assertTrue(is_array($builtIn) && (count($builtIn) > 0),
			'the proxy carries a built-in default');
		$reference = (array)$this->reference;
		sort($reference);
		sort($builtIn);
		$this->assertEquals($reference, $builtIn,
			'the built-in default states the same policy as conf/xmlrpc_proxy.php');
	}

	public function testEveryOtherDefinitionOfThePolicyAgreesWithIt()
	{
		$reference = (array)$this->reference;
		sort($reference);
		$others = $this->otherDefiners();
		$this->assertTrue(in_array($this->root . '/plugins/httprpc/conf.php',
			$this->scannedPolicyFiles, true),
			'the scan inspects the plugin override that can replace the shared policy');
		foreach ($others as $path) {
			$theirs = (array)$this->safeParamsOf($path);
			sort($theirs);
			$name = substr($path, strlen($this->root) + 1);
			$this->assertEquals(json_encode($reference), json_encode($theirs),
				"{$name} states the same safe-parameter policy as conf/xmlrpc_proxy.php");
		}
	}
}
