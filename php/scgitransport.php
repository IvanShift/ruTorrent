<?php

/**
 * One framed SCGI exchange. This layer owns bytes and deadlines only; XML is
 * deliberately left to its caller, which knows the response it requested.
 */
class rSCGITransport
{
	const RESPONSE_RAW = 'raw';
	const RESPONSE_BODY = 'body';
	const MAX_HEADER_BYTES = 65536;
	const MAX_WRITE_BYTES = 65536;
	const DEFAULT_MAX_RESPONSE_BYTES = 67108864;
	const MAX_WIRE_RESPONSE_BYTES = 104857600;

	public static function send($host, $port, $payload, $trusted = true, $connectTimeout = 30,
		&$failure = null, $transferTimeout = null, $maxResponseBytes = null,
		$responseMode = self::RESPONSE_RAW)
	{
		$failure = null;
		if($payload === '')
			return self::fail($failure, 'empty-request');
		if(($responseMode !== self::RESPONSE_RAW) && ($responseMode !== self::RESPONSE_BODY))
			return self::fail($failure, 'invalid-response-mode');
		$limit = self::responseLimit($maxResponseBytes);
		if($limit === null)
			return self::fail($failure, 'invalid-response-limit');
		$transferTimeout = self::transferTimeout($transferTimeout);

		$address = (($port === 0) || ($port === '0')) ? $host : 'tcp://'.$host.':'.$port;
		$socket = static::openSocket($address, $errno, $error, $connectTimeout);
		if($socket === false)
			return self::fail($failure, 'connect-failed');
		try
		{
			if(!static::configureBlocking($socket, false))
				return self::fail($failure, 'socket-config-failed');

			$header = "CONTENT_LENGTH\x00".strlen($payload)."\x00CONTENT_TYPE\x00text/xml\x00"
				."SCGI\x001\x00UNTRUSTED_CONNECTION\x00".($trusted ? '0' : '1')."\x00";
			$prefix = strlen($header).':'.$header.',';
			$deadline = static::monotonicNow() + $transferTimeout;
			if(!self::writeAll($socket, $prefix, $deadline, $failure))
				return null;
			if(!self::writeAll($socket, $payload, $deadline, $failure))
				return null;

			if(!static::configureBlocking($socket, true) || !self::setReadTimeout($socket, $transferTimeout))
				return self::fail($failure, 'socket-config-failed');
			return self::readResponse($socket, $limit, $responseMode, $failure);
		}
		finally
		{
			fclose($socket);
		}
	}

	private static function fail(&$failure, $code)
	{
		$failure = $code;
		return null;
	}

	protected static function openSocket($address, &$errno, &$error, $timeout)
	{
		return @stream_socket_client($address, $errno, $error, $timeout, STREAM_CLIENT_CONNECT);
	}

	protected static function configureBlocking($socket, $blocking)
	{
		return @stream_set_blocking($socket, $blocking);
	}

	protected static function waitWritable($socket, $seconds, $microseconds)
	{
		$read = null;
		$write = array($socket);
		$except = null;
		return @stream_select($read, $write, $except, $seconds, $microseconds);
	}

	protected static function writeBytes($socket, $bytes, $length)
	{
		return @fwrite($socket, $bytes, $length);
	}

	protected static function configureReadTimeout($socket, $seconds, $microseconds)
	{
		return @stream_set_timeout($socket, $seconds, $microseconds);
	}

	protected static function readBytes($socket, $length)
	{
		return @fread($socket, $length);
	}

	protected static function socketMetadata($socket)
	{
		return stream_get_meta_data($socket);
	}

	protected static function monotonicNow()
	{
		if(function_exists('hrtime'))
		{
			$value = hrtime();
			return ((float)$value[0]) + (((float)$value[1]) / 1000000000.0);
		}
		return microtime(true);
	}

	private static function responseLimit($value)
	{
		if($value === null)
			return self::DEFAULT_MAX_RESPONSE_BYTES;
		if(is_int($value))
			return (($value >= 1) && ($value <= self::MAX_WIRE_RESPONSE_BYTES)) ? $value : null;
		if(!is_string($value) || !preg_match('/^[0-9]+$/', $value))
			return null;
		$normal = ltrim($value, '0');
		if($normal === '')
			return null;
		$maximum = (string)self::MAX_WIRE_RESPONSE_BYTES;
		if((strlen($normal) > strlen($maximum)) ||
			((strlen($normal) === strlen($maximum)) && (strcmp($normal, $maximum) > 0)))
			return null;
		return (int)$normal;
	}

	private static function transferTimeout($value)
	{
		if($value === null)
		{
			$ini = ini_get('default_socket_timeout');
			$number = (float)$ini;
			if(is_numeric($ini) && is_finite($number) && ($number > 0))
				return $number;
			return 60.0;
		}
		if(!(is_int($value) || is_float($value) || is_string($value)) || !is_numeric($value))
			return 60.0;
		$number = (float)$value;
		return (is_finite($number) && ($number > 0)) ? $number : 60.0;
	}

