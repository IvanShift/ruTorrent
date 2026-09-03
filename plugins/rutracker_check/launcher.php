<?php

/**
 * The manual "check for update" handover, shared by its two entrypoints.
 *
 * action.php runs inside the web request: it turns the POSTed selection into a
 * list of hashes, writes them to a file in the temp directory and starts a
 * detached batch_check.php to work through them. batch_check.php is that child.
 *
 * The pieces live here because both sides need the same answer about the same
 * file, and because a detached launch is the one step in this route whose
 * failure is invisible unless it is checked for on purpose.
 */

/**
 * Builds and submits detached PHP commands.
 *
 * A true result means only that the outer shell accepted the detached command.
 * It does not claim the background child reached PHP or user code, and nothing
 * here pretends otherwise: the caller gets the shell's answer, not a promise.
 */
class RuTrackerDetachedPhp
{
	/**
	 * Whether the configured interpreter can actually be run. The PHP binary is
	 * a configured string, so "php" may be a bare name resolved through PATH or
	 * an absolute path that no longer exists.
	 */
	static private function executableExists($php)
	{
		$php = (string) $php;
		if($php === '')
			return false;
		if(strpos($php, DIRECTORY_SEPARATOR) !== false)
			return @is_file($php) && @is_executable($php);

		$path = getenv('PATH');
		if(!is_string($path) || $path === '')
			return false;
		foreach(explode(PATH_SEPARATOR, $path) as $directory)
		{
			if($directory === '')
				$directory = '.';
			$candidate = $directory
				. (substr($directory, -1) === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR)
				. $php;
			if(@is_file($candidate) && @is_executable($candidate))
				return true;
		}
		return false;
	}

	/**
	 * The command string for a detached run. Every word that came from
	 * configuration or from a caller is quoted, the interpreter included: an
	 * install whose PHP path contains a space would otherwise be split into two
	 * words and the child would never start.
	 */
	static public function command($php, $script, $arguments = array())
	{
		$parts = array(
			escapeshellarg((string) $php),
			'-f',
			escapeshellarg((string) $script),
		);
		foreach((array) $arguments as $argument)
			$parts[] = escapeshellarg((string) $argument);
		return implode(' ', $parts) . ' > /dev/null 2>&1 &';
	}

	static public function execute($command)
	{
		if(!function_exists('exec'))
			return false;
		$output = array();
		$status = 1;
		@exec((string) $command, $output, $status);
		return $status === 0;
	}

	/**
	 * @param callable|null $launcher Injection seam for tests; the default runs
	 *                                the command through the shell.
	 * @return bool Whether the shell accepted the detached command.
	 */
	static public function launch($php, $script, $arguments = array(), $launcher = null)
	{
		if($launcher === null)
		{
			// These facts are knowable before detaching, and after detaching
			// nothing about the child is observable at all. Checking them here is
			// what turns "we asked" into "the request was refusable".
			if(!self::executableExists($php)
				|| !is_string($script) || !@is_file($script) || !@is_readable($script))
				return false;
			$launcher = array(__CLASS__, 'execute');
		}
		return call_user_func($launcher, self::command($php, $script, $arguments));
	}
}

/** Creates the manual-check handover and owns its failed-dispatch cleanup. */
class RuTrackerBatchDispatch
{
	static private function log($logger, $message)
	{
		if($logger === null)
			return;
		try
		{
			call_user_func($logger, $message);
		}
		catch(Throwable $e)
		{
			// A diagnostic sink must not turn a handled launch refusal into a
			// second, unhandled request failure.
		}
	}

	/**
	 * Create the handover file, exclusively.
	 *
	 * A name built from the user and the wall clock second is the same name
	 * twice for two checks started in the same second: the second write lands on
	 * top of the first, one selection is lost, and whichever child runs first
	 * deletes the file under the other. An exclusive create cannot collide, and
	 * unlike tempnam() it never quietly falls back to a different directory when
	 * the configured one is unusable.
	 *
	 * @return array|false array($path, $handle), or false with $error set.
	 */
	static private function createHandover($tempDirectory, &$error)
	{
		$error = null;
		$directory = (string) $tempDirectory;
		if($directory === '' || !@is_dir($directory))
		{
			$error = 'the temporary directory is not usable';
			return false;
		}
		if(substr($directory, -1) !== DIRECTORY_SEPARATOR)
			$directory .= DIRECTORY_SEPARATOR;

		for($attempt = 0; $attempt < 16; $attempt++)
		{
			$handle = @fopen($directory . 'rutorrent-prm-' . self::uniqueSuffix(), 'xb');
			if($handle !== false)
			{
				$meta = stream_get_meta_data($handle);
				return array($meta['uri'], $handle);
			}
		}
		$error = 'could not create a handover file';
		return false;
	}

