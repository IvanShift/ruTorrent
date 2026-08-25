<?php

/**
 * Shared, fail-closed SCGI transport for XML-RPC communication with rTorrent.
 */
class rSCGITransport
{
	const MAX_HEADER_BYTES = 65536;
	const MAX_BODY_BYTES = 67108864;

	/**
	 * Send one XML-RPC request and return one complete XML-RPC methodResponse.
	 *
	 * @return array|null array('headers' => string, 'body' => string,
	 *                    'raw' => string), or null on any transport/framing error
	 */
	public static function send($host, $port, $payload, $trusted = true, $timeout = 30, &$errorLog = null)
	{
		$errorLog = null;
		if(!function_exists('simplexml_load_string'))
		{
			$errorLog = 'SimpleXML extension is required for SCGI response validation';
			return null;
		}
		$payload = (string) $payload;
		$contentLength = strlen($payload);
		if($contentLength === 0)
		{
			$errorLog = 'empty payload';
			return null;
		}

		$timeout = (int) $timeout;
		if($timeout <= 0)
			$timeout = 30;
		$socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
		if($socket === false)
		{
			$errorLog = 'cannot reach rtorrent at ' . $host . ': ' . $errstr;
			return null;
		}
		stream_set_timeout($socket, $timeout);

		$scgiHeaders = "CONTENT_LENGTH\x00" . $contentLength . "\x00CONTENT_TYPE\x00text/xml\x00"
			. "SCGI\x001\x00UNTRUSTED_CONNECTION\x00" . ($trusted ? '0' : '1') . "\x00";
		$request = strlen($scgiHeaders) . ':' . $scgiHeaders . ',' . $payload;
		$totalLength = strlen($request);
		$written = 0;
		$writeDeadline = microtime(true) + $timeout;
		stream_set_blocking($socket, false);
		while($written < $totalLength)
		{
			$remainingTime = $writeDeadline - microtime(true);
			if($remainingTime <= 0)
			{
				$errorLog = 'timed out writing to rtorrent after ' . $timeout . 's ('
					. $written . ' of ' . $totalLength . ' bytes written)';
				fclose($socket);
				return null;
			}
			$seconds = (int) floor($remainingTime);
			$microseconds = (int) (($remainingTime - $seconds) * 1000000);
			$read = null;
			$write = array($socket);
			$except = null;
			$ready = @stream_select($read, $write, $except, $seconds, $microseconds);
			if($ready === false)
			{
				$errorLog = 'failed waiting to write to rtorrent socket';
				fclose($socket);
				return null;
			}
			if($ready === 0)
			{
				$errorLog = 'timed out writing to rtorrent after ' . $timeout . 's ('
					. $written . ' of ' . $totalLength . ' bytes written)';
				fclose($socket);
				return null;
			}
			$count = @fwrite($socket, substr($request, $written));
			$meta = stream_get_meta_data($socket);
			if(!empty($meta['timed_out']))
			{
				$errorLog = 'timed out writing to rtorrent after ' . $timeout . 's ('
					. $written . ' of ' . $totalLength . ' bytes written)';
				fclose($socket);
				return null;
			}
			if($count === false || $count === 0)
			{
				$errorLog = 'failed write to rtorrent socket after ' . $written
					. ' of ' . $totalLength . ' bytes';
				fclose($socket);
				return null;
			}
			$written += $count;
		}
		stream_set_blocking($socket, true);
		stream_set_timeout($socket, $timeout);

		$headerBuffer = '';
		$responseHeaders = null;
		$body = '';
		$expectedLength = null;
		while(true)
		{
			$readLength = 65536;
			if($responseHeaders === null)
			{
				// A complete delimiter may begin immediately after an exact-limit
				// header. Keep room for those four bytes, but no more.
				$headerReadCapacity = self::MAX_HEADER_BYTES + 4 - strlen($headerBuffer);
				if($headerReadCapacity <= 0)
				{
					$errorLog = 'response headers exceed ' . self::MAX_HEADER_BYTES . ' bytes';
					fclose($socket);
					return null;
				}
				$readLength = min($readLength, $headerReadCapacity);
			}
			$chunk = @fread($socket, $readLength);
			// Timeout metadata must win over the ambiguous false/empty fread result.
			$meta = stream_get_meta_data($socket);
			if(!empty($meta['timed_out']))
			{
				$errorLog = 'timed out reading from rtorrent after ' . $timeout . 's';
				fclose($socket);
				return null;
			}
			if($chunk === false)
			{
				$errorLog = 'read error from rtorrent socket';
				fclose($socket);
				return null;
			}
			if($chunk === '')
			{
				if(feof($socket))
					break;
				$errorLog = 'empty read from rtorrent socket before EOF';
				fclose($socket);
				return null;
			}

			if($responseHeaders === null)
			{
				$headerBuffer .= $chunk;
				$delimiter = strpos($headerBuffer, "\r\n\r\n");
				if($delimiter === false)
				{
					// Up to three trailing bytes may still become the delimiter.
					// They are not provably header bytes until a later byte breaks
					// that prefix, so do not charge them against the exact limit.
					$partialDelimiterBytes = 0;
					$maximumPrefix = min(3, strlen($headerBuffer));
					for($prefixLength = $maximumPrefix; $prefixLength > 0; $prefixLength--)
					{
						if(substr($headerBuffer, -$prefixLength)
							=== substr("\r\n\r\n", 0, $prefixLength))
						{
							$partialDelimiterBytes = $prefixLength;
							break;
						}
					}
					$provableHeaderBytes = strlen($headerBuffer) - $partialDelimiterBytes;
					if($provableHeaderBytes > self::MAX_HEADER_BYTES)
					{
						$errorLog = 'response headers exceed ' . self::MAX_HEADER_BYTES . ' bytes';
						fclose($socket);
						return null;
					}
					continue;
				}
				if($delimiter > self::MAX_HEADER_BYTES)
				{
					$errorLog = 'response headers exceed ' . self::MAX_HEADER_BYTES . ' bytes';
					fclose($socket);
					return null;
				}

				$responseHeaders = substr($headerBuffer, 0, $delimiter);
				if(!self::parseHeaders($responseHeaders, $expectedLength, $errorLog))
				{
					fclose($socket);
					return null;
				}
				$body = substr($headerBuffer, $delimiter + 4);
				$headerBuffer = '';
			}
			else
				$body .= $chunk;

			if(strlen($body) > self::MAX_BODY_BYTES)
			{
				$errorLog = 'response body exceeds ' . self::MAX_BODY_BYTES . ' bytes';
				fclose($socket);
				return null;
			}
			if($expectedLength !== null && strlen($body) > $expectedLength)
			{
				$errorLog = 'overlong response from rtorrent: expected ' . $expectedLength
					. ' bytes, got at least ' . strlen($body);
				fclose($socket);
				return null;
			}
		}
		fclose($socket);

		if($responseHeaders === null)
		{
			$errorLog = ($headerBuffer === '') ? 'empty response from rtorrent'
				: 'malformed response from rtorrent: missing header delimiter';
			return null;
		}
		$actualLength = strlen($body);
		if($expectedLength !== null && $actualLength < $expectedLength)
		{
			$errorLog = 'truncated response from rtorrent: expected ' . $expectedLength
				. ' bytes, got ' . $actualLength;
			return null;
		}
		if(!self::isMethodResponse($body))
		{
			$errorLog = 'invalid XML methodResponse from rtorrent';
			return null;
		}

		return array(
			'headers' => $responseHeaders,
			'body' => $body,
			'raw' => $responseHeaders . "\r\n\r\n" . $body,
		);
	}

