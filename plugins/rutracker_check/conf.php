<?php

$updateInterval 	= 60;	// in minutes, zero for disable
$ignoreLabels 	= ['tv-sonarr', 'radarr'];	// list of labels to ignore

// ??=, not =: every evaluator of this file -- the CLI entry points
// (update.php, batch_check.php) and the web path (php/getplugins.php) --
// runs it in global scope after conf/config.php has already been loaded,
// so a plain assignment silently discards a value the administrator set
// there. The default applies only when nothing else has set the variable.
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
