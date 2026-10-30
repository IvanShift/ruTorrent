<?php
require_once dirname(__DIR__, 2).'/php/cli-only.php';
rutorrent_require_cli(__FILE__);

// Run by the unpack scripts (stage.sh):
// place.php <how> <hash> <user> place <private directory> <output> <links the extractor skipped>
// place.php <how> <hash> <user> refuse|found <reason> <name>
if((count($argv) < 7) || !in_array($argv[4], array('place', 'refuse', 'found'), true) ||
	(($argv[4] === 'place') && (count($argv) < 8)))
{
	fwrite(STDERR, "usage: place.php how hash user place directory output skipped | refuse|found reason name\n");
	exit(2);
}
if($argv[3] !== '')
	$_SERVER['REMOTE_USER'] = $argv[3];
require_once( dirname(__FILE__)."/unpack.php" );
if($argv[4] !== 'place')
{
	rUnpack::report($argv[1], $argv[2], ($argv[4] === 'refuse') ? "refused" : "found", $argv[5], $argv[6]);
	exit(1);
}
foreach(rUnpack::PLACEMENT_FUNCTIONS as $function)
	if(!function_exists($function))
	{
		rUnpack::report($argv[1], $argv[2], "refused", "check-unavailable", $function);
		exit(1);
	}
exit(rUnpack::place($argv[1], $argv[2], $argv[5], $argv[6], (int)$argv[7]));
