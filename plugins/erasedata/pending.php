<?php

// The durable primitives this store is built out of: the atomic writer, the
// generation rules and the shared file mode. filesystem.php requires this file
// back, so the pair loads correctly from either side.
require_once(dirname(__FILE__)."/filesystem.php");

// The obligation store between whoever asks for a download to be removed with
// its data and the guarded worker that carries it out.
//
// A firing may cover several hundred downloads, and doing the RPC work inline
// made each firing a blocking client of a server that answers one request at a
// time: everything timed out, nothing was recorded, and nothing recorded meant
// the whole sweep came back on the caller's next pass, for ever. So a producer
// does only the recording part -- one local file write, which should not fail
// however busy the server is -- and the worker does the rest.
//
// What this file owns, and nothing else:
//
//   * the marker record: an exact four-field schema, written durably under a
//     name that carries the hash AND the generation it was admitted under;
//   * reading the store back as obligations, which never mutates anything;
//   * the canonical order the per-hash locks are taken in.
//
// What it deliberately does NOT own any more:
//
//   * draining. The producer used to own a drain lock and a drain loop, so the
//     process that recorded a request also decided when to erase; invariant 5
//     puts that in the guarded update.php child instead.
//   * giving up. The upstream queue abandoned a request after N attempts,
//     leaving a download the user asked to delete sitting there with its data.
//     Invariant 9 forbids a terminal cap: an obligation that is not resolved is
//     retained and retried, however long that takes.
//   * deciding anything about a hash. Reading the store erases nothing, asks
//     rTorrent nothing and deletes nothing -- not even a candidate nobody can
//     parse, which the retirement scan still has to be able to see.

// One marker holds exactly these four fields, in this order, and nothing else.
define('ERASEDATA_PENDING_MARKER_VERSION', 1);
// version=1\n + generation=<16>\n + hash=<40>\n + force=<1>\n is exactly 92
// bytes for every marker: all four widths are fixed, so no marker is ever
// larger. The ceiling is generous enough for a future field and small enough
// that a marker can never become a memory ceiling of its own.
define('ERASEDATA_PENDING_MARKER_MAX_BYTES', 4096);
// Cardinality caps. Exceeding either is a refusal, never a truncation: dropping
// an obligation to fit a bound is exactly what invariant 9 forbids.
define('ERASEDATA_PENDING_MAX_MARKERS', 65536);
define('ERASEDATA_PENDING_MAX_GENERATIONS', 4095);

