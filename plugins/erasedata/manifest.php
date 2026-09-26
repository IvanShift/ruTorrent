<?php

final class ErasedataManifestCodec
{
	const VERSION = 2;
	const CLEANUP_VERSION = 3;
	const OPERATION_REMOVE_PAYLOAD = 'remove_payload';
	const OPERATION_CLEANUP_OBSOLETE = 'cleanup_obsolete';
	const MAX_MANIFEST_BYTES = 67108864; // 64 MiB
	const MAX_FILES = 1000000;
	const MAX_PATH_BYTES = 1048576; // 1 MiB
	const READ_CHUNK_BYTES = 65536;
	const MAX_CAPTURED_NAME_BYTES = 4096;

	// The one canonical force type: the INTEGER 1 or 2, never the string.
	//
	// Invariant 13. The wire still speaks strings -- an HTTP parameter and an
	// argv word are strings and cannot be anything else -- so the two exact
	// decimal spellings are accepted here, at the boundary, and converted once.
	// Everything past this point is an integer, and anything that is neither an
	// exact spelling nor the exact integer is refused rather than coerced: true,
	// 1.0, "01", " 1" and "1\n" are each somebody's mistake, and guessing at one
	// of them means guessing at a deletion.
	public static function normalizeForce($value)
	{
		if($value === 1 || $value === 2)
			return($value);
		if($value === "1")
			return(1);
		if($value === "2")
			return(2);
		return(null);
	}

	public static function isValidAbsolutePath($path)
	{
		if(!is_string($path) || strlen($path) === 0 || strlen($path) > self::MAX_PATH_BYTES)
			return(false);
		if(strpos($path, "\0") !== false)
			return(false);
		if($path[0] !== '/')
			return(false);
		$parts = explode('/', $path);
		for($i = 1; $i < count($parts); $i++)
		{
			$p = $parts[$i];
			if($p === '.' || $p === '..')
				return(false);
		}
		return(true);
	}

	public static function isUnderBase($file, $base)
	{
		if(!self::isValidAbsolutePath($file) || !self::isValidAbsolutePath($base))
			return(false);
		$cleanBase = rtrim($base, '/');
		if($cleanBase === '')
			return(false);
		if($file === $cleanBase)
			return(true);
		if(strpos($file, $cleanBase.'/') !== 0)
			return(false);
		return(true);
	}

	private static function base64EncodedLength($rawLength)
	{
		if(!is_int($rawLength) || $rawLength < 0)
			return(false);
		$groups = intdiv($rawLength, 3);
		if($groups > intdiv(PHP_INT_MAX, 4))
			return(false);
		$encoded = $groups * 4;
		if(($rawLength % 3) !== 0)
		{
			if($encoded > PHP_INT_MAX - 4)
				return(false);
			$encoded += 4;
		}
		return($encoded);
	}

	private static function addWithinLimit($total, $increment, $limit)
	{
		if(!is_int($total) || !is_int($increment) || !is_int($limit)
			|| $total < 0 || $increment < 0 || $limit < 0
			|| $total > $limit || $increment > $limit - $total)
			return(false);
		return($total + $increment);
	}

