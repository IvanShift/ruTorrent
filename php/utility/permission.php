<?php

class Permission
{
	// PHP builds without ext-posix still expose the effective UID on Linux.
	public static function effectiveUid()
	{
		if(function_exists('posix_geteuid'))
			return(posix_geteuid());
		$status = @file_get_contents('/proc/self/status');
		if(is_string($status) && preg_match('/^Uid:[ \t]*[0-9]+[ \t]+([0-9]+)/m', $status, $match))
			return((int)$match[1]);
		return(false);
	}

	private static function isUserHavePrim($uid,$gids,$file,$flags)
	{
		$ss=LFS::stat($file);
		if($ss)
		{
			// POSIX selects exactly one class: owner, matching group, or other.
			if($uid==$ss['uid'])
				$flags<<=6;
			else
				foreach($gids as $gid)
				{
					// Group IDs arrive as strings from command output, so they
					// are compared as numbers -- and only when the entry spells
					// one, because anything else names no group at all.
					$id=(int)$gid;
					if(((string)$id===(string)$gid) && ($id===(int)$ss['gid']))
					{
						$flags<<=3;
						break;
					}
				}
			return(($ss['mode'] & $flags) == $flags);
		}
		return(false);
	}

	public static function doesUserHave($uid,$gids,$file,$flags)
	{
		require_once(__DIR__.'/lfs.php');
		if($uid<=0)
		{
				if(($flags & 0x0001) && !is_dir($file))
						return(($ss=LFS::stat($file)) && ($ss['mode'] & 0x49));
				else
				return(true);
		}
		if(is_link($file))
		{
			try { $newfile = @readlink($file); }
			catch(Throwable $error) { return(false); }
			if(!is_string($newfile) || ($newfile === ''))
				return(false);
			$file = substr($newfile, 0, 1) === '/' ? $newfile
				: realpath(dirname($file) . DIRECTORY_SEPARATOR . $newfile);
		}
		if(self::isUserHavePrim($uid,$gids,$file,$flags))
		{
			if(($flags & 0x0002) && !is_dir($file))
				$flags = 0x0003;
			else
				$flags = 0x0001;
			return(self::isUserHavePrim($uid,$gids,dirname($file),$flags));
		}
		return(false);
	}
}
