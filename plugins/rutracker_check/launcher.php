<?php

/**
 * Builds and submits detached PHP commands.
 *
 * A true result means only that the outer shell accepted the detached command;
 * it does not claim that the background child reached PHP or user code.
 */
class RuTrackerDetachedPhp
{
	static private function executableExists($php)
	{
		$php = (string) $php;
		if($php === '') return false;
		if(strpos($php, DIRECTORY_SEPARATOR) !== false)
			return @is_file($php) && @is_executable($php);

		$path = getenv('PATH');
		if(!is_string($path) || $path === '') return false;
		foreach(explode(PATH_SEPARATOR, $path) as $directory)
		{
			if($directory === '') $directory = '.';
			$candidate = $directory
				. (substr($directory, -1) === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR)
				. $php;
			if(@is_file($candidate) && @is_executable($candidate)) return true;
		}
		return false;
	}

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
		if(!function_exists('exec')) return false;
		$output = array();
		$status = 1;
		@exec((string) $command, $output, $status);
		return $status === 0;
	}

	static public function launch($php, $script, $arguments = array(), $launcher = null)
	{
		if($launcher === null)
		{
			// These local facts are knowable before detaching. They do not prove
			// that the child reaches PHP; after this preflight the return value is
			// still only the outer shell's acceptance status.
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
		if($logger === null) return;
		try
		{
			call_user_func($logger, $message);
		}
		catch(Throwable $e)
		{
			// A diagnostic sink must not turn a handled launch refusal into a
			// second request failure.
		}
		catch(Exception $e)
		{
		}
	}

	static public function removeHandover($path, $deleter = null)
	{
		if(!is_string($path) || $path === '' || !file_exists($path)) return true;
		if($deleter === null) return @unlink($path);
		return call_user_func($deleter, $path) === true;
	}

	static public function dispatch($hashes, $tempDirectory, $php, $script, $user,
		$launcher = null, $logger = null, $deleter = null)
	{
		$handover = @tempnam((string) $tempDirectory, 'rutorrent-prm-');
		if($handover === false)
		{
			self::log($logger, 'rutracker_check: could not create the manual batch handover');
			return false;
		}

		if(@file_put_contents($handover, serialize((array) $hashes)) === false)
		{
			$removed = false;
			try { $removed = self::removeHandover($handover, $deleter); }
			catch(Throwable $e) { $removed = false; }
			catch(Exception $e) { $removed = false; }
			self::log($logger, 'rutracker_check: could not write the manual batch handover');
			if(!$removed)
				self::log($logger, 'rutracker_check: could not remove the unwritten manual batch handover');
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
		catch(Exception $e)
		{
			$accepted = false;
		}
		if($accepted) return true;

		self::log($logger, 'rutracker_check: manual batch command was not accepted by the shell');
		$removed = false;
		try { $removed = self::removeHandover($handover, $deleter); }
		catch(Throwable $e) { $removed = false; }
		catch(Exception $e) { $removed = false; }
		if(!$removed)
			self::log($logger, 'rutracker_check: could not remove the manual batch handover after launch refusal');
		return false;
	}
}

/** Parses and validates repeated hash parameters from manual batch requests. */
class RuTrackerBatchRequest
{
	const MAX_BODY_BYTES = 262144; // 256 KiB
	const MAX_HASHES = 4096;

	/**
	 * Parse repeated hash=<40hex> segments from raw HTTP request body.
	 *
	 * @param string      $body
	 * @param string|null $error Out parameter with diagnostic error message if any
	 * @return array List of unique uppercase 40-hex hashes, or empty array on failure/rejection
	 */
	static public function parseHashes($body, &$error = null)
	{
		$error = null;
		if(!is_string($body))
		{
			$error = 'body is not a string';
			return array();
		}
		if(strlen($body) > self::MAX_BODY_BYTES)
		{
			$error = 'body exceeds maximum size';
			return array();
		}

		$segments = explode('&', $body);
		$hashes = array();
		$seen = array();

		foreach($segments as $segment)
		{
			if($segment === '') continue;
			$parts = explode('=', $segment, 2);
			if(count($parts) !== 2) continue;

			$key = rawurldecode($parts[0]);
			$val = rawurldecode($parts[1]);

			if($key !== 'hash') continue;

			$val = strtoupper($val);
			if(strlen($val) !== 40 || !ctype_xdigit($val)) continue;

			if(isset($seen[$val])) continue;

			if(count($hashes) >= self::MAX_HASHES)
			{
				$error = 'maximum unique hashes limit exceeded';
				return array(); // Reject WHOLE batch if 4097th unique hash appears
			}

			$seen[$val] = true;
			$hashes[] = $val;
		}

		return $hashes;
	}
}
