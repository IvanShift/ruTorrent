<?php

$updateInterval 	= 60;	// in minutes, zero for disable
$ignoreLabels 	= ['tv-sonarr', 'radarr'];	// list of labels to ignore

// The plugin's own knobs are assigned only when nothing has set them yet, so
// they can be overridden from the main conf/config.php (which is loaded first
// and, unlike this file, lives in the persistent profile). A plain assignment
// here would clobber that override on every request.
$rutrackerCheckDebug ??= false;	// write diagnostic messages to the configured ruTorrent log

$rutrackerFuseShare	??= 0.2;	// candidate share per announce host that trips the fuse
$rutrackerFuseFloor	??= 3;	// minimum absolute candidates before the fuse may trip
$rutrackerDeleteCycles	??= 3;	// dump+tracker confirmations required for STE_DELETED
$rutrackerMetaDeadline	??= 86400;	// seconds to wait for magnet metadata
$rutrackerMetaWait	??= 10;		// seconds to wait for it inside the cycle, before deferring to the next one
$rutrackerLayer2Enabled	??= true;	// announce confirmation layer
$rutrackerAnnouncePause	??= 5;	// seconds between probe announces
$rutrackerAnnounceCap	??= 10;	// probe announces per cycle per announce host
$rutrackerSweepCooldown	??= 86400;	// seconds between automatic full forum sweeps