	public static function encode($hash, array $paths, $force, array $limits = array())
	{
		$normalizedForce = is_int($force) ? ($force === 1 || $force === 2 ? $force : null) : self::normalizeForce($force);
		if(is_null($normalizedForce))
			return(false);
		$maxFiles = self::MAX_FILES;
		$maxManifestBytes = self::MAX_MANIFEST_BYTES;
		foreach($limits as $name => $value)
		{
			if(!is_int($value) || $value <= 0)
				return(false);
			if($name === 'max_files' && $value <= self::MAX_FILES)
				$maxFiles = $value;
			else if($name === 'max_manifest_bytes' && $value <= self::MAX_MANIFEST_BYTES)
				$maxManifestBytes = $value;
			else
				return(false);
		}

		if(!is_string($hash) || !preg_match('/^[0-9A-Fa-f]{40}$/D', $hash))
			return(false);
		$canonicalHash = strtoupper($hash);

		if(!isset($paths['base']) || !isset($paths['multi']) || !isset($paths['files']) || !is_array($paths['files']))
			return(false);

		$base = $paths['base'];
		if(!self::isValidAbsolutePath($base) || rtrim($base, '/') === '' || $base === '.' || $base === '..')
			return(false);

		$multi = ($paths['multi'] === true || $paths['multi'] === 1 || $paths['multi'] === "1");
		$files = $paths['files'];
		$numFiles = count($files);
		if($numFiles === 0 || $numFiles > $maxFiles)
			return(false);

		if(!$multi)
		{
			if($numFiles !== 1 || $files[0] !== $base)
				return(false);
		}

		$prefix = '{"version":2,"hash":"'.$canonicalHash.'","path_encoding":"base64","files":[';
		$middle = '],"base":"';
		$suffix = '","multi":'.($multi ? 'true' : 'false').',"force":'.$normalizedForce."}\n";
		$manifestBytes = self::addWithinLimit(
			strlen($prefix) + strlen($middle) + strlen($suffix),
			self::base64EncodedLength(strlen($base)), $maxManifestBytes);
		if($manifestBytes === false)
			return(false);

		$seenFiles = array();
		foreach($files as $index => $f)
		{
			if(!self::isValidAbsolutePath($f))
				return(false);
			if($multi && !self::isUnderBase($f, $base))
				return(false);
			if(isset($seenFiles[$f]))
				return(false);
			$encodedLength = self::base64EncodedLength(strlen($f));
			if($encodedLength === false)
				return(false);
			$entryBytes = self::addWithinLimit($encodedLength, 2 + ($index === 0 ? 0 : 1), PHP_INT_MAX);
			$manifestBytes = $entryBytes === false ? false
				: self::addWithinLimit($manifestBytes, $entryBytes, $maxManifestBytes);
			if($manifestBytes === false)
				return(false);
			$seenFiles[$f] = true;
		}

		$encodedFiles = array();
		foreach($files as $f)
			$encodedFiles[] = base64_encode($f);

		$data = array(
			"version" => self::VERSION,
			"hash" => $canonicalHash,
			"path_encoding" => "base64",
			"files" => $encodedFiles,
			"base" => base64_encode($base),
			"multi" => $multi,
			"force" => $normalizedForce,
		);

		$json = json_encode($data, JSON_UNESCAPED_SLASHES);
		if($json === false)
			return(false);

		$manifest = $json."\n";
		if(strlen($manifest) !== $manifestBytes || strlen($manifest) > $maxManifestBytes)
			return(false);

		return($manifest);
	}

	public static function encodeCleanupObsolete($oldHash, $newHash, $marker, $replacementRecord, $base, array $entries)
	{
		if(!self::isValidHash($oldHash) || !self::isValidHash($newHash))
			return(false);
		$canonicalOldHash = strtoupper($oldHash);
		$canonicalNewHash = strtoupper($newHash);
		if($canonicalOldHash === $canonicalNewHash || !self::isValidMarker($marker)
			|| !self::isValidReplacementRecord($replacementRecord, $canonicalOldHash))
			return(false);

		if(!self::isValidCleanupBase($base) || count($entries) === 0 || count($entries) > self::MAX_FILES
			|| array_keys($entries) !== range(0, count($entries) - 1))
			return(false);

		$seen = array();
		$encodedEntries = array();
		foreach($entries as $entry)
		{
			if(!is_array($entry) || !self::hasExactKeys($entry, array('path', 'identity'))
				|| !is_string($entry['path']) || !self::isStrictlyUnderBase($entry['path'], $base)
				|| isset($seen[$entry['path']]))
				return(false);
			$identity = self::normalizeIdentity($entry['identity']);
			if($identity === false)
				return(false);
			$seen[$entry['path']] = true;
			$encodedEntries[] = array(
				'path' => base64_encode($entry['path']),
				'canonical' => base64_encode($identity['canonical']),
				'lstat' => $identity['lstat'],
				'stat' => $identity['stat'],
				'size' => $identity['size'],
				'mtime' => $identity['mtime'],
			);
		}

		$data = array(
			'version' => self::CLEANUP_VERSION,
			'operation' => self::OPERATION_CLEANUP_OBSOLETE,
			'hash' => $canonicalOldHash,
			'new_hash' => $canonicalNewHash,
			'marker' => $marker,
			'replacement_record' => $replacementRecord,
			'path_encoding' => 'base64',
			'base' => base64_encode($base),
			'files' => $encodedEntries,
		);
		$json = json_encode($data, JSON_UNESCAPED_SLASHES);
		if($json === false)
			return(false);
		$manifest = $json."\n";
		return(strlen($manifest) <= self::MAX_MANIFEST_BYTES ? $manifest : false);
	}

