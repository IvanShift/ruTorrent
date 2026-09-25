<?php

	// Policy for raw XMLRPC pass-through, shared by every entry point that
	// fronts rtorrent: the httprpc plugin's proxy and rpc2.php.
	//
	// It lives here rather than in plugins/httprpc/conf.php so that there is
	// one policy rather than one per caller. plugins/httprpc/conf.php still
	// works and still wins where it sets something, so an existing edit is not
	// lost — but new deployments should edit this file.

	// "sanitize"           — (default) rebuild what can be rebuilt, refuse the
	//                        execution primitives, pass the rest to rtorrent
	//                        untrusted and let its own allowlist decide
	// "passthrough_unsafe" — send everything as trusted (DANGEROUS)
	// "off"                — reject all raw XMLRPC pass-through
	$XMLRPCProxy = "sanitize";

	// Log what the proxy decided and why.
	$XMLRPCProxyLog = true;

	// Command names allowed as a command parameter of load.* and of the
	// multicalls. Full names, matched exactly: 'd.custom' does NOT cover
	// 'd.custom1.set'. Any denied or unrecognised command in a load.* or in
	// a multicall causes the entire call to be rejected. Directory setters
	// are accepted only in load tails, never in multicall filters or results.
	//
	// This list is about WRITING. Commands that only read -- d.name, d.hash,
	// t.url and the rest of what a client asks for when it wants a listing --
	// are allowed in a multicall's result slots by $safeGetters in
	// php/xmlrpc_proxy.php, which is not configurable for the same reason
	// $denyPrefixes and $elevate are not: it states what the proxy is, not
	// how this installation is set up. Nothing here needs to repeat them.
	//
	// Leaving this file out entirely is not the same as setting the list
	// empty. This shipped file asks the proxy for its built-in default, so an
	// install without this file still works; an install that sets the list
	// empty here has said to forbid every command parameter that WRITES, and
	// is obeyed -- a multicall of read commands still goes through, because
	// the read list is the proxy's own and not this file's to withdraw.
	// The filter slot of d.multicall.filtered uses this list alone, not the
	// built-in readers. With this shipped list only a setter can be a filter;
	// a read filter such as d.is_active= is refused unless explicitly added.
	// An empty list refuses every filtered multicall. To refuse raw
	// pass-through altogether, set $XMLRPCProxy to "off".
	// An explicit list is a complete override, including after an upgrade;
	// review it when adopting new defaults instead of silently widening it.
	// Upgrade: persisted conf/ volumes keep their existing file. This shipped
	// file inherits d.directory.base.set, f.set_create_queued and
	// f.set_resize_queued from the built-in default below. In a persisted file
	// with an explicit $XMLRPCProxySafeParams = array(...), append those three
	// quoted names inside that array (and any httprpc override). An upgrade
	// does not widen an explicit custom list automatically.
	// The shared policy loader provides XMLRPCProxy before this file is included.
	// A persisted conf/ volume may be read by an older proxy after rollback.
	// Leave its earlier implicit policy in place when this method is absent.
	if(method_exists('XMLRPCProxy', 'defaultSafeParams'))
		$XMLRPCProxySafeParams = XMLRPCProxy::defaultSafeParams();

	// Let a caller name a path on rtorrent's own filesystem in load.start or
	// load.normal (default: false).
	//
	// rtorrent treats a load URI that is not an http/https/ftp or magnet URI as
	// a path on its own machine: it opens that file and records it as the
	// download's tied file, which d.delete_tied later unlinks. A remote client
	// has no way to know what is on that filesystem and does not need this —
	// clients send a URL, a magnet, or the torrent itself via load.raw_start.
	//
	// Turn it on only if something you run posts server-local paths through the
	// proxy, and note that it lets that caller choose which file d.delete_tied
	// removes. rTorrent resolves the path after PHP has made its policy decision,
	// so a process that can also replace path components may race that decision;
	// enabling this flag explicitly accepts that conditional risk. Each forwarded
	// local path is logged. ruTorrent's own "add torrent" and rTorrent's internal
	// watch scheduler do not go through this proxy, so this does not affect them.
	$XMLRPCProxyAllowLocalPaths = false;

	// Allow "/" as the boundary for where a caller may have a download written
	// (default: false).
	//
	// d.directory.set and d.directory_base.set name the directory rtorrent
	// writes a download into, and the caller supplies the torrent, so they name
	// the file too. They are confined to $topDirectory from conf/config.php,
	// which is the same boundary correctDirectory() already holds the panel to.
	//
	// Stock ruTorrent ships $topDirectory = "/", which confines nothing. A check
	// that is present but permits everything is worse than none, so with that
	// setting this endpoint refuses to serve until somebody has decided which it
	// is: either set $topDirectory to the directory downloads belong under, or
	// set this to true and accept that a caller may write anywhere the rtorrent
	// user can. On a single-user box where the only caller is you, that may be
	// exactly what you want -- but say it on purpose.
	//
	// This switch is independent of $XMLRPCProxyAllowLocalPaths. Set both to true
	// only when the caller must use server-local .torrent paths and $topDirectory
	// intentionally remains "/". sanitize mode still rejects dangerous RPC
	// command families regardless of these two path switches.
	$XMLRPCProxyAllowRootDirectory = false;
