<?php
require_once( 'util.php' );

class rCache
{
	protected $dir;
	// How each cache file looked when this process loaded it, keyed by cache key.
	protected static $loadStamps = [];

	public function __construct( $name = '' )
	{
		$this->dir = FileUtil::getSettingsPath().$name;
		if(!is_dir($this->dir))
			FileUtil::makeDirectory($this->dir);
	}
	public static function flock( $fp )
	{
		$i = 0;
		while(!flock($fp, LOCK_EX | LOCK_NB))
		{
			usleep(round(rand(0, 100)*1000));
			if(++$i>20)
				return(false);
		}
		return(true);
	}
	protected static function getCacheKey( $rss )
	{
		return(get_class($rss).':'.$rss->hash);
	}
	// A cache file's identity, used to tell whether it is still the one this
	// process loaded. filemtime resolves only to the second, so two writes
	// inside one second look identical -- and that is the common case here:
	// replacing a torrent fires three writers within the same second. set()
	// always publishes through rename(), which gives the file a fresh inode,
	// so the inode is what actually separates them.
	protected static function stampOf( $name )
	{
		clearstatcache(true, $name);
		$st = @stat($name);
		return($st===false ? null : $st['ino'].':'.$st['mtime'].':'.$st['size']);
	}
	// True when the file on disk is no longer the one this process loaded.
	protected static function hasChangedSinceLoad( $name, $stamp )
	{
		if(!is_null($stamp))
			return($stamp !== self::stampOf($name));
		// This process loaded nothing: the file was absent or unreadable when
		// get() ran. Any file present now was published by someone else, and
		// merging what is on disk is always safe for the merge-capable
		// classes that are the only callers of this check.
		return(is_file($name));
	}
	public function set( $rss, $arg = null )
	{
		global $profileMask;
		$name = $this->getName($rss);
		$lockName = $name.'.lock';
		// Keep one writer per cache key so merge and atomic rename see a stable file.
		$lock = fopen( $lockName, "c" );
		if($lock===false)
			return(false);
		@chmod($lockName,$profileMask & 0666);
		if(!self::flock( $lock ))
		{
			fclose($lock);
			return(false);
		}

		$cacheKey = is_object($rss) ? self::getCacheKey($rss) : null;
		$stamp = ($cacheKey !== null) ? (self::$loadStamps[$cacheKey] ?? null) : null;
		if(     is_object($rss) &&
			method_exists($rss,"merge") &&
			self::hasChangedSinceLoad($name, $stamp))
		{
			$className = get_class($rss);
			$newInstance = new $className();
			if($this->get($newInstance) &&
				!$rss->merge($newInstance, $arg))
			{
				flock( $lock, LOCK_UN );
				fclose( $lock );
				return(false);
			}
		}
		// Use a per-process temporary file and publish it atomically under the key lock.
		$tmpName = $name.'.'.getmypid().'.'.uniqid('', true).'.tmp';
		$fp = fopen( $tmpName, "wb" );
		if($fp!==false)
		{
			$str = serialize( $rss );
			if((fwrite( $fp, $str ) == strlen($str)) && fflush( $fp ))
			{
				if(fclose( $fp ) !== false)
				{
					@chmod($tmpName,$profileMask & 0666);
					if(@rename( $tmpName, $name ))
					{
						@chmod($name,$profileMask & 0666);
						flock( $lock, LOCK_UN );
						fclose( $lock );
						return(true);
					}
				}
				else
					@unlink( $tmpName );
			}
			else
				fclose( $fp );
		}
		@unlink( $tmpName );
		flock( $lock, LOCK_UN );
		fclose( $lock );
	        return(false);
	}
	public function get( &$rss )
	{
		$fname = $this->getName($rss);
		// Stamp before reading. If a concurrent rename lands between the stat
		// and the read, this process holds new content under an old stamp and
		// its set() merely performs one redundant merge. Stamping after the
		// read inverts that: a rename between read and stat pairs old content
		// with the new identity, and that concurrent write is never merged.
		$stamp = self::stampOf($fname);
		$ret = @file_get_contents($fname);
		if($ret!==false)
		{
			// Corrupt or legacy cache files can emit warnings; always restore the caller's handler.
			set_error_handler(function () { return true; });
			try {
				$tmp = unserialize($ret);
			} finally {
				restore_error_handler();
			}
			if(is_array($tmp))
			{
				$rss = $tmp;
				$ret = true;
			}
			else
			{
				if(($tmp!==false) &&
					(!isset($rss->version) ||
					(isset($rss->version) && !isset($tmp->version)) ||
					(isset($tmp->version) && ($tmp->version==$rss->version))))
				{
					$rss = $tmp;
					$cacheKey = self::getCacheKey($rss);
					self::$loadStamps[$cacheKey] = $stamp;
					$ret = true;
				}
				else
					$ret = false;
			}
		}
		return($ret);
	}
	public function remove( $rss )
	{
		global $profileMask;
		$name = $this->getName($rss);
		$lockName = $name.'.lock';
		// Delete cache data and its sidecar lock while holding the same key lock used by writers.
		$lock = fopen( $lockName, "c" );
		if($lock!==false)
		{
			@chmod($lockName,$profileMask & 0666);
			if(self::flock( $lock ))
			{
				$ret = @unlink($name);
				flock( $lock, LOCK_UN );
				fclose( $lock );
				@unlink($lockName);
				return($ret);
			}
			fclose($lock);
		}
		return(@unlink($name));
	}
	protected function getName($rss)
	{
	        return($this->dir."/".(is_object($rss) ? $rss->hash : $rss['__hash__']));
	}
	public function getModified( $obj = null )
	{
		return(@filemtime( is_null($obj) ? $this->dir :
			(is_object($obj) ? $this->getName($obj) : $this->dir."/".$obj) ));

	}
}
