<?php
require_once(__DIR__.'/../../php/TestCase.php');
function rpcMethodCapability($required, &$available = null)
{
	$available = ErasedataNativeCapabilityTest::$methods;
	return(ErasedataNativeCapabilityTest::$capability);
}
require_once(__DIR__.'/../../../plugins/erasedata/native.php');
require_once(__DIR__.'/CollectorFixture.php');
class ErasedataNativeCapabilityTest extends TestCase
{
	public static $methods = null;
	public static $capability = 'unconfirmed';
	public function testOnlyConfirmedStockWithoutAnyNativeExtensionKeepsLegacyBoundary()
	{
		rXMLRPCRequest::$responses = array('system.client_version' => array('ok'=>true, 'val'=>array('0.9.8')));
		self::$capability = 'unsupported'; self::$methods = array('system.listMethods');
		$this->assertEquals('legacy', erasedataNativeCapability(), 'known stock0.9.8 retains the user-approved historical behavior');
		foreach(array('system.erasedata.prepare','system.erasedata.cancel','system.erasedata.future','d.incarnation') as $method)
		{
			self::$methods = array('system.listMethods', $method);
			$this->assertEquals('unsupported', erasedataNativeCapability(), 'a partial or extended native ABI never falls back: '.$method);
		}
		self::$methods = array('system.listMethods');
		rXMLRPCRequest::$responses['system.client_version'] = array('ok'=>false);
		$this->assertEquals('unsupported', erasedataNativeCapability(), 'unknown version cannot select the stock exception');
		rXMLRPCRequest::$responses['system.client_version'] = array('ok'=>true, 'val'=>array('0.16.25'));
		$this->assertEquals('unsupported', erasedataNativeCapability(), 'current daemon missing the ABI refuses admission');
		rXMLRPCRequest::$responses['system.client_version'] = array('ok'=>true,'val'=>array('0.9.8'),
			'responseTypes'=>array('struct'),'responseValues'=>array(array('version'=>'0.9.8')));
		$this->assertEquals('unsupported',erasedataNativeCapability(),'a flattened struct cannot claim the stock version');
		self::$capability = 'unconfirmed'; self::$methods = null;
		$this->assertEquals('unconfirmed', erasedataNativeCapability(), 'unknown method snapshot never falls back');
		self::$capability = 'unsupported';
		$this->assertEquals('unconfirmed', erasedataNativeCapability(), 'an absent method snapshot cannot claim known unsupported');
		self::$capability = 'available';
		$this->assertEquals('available', erasedataNativeCapability(), 'complete native capability selects the fenced path');
	}
}