	private static function isValidHash($hash)
	{
		return(is_string($hash) && preg_match('/^[0-9A-Fa-f]{40}$/D', $hash));
	}

	private static function isValidManifestBase($base)
	{
		return(self::isValidAbsolutePath($base) && rtrim($base, '/') !== '' && $base !== '.' && $base !== '..');
	}

	private static function isStrictlyUnderBase($path, $base)
	{
		return(self::isValidCanonicalAbsolutePath($path) && self::isValidCleanupBase($base)
			&& strpos($path, $base.'/') === 0);
	}

	private static function isValidCanonicalAbsolutePath($path)
	{
		return(self::isValidAbsolutePath($path) && $path !== '/' && substr($path, -1) !== '/'
			&& strpos($path, '//') === false);
	}

	private static function isValidCleanupBase($base)
	{
		return(self::isValidCanonicalAbsolutePath($base));
	}

	private static function hasExactKeys(array $value, array $keys)
	{
		if(count($value) !== count($keys))
			return(false);
		foreach($keys as $key)
			if(!array_key_exists($key, $value))
				return(false);
		return(true);
	}

	// The one canonical-base64 rule of this plugin: a value must decode and
	// re-encode to exactly the bytes it arrived as, so no alternative encoding
	// of the same payload is ever accepted.
	public static function decodeCanonicalBase64($value)
	{
		if(!is_string($value))
			return(false);
		$raw = base64_decode($value, true);
		return($raw !== false && base64_encode($raw) === $value ? $raw : false);
	}

	private static function isNonnegativeInteger($value)
	{
		return(is_int($value) && $value >= 0);
	}

	private static function normalizeIdentity($identity)
	{
		if(!is_array($identity) || !self::hasExactKeys($identity, array('canonical', 'lstat', 'stat', 'size', 'mtime'))
			|| !is_string($identity['canonical']) || !self::isValidCanonicalAbsolutePath($identity['canonical'])
			|| !is_array($identity['lstat']) || !self::hasExactKeys($identity['lstat'], array('dev', 'ino'))
			|| !is_array($identity['stat']) || !self::hasExactKeys($identity['stat'], array('dev', 'ino')))
			return(false);
		foreach(array($identity['lstat']['dev'], $identity['lstat']['ino'], $identity['stat']['dev'],
			$identity['stat']['ino'], $identity['size'], $identity['mtime']) as $value)
			if(!self::isNonnegativeInteger($value))
				return(false);
		return(array(
			'canonical' => $identity['canonical'],
			'lstat' => array('dev' => $identity['lstat']['dev'], 'ino' => $identity['lstat']['ino']),
			'stat' => array('dev' => $identity['stat']['dev'], 'ino' => $identity['stat']['ino']),
			'size' => $identity['size'],
			'mtime' => $identity['mtime'],
		));
	}

	private static function isValidMarker($marker)
	{
		return(is_string($marker) && preg_match('/^[0-9A-Fa-f]{32}$/D', $marker));
	}

	private static function isValidReplacementRecord($record, $canonicalOldHash)
	{
		if(!is_string($record) || !preg_match('/^([0-9A-Fa-f]{40})-(started|open|stopped)-([1-9][0-9]*)$/D', $record, $match))
			return(false);
		return(strtoupper($match[1]) === $canonicalOldHash);
	}

	private static function hasUniqueJSONObjectMembers($json)
	{
		$offset = 0;
		if(!self::scanJSONValue($json, $offset, 0))
			return(false);
		self::skipJSONWhitespace($json, $offset);
		return($offset === strlen($json));
	}

	private static function skipJSONWhitespace($json, &$offset)
	{
		$length = strlen($json);
		while($offset < $length && ($json[$offset] === " " || $json[$offset] === "\t"
			|| $json[$offset] === "\r" || $json[$offset] === "\n"))
			$offset++;
	}

	private static function scanJSONString($json, &$offset, &$decoded)
	{
		$length = strlen($json);
		if($offset >= $length || $json[$offset] !== '"')
			return(false);
		$start = $offset++;
		while($offset < $length)
		{
			$c = $json[$offset];
			if($c === '"')
			{
				$offset++;
				$decoded = json_decode(substr($json, $start, $offset - $start), true);
				return(is_string($decoded));
			}
			if($c === '\\')
			{
				if($offset + 1 >= $length)
					return(false);
				$offset += 2;
				continue;
			}
			if(ord($c) < 0x20)
				return(false);
			$offset++;
		}
		return(false);
	}

