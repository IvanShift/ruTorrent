<?php
require_once(dirname(__DIR__) . '/php/cli-only.php');
rutorrent_require_cli(__FILE__);

// Clear one reviewed, exhausted retrackers queue retry state.
//
//   php tools/retrackers-retry-reset.php [--user=NAME]                list terminal jobs
//   php tools/retrackers-retry-reset.php [--user=NAME] HASH           dry run for one job
//   php tools/retrackers-retry-reset.php [--user=NAME] HASH --apply   clear its terminal state
//
// Run it as the rTorrent daemon's user, for example inside the Docker image:
//   docker exec -u torrent <container> php85 /rutorrent/app/tools/retrackers-retry-reset.php
// The queue belongs to the ruTorrent profile. With $forbidUserSettings = true
// (the Docker image's default) every login shares that one profile: run the
// tool without --user, and a --user given anyway selects the shared profile.
// With per-user settings, --user=<login> names the login's own profile. The
// worker's log line and the WebUI notice print the exact command.
// Exit status: 0 done or listed; 1 refused, nothing changed (also a hash this
// rTorrent does not hold); 2 usage, a daemon without the native queue
// commands, an unreachable daemon or an unconfirmed answer.
// The rules live in plugins/retrackers/retryreset.php.

$args = array_slice(isset($argv) && is_array($argv) ? $argv : array(), 1);
$user = '';
foreach ($args as $arg) {
	if (strncmp($arg, '--user=', 7) === 0) $user = substr($arg, 7);
}
// RetrackersProfileName::valid(), inline: REMOTE_USER precedes the profile load.
if (preg_match('/^[a-z0-9_-]*$/D', $user) !== 1) {
	fwrite(STDERR, "usage: retrackers-retry-reset.php [--user=NAME] [HASH [--apply]]\n");
	exit(2);
}
$_SERVER['REMOTE_USER'] = $user;
// Profile configuration is imported at file scope for the SCGI globals.
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_DISPATCH_IMPORT_ONLY', true);
require_once(dirname(__DIR__) . '/plugins/retrackers/retrackers.php');
require_once(dirname(__DIR__) . '/php/xmlrpc.php');
require_once(dirname(__DIR__) . '/php/rtorrent.php');
require_once(dirname(__DIR__) . '/plugins/retrackers/dispatch.php');
require_once(dirname(__DIR__) . '/plugins/retrackers/retryreset.php');
exit(RetrackersRetryReset::main($args, new RetrackersQueueRpcAdapter(), STDOUT, STDERR, array(
	'shared_profile' => !empty($forbidUserSettings),
	'scgi' => array('host' => $scgi_host, 'port' => $scgi_port),
)));