	private static function parseHeaders($headers, &$contentLength, &$errorLog)
	{
		$contentLength = null;
		$seenContentLength = false;
		foreach(explode("\r\n", $headers) as $line)
		{
			if(!preg_match('/^([A-Za-z0-9!#$%&\x27*+.^_`|~-]+):[ \t]*(.*)$/D', $line, $match))
			{
				$errorLog = 'malformed response header from rtorrent';
				return false;
			}
			if(strcasecmp($match[1], 'Content-Length') !== 0)
				continue;
			if($seenContentLength)
			{
				$errorLog = 'malformed response: duplicate Content-Length header';
				return false;
			}
			$seenContentLength = true;
			$value = trim($match[2]);
			if($value === '' || !ctype_digit($value))
			{
				$errorLog = 'malformed Content-Length header';
				return false;
			}
			$normalized = ltrim($value, '0');
			if($normalized === '')
				$normalized = '0';
			$maximum = (string) self::MAX_BODY_BYTES;
			if(strlen($normalized) > strlen($maximum) ||
				(strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0))
			{
				$errorLog = 'response body exceeds ' . self::MAX_BODY_BYTES . ' bytes';
				return false;
			}
			$contentLength = (int) $normalized;
		}
		return true;
	}

	private static function isMethodResponse($body)
	{
		if(!function_exists('simplexml_load_string'))
			return false;
		$previous = libxml_use_internal_errors(true);
		libxml_clear_errors();
		$xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
		$valid = ($xml !== false && $xml->getName() === 'methodResponse');
		libxml_clear_errors();
		libxml_use_internal_errors($previous);
		return $valid;
	}
}
