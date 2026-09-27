<?php

$updateInterval 	= 60;	// in minutes, zero for disable
$ignoreLabels 	= ['tv-sonarr', 'radarr'];	// list of labels to ignore

// ??=, not =: every evaluator of this file -- the CLI entry points
// (update.php, batch_check.php) and the web path (php/getplugins.php) --
// runs it in global scope after conf/config.php has already been loaded,
// so a plain assignment silently discards a value the administrator set
// there. The default applies only when nothing else has set the variable.
$rutrackerCheckDebug ??= false; // opt in to diagnostics in ruTorrent's shared application log

// One exact old generation for which the owner requested a single restart.
// Older releases lost the run-state key during a failed replacement, and the
// remaining fingerprint also occurs after a manual check of a stopped torrent.
// Bind recovery to this predecessor, successor and original check timestamp;
// any later check or a different torrent remains diagnostic only. The atomic
// chk-revived stamp prevents a second restart after the user stops it again.
$rutrackerLegacyRecoveryPairs ??= array(
    'E6B624DE55F3622EB9551E92A93BCC6F8C4DAC09' => array(
        'successor' => '0B0F0F15CBF33BE9741FCADE8BDC51FA7569080A',
        'checked_at' => '1790391624',
        'state_changed' => '1790391635',
        'state_counter' => '9',
    ),
);

// The fuse share is a fraction, not a percentage. A value above 1 uses the
// default 0.2 and writes one operator-visible log line per process; a negative
// value clamps to 0. Other numeric bounds are clamped where they are read:
// a delete-cycle count of 0 would settle a deletion on its first sighting.
$rutrackerForeignMaxRest ??= 86400; // maximum foreign UPTODATE rest in seconds; hash jitter spreads due checks
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
// Seconds between automatic full forum sweeps.
//
// Domain: a nonnegative PHP integer, or its canonical decimal spelling as a
// string. Unset or null means this default. Anything else -- an empty string,
// a boolean, a negative number, a float, '1e3', ' 24', '024', '+24', an array,
// an object, a decimal past PHP_INT_MAX -- is not read as a number at all:
// forumindex.php falls back to this same 86400 and says so once per process in
// ruTorrent's application log ("config: invalid rutrackerSweepCooldown").
// Noncanonical spellings that used to be coerced (notably '' and other text,
// which reached (int) as 0) therefore change meaning: they now get the
// one-day default rather than the old zero-length window.
//
// Valid values below 3600, including 0 and 24, use a one-hour floor without
// a warning. This also lengthens the missed-topic suppression window.
//
// Cost: a sweep issues one dump request per forum in the tracker's CURRENT
// tree, so the price follows that tree's size rather than any fixed number,
// and 304s, early exits and refusals all lower it. For scale only, the design
// sample of 2026-08-06 fetched 140 forums in 70 s over 6 parallel connections
// for 50.4 MB DECOMPRESSED, and extrapolated the then-listed 1261 forums to
// roughly 10-11 minutes parallel or about an hour sequentially. That is a
// historical sample and an extrapolation, not today's capture, not network
// volume, and not the guaranteed cost of any given run.
//
// The floor limits launch frequency; forumcrawl.php has no whole-crawl lock,
// so a crawl lasting longer than its interval may still overlap the next one.
$rutrackerSweepCooldown	??= 86400;	// >= 0 configured seconds, effective minimum 3600
