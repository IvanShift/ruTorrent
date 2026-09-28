<?php
require_once( 'util.php' );

class rCache
{
	protected $dir;
	// A loaded object's exact file version, without retaining the object itself.
	protected static $loadedStates = [];

	public function __construct( $name = '' )
	{
		global $profileMask;
		$settings = FileUtil::getSettingsPath();
		FileUtil::makeDirectory($settings,
			(isset($profileMask) ? $profileMask : 0777) | 01000, true);
		$this->dir = $settings.$name;
		if($name !== '')
			FileUtil::makeDirectory($this->dir, null, true);
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
	private static function loadedState( $object, $name )
	{
		$id = spl_object_id($object);
		$state = self::$loadedStates[$id] ?? null;
		return($state !== null && $state['object']->get() === $object
			&& $state['path'] === $name ? $state : null);
	}
	private static function rememberLoadedState( $object, $name, $stamp, $bytes )
	{
		self::$loadedStates[spl_object_id($object)] = array(
			'object' => WeakReference::create($object), 'path' => $name,
			'stamp' => $stamp, 'digest' => hash('sha256', $bytes));
	}
	private static function logRefusal( $operation, $name, $reason )
	{
		$key = substr(preg_replace('/[^a-z0-9_.-]/i', '?', basename($name)), 0, 96);
		FileUtil::toLog('rCache: '.$operation.' refused: '.$reason.'; key='.$key
			.'; cache entry unchanged');
	}
	// A key names one file inside the cache directory: the shipped ones are a
	// '<name>.dat', an md5 or a short fixed word. It is concatenated into a
	// path, and it is not always internal -- plugins/rss/action.php takes one
	// from a request parameter -- so a key holding a separator would choose
	// which file is read and unserialized, or which file a store replaces.
	protected static function isValidKey( $key )
	{
		return(is_string($key) && (strlen($key)>0) &&
			(strpbrk($key,"/\\\0")===false) &&
			($key!=='.') && ($key!=='..'));
	}
	// unserialize() constructs whatever class the stored bytes name, and runs
	// that class's magic methods while doing it. So the classes a cache file
	// may name are the ones the caller stores: its own, plus any it declares
	// through a static cacheClasses(). A file naming another class is not
	// loaded at all -- see holdsRefusedClass() -- rather than half loaded.
	protected static function allowedClasses( $target )
	{
		if(!is_object($target))
			return(false);
		$class = get_class($target);
		$allowed = array($class);
		if(is_callable(array($class,'cacheClasses')))
			$allowed = array_merge($allowed,call_user_func(array($class,'cacheClasses')));
		return($allowed);
	}
	// An allowed nested class is not an allowed root. Check the serialized
	// header before unserialize(), so a wrong root cannot run __wakeup() on
	// the way to being refused. Shipped cache roots serialize as O: or a:.
	protected static function hasExpectedRoot( $bytes, $target )
	{
		if(is_array($target))
			return(strncmp($bytes,'a:',2)===0);
		if(!is_object($target))
			return(false);
		$class = get_class($target);
		$prefix = 'O:'.strlen($class).':"'.$class.'":';
		return(strncmp($bytes,$prefix,strlen($prefix))===0);
	}
	// What the walk below may spend. Its shape is chosen by whoever wrote the
	// cache file, so it cannot be allowed to cost whatever that shape asks
	// for. An object met twice is recognised and not walked again, but an
	// array is a value and has no identity to recognise: an array containing
	// itself -- fifteen bytes of "a:1:{i:0;R:1;}" -- is walked until the
	// process dies, and a file may also nest deeper than the stack goes or
	// hold one sub-value at so many places through R: back-references that a
	// few hundred bytes describe more nodes than can ever be visited.
	//
	// So the walk is allowed one node per byte of the file the value came
	// from, and never fewer than WALK_MIN_NODES. A file pays its own length
	// for what it asks to have walked: the shortest a node can be written is
	// two bytes, so no honest file ever reaches its own allowance, while a
	// file that names one sub-value at a million places buys only the nodes
	// its R: back-references are written in. What a walk costs is therefore
	// bounded by what the file costs to read, rather than by the graph the
	// file describes.
	//
	// A file that exceeds either budget is refused, which is the same answer
	// as a refused class: a miss. Note that a miss is not always rebuilt --
	// rCookies::load() and rRetrackers::load() return their defaults and
	// leave the file alone -- so a refused file stays on disk and is walked
	// again on the next read. That is what keeps the allowance small.
	const WALK_MAX_DEPTH = 64;
	const WALK_MIN_NODES = 100000;

	// Whether unserialize() met a class it was not allowed to construct, at
	// any depth. Such a class comes back as __PHP_Incomplete_Class, which
	// would fail later and further away if it were handed to the caller.
	// True as well when the value costs more than the budgets above, because
	// what could not be walked has not been shown to be free of one. $bytes
	// is the length of the file the value was unserialized from, and $reason
	// comes back saying which of the three refused it.
	protected static function holdsRefusedClass( $value, $bytes = 0, &$reason = null )
	{
		$budget = max(self::WALK_MIN_NODES,$bytes);
		$reason = null;
		return(self::walkForRefusedClass($value,new SplObjectStorage(),0,$budget,$reason));
	}
	private static function walkForRefusedClass( $value, $seen, $depth, &$budget, &$reason )
	{
		if(--$budget < 0)
		{
			$reason = 'describes more than a cache file of its size may describe';
			return(true);
		}
		if($depth > self::WALK_MAX_DEPTH)
		{
			$reason = 'nests deeper than a cache file may nest';
			return(true);
		}
		if(is_object($value))
		{
			if($value instanceof __PHP_Incomplete_Class)
			{
				$reason = 'names a class it may not hold';
				return(true);
			}
			if($seen->offsetExists($value))
				return(false);
			$seen->offsetSet($value);
			$value = (array)$value;
		}
		if(is_array($value))
			foreach($value as $item)
				if(self::walkForRefusedClass($item,$seen,$depth+1,$budget,$reason))
					return(true);
		return(false);
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
	// A matching inode/mtime/size can be an ABA after deletion and reuse.
	// Return null when equal-stamp content cannot be read: overwriting it
	// would silently lose a version this process could not compare.
	protected static function hasChangedSinceLoad( $name, $state )
	{
		if($state === null)
			return(is_file($name));
		if($state['stamp'] !== self::stampOf($name))
			return(true);
		$bytes = @file_get_contents($name);
		return($bytes === false ? null : hash('sha256', $bytes) !== $state['digest']);
	}
	public function set( $rss, $arg = null )
	{
		global $profileMask;
		$name = $this->getName($rss);
		if(is_null($name))
		{
			FileUtil::toLog('rCache: set refused: invalid key; cache entry unchanged');
			return(false);
		}
		$lockName = $name.'.lock';
		// One writer per cache key. The changed-since-load check, the merge
		// and the publishing rename must form a single critical section: two
		// writers that both check before either publishes both conclude
		// nothing changed, both skip merging, and the later rename erases the
		// earlier writer's data. The lock is a sidecar file because the cache
		// file itself is replaced by rename() on every store.
		$lock = @fopen( $lockName, "c" );
		if($lock===false)
		{
			self::logRefusal('set', $name, 'key lock open failed');
			return(false);
		}
		@chmod($lockName,$profileMask & 0666);
		if(!self::flock( $lock ))
		{
			fclose($lock);
			self::logRefusal('set', $name, 'key lock unavailable');
			return(false);
		}

		$state = is_object($rss) ? self::loadedState($rss, $name) : null;
		$changed = is_object($rss) && method_exists($rss, "merge")
			? self::hasChangedSinceLoad($name, $state) : false;
		if($changed === null)
		{
			flock($lock, LOCK_UN);
			fclose($lock);
			self::logRefusal('set', $name, 'cache digest unreadable');
			return(false);
		}
		if($changed)
		{
			$className = get_class($rss);
			$newInstance = new $className();
			if($this->get($newInstance) &&
				!$rss->merge($newInstance, $arg))
			{
				flock( $lock, LOCK_UN );
				fclose( $lock );
				self::logRefusal('set', $name, 'merge rejected newer cache entry');
				return(false);
			}
		}
		// Use a per-process temporary file and publish it atomically under the key lock.
		$tmpName = $name.'.'.getmypid().'.'.uniqid('', true).'.tmp';
		$reason = 'temporary file open failed';
		$fp = @fopen( $tmpName, "wb" );
		if($fp!==false)
		{
			$str = serialize( $rss );
			$written = @fwrite( $fp, $str );
			$flushed = ($written === strlen($str)) && @fflush( $fp );
			$closed = @fclose( $fp );
			if($written !== strlen($str))
				$reason = 'temporary file write failed';
			else if(!$flushed)
				$reason = 'temporary file flush failed';
			else if(!$closed)
				$reason = 'temporary file close failed';
			else
			{
				@chmod($tmpName,$profileMask & 0666);
				if(@rename( $tmpName, $name ))
				{
					@chmod($name,$profileMask & 0666);
					if(is_object($rss))
						self::rememberLoadedState($rss, $name, self::stampOf($name), $str);
					flock( $lock, LOCK_UN );
					fclose( $lock );
					return(true);
				}
				$reason = 'cache publish failed';
			}
		}
		@unlink( $tmpName );
		flock( $lock, LOCK_UN );
		fclose( $lock );
		self::logRefusal('set', $name, $reason);
		return(false);
	}
	public function get( &$rss )
	{
		$fname = $this->getName($rss);
		if(is_null($fname))
			return(false);
		// Stamp before reading. If a concurrent rename lands between the stat
		// and the read, this process holds new content under an old stamp and
		// its set() merely performs one redundant merge. Stamping after the
		// read inverts that: a rename between read and stat pairs old content
		// with the new identity, and that concurrent write is never merged.
		$stamp = self::stampOf($fname);
		$ret = @file_get_contents($fname);
		if($ret!==false)
		{
			if(!self::hasExpectedRoot($ret,$rss))
			{
				FileUtil::toLog('rCache: '.basename($fname).' names a different root value; not loaded.');
				return(false);
			}
			// Hide parser diagnostics without swallowing warnings raised by a
			// permitted class's __wakeup(), and restore the caller's handler on throw.
			$previousHandler = null;
			$previousHandler = set_error_handler(function ($severity, $message, $file, $line) use (&$previousHandler) {
				if($file===__FILE__ && strpos($message, 'unserialize():')===0)
					return(true);
				return(is_callable($previousHandler)
					? call_user_func($previousHandler, $severity, $message, $file, $line) : false);
			});
			try {
				$tmp = @unserialize($ret,array('allowed_classes'=>self::allowedClasses($rss)));
			} finally {
				restore_error_handler();
			}
			if(self::holdsRefusedClass($tmp,strlen($ret),$reason))
			{
				FileUtil::toLog('rCache: '.basename($fname).' '.$reason.'; not loaded.');
				return(false);
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
					self::rememberLoadedState($rss, $fname, $stamp, $ret);
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
		if(is_null($name))
			return(false);
		$lockName = $name.'.lock';
		// An already-open sidecar must remain the same lock inode for later writers.
		$lock = @fopen( $lockName, "c" );
		if($lock===false)
		{
			self::logRefusal('remove', $name, 'key lock open failed');
			return(false);
		}
		@chmod($lockName,$profileMask & 0666);
		if(!self::flock( $lock ))
		{
			fclose($lock);
			self::logRefusal('remove', $name, 'key lock unavailable');
			return(false);
		}
		$ret = @unlink($name);
		flock( $lock, LOCK_UN );
		fclose( $lock );
		if(!$ret)
			self::logRefusal('remove', $name, 'cache unlink failed');
		return($ret);
	}
	// Null when the key names anything other than a file in this directory.
	// Every caller treats that as a miss rather than reaching for the path.
	protected function getName($rss)
	{
		$key = is_object($rss) ? $rss->hash : (isset($rss['__hash__']) ? $rss['__hash__'] : null);
		if(!self::isValidKey($key))
			return(null);
		return($this->dir."/".$key);
	}
	public function getModified( $obj = null )
	{
		if(is_null($obj))
			return(@filemtime($this->dir));
		$name = is_object($obj) ? $this->getName($obj) :
			(self::isValidKey($obj) ? $this->dir."/".$obj : null);
		return(is_null($name) ? false : @filemtime($name));

	}
}
