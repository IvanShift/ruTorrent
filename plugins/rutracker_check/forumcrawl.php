<?php

if( count( $argv ) > 1 )
	$_SERVER['REMOTE_USER'] = $argv[1];

if( !chdir( dirname( __FILE__) ) )
	exit();

require_once( "check.php" );
require_once( "forumindex.php" );

// The whole transaction lives in RuTrackerForumIndex::runCrawl(), extracted
// so that its decisions -- bail before draining the queue when the fleet
// scan fails, requeue everything on a failed crawl, record misses only on a
// completed one -- are testable. This driver supplies only the process
// boilerplate and the log.
$line = RuTrackerForumIndex::runCrawl( time() );
if( $line !== null )
	ruTrackerChecker::logDebug( "forumcrawl: " . $line );
