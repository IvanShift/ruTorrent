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

// The command names a caller may attach to a load.* or to a multicall are
// $XMLRPCProxySafeParams in conf/xmlrpc_proxy.php, which action.php loads
// before this file. One list serves every entry point that fronts rtorrent,
// so a client that works through one works through all of them.
//
// Setting the list here is still honoured, and still wins, because this file
// is evaluated after that one -- but it then applies to this entry point
// alone. Set it here only when this one is meant to differ.
