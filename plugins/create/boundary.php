<?php

/**
 * What a create task hashes is served by rtorrent once it is seeded, so the
 * path and everything a creator reaches under it have to stay inside the top
 * directory once links are followed. mktorrent and the other external
 * creators follow links under a folder, so the check comes before any
 * creator starts rather than inside one creator's own walk.
 */
final class CreateBoundary
{
	/**
	 * Why a creator may not read $path, or null when it may. $path is
	 * normalised as correctReportedDirectory() does it. On a refusal $entry
	 * is the entry it is about, as the creator sees it, relative to the top
	 * directory the way the "Incorrect directory" answer shows it.
	 */
	public static function refusal(&$path, &$entry)
	{
		$reason = self::find($path, $at);
		// Only a refusal names an entry, byte for byte: plugin.info treats
		// mbstring as optional.
		global $topDirectory;
		$entry = ($reason === null) ? null : (string)substr($at, strlen($topDirectory) - 1);
		return($reason);
	}

	/** The reason refusal() gives, with $at set to the entry it is about. */
	private static function find(&$path, &$at)
	{
		$inside = rTorrentSettings::get()->correctReportedDirectory($path) && rTorrent::isInsideTopDirectory($path);
		$at = $path;
		if(!$inside)
			return('outside-top-directory');
		global $topDirectory;
		// Nothing is outside a top directory of "/", the default.
		if(FileUtil::addslash($topDirectory) === '/')
			return(null);
		// isInsideTopDirectory() above already refused without realpath() or
		// is_link(). Without these, PHP 7.4 returns null, which would skip
		// the links or, for is_dir(), the whole walk; PHP 8 throws.
		if(!function_exists('scandir') || !function_exists('is_dir'))
			return('walk-unavailable');
		if(!@is_dir($path))
			return(null);
		// Each real folder is listed once and without recursion, so a link
		// back up the tree cannot loop the walk. Lookups go through real
		// paths: the links in a path as the creator sees it ($name, kept for
		// the answer) count toward the kernel's limit, past which an entry
		// looks like neither a link nor a folder.
		$pending = array(array(rtrim($path, '/'), $path));
		$seen = array();
		while(count($pending))
		{
			list($name, $dir) = array_pop($pending);
			$at = $name;
			$real = @realpath($dir);
			if($real === false)
				return('unreadable-directory');
			if(isset($seen[$real]))
				continue;
			$seen[$real] = true;
			$items = @scandir($real);
			if(!is_array($items))
				return('unreadable-directory');
			foreach($items as $item)
			{
				if(($item === '.') || ($item === '..'))
					continue;
				$link = $real.'/'.$item;
				if(@is_link($link) && !rTorrent::isInsideTopDirectory($link))
				{
					$at = $name.'/'.$item;
					// As in isInsideTopDirectory(): a link that does not
					// resolve is refused too.
					return(is_string(@realpath($link)) ? 'link-outside-top-directory' : 'link-unresolved');
				}
				if(@is_dir($link))
					$pending[] = array($name.'/'.$item, $link);
			}
		}
		return(null);
	}

	/** Logs a refusal of $what with its reason and entry, one line whatever the name holds. */
	public static function log($what, $reason, $entry)
	{
		FileUtil::toLog($what.' refused reason='.$reason.' entry='.JSON::safeEncode($entry));
	}
}
