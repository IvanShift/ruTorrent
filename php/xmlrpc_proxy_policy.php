<?php
// Included at the entrypoint's top level so variables from the operator's
// policy keep the same scope as the door's later plugin configuration.
// Resolve the class beside this loader because conf/ may be symlinked to a volume.
require_once(__DIR__.'/xmlrpc_proxy.php');
$xmlrpcProxyPolicyFile = dirname(__FILE__).'/../conf/xmlrpc_proxy.php';
if(is_file($xmlrpcProxyPolicyFile))
{
	if(!is_readable($xmlrpcProxyPolicyFile))
		return false;
	require_once($xmlrpcProxyPolicyFile);
}
return true;
