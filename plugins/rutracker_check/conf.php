<?php

$updateInterval 	= 60;	// in minutes, zero for disable
$ignoreLabels 	= ['tv-sonarr', 'radarr'];	// list of labels to ignore

// ??=, not =: every evaluator of this file -- the CLI entry points
// (update.php, batch_check.php) and the web path (php/getplugins.php) --
// runs it in global scope after conf/config.php has already been loaded,
// so a plain assignment silently discards a value the administrator set
// there. The default applies only when nothing else has set the variable.
$rutrackerCheckDebug ??= false;	// write diagnostic messages to the configured ruTorrent log

// Out-of-range values are clamped where they are read rather than trusted:
// a fuse share written as 20 instead of 0.2 would make the fuse inert, and
// a delete-cycle count of 0 would settle a deletion on its first sighting.
$rutrackerFuseShare	??= 0.2;	// 0.0-1.0: candidate SHARE (a fraction, not a percent) per announce host that trips the fuse
$rutrackerFuseFloor	??= 3;	// >= 1: minimum absolute candidates before the fuse may trip
$rutrackerDeleteCycles	??= 3;	// >= 1: dump+tracker confirmations required for STE_DELETED
$rutrackerMetaDeadline	??= 86400;	// >= 0 seconds to wait for magnet metadata
$rutrackerMetaWait	??= 10;		// 0-60 seconds to wait for it inside the cycle, before deferring to the next one
$rutrackerLayer2Enabled	??= true;	// announce confirmation layer; disabling it also disables
					// deleted-topic detection: the forum dump alone may only ever
					// corroborate a deletion, never conclude one
$rutrackerAnnouncePause	??= 5;	// 0-60 seconds between probe announces
$rutrackerAnnounceCap	??= 10;	// 0-40 probe announces per persisted window per announce host; 0 disables the probe
$rutrackerSweepCooldown	??= 86400;	// >= 0 seconds between automatic full forum sweeps
