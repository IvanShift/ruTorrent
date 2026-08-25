<?php

/**
 * Filesystem-aware path resolution shared by the standalone and session XMLRPC endpoints.
 */
class XMLRPCPathResolver
{
	private static function normalizeResolvedPath($path)
	{
		return $path === '/' ? '/' : rtrim($path, '/');
	}

	/**
	 * Resolve a path through its deepest existing ancestor and retain its missing tail.
	 *
	 * @param mixed $path
	 * @return string
	 */
	public static function deepestExistingAncestor($path)
	{
		$real = @realpath($path);
		if($real !== false)
			return $real;

		$parts = explode('/', trim($path, '/'));
		$tail = array();
		while(count($parts) > 0)
		{
			array_unshift($tail, array_pop($parts));
			$base = '/'.implode('/', $parts);
			$real = @realpath(($base === '') ? '/' : $base);
			if($real !== false)
				return rtrim($real, '/').'/'.implode('/', $tail);
		}
		return '';
	}

	/**
	 * Inspect one filesystem name without deciding any caller-specific policy.
	 *
	 * Existing names must have a stable lexical entry, target identity and
	 * canonical path. Missing names retain a canonicalised intended location
	 * through deepestExistingAncestor(), so directory symlink aliases still
	 * compare correctly before a leaf is created.
	 *
	 * @param mixed $path
	 * @return array|false
	 */
	public static function filesystemIdentity($path)
	{
		if(!is_string($path) || $path === '' || $path[0] !== '/'
			|| strpos($path, "\0") !== false)
			return false;
		clearstatcache(true, $path);
		$exists = file_exists($path) || is_link($path);
		if(!$exists)
		{
			$resolved = self::deepestExistingAncestor($path);
			if($resolved === '') return false;
			return array(
				'exists' => false,
				'path' => self::normalizeResolvedPath($resolved),
				'lstat' => null,
				'stat' => null,
			);
		}

		$lstat = @lstat($path);
		$stat = @stat($path);
		$resolved = @realpath($path);
		if(!is_array($lstat) || !is_array($stat) || $resolved === false)
			return false;
		return array(
			'exists' => true,
			'path' => self::normalizeResolvedPath($resolved),
			'lstat' => array('dev'=>$lstat['dev'], 'ino'=>$lstat['ino']),
			'stat' => array('dev'=>$stat['dev'], 'ino'=>$stat['ino']),
		);
	}
}
