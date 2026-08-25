<?php

// CLI entry point for "erase this download and delete its data", for callers
// that run inside rtorrent and have no PHP context of their own -- the ratio
// group commands built in plugins/ratio/ratio.php.
//
// Usage: php erase.php <hash> <force> [user]
//   force: required; exact 1 deletes the download's own files, exact 2 deletes
//          the whole base path when $enableForceDeletion is on.
//   user:  the ruTorrent user, on multi-user installs. Trails the other
//          arguments because it is empty on a single-user install.
//
// The file list is read over RPC and recorded for the garbage collector before
// the download is erased, which is the sequence the web UI's "Remove and delete
// data" takes as well.

$hash = isset($argv[1]) ? $argv[1] : "";
$force = isset($argv[2]) ? $argv[2] : null;
$user = isset($argv[3]) ? $argv[3] : "";

if(!preg_match('/^[0-9A-Fa-f]{40}$/', $hash))
	exit(1);
require_once( dirname(__FILE__)."/manifest.php" );
if(is_null(ErasedataManifestCodec::normalizeForce($force)))
	exit(1);
if($user !== "")
	$_SERVER['REMOTE_USER'] = $user;

require_once( dirname(__FILE__)."/../../php/xmlrpc.php" );
require_once( dirname(__FILE__)."/removewithdata.php" );

// A pending manifest may belong to an older torrent generation with the same
// infohash, so it cannot suppress this generation's erase request.
exit((erasedataRemoveWithData(array($hash), $force) === false) ? 1 : 0);