	static private function uniqueSuffix()
	{
		try
		{
			if(function_exists('random_bytes'))
				return bin2hex(random_bytes(12));
		}
		catch(Throwable $e)
		{
			// No usable entropy source. The exclusive create below is what
			// guarantees uniqueness; this only keeps collisions rare.
		}
		return str_replace('.', '', uniqid('', true));
	}

	static public function removeHandover($path, $deleter = null)
	{
		if(!is_string($path) || $path === '' || !file_exists($path))
			return true;
		if($deleter === null)
			return @unlink($path);
		return call_user_func($deleter, $path) === true;
	}

	/**
	 * Write the selection to a handover file and start the detached worker.
	 *
	 * @param callable|null $writer Injection seam for tests. It receives the open
	 *                              handle and the payload and returns the number
	 *                              of bytes written, or false.
	 * @return bool True only when the handover is fully on disk and the shell
	 *              accepted the worker.
	 */
	static public function dispatch($hashes, $tempDirectory, $php, $script, $user,
		$launcher = null, $logger = null, $deleter = null, $writer = null)
	{
		$error = null;
		$created = self::createHandover($tempDirectory, $error);
		if($created === false)
		{
			self::log($logger, 'rutracker_check: manual batch was not dispatched: ' . $error);
			return false;
		}
		list($handover, $handle) = $created;

		$payload = serialize(array_values((array) $hashes));
		$written = ($writer === null)
			? @fwrite($handle, $payload)
			: call_user_func($writer, $handle, $payload);
		$flushed = @fflush($handle);
		@fclose($handle);

		// A short write is not a failed write: file_put_contents() and fwrite()
		// both report the bytes they managed to store, and a truncated handover
		// unserializes to false in the child, which then checks nothing and says
		// nothing. Comparing the count is what separates the two.
		if($written !== strlen($payload) || $flushed === false)
		{
			self::log($logger, 'rutracker_check: manual batch handover was not written completely');
			self::cleanUp($handover, $deleter, $logger);
			return false;
		}

		$accepted = false;
		try
		{
			$accepted = RuTrackerDetachedPhp::launch(
				$php, $script, array($handover, $user), $launcher) === true;
		}
		catch(Throwable $e)
		{
			$accepted = false;
		}
		if($accepted)
			return true;

		self::log($logger, 'rutracker_check: manual batch worker was not accepted by the shell');
		self::cleanUp($handover, $deleter, $logger);
		return false;
	}

	/** Remove a handover no worker will ever read, and say so if it stays. */
	static private function cleanUp($handover, $deleter, $logger)
	{
		$removed = false;
		try
		{
			$removed = self::removeHandover($handover, $deleter);
		}
		catch(Throwable $e)
		{
			$removed = false;
		}
		if(!$removed)
			self::log($logger, 'rutracker_check: undispatched manual batch handover could not be removed');
	}
}

/** Parses and validates the repeated hash parameters of a manual batch request. */
class RuTrackerBatchRequest
{
	// The browser sends one 45-byte "&hash=<40 hex>" group per selected torrent,
	// so this holds several thousand torrents while still bounding what an
	// unauthenticated-by-mistake endpoint can be made to buffer.
	const MAX_BODY_BYTES = 262144; // 256 KiB

	/**
	 * The unique hashes named by a raw application/x-www-form-urlencoded body.
	 *
	 * Whatever follows hash= is handed to the checker and reaches rTorrent, so
	 * this accepts only what a torrent hash can be: 40 hex characters. Anything
	 * else is dropped rather than passed on.
	 *
	 * @param string      $body
	 * @param string|null $error Set to a classified reason when the whole request
	 *                           is refused, and left null otherwise.
	 * @return array Unique uppercase 40-hex hashes, in the order they appeared.
	 */
	static public function parseHashes($body, &$error = null)
	{
		$error = null;
		if(!is_string($body))
		{
			$error = 'the request body could not be read';
			return array();
		}
		if(strlen($body) > self::MAX_BODY_BYTES)
		{
			$error = 'the request body is larger than this endpoint accepts';
			return array();
		}

		$hashes = array();
		$seen = array();
		foreach(explode('&', $body) as $segment)
		{
			if($segment === '')
				continue;
			$parts = explode('=', $segment, 2);
			if(count($parts) !== 2)
				continue;
			if(rawurldecode($parts[0]) !== 'hash')
				continue;

			$hash = strtoupper(rawurldecode($parts[1]));
			if(strlen($hash) !== 40 || !ctype_xdigit($hash))
				continue;
			if(isset($seen[$hash]))
				continue;

			$seen[$hash] = true;
			$hashes[] = $hash;
		}
		return $hashes;
	}
}
