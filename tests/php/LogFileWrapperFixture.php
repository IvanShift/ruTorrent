<?php

/**
 * Stands in for PHP's plain-file wrapper in a child process, so that a test
 * without a second Unix user or a fork can refuse the open fs.protected_regular
 * refuses, record what FileUtil::toLog() does to the log and act between its
 * calls. Everything else reaches the real file through a real handle, its real
 * flock() included.
 *
 * Options: 'refuse', the fopen() modes to refuse; 'fifo', report the log as a
 * FIFO; 'lockFails', flock() fails for a reason other than another holder;
 * 'beforeWrite', text error_log(.., 3, ..) appends just before the write;
 * 'throwOnWrite', the write throws.
 */
class LogFileWrapperFixture
{
	public $context;
	public static $calls = array();
	public static $lockOperations = array();
	private static $options = array();
	private $path;
	private $real;

	public static function install($options)
	{
		self::$options = $options + array('refuse' => array(), 'fifo' => false, 'lockFails' => false,
			'beforeWrite' => '', 'throwOnWrite' => false);
		self::replacePlainFiles();
	}

	public static function remove()
	{
		stream_wrapper_restore('file');
	}

	private static function replacePlainFiles()
	{
		stream_wrapper_unregister('file');
		stream_wrapper_register('file', __CLASS__);
	}

	private static function withPlainFiles($call)
	{
		self::remove();
		try {
			return $call();
		} finally {
			self::replacePlainFiles();
		}
	}

	public function url_stat($path, $flags)
	{
		$stat = self::withPlainFiles(function () use ($path) { return @stat($path); });
		if ($stat !== false && self::$options['fifo']) {
			$stat['mode'] = $stat[2] = 0010600;
		}
		return $stat;
	}

	public function stream_open($path, $mode, $options, &$openedPath)
	{
		self::$calls[] = 'open ' . $mode;
		if (in_array($mode, self::$options['refuse'], true)) {
			return false;
		}
		$this->path = $path;
		$this->real = self::withPlainFiles(function () use ($path, $mode) { return fopen($path, $mode); });
		return (bool)$this->real;
	}

	public function stream_lock($operation)
	{
		self::$calls[] = 'lock';
		self::$lockOperations[] = $operation;
		if (self::$options['lockFails']) {
			// flock() takes the reason from errno.
			self::withPlainFiles(function () { @stat('/nonexistent/rutorrent-log-test'); });
			return false;
		}
		return flock($this->real, $operation);
	}

	// PHP also asks an append stream for its position after opening it.
	public function stream_seek($offset, $whence)
	{
		if ($whence !== SEEK_END) {
			return true;
		}
		self::$calls[] = 'seek to end';
		return fseek($this->real, $offset, $whence) === 0;
	}

	public function stream_tell()
	{
		return ftell($this->real);
	}

	public function stream_write($data)
	{
		self::$calls[] = 'write';
		if (self::$options['throwOnWrite']) {
			throw new RuntimeException('write failed');
		}
		if (self::$options['beforeWrite'] !== '') {
			$other = self::$options['beforeWrite'];
			$path = $this->path;
			self::withPlainFiles(function () use ($other, $path) { error_log($other, 3, $path); });
		}
		return fwrite($this->real, $data);
	}

	public function stream_close()
	{
		self::$calls[] = 'close';
		fclose($this->real);
	}
}
