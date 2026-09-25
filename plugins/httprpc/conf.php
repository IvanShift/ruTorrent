<?php

// Raw XMLRPC proxy configuration for the httprpc plugin.
//
// The primary shared policy lives in conf/xmlrpc_proxy.php.
// User/per-install overrides can still be placed in this file or in
// conf/users/<user>/plugins/httprpc/conf.php.

// $XMLRPCProxySafeParams in conf/xmlrpc_proxy.php lists writable commands
// allowed in load.* tails, multicall result slots, and the filter slot of
// d.multicall.filtered. Read-only result commands come from $safeGetters in
// php/xmlrpc_proxy.php and cannot be removed by changing this list. Set
// $XMLRPCProxy = "off" to disable raw pass-through entirely. action.php loads
// the shared policy before this file.
//
// Setting the list here is still honoured, and still wins, because this file
// is evaluated after that one -- but it then applies to this entry point
// alone. Set it here only when this one is meant to differ.
