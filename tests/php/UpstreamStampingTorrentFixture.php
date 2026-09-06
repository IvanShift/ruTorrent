<?php

/**
 * Torrent with upstream Novik/ruTorrent's touch(), and nothing else changed.
 *
 * Upstream's touch() stamps 'created by' and 'creation date' on every call, and
 * announce()/announce_list()/comment()/is_private() and their clear_* siblings
 * all call it. This fork's Torrent::touch() returns first unless the class
 * built the info dictionary out of files on disk, so on a decoded .torrent
 * every setter leaves both keys alone.
 *
 * That fork-only quiet is why a snapshot-and-restore around a run of setters
 * looks like dead code here: delete one and every case that exercises it on
 * this fork stays green. Several places carry such a restore anyway --
 * plugins/edit/action.php, plugins/rutracker_check/trackers/nnmclub.php and
 * plugins/rutracker_check/metafetch.php -- because each ships to upstream
 * separately from php/Torrent.php, where the stamping is still live. Driving
 * the same code through this stand-in is what makes those restores testable:
 * remove one and the case written against this class fails, while the rest of
 * its file stays green.
 *
 * The body is upstream's, with the clock replaced by a constant so the stamp
 * can be asserted exactly.
 *
 * Requiring this file needs php/Torrent.php already loaded -- it is a subclass,
 * and Torrent.php wants the working directory set to php/ while it loads.
 */

if (!class_exists('Torrent', false))
    throw new RuntimeException('UpstreamStampingTorrentFixture.php needs php/Torrent.php loaded first');

class UpstreamStampingTorrent extends Torrent
{
	/** What this stand-in writes instead of time(). */
	const STAMP_DATE = 1700000000;

	protected function touch()
	{
		$this->setMeta('created by', 'ruTorrent (PHP Class - Adrien Gibrat)');
		$this->setMeta('creation date', self::STAMP_DATE);
	}
}
