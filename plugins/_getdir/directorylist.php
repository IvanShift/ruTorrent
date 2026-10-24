<?php

class rDirectoryList
{
	public static function scan($directory)
	{
		try
		{
			$entries = @scandir($directory, SCANDIR_SORT_NONE);
		}
		catch(Throwable $e)
		{
			return(null);
		}
		if(!is_array($entries))
			return(null);
		foreach($entries as $entry)
			if(!is_string($entry))
				return(null);
		return($entries);
	}

	public static function compare($a, $b)
	{
		if($a === $b)
			return(0);
		if($a === '.' || $b === '.')
			return($a === '.' ? -1 : 1);
		if($a === '..' || $b === '..')
			return($a === '..' ? -1 : 1);
		try
		{
			if(@function_exists('mb_strtolower'))
			{
				$left = @mb_strtolower($a);
				$right = @mb_strtolower($b);
				if(is_string($left) && is_string($right))
					return(strcmp($left, $right));
			}
		}
		catch(Throwable $e) {}
		return(strcasecmp($a, $b));
	}
}
