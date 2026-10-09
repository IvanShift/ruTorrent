<?php

// Shared profile bootstrap for the two operator tools. Include at file scope:
// ruTorrent imports its profile configuration into the SCGI globals there.
require_once(__DIR__ . '/../../php/cli-only.php');
rutorrent_require_cli(__FILE__);
$args = array_slice(isset($argv) && is_array($argv) ? $argv : array(), 1);
$user = '';
foreach ($args as $arg) if (strncmp($arg, '--user=', 7) === 0) $user = substr($arg, 7);
// REMOTE_USER must be validated before cache.php loads the profile; this is
// the same grammar as RetrackersProfileName::valid(), loaded below.
if (preg_match('/^[a-z0-9_-]*$/D', $user) !== 1) {
	fwrite(STDERR, "invalid --user: expected lowercase profile name\n");
	exit(2);
}
$_SERVER['REMOTE_USER'] = $user;
// Settings reads must not create profiles or send bootstrap mutations.
define('RUTORRENT_SETTINGS_READ_ONLY', true);
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_DISPATCH_IMPORT_ONLY', true);
require_once(__DIR__ . '/retrackers.php');
require_once(__DIR__ . '/../../php/xmlrpc.php');
require_once(__DIR__ . '/../../php/rtorrent.php');
require_once(__DIR__ . '/dispatch.php');
require_once(__DIR__ . '/retryreset.php');
$retrackersToolContext = array('shared_profile' => !empty($forbidUserSettings),
	'scgi' => array('host' => $scgi_host, 'port' => $scgi_port));
