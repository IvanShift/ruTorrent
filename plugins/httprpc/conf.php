<?php

// Raw XMLRPC proxy configuration for the httprpc plugin.
//
// The primary shared policy lives in conf/xmlrpc_proxy.php.
// User/per-install overrides can still be placed in this file or in
// conf/users/<user>/plugins/httprpc/conf.php.

$commonConf = dirname(__FILE__)."/../../conf/xmlrpc_proxy.php";
if(is_file($commonConf))
	require_once($commonConf);

// Only define defaults if not already defined in conf/xmlrpc_proxy.php:
if(!isset($XMLRPCProxy))
	$XMLRPCProxy = "sanitize";

if(!isset($XMLRPCProxyLog))
	$XMLRPCProxyLog = true;

if(!isset($XMLRPCProxyAllowLocalPaths))
	$XMLRPCProxyAllowLocalPaths = false;

if(!isset($XMLRPCProxySafeParams))
{
	$XMLRPCProxySafeParams = array(
		'd.custom1.set',            // label
		'd.custom2.set',            // custom field
		'd.custom3.set',            // custom field
		'd.custom4.set',            // custom field
		'd.custom5.set',            // used by erasedata
		'd.custom.set',             // generic custom field
		'd.directory.set',          // download directory
		'd.directory_base.set',     // base directory
		'd.priority.set',           // priority
		'd.throttle_name.set',      // throttle group
		'd.views.push_back_unique', // view membership
		'd.delete_tied',            // delete .torrent on remove

		// Actions (not setters):
		'd.open', 'd.close', 'd.start', 'd.stop',
	);
}