	private static function scanJSONValue($json, &$offset, $depth)
	{
		if($depth > 512)
			return(false);
		self::skipJSONWhitespace($json, $offset);
		if($offset >= strlen($json))
			return(false);
		if($json[$offset] === '{')
			return(self::scanJSONObject($json, $offset, $depth + 1));
		if($json[$offset] === '[')
			return(self::scanJSONArray($json, $offset, $depth + 1));
		if($json[$offset] === '"')
		{
			$decoded = null;
			return(self::scanJSONString($json, $offset, $decoded));
		}
		$start = $offset;
		$length = strlen($json);
		while($offset < $length && strpos(" \t\r\n,]}", $json[$offset]) === false)
			$offset++;
		return($offset > $start);
	}

	private static function scanJSONObject($json, &$offset, $depth)
	{
		$offset++;
		self::skipJSONWhitespace($json, $offset);
		if($offset < strlen($json) && $json[$offset] === '}')
		{
			$offset++;
			return(true);
		}
		$seen = array();
		while(true)
		{
			$key = null;
			if(!self::scanJSONString($json, $offset, $key) || array_key_exists($key, $seen))
				return(false);
			$seen[$key] = true;
			self::skipJSONWhitespace($json, $offset);
			if($offset >= strlen($json) || $json[$offset] !== ':')
				return(false);
			$offset++;
			if(!self::scanJSONValue($json, $offset, $depth))
				return(false);
			self::skipJSONWhitespace($json, $offset);
			if($offset >= strlen($json))
				return(false);
			if($json[$offset] === '}')
			{
				$offset++;
				return(true);
			}
			if($json[$offset] !== ',')
				return(false);
			$offset++;
			self::skipJSONWhitespace($json, $offset);
		}
	}

	private static function scanJSONArray($json, &$offset, $depth)
	{
		$offset++;
		self::skipJSONWhitespace($json, $offset);
		if($offset < strlen($json) && $json[$offset] === ']')
		{
			$offset++;
			return(true);
		}
		while(true)
		{
			if(!self::scanJSONValue($json, $offset, $depth))
				return(false);
			self::skipJSONWhitespace($json, $offset);
			if($offset >= strlen($json))
				return(false);
			if($json[$offset] === ']')
			{
				$offset++;
				return(true);
			}
			if($json[$offset] !== ',')
				return(false);
			$offset++;
			self::skipJSONWhitespace($json, $offset);
		}
	}

	public static function decodeBytes($bytes, $expectedHash)
	{
		if(!is_string($bytes) || strlen($bytes) === 0 || strlen($bytes) > self::MAX_MANIFEST_BYTES)
			return(false);
		if(!is_string($expectedHash) || !preg_match('/^[0-9A-Fa-f]{40}$/D', $expectedHash))
			return(false);
		$canonicalExpectedHash = strtoupper($expectedHash);

		// A legacy newline-delimited list cannot prove its base: a path element
		// may itself contain a newline and forge the trailing base/mode fields.
		// Keep this decision in the codec so no caller can accidentally revive
		// the old destructive interpretation.
		if(self::isUnverifiedPlaintext($bytes))
			return(false);
		$json = json_decode($bytes, true);
		if(!is_array($json))
			return(false);
		// Serialized member names are required to be unique for cleanup jobs:
		// those artifacts are capabilities authorizing deletion under another
		// torrent's base. The v2 payload writer cannot emit duplicate keys.
		if(isset($json['version']) && $json['version'] === self::CLEANUP_VERSION
			&& !self::hasUniqueJSONObjectMembers($bytes))
			return(false);
		return(self::decodeJSON($json, $canonicalExpectedHash));
	}

	public static function isUnverifiedPlaintext($bytes)
	{
		if(!is_string($bytes))
			return(false);
		$length = strlen($bytes);
		for($i = 0; $i < $length; $i++)
		{
			if($bytes[$i] !== ' ' && $bytes[$i] !== "\t"
				&& $bytes[$i] !== "\r" && $bytes[$i] !== "\n")
				return($bytes[$i] !== '{');
		}
		return(false);
	}

