<?php

if( count( $argv ) > 1 )
	$_SERVER['REMOTE_USER'] = $argv[1];

if( !chdir( dirname( __FILE__) ) )
	exit();

require_once( "check.php" );
require_once( "forumindex.php" );

// Every resolved topic is written back to chk-forum through $byTopic, built
// from this multicall -- without it there is nothing useful sweep() could
// produce, so bail out before draining the queue or crawling: a topic
// resolved with no known hash could be neither written nor requeued, and
// draining the queue here would just lose whatever it held.
$req = new rXMLRPCRequest( new rXMLRPCCommand("d.multicall", array("main",
	getCmd("d.get_hash="),
	getCmd("d.get_custom=")."chk-topic",
	getCmd("d.get_custom=")."chk-forum")) );
if( !$req->success() )
	exit();

// Wanted set: the explicit queue (topics an update pass couldn't resolve
// from cache or feed) plus every t-ru torrent whose chk-topic is known but
// chk-forum isn't -- catches anything a caller queued and lost track of.
$wanted = RuTrackerForumIndex::takeQueue();
$byTopic = array();
for( $i = 0; $i < count($req->val); $i += 3 )
{
	$topic = (int) $req->val[$i + 1];
	if( $topic && $req->val[$i + 2] === "" )
	{
		$wanted[] = $topic;
		$byTopic[$topic][] = $req->val[$i];
	}
}
$wanted = array_values( array_unique( array_map('intval', $wanted) ) );
if( !count($wanted) )
	exit();

// Mark the cooldown before crawling, not after: a crawl that fails partway
// through (dead endpoint, network outage) must not be retried every cycle
// until the next scheduled sweep is due.
RuTrackerForumIndex::markSweep( time() );

// takeQueue() above already drained the persistent queue, so a topic that
// doesn't come out of sweep() resolved would otherwise vanish. sweep()
// returns null when the crawl couldn't even start (tree fetch failed); a
// thrown exception is treated the same way, since either means nothing was
// learned about any wanted topic. Both are transient, so requeue the whole
// wanted set and retry next cooldown. Any other return is a completed
// crawl: write back what it found, and mark everything still unresolved as
// a miss instead of requeueing it, so a topic the crawl actually proved
// absent stops retriggering sweeps forever (queueTopic() suppresses a
// fresh miss on its own).
$resolved = null;
try
{
	$resolved = RuTrackerForumIndex::sweep($wanted);
}
catch( Throwable $error )
{
	ruTrackerChecker::logDebug("forumcrawl: sweep failed: " . $error->getMessage());
}

if( $resolved === null )
{
	foreach( $wanted as $topic )
		RuTrackerForumIndex::queueTopic($topic);
	ruTrackerChecker::logDebug("forumcrawl: wanted " . count($wanted) . ", crawl failed");
	exit();
}

$now = time();
foreach( array_diff($wanted, array_keys($resolved)) as $topic )
	RuTrackerForumIndex::markMiss($topic, $now);

foreach( $resolved as $topic => $forum )
{
	foreach( ($byTopic[$topic] ?? array()) as $hash )
	{
		$write = new rXMLRPCRequest( new rXMLRPCCommand(
			getCmd("d.set_custom"), array($hash, "chk-forum", (string) $forum)) );
		$write->important = false;
		$write->success();
	}
}
ruTrackerChecker::logDebug("forumcrawl: wanted " . count($wanted) . ", resolved " . count($resolved));
