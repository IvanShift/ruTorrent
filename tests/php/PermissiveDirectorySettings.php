<?php

/**
 * For rTorrentSettings doubles in tests that build a load or a command without
 * a daemon to resolve directories against: every directory is accepted, so
 * the code under test reaches the payload it builds. One definition keeps the
 * override in step with rTorrentSettings::correctDirectory()'s signature.
 */
trait PermissiveDirectorySettings
{
	public function correctDirectory(&$dir, $resolve_links = false, $preserve_top = false)
	{
		return true;
	}
	public function correctReportedDirectory(&$dir, $resolve_links = false, $preserve_top = false)
	{
		return true;
	}
}
