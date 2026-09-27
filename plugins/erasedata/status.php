<?php

require_once('../../php/xmlrpc.php');
require_once('removewithdata.php');

// The retirement scanner already accounts for every durable queue artifact.
// Report counts only: filenames, paths and hashes belong in the server log.
$scan = erasedataRetirementScan(array(
	'listPath' => FileUtil::getSettingsPath().'/erasedata',
));
CachedEcho::send(JSON::safeEncode(array(
	'empty' => $scan['empty'],
	'unreadable' => $scan['unreadable'],
	'candidates' => $scan['candidates'],
)), 'application/json');
