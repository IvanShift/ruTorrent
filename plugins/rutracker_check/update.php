<?php

if( !chdir( dirname( __FILE__) ) )
	exit();

if( count( $argv ) > 1 )
	$_SERVER['REMOTE_USER'] = $argv[1];

require_once( "check.php" );
require_once( "updatepass.php" );

// Two cycles were seen running three seconds apart on the live system, each
// repeating the other's work. See RuTrackerState::acquireCycleLock() for why
// that is worse than wasteful. The handle stays in scope for the whole file so
// the lock is held until this process exits.
$cycleLock = RuTrackerState::acquireCycleLock();
if($cycleLock === false)
{
	ruTrackerChecker::logDebug("update: another cycle is still running, this one stands down");
	exit();
}

// The scheduler starts this file as a detached "sh -c ... &", so PHP's own
// stderr goes nowhere. A fatal partway through the cycle therefore leaves no
// trace whatsoever, and the summary at the bottom never runs -- indistinguishable
// from a cycle that was never scheduled at all. This line brackets the cycle from
// the front, so a start without its matching summary pins the death to this file.
// The rTorrent version rides along: an upgrade on the live system turned out to
// change how a cycle behaves, and nothing recorded which version produced which
// cycle. It is asked of the daemon rather than of the cached settings, which go
// stale across exactly the upgrade this is meant to catch.
// src tells the two apart: rTorrent's scheduler always passes the user name as
// argv[1] (empty string included), a hand run from the shell passes nothing.
ruTrackerChecker::logDebug("update: cycle start ".ruTrackerChecker::liveVersionLabel()
	." src=".(count($argv) > 1 ? "scheduler" : "cli"));

// Before this cycle can create new work, finish what a crashed one left
// behind: a replacement whose transaction died between erasing the old
// torrent and activating the new one is invisible to everything below --
// stopped and closed, so outside the "seeding" view, and with no predecessor
// left for a check to reach it through. Deliberately outside the seeding
// request's success gate, so the repair still runs on a cycle where the
// daemon is flaky, and under the cycle lock taken above.
RuTrackerUpdatePass::sweepReplacements(time());

$req =  new rXMLRPCRequest(
		new rXMLRPCCommand("d.multicall",array("seeding",
			getCmd("d.get_hash="),
			getCmd("d.get_custom=")."chk-state",
			getCmd("d.get_custom=")."chk-time",
			getCmd("d.get_custom1="),
			getCmd("d.get_message="),
			getCmd("d.get_custom=")."chk-del",
			getCmd("d.get_custom=")."chk-msg",
			getCmd("cat").'="$'.getCmd("t.multicall=").getCmd("d.get_hash=").","
				.getCmd("t.get_url")."=,".getCmd("cat=|").","
				.getCmd("t.is_enabled=").",".getCmd("cat=|").","
				.getCmd("t.failed_counter=").",".getCmd("cat=|").","
				.getCmd("t.success_counter=").",".getCmd("cat=#").'"'
		))
	);
if($req->success())
{
	// Only rows carrying a supported tracker go on to the update pass, same
	// as before -- but a torrent's tracker list can start with dht:// or any
	// other row, so every row is checked, not just the first.
	$rows = array();
	$supported = ruTrackerChecker::supportedTrackers();
	foreach(RuTrackerUpdatePass::parseMulticall($req->val) as $row)
		if(RuTrackerUpdatePass::isTrackerSupported($row['trackers'], $supported))
			$rows[] = $row;

	$forumChanged = RuTrackerUpdatePass::pollFeed();
	$result = RuTrackerUpdatePass::run($rows, $forumChanged);
	RuTrackerUpdatePass::reapOrphans(time());

	RuTrackerForumIndex::spawnCrawl();

	ruTrackerChecker::logDebug("update: cycle done"
		." checked=".count($result['checked'])
		." uptodate=".$result['uptodate']." fused=".implode(',',$result['fused']));
}
else
	// The one outcome that used to be completely silent: every statement above,
	// the summary included, hangs off this request succeeding, so a daemon that
	// is down, busy or refusing the call produced an empty log and looked exactly
	// like a plugin with diagnostics switched off.
	ruTrackerChecker::logDebug("update: aborted, the seeding multicall failed"
		.($req->fault ? ": ".$req->faultString : ""));
