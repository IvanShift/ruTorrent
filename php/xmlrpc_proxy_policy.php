<?php
// Included at the entrypoint's top level so variables from the operator's
// policy keep the same scope as the door's later plugin configuration.
$xmlrpcProxyPolicyFile = dirname(__FILE__).'/../conf/xmlrpc_proxy.php';
if(is_file($xmlrpcProxyPolicyFile))
{
	if(!is_readable($xmlrpcProxyPolicyFile))
		return false;
	require_once($xmlrpcProxyPolicyFile);
}
return true;
