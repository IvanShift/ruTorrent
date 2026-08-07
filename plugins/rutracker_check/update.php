<?php

if( !chdir( dirname( __FILE__) ) )
	exit();

if( count( $argv ) > 1 )
	$_SERVER['REMOTE_USER'] = $argv[1];

require_once( "check.php" );
require_once( "updatepass.php" );

$req =  new rXMLRPCRequest(
		new rXMLRPCCommand("d.multicall",array("seeding",
			getCmd("d.get_hash="),
			getCmd("d.get_custom=")."chk-state",
			getCmd("d.get_custom=")."chk-time",
			getCmd("d.get_custom=")."chk-stime",
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

	RuTrackerUpdatePass::pollFeed();
	$result = RuTrackerUpdatePass::run($rows);
	RuTrackerUpdatePass::reapOrphans(time());

	if(count(RuTrackerForumIndex::takeQueuePeek()) && RuTrackerForumIndex::sweepAllowed(time()))
		shell_exec( Utility::getPHP()." -f ".escapeshellarg(dirname(__FILE__)."/forumcrawl.php")
			." ".escapeshellarg(User::getUser())." > /dev/null 2>&1 &" );

	ruTrackerChecker::logDebug("update: checked=".count($result['checked'])
		." uptodate=".$result['uptodate']." fused=".implode(',',$result['fused']));
}
