<?php

require_once('utf.php');

class JSON
{
	const BYTE_DOCUMENT_KEY = '$rutorrent_bytes';

	/** Opt-in durable journals: ordinary JSON stays byte-identical; raw strings use base64. */
	public static function encodeBytes($document, $flags = 0)
	{
		try {
			foreach(array('json_encode','json_last_error','is_string','is_array') as $function)
				if(!@function_exists($function)) return false;
			// Lossy flags cannot turn a path into a different, successfully saved identity.
			$strict = $flags & ~(JSON_INVALID_UTF8_IGNORE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_THROW_ON_ERROR);
			$json = json_encode($document, $strict);
			if(is_string($json)) return $json;
			if(json_last_error() !== JSON_ERROR_UTF8 || !is_array($document) || !self::byteFunctions()) return false;
			$json = '{"$rutorrent_bytes":1,"data":';
			self::writeByteValue($document, $json, 0);
			return $json.'}';
		} catch(Throwable $error) { return false; }
	}

	/** Decode before the owning journal's existing schema, identity and digest checks. */
	public static function decodeBytes($json)
	{
		try {
			foreach(array('json_decode','is_string','is_array','array_key_exists') as $function)
				if(!@function_exists($function)) return null;
			if(!is_string($json)) return null;
			$document = json_decode($json, true);
			if(!is_array($document) || !array_key_exists(self::BYTE_DOCUMENT_KEY, $document)) return $document;
			if(!self::byteFunctions()) return null;
			// This root key is reserved for these opt-in journal owners, never guessed from a path.
			if(array_keys($document) !== array(self::BYTE_DOCUMENT_KEY, 'data') || $document[self::BYTE_DOCUMENT_KEY] !== 1
				|| !is_array($document['data'])) return null;
			self::readByteValue($document['data'], 0);
			return $document['data'];
		} catch(Throwable $error) { return null; }
	}

	private static function byteFunctions()
	{
		foreach(array('base64_encode','base64_decode','array_keys','array_key_exists','is_string','is_array',
			'is_int','is_bool','is_float','is_finite','substr','json_encode','json_decode') as $function)
			if(!@function_exists($function)) return false;
		return true;
	}

	private static function byteString($encoded)
	{
		if(!is_string($encoded)) throw new UnexpectedValueException('byte-string-invalid');
		$raw = base64_decode($encoded, true);
		if(!is_string($raw) || base64_encode($raw) !== $encoded)
			throw new UnexpectedValueException('byte-string-invalid');
		return $raw;
	}

	private static function encodedByteString($value)
	{
		$encoded = base64_encode($value);
		if(!is_string($encoded) || self::byteString($encoded) !== $value)
			throw new UnexpectedValueException('byte-string-incomplete');
		return $encoded;
	}

	private static function byteList(array $value)
	{
		$next = 0;
		foreach($value as $key=>$unused) if($key !== $next++) return false;
		return true;
	}

	private static function writeByteValue($value, &$json, $depth)
	{
		if($depth > 128) throw new UnexpectedValueException('byte-document-depth');
		if(is_string($value)) {
			$json .= '"'.self::encodedByteString($value).'"';
			return;
		}
		if(is_array($value)) {
			$list = self::byteList($value);
			$json .= $list ? '[' : '{';
			$separator = '';
			foreach($value as $key=>$child) {
				$json .= $separator;
				$separator = ',';
				if(!$list) $json .= '"'.(is_int($key) ? 'i:'.$key : 's:'.self::encodedByteString($key)).'":';
				self::writeByteValue($child, $json, $depth+1);
			}
			$json .= $list ? ']' : '}';
			return;
		}
		if(!is_int($value) && !is_bool($value) && $value !== null && !(is_float($value) && is_finite($value)))
			throw new UnexpectedValueException('byte-document-type');
		$scalar = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
		if(!is_string($scalar) || json_decode($scalar) !== $value || json_last_error() !== JSON_ERROR_NONE)
			throw new UnexpectedValueException('byte-document-scalar');
		$json .= $scalar;
	}

	private static function readByteValue(&$value, $depth)
	{
		if($depth > 128) throw new UnexpectedValueException('byte-document-depth');
		if(is_string($value)) { $value = self::byteString($value); return; }
		if(!is_array($value)) {
			if(is_int($value) || is_bool($value) || $value === null || (is_float($value) && is_finite($value))) return;
			throw new UnexpectedValueException('byte-document-type');
		}
		if(self::byteList($value)) {
			foreach($value as &$child) self::readByteValue($child, $depth+1);
			unset($child);
			return;
		}
		// Consume entries as they are decoded instead of retaining two complete document trees.
		$result = array();
		$keys = array_keys($value);
		if(!is_array($keys)) throw new UnexpectedValueException('byte-document-keys-unavailable');
		foreach($keys as $key) {
			if(!is_string($key)) throw new UnexpectedValueException('byte-document-key');
			$tag = substr($key, 0, 2);
			$encoded = substr($key, 2);
			if($tag.$encoded !== $key) throw new UnexpectedValueException('byte-document-key');
			if($tag === 's:') $raw = self::byteString($encoded);
			elseif($tag === 'i:' && (string)(int)$encoded === $encoded) $raw = (int)$encoded;
			else throw new UnexpectedValueException('byte-document-key');
			$probe = array($raw=>true);
			if(array_keys($probe) !== array($raw) || array_key_exists($raw, $result))
				throw new UnexpectedValueException('byte-document-key');
			self::readByteValue($value[$key], $depth+1);
			$result[$raw] = $value[$key];
			unset($value[$key]);
		}
		if($value !== array()) throw new UnexpectedValueException('byte-document-keys-incomplete');
		$value = $result;
	}

	public static function safeEncode($value)
	{
		return json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
	}

	// A JavaScript literal for $value, to be interpolated into an emitted
	// script body. The literal carries its own quotes, so a caller must not
	// add any. The HEX_* flags keep <, >, &, ' and " out of the result, so no
	// input can close the surrounding string literal or the <script> element
	// that holds it.
	public static function jsValue($value)
	{
		$json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE |
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
		return($json===false ? 'null' : $json);
	}
}