if(!function_exists('erasedataEncodePendingMarker'))
{
	// The exact four-field record, byte deterministic.
	//
	// Deterministic matters: a marker is compared byte for byte by the cases
	// that prove nothing rewrites an obligation, and a record that re-encoded
	// differently would make a rewrite invisible.
	//
	// Force is the integer 1 or 2 in every PHP value and the single decimal
	// character in the file. Anything else is refused here rather than coerced,
	// because coercing an unreadable force to 1 turns "I could not tell what was
	// asked for" into "delete the download's own files", which is a decision
	// this layer has no standing to make.
	function erasedataEncodePendingMarker(array $record)
	{
		$keys = array('version', 'generation', 'hash', 'force');
		if(count($record) !== count($keys))
			return(false);
		foreach($keys as $key)
			if(!array_key_exists($key, $record))
				return(false);
		if($record['version'] !== ERASEDATA_PENDING_MARKER_VERSION)
			return(false);
		if(!erasedataGenerationIsValid($record['generation']))
			return(false);
		if(!erasedataIsCanonicalPendingHash($record['hash']))
			return(false);
		if($record['force'] !== 1 && $record['force'] !== 2)
			return(false);
		return('version='.ERASEDATA_PENDING_MARKER_VERSION."\n"
			.'generation='.$record['generation']."\n"
			.'hash='.$record['hash']."\n"
			.'force='.$record['force']."\n");
	}

	// The record, or false. Strict in every direction: exact keys, no unknown
	// key, no duplicate key, no missing key, exact types, and a trailing
	// newline with nothing after it.
	function erasedataDecodePendingMarker($bytes)
	{
		if(!is_string($bytes) || $bytes === ''
			|| strlen($bytes) > ERASEDATA_PENDING_MARKER_MAX_BYTES
			|| substr($bytes, -1) !== "\n")
			return(false);
		$lines = explode("\n", substr($bytes, 0, -1));
		$keys = array('version', 'generation', 'hash', 'force');
		if(count($lines) !== count($keys))
			return(false);
		$fields = array();
		foreach($lines as $line)
		{
			$split = strpos($line, '=');
			if($split === false || $split === 0)
				return(false);
			$key = substr($line, 0, $split);
			// Belt and braces, and deliberately not mutation-killable: the
			// record is already required to hold exactly four lines, so one
			// that repeats a key is missing another and the missing-key loop
			// below refuses it anyway. This states the "no duplicate key"
			// clause of the contract above instead of leaving it an accident
			// of the arity check, which a later optional field would undo.
			if(array_key_exists($key, $fields))
				return(false);
			$fields[$key] = substr($line, $split + 1);
		}
		foreach($keys as $key)
			if(!array_key_exists($key, $fields))
				return(false);
		if($fields['version'] !== (string)ERASEDATA_PENDING_MARKER_VERSION)
			return(false);
		if(!erasedataGenerationIsValid($fields['generation']))
			return(false);
		if(!erasedataIsCanonicalPendingHash($fields['hash']))
			return(false);
		if($fields['force'] !== '1' && $fields['force'] !== '2')
			return(false);
		return(array(
			'version' => ERASEDATA_PENDING_MARKER_VERSION,
			'generation' => $fields['generation'],
			'hash' => $fields['hash'],
			'force' => (int)$fields['force'],
		));
	}

	// 40 hex digits, uppercase: the canonical spelling every record carries and
	// every lock is named with.
	function erasedataIsCanonicalPendingHash($value)
	{
		return(is_string($value) && preg_match('/^[0-9A-F]{40}$/D', $value) === 1);
	}

	function erasedataIsPendingHash($value)
	{
		return(is_string($value) && preg_match('/^[0-9A-Fa-f]{40}$/D', $value) === 1);
	}

	// Record one erase request.
	//
	// The name binds the hash to the generation: two physical torrents can share
	// an infohash, and an obligation admitted for one of them must never be
	// acknowledged by anything belonging to the other.
	//
	// Returns true when the obligation is recorded (or already was), false when
	// the request is refused or could not be made durable. A false return is
	// terminal for this attempt: nothing was written, so the caller holds no
	// obligation and must refuse the request rather than go on.
	function erasedataQueueRequest($listPath, $hash, $force, $generation)
	{
		if(!is_string($listPath) || $listPath === '' || strpos($listPath, "\0") !== false)
			return(false);
		// Refused, not coerced: see erasedataEncodePendingMarker().
		if($force !== 1 && $force !== 2)
			return(false);
		if(!erasedataIsPendingHash($hash))
			return(false);
		if(!erasedataGenerationIsValid($generation))
			return(false);
		$canonical = strtoupper($hash);
		$marker = erasedataPendingMarkerPath($listPath, $canonical, $generation);
		if(!is_string($marker))
			return(false);
		clearstatcache(true, $marker);
		// Already recorded, and never rewritten: a caller that fires again -- a
		// ratio group command does so on every check -- must not be able to
		// change the force or the generation an admission already bound.
		if(is_file($marker))
			return(true);
		// This generation's own manifest is already published, so the obligation
		// is discharged and the collector owns what is left of it. A bare
		// <hash>.list is NOT that manifest: it belongs to an older torrent that
		// happened to share the infohash.
		if(erasedataPendingGenerationIsPublished($listPath, $canonical, $generation))
			return(true);
		$bytes = erasedataEncodePendingMarker(array(
			'version' => ERASEDATA_PENDING_MARKER_VERSION,
			'generation' => $generation,
			'hash' => $canonical,
			'force' => $force,
		));
		if($bytes === false)
			return(false);
		return(erasedataWriteDurableFile($marker, $bytes));
	}

	// <listPath>/<HASH>.<generation>.pending, or false for anything that is not
	// a hash bound to a generation.
	//
	// The name carries the CANONICAL uppercase hash, never the caller's
	// spelling. erasedataIsPendingHash() accepts either case, so a name built
	// from the spelling handed in makes two files out of one obligation: the
	// "already recorded" guard misses the other spelling, and the record inside
	// -- which is always canonical -- then names only one of the two in
	// markers[HASH]['path']. A worker that discharges the obligation unlinks
	// that one and the other stays for ever, so invariant 10's proven-empty scan
	// of pending can never succeed and the schedule can never retire.
	function erasedataPendingMarkerPath($listPath, $hash, $generation)
	{
		if(!is_string($listPath) || $listPath === ''
			|| strpos($listPath, "\0") !== false
			|| !erasedataIsPendingHash($hash)
			|| !erasedataGenerationIsValid($generation))
			return(false);
		return($listPath.'/'.strtoupper($hash).'.'.$generation.'.pending');
	}

	// The published manifests among $entries, as $canonicalHash => $generation
	// => true. A manifest is <hash>.<generation>.<token>.list; a bare
	// <hash>.list carries no generation and so acknowledges nothing.
	function erasedataPublishedGenerations($entries)
	{
		$published = array();
		foreach($entries as $entry)
		{
			$parts = explode('.', $entry);
			if(count($parts) < 4 || $parts[count($parts) - 1] !== 'list')
				continue;
			if(!erasedataIsPendingHash($parts[0])
				|| !erasedataGenerationIsValid($parts[1]))
				continue;
			$published[strtoupper($parts[0])][$parts[1]] = true;
		}
		return($published);
	}

	function erasedataPendingGenerationIsPublished($listPath, $canonicalHash, $generation)
	{
		$entries = @scandir($listPath);
		if(!is_array($entries))
			return(false);
		$published = erasedataPublishedGenerations($entries);
		return(isset($published[$canonicalHash][$generation]));
	}

	// Everything still owed, grouped by the generation it was admitted under, or
	// false when the store cannot be read or is beyond its bounds.
	//
	// Reading is a pure read. It consumes nothing, counts nothing, rewrites
	// nothing and deletes nothing -- including a marker nobody could have
	// written, which is not an obligation but must survive for the retirement
	// scan to refuse to retire on.
	//
	//   array(
	//     '<generation>' => array(
	//       'generation' => '<16 lowercase hex>',
	//       'force'      => 1|2, or false when the members disagree,
	//       'hashes'     => canonical uppercase, unique, sorted,
	//       'markers'    => '<HASH>' => array('path' => ..., 'force' => 1|2),
	//     ),
	//   )
	//
	// One admission binds one generation and one force to every member, so a
	// group whose members disagree on force is corruption: it is reported whole,
	// with a false group force, so a caller that checks the force fails closed
	// on it instead of picking one of the two.
	function erasedataPendingObligations($listPath)
	{
		if(!is_string($listPath) || $listPath === '')
			return(false);
		$entries = @scandir($listPath);
		if(!is_array($entries))
			return(false);
		$published = erasedataPublishedGenerations($entries);
		$obligations = array();
		$markers = 0;
		foreach($entries as $entry)
		{
			$parts = explode('.', $entry);
			if(count($parts) !== 3 || $parts[2] !== 'pending')
				continue;
			if(!erasedataIsPendingHash($parts[0])
				|| !erasedataGenerationIsValid($parts[1]))
				continue;
			if(++$markers > ERASEDATA_PENDING_MAX_MARKERS)
				return(false);
			$canonical = strtoupper($parts[0]);
			$generation = $parts[1];
			if(isset($published[$canonical][$generation]))
				continue;
			$path = $listPath.'/'.$entry;
			$bytes = @file_get_contents($path, false, null, 0,
				ERASEDATA_PENDING_MARKER_MAX_BYTES + 1);
			$record = is_string($bytes) ? erasedataDecodePendingMarker($bytes) : false;
			// Exact cross-field equality: a record whose own hash or generation
			// disagrees with the name it is filed under binds nothing, so it is
			// not an obligation. It is left exactly where it is.
			if(!is_array($record) || $record['hash'] !== $canonical
				|| $record['generation'] !== $generation)
				continue;
			if(!isset($obligations[$generation]))
			{
				if(count($obligations) >= ERASEDATA_PENDING_MAX_GENERATIONS)
					return(false);
				$obligations[$generation] = array(
					'generation' => $generation,
					'force' => $record['force'],
					'hashes' => array(),
					'markers' => array(),
				);
			}
			else if($obligations[$generation]['force'] !== $record['force'])
				$obligations[$generation]['force'] = false;
			$obligations[$generation]['hashes'][] = $canonical;
			$obligations[$generation]['markers'][$canonical] = array(
				'path' => $path,
				'force' => $record['force'],
			);
		}
		foreach(array_keys($obligations) as $generation)
		{
			$hashes = array_values(array_unique($obligations[$generation]['hashes']));
			sort($hashes, SORT_STRING);
			$obligations[$generation]['hashes'] = $hashes;
			ksort($obligations[$generation]['markers'], SORT_STRING);
		}
		ksort($obligations, SORT_STRING);
		return($obligations);
	}

	// Take the per-hash locks for one batch, BLOCKING, in canonical order.
	//
	// Invariant 7: the order is decided here, before the first lock is taken,
	// and it is canonical -- uppercased, deduplicated, sorted -- so two workers
	// handed the same hashes in opposite orders still walk them in the same
	// order and cannot each end up holding what the other is waiting for. Taking
	// the order a caller happened to hand in is a deterministic deadlock, not a
	// rare one.
	//
	// Returns $canonicalHash => handle, in the order the locks were taken, or
	// false. A refusal releases everything it had already taken, so a caller
	// never inherits a partial set.
	function erasedataLockObligations($listPath, $hashes)
	{
		if(!is_string($listPath) || $listPath === '' || strpos($listPath, "\0") !== false
			|| !is_array($hashes) || count($hashes) > ERASEDATA_PENDING_MAX_MARKERS)
			return(false);
		$canonical = array();
		foreach($hashes as $hash)
		{
			if(!erasedataIsPendingHash($hash))
				return(false);
			$canonical[strtoupper($hash)] = true;
		}
		$canonical = array_keys($canonical);
		sort($canonical, SORT_STRING);
		$locks = array();
		foreach($canonical as $hash)
		{
			$path = $listPath.'/'.$hash.'.lock';
			$handle = @fopen($path, 'c');
			if($handle === false)
			{
				erasedataUnlockObligations($locks);
				return(false);
			}
			// The lock is shared between the web user and whoever rTorrent runs
			// the scheduled child as, so it carries the profile mode whether
			// this process created it or found it.
			@chmod($path, erasedataSharedFileMode());
			if(!@flock($handle, LOCK_EX))
			{
				@fclose($handle);
				erasedataUnlockObligations($locks);
				return(false);
			}
			$locks[$hash] = $handle;
		}
		return($locks);
	}

	// Release what erasedataLockObligations() took, innermost first. True only
	// when every lock really came off.
	function erasedataUnlockObligations($locks)
	{
		if(!is_array($locks))
			return(false);
		$released = true;
		foreach(array_reverse($locks, true) as $handle)
		{
			if(!is_resource($handle))
			{
				$released = false;
				continue;
			}
			if(!@flock($handle, LOCK_UN))
				$released = false;
			if(@fclose($handle) !== true)
				$released = false;
		}
		return($released);
	}
}
