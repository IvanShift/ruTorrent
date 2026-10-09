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

require_once(dirname(__DIR__) . '/plugins/retrackers/clitool.php');
exit(RetrackersRetryReset::main($args, new RetrackersQueueRpcAdapter(), STDOUT, STDERR, $retrackersToolContext));