	private static function decodeJSON(array $json, $canonicalExpectedHash)
	{
		if(!array_key_exists('version', $json))
			return(false);
		if($json['version'] === self::VERSION)
			return(self::decodeVersionTwoJSON($json, $canonicalExpectedHash));
		if($json['version'] === self::CLEANUP_VERSION)
			return(self::decodeCleanupJSON($json, $canonicalExpectedHash));
		return(false);
	}

	private static function decodeVersionTwoJSON(array $json, $canonicalExpectedHash)
	{
		$expectedKeys = array('version', 'hash', 'path_encoding', 'files', 'base', 'multi', 'force');
		if(!self::hasExactKeys($json, $expectedKeys) || $json['version'] !== self::VERSION
			|| !is_string($json['hash']) || !preg_match('/^[0-9A-F]{40}$/D', $json['hash'])
			|| $json['hash'] !== $canonicalExpectedHash
			|| $json['path_encoding'] !== 'base64' || !is_bool($json['multi'])
			|| !is_int($json['force']) || ($json['force'] !== 1 && $json['force'] !== 2))
			return(false);

		$baseRaw = self::decodeCanonicalBase64($json['base']);
		if($baseRaw === false || !self::isValidManifestBase($baseRaw) || !is_array($json['files']))
			return(false);
		$numFiles = count($json['files']);
		if($numFiles === 0 || $numFiles > self::MAX_FILES || array_keys($json['files']) !== range(0, $numFiles - 1))
			return(false);

		$seen = array();
		$decodedFiles = array();
		foreach($json['files'] as $fEnc)
		{
			$fRaw = self::decodeCanonicalBase64($fEnc);
			if($fRaw === false || !self::isValidAbsolutePath($fRaw)
				|| ($json['multi'] && !self::isUnderBase($fRaw, $baseRaw)) || isset($seen[$fRaw]))
				return(false);
			$seen[$fRaw] = true;
			$decodedFiles[] = $fRaw;
		}
		if(!$json['multi'] && ($numFiles !== 1 || $decodedFiles[0] !== $baseRaw))
			return(false);
		return(array(
			'version' => self::VERSION,
			'operation' => self::OPERATION_REMOVE_PAYLOAD,
			'hash' => $canonicalExpectedHash,
			'files' => $decodedFiles,
			'base' => $baseRaw,
			'multi' => $json['multi'],
			'force' => $json['force'],
			'keep_base' => false,
			'legacy' => false,
		));
	}

	private static function decodeCleanupJSON(array $json, $canonicalExpectedHash)
	{
		$expectedKeys = array('version', 'operation', 'hash', 'new_hash', 'marker', 'replacement_record',
			'path_encoding', 'base', 'files');
		if(!self::hasExactKeys($json, $expectedKeys) || $json['version'] !== self::CLEANUP_VERSION
			|| $json['operation'] !== self::OPERATION_CLEANUP_OBSOLETE
			|| !is_string($json['hash']) || strtoupper($json['hash']) !== $canonicalExpectedHash
			|| !self::isValidHash($json['new_hash']) || strtoupper($json['new_hash']) === $canonicalExpectedHash
			|| !self::isValidMarker($json['marker'])
			|| !self::isValidReplacementRecord($json['replacement_record'], $canonicalExpectedHash)
			|| $json['path_encoding'] !== 'base64')
			return(false);
		$baseRaw = self::decodeCanonicalBase64($json['base']);
		if($baseRaw === false || !self::isValidCleanupBase($baseRaw) || !is_array($json['files']))
			return(false);
		$numFiles = count($json['files']);
		if($numFiles === 0 || $numFiles > self::MAX_FILES || array_keys($json['files']) !== range(0, $numFiles - 1))
			return(false);

		$files = array();
		$identities = array();
		foreach($json['files'] as $entry)
		{
			if(!is_array($entry) || !self::hasExactKeys($entry, array('path', 'canonical', 'lstat', 'stat', 'size', 'mtime')))
				return(false);
			$path = self::decodeCanonicalBase64($entry['path']);
			$canonical = self::decodeCanonicalBase64($entry['canonical']);
			if($path === false || $canonical === false || !self::isStrictlyUnderBase($path, $baseRaw)
				|| isset($identities[$path]))
				return(false);
			$identity = self::normalizeIdentity(array(
				'canonical' => $canonical,
				'lstat' => $entry['lstat'],
				'stat' => $entry['stat'],
				'size' => $entry['size'],
				'mtime' => $entry['mtime'],
			));
			if($identity === false)
				return(false);
			$files[] = $path;
			$identities[$path] = $identity;
		}
		return(array(
			'version' => self::CLEANUP_VERSION,
			'operation' => self::OPERATION_CLEANUP_OBSOLETE,
			'hash' => $canonicalExpectedHash,
			'new_hash' => strtoupper($json['new_hash']),
			'marker' => $json['marker'],
			'replacement_record' => $json['replacement_record'],
			'base' => $baseRaw,
			'files' => $files,
			'identities' => $identities,
			'multi' => true,
			'force' => 1,
			'keep_base' => true,
			'legacy' => false,
		));
	}

