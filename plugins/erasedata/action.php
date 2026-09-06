<?php

require_once( '../../php/xmlrpc.php' );
require_once( 'removewithdata.php' );

// Bounded, index-checked parsing of the raw form body.
//
// The upstream loop split each entry on "=" with no limit and then read
// $parts[1] unconditionally, so a body carrying a bare "hash" -- or any entry
// with no "=" at all -- raised an undefined-index notice before anything could
// refuse it, and an entry carrying several "=" silently lost everything after
// the second one. Both are fixed here rather than after the fact: a request
// this door cannot account for entry by entry is refused whole, before the
// shared admission API is entered at all.
//
// The bounds below are deliberately literals rather than constants borrowed
// from removewithdata.php: this file is the outermost door, and what it refuses
// must not depend on which helper happened to load first.
$hash = array();
$vs = array();
$mode = "";
$malformed = 0;
if (!isset($HTTP_RAW_POST_DATA))
	$HTTP_RAW_POST_DATA = file_get_contents("php://input");
if(is_string($HTTP_RAW_POST_DATA) && $HTTP_RAW_POST_DATA !== "")
{
	// 64 KiB and 4096 entries: a "remove and delete data" over a thousand
	// downloads fits comfortably, and a body that does not is refused rather
	// than parsed into an unbounded array.
	if(strlen($HTTP_RAW_POST_DATA) > 65536)
		$malformed++;
	else
	{
		$entries = explode('&', $HTTP_RAW_POST_DATA);
		if(count($entries) > 4096)
			$malformed++;
		else
			foreach($entries as $entry)
			{
				$parts = explode("=", $entry, 2);
				if(count($parts) !== 2 || $parts[0] === "")
				{
					$malformed++;
					continue;
				}
				switch($parts[0])
				{
					case "hash": $hash[] = $parts[1]; break;
					case "v":    $vs[]   = rawurldecode($parts[1]); break;
					case "mode": $mode   = $parts[1]; break;
				}
			}
	}
}

$result = null;
if($malformed === 0 && $mode == "removewithdata" && count($hash))
{
	// The wire boundary, and the only place a decimal spelling becomes the
	// integer force everything below this line speaks. An unreadable force is
	// refused here; it is never coerced to "delete the download's own files".
	$forceDelete = isset($vs[0]) ? ErasedataManifestCodec::normalizeForce($vs[0]) : null;
	if(!is_null($forceDelete))
		// One generation-bound admission transaction, shared with the CLI door
		// in erase.php. Neither door reaches the destructive producer directly.
		$result = erasedataAdmitRemoval($hash, $forceDelete);
}

if(is_null($result))
{
	header("HTTP/1.0 500 Server Error");
	CachedEcho::send("Could not reach rTorrent over XMLRPC. Is rTorrent running?", "text/html");
}
else
	CachedEcho::send(JSON::safeEncode($result), "application/json");
