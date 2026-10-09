<?php
// Dry run by default; --apply enqueues at most --limit=N (default 50).
// Run as the daemon user. --user=NAME selects per-user settings only when
// ruTorrent does not share one profile ($forbidUserSettings).
require_once(dirname(__DIR__) . '/plugins/retrackers/clitool.php');
require_once(dirname(__DIR__) . '/plugins/retrackers/applyexisting.php');
$settings = rRetrackers::load();
$retrackersToolContext['trackers'] = array();
foreach ($settings->list as $tier) foreach ($tier as $url) $retrackersToolContext['trackers'][] = $url;
$retrackersToolContext['dont_add_private'] = !empty($settings->dontAddPrivate);
exit(RetrackersApplyExisting::main($args, new RetrackersQueueRpcAdapter(), STDOUT, STDERR, $retrackersToolContext));