	private static function writeAll($socket, $bytes, $deadline, &$failure)
	{
		$offset = 0;
		$length = strlen($bytes);
		while($offset < $length)
		{
			$remaining = $deadline - static::monotonicNow();
			if($remaining <= 0)
				return self::fail($failure, 'write-timeout') !== null;
			$seconds = (int)floor($remaining);
			$microseconds = (int)(($remaining - $seconds) * 1000000);
			if(($seconds === 0) && ($microseconds === 0))
				$microseconds = 1;
			$selected = static::waitWritable($socket, $seconds, $microseconds);
			if($selected === false)
			{
				$failure = 'write-wait-failed';
				return false;
			}
			if($selected === 0)
			{
				$failure = 'write-timeout';
				return false;
			}
			$offered = min(self::MAX_WRITE_BYTES, $length - $offset);
			$slice = substr($bytes, $offset, $offered);
			$written = static::writeBytes($socket, $slice, $offered);
			if(($written === false) || ($written <= 0) || ($written > $offered))
			{
				$failure = 'write-failed';
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	private static function setReadTimeout($socket, $timeout)
	{
		$seconds = (int)floor($timeout);
		$microseconds = (int)(($timeout - $seconds) * 1000000);
		if(($seconds === 0) && ($microseconds === 0))
			$microseconds = 1;
		return static::configureReadTimeout($socket, $seconds, $microseconds);
	}

	private static function readResponse($socket, $limit, $responseMode, &$failure)
	{
		$received = '';
		while(($delimiter = strpos($received, "\r\n\r\n")) === false)
		{
			if(strlen($received) > self::MAX_HEADER_BYTES + 3)
				return self::fail($failure, 'headers-too-large');
			$chunk = static::readBytes($socket, min(8192, self::MAX_HEADER_BYTES + 4 - strlen($received)));
			if(($chunk === false) || ($chunk === ''))
				return self::readFailure($socket, $received === '', $failure,
					($received === '') ? 'closed-before-headers' : 'truncated-headers');
			$received .= $chunk;
		}
		$header = substr($received, 0, $delimiter);
		if(strlen($header) > self::MAX_HEADER_BYTES)
			return self::fail($failure, 'headers-too-large');
		$declared = self::contentLength($header, $limit, $failure);
		if($declared === null)
			return null;
		$buffer = @fopen('php://temp/maxmemory:1048576', 'w+b');
		if($buffer === false)
			return self::fail($failure, 'read-failed');
		try
		{
			if(($responseMode === self::RESPONSE_RAW) && !self::append($buffer, $header."\r\n\r\n"))
				return self::fail($failure, 'read-failed');
			$tail = substr($received, $delimiter + 4);
			$received = '';
			$bodyBytes = min(strlen($tail), $declared);
			if(($bodyBytes > 0) && !self::append($buffer, substr($tail, 0, $bodyBytes)))
				return self::fail($failure, 'read-failed');
			while($bodyBytes < $declared)
			{
				$chunk = static::readBytes($socket, min(65536, $declared - $bodyBytes));
				if(($chunk === false) || ($chunk === ''))
					return self::readFailure($socket, false, $failure, 'truncated-body');
				if(!self::append($buffer, $chunk))
					return self::fail($failure, 'read-failed');
				$bodyBytes += strlen($chunk);
			}
			rewind($buffer);
			$result = stream_get_contents($buffer);
			return ($result === false) ? self::fail($failure, 'read-failed') : $result;
		}
		finally
		{
			fclose($buffer);
		}
	}

	private static function append($buffer, $bytes)
	{
		$offset = 0;
		$length = strlen($bytes);
		while($offset < $length)
		{
			$written = @fwrite($buffer, substr($bytes, $offset), $length - $offset);
			if(($written === false) || ($written === 0))
				return false;
			$offset += $written;
		}
		return true;
	}

	private static function readFailure($socket, $beforeHeaders, &$failure, $closed = 'closed-before-headers')
	{
		$meta = static::socketMetadata($socket);
		if(isset($meta['timed_out']) && $meta['timed_out'])
			return self::fail($failure, 'read-timeout');
		if(isset($meta['eof']) && $meta['eof'])
			return self::fail($failure, $closed);
		return self::fail($failure, 'read-failed');
	}

	private static function contentLength($header, $limit, &$failure)
	{
		$count = 0;
		$value = null;
		foreach(explode("\r\n", $header) as $line)
		{
			$colon = strpos($line, ':');
			$fieldValue = ($colon === false) ? '' : substr($line, $colon + 1);
			if(($colon === false) || !preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', substr($line, 0, $colon)) ||
				!preg_match('/^[\x09\x20-\x7E\x80-\xFF]*$/D', $fieldValue))
				return self::fail($failure, 'malformed-header');
			if(strcasecmp(substr($line, 0, $colon), 'Content-Length') === 0)
			{
				$count++;
				$value = trim($fieldValue, " \t");
			}
		}
		if($count === 0)
			return self::fail($failure, 'missing-content-length');
		if($count > 1)
			return self::fail($failure, 'duplicate-content-length');
		if(($value === '') || !preg_match('/^[0-9]+$/', $value))
			return self::fail($failure, 'malformed-content-length');
		$value = ltrim($value, '0');
		if($value === '')
			return self::fail($failure, 'zero-content-length');
		$limitString = (string)$limit;
		if((strlen($value) > strlen($limitString)) ||
			((strlen($value) === strlen($limitString)) && (strcmp($value, $limitString) > 0)))
			return self::fail($failure, 'response-too-large');
		return (int)$value;
	}
}