	// The only reader of manifest-protocol bytes. Every consumer goes through
	// this so the byte ceiling is enforced while reading instead of after the
	// allocation. $limit bounds one specific artifact and is never raised above
	// the manifest ceiling by a caller that omits it.
	public static function readBoundedHandle($handle, $limit = self::MAX_MANIFEST_BYTES)
	{
		if(!is_resource($handle) || !is_int($limit) || $limit < 0)
			return(false);

		$bytes = '';
		$readTotal = 0;
		while(!feof($handle))
		{
			$chunk = fread($handle, self::READ_CHUNK_BYTES);
			if($chunk === false)
				return(false);
			if(strlen($chunk) === 0)
				break;
			$readTotal += strlen($chunk);
			if($readTotal > $limit)
				return(false);
			$bytes .= $chunk;
		}
		return($bytes);
	}

	public static function readBoundedFile($path, $limit = self::MAX_MANIFEST_BYTES)
	{
		if(!is_string($path) || $path === '')
			return(false);
		$handle = @fopen($path, 'rb');
		if($handle === false)
			return(false);
		$bytes = self::readBoundedHandle($handle, $limit);
		@fclose($handle);
		return($bytes);
	}

	public static function decodeStream($handle, $expectedHash)
	{
		$bytes = self::readBoundedHandle($handle);
		if($bytes === false)
			return(false);
		return(self::decodeBytes($bytes, $expectedHash));
	}

	// The only promotion of a staged manifest to its published name. The list
	// name is derived from the staging name, never guessed, and an existing or
	// symlinked list name is refused instead of replaced, so one generation can
	// never overwrite another. $filesystem is the collector's mutation seam;
	// the producer has none and gets the plain one.
	// This file requires nothing, because erase.php loads it alone for its force
	// pre-check and requiring filesystem.php here would make a cycle. Publishing
	// therefore borrows two symbols from files it cannot name. Every caller
	// loads them; a future one that does not gets a refusal it can read instead
	// of a fatal on an undefined symbol.
	// $reportRefusal: whether a failed publication writes its own line here.
	//
	// It is a CONDITION, not an event -- the drain retries a retained staging on
	// every tick, five seconds apart, for as long as the obstacle stands -- and
	// this class has no memory across ticks, so the line below repeated about
	// 17k times a day. Callers that classify the refusal themselves and pass it
	// through the drain's durable report memory pass false; the note they write
	// says the same thing once. Callers that say nothing of their own leave it
	// true, because a refusal nobody reports is worse than one reported often.
	public static function publishStaging($tmpPath, $hash,
		?ErasedataFilesystemOps $filesystem = null, $reportRefusal = true)
	{
		if(!class_exists('ErasedataFilesystemOps', false)
			|| !function_exists('erasedataRepairFileMode'))
		{
			FileUtil::toLog('erasedata: publishStaging needs filesystem.php and'
				.' removewithdata.php loaded, refusing to publish '.$hash);
			return(false);
		}
		$listFile = is_string($tmpPath) && substr($tmpPath, -4) === '.tmp'
			? substr($tmpPath, 0, -4).'.list' : '';
		if($filesystem === null)
			$filesystem = new ErasedataFilesystemOps();
		if($listFile === '' || file_exists($listFile) || is_link($listFile)
			|| !is_file($tmpPath) || !$filesystem->rename($tmpPath, $listFile))
		{
			if($reportRefusal)
				FileUtil::toLog("erasedata: failed to publish manifest for ".$hash
					.", staging retained");
			return(false);
		}
		erasedataRepairFileMode($listFile);
		return(true);
	}
}
