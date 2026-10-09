<?php

// The native owner grants deletion authority. These helpers only validate exact
// replies and never turn a missing hash, transport failure or unknown method
// into an accepted incarnation or a successful erase.
if(!function_exists('erasedataNativeCapability'))
{
	function erasedataNativeCapability()
	{
		if(!function_exists('rpcMethodCapability')) return('unconfirmed');
		$methods = array('d.incarnation');
		foreach(array('prepare', 'state', 'erase', 'collect_start', 'collect_begin', 'collect_finish', 'cancel') as $name)
			$methods[] = 'system.erasedata.'.$name;
		$available = null;
		$answer = rpcMethodCapability($methods, $available);
		if($answer !== 'unsupported') return($answer);
		if(!is_array($available)) return('unconfirmed');
		foreach($available as $method)
			if(strpos($method, 'system.erasedata.') === 0 || $method === 'd.incarnation')
				return('unsupported');
		$request = new rXMLRPCRequest(new rXMLRPCCommand('system.client_version'));
		$request->important = false;
		if(!$request->success() || $request->fault || $request->responseTypes !== array('string')
			|| $request->responseValues !== array('0.9.8'))
			return('unsupported');
		// Historical stock application boundary only. It cannot provide the
		// native lifetime fence and retains its documented legacy limitation.
		return('legacy');
	}
}

function erasedataNativeCall($operation, $hash, array $intent, $pid = null)
{
	$intent = ErasedataManifestCodec::canonicalNativeIntent($intent);
	if($intent === false) return(false);
	if(!in_array($operation, array('prepare', 'state', 'erase', 'collect_start',
		'collect_begin', 'collect_finish', 'cancel'), true)) return(false);
	$params = array('', $hash, $intent['incarnation'], $intent['generation'],
		$intent['user'], $intent['nonce'], $intent['digest']);
	if($pid !== null) $params[] = (string)$pid;
	$request = new rXMLRPCRequest(new rXMLRPCCommand('system.erasedata.'.$operation, $params));
	$request->important = false;
	if(!$request->success() || $request->fault || !isset($request->responseValues, $request->responseTypes)
		|| count($request->responseValues) !== 1 || count($request->responseTypes) !== 1)
		return(false);
	$value = $request->responseValues[0];
	if($operation === 'state')
	{
		if($request->responseTypes !== array('struct') || !is_array($value) || count($value) !== 2
			|| !isset($value['phase'], $value['live_relation'])
			|| !in_array($value['phase'], array('missing', 'mismatch', 'prepared', 'erase-started', 'committed'), true)
			|| !in_array($value['live_relation'], array('absent', 'exact', 'different'), true))
			return(false);
		return($value);
	}
	return($request->responseTypes === array('string') && is_string($value) ? $value : false);
}

function erasedataNativeIncarnation($hash)
{
	$request = new rXMLRPCRequest(new rXMLRPCCommand('d.incarnation', array($hash)));
	$request->important = false;
	return($request->success() && !$request->fault && $request->responseTypes === array('string')
		&& count($request->responseValues) === 1 && is_string($request->responseValues[0])
		&& preg_match('/^[a-f0-9]{32}$/D', $request->responseValues[0])
		? $request->responseValues[0] : false);
}

function erasedataNativeBuildIntent($hash, array $paths, $force, $generation, $user, $incarnation)
{
	$payload = ErasedataManifestCodec::encode($hash, $paths, $force);
	$nonce = erasedataPrivateToken();
	if(!is_string($payload) || !is_string($nonce)) return(false);
	$intent = array('incarnation' => $incarnation, 'nonce' => $nonce,
		'generation' => $generation, 'user' => $user, 'digest' => hash('sha256', $payload));
	return(ErasedataManifestCodec::encodeBound($hash, $paths, $force, $intent) !== false ? $intent : false);
}

function erasedataNativePrepareIntent($hash, array $intent)
{
	$answer = erasedataNativeCall('prepare', $hash, $intent);
	if($answer === 'prepared') return(true);
	// Reconcile the nonce minted before the call; never replay preparation.
	$state = erasedataNativeCall('state', $hash, $intent);
	return(is_array($state) && $state['phase'] === 'prepared'
		&& $state['live_relation'] === 'exact');
}

function erasedataNativeCancelIntent($hash, array $intent)
{
	$answer = erasedataNativeCall('cancel', $hash, $intent);
	if($answer === 'cancelled') return(true);
	$state = erasedataNativeCall('state', $hash, $intent);
	// Missing grants cancellation of own application artifacts only.
	return(is_array($state) && in_array($state['phase'], array('missing', 'mismatch'), true));
}

function erasedataNativeEraseIntent($hash, array $intent)
{
	$answer = erasedataNativeCall('erase', $hash, $intent);
	if($answer === 'committed') return(true);
	$state = erasedataNativeCall('state', $hash, $intent);
	return(is_array($state) && $state['phase'] === 'committed'
		&& $state['live_relation'] === 'absent');
}

// Caller holds the hash lock. A missing native E can never recreate this
// generation; removing its exact application record closes the replay path.
function erasedataNativeRetireJournal($listPath, $hash, array $intent, array $completed)
{
	$lock = erasedataAcquireDrainStateLock($listPath);
	if(!is_resource($lock)) return(false);
	try
	{
		$state = erasedataReadDrainState($listPath);
		if(!is_array($state)) return(false);
		$statePath = erasedataDrainStatePath($listPath);
		$absent = !erasedataPathExists($statePath) && erasedataPathLookupAnswers($statePath);
		if(!$absent && $state['user'] !== $intent['user']) return(false);
		$generation = $intent['generation'];
		if(isset($state['journal'][$generation]))
		{
			$entry = $state['journal'][$generation];
			if(isset($entry['staging'][$hash]))
			{
				$binding = $entry['staging'][$hash];
				if($binding['path'] !== substr($completed['path'], 0, -15).'.tmp'
					|| $binding['dev'] !== (string)$completed['stat']['dev']
					|| $binding['ino'] !== (string)$completed['stat']['ino']) return(false);
			}

			unset($entry['staging'][$hash]);
			$entry['hashes'] = array_values(array_diff($entry['hashes'], array($hash)));
			if(count($entry['hashes'])) $state['journal'][$generation] = $entry;
			else unset($state['journal'][$generation]);
			if(!erasedataWriteDrainState($listPath, $state)) return(false);
		}
		return(erasedataDischargePendingMarker($listPath, $hash, $generation));
	}
	finally { erasedataReleaseDrainStateLock($lock); }
}

function erasedataNativeReadBoundStaging(array $record, $hash)
{
	foreach(array($record['path'], substr($record['path'], 0, -4).'.list') as $path)
	{
		clearstatcache(true, $path);
		if(!erasedataNativeManifestStatSafe(@lstat($path))) continue;
		$handle = @fopen($path, 'rb');
		if(!is_resource($handle)) continue;
		try
		{
			$stat = @fstat($handle);
			$current = @lstat($path);
			if(is_link($path) || !erasedataNativeManifestStatSafe($stat) || !is_array($current)
				|| (string)$stat['dev'] !== $record['dev'] || (string)$stat['ino'] !== $record['ino']
				|| !erasedataSameStatIdentity($stat, $current)) continue;
			$bytes = ErasedataManifestCodec::readBoundedHandle($handle);
			$m = is_string($bytes) ? ErasedataManifestCodec::decodeBytes($bytes, $hash) : false;
			if(is_array($m) && $m['version'] === ErasedataManifestCodec::BOUND_PAYLOAD_VERSION)
				return(array('path' => $path, 'stat' => $stat, 'manifest' => $m));
		}
		finally { @fclose($handle); }
	}
	return(false);
}

// Recovery consumes the producer's exact V4 binding. It never samples current
// paths, prepares another intent, or treats generic torrent absence as commit.
function erasedataNativeDrainGenerationPass($listPath, $user, $generation, array $job,
	ErasedataFilesystemOps $filesystem, array &$notes)
{
	$members = count($job['hashes']);
	$result = array('published' => 0, 'retained' => $members, 'cancelled' => 0, 'unrecoverable' => 0);
	if(erasedataNativeCapability() !== 'available')
	{
		$notes[] = array('native-authority-unavailable', $generation, $members,
			'obligations-retained-nothing-erased', null);
		return($result);
	}
	$locks = erasedataLockObligations($listPath, $job['hashes']);
	if(!is_array($locks)) return($result);
	$stateLock = erasedataAcquireDrainStateLock($listPath);
	try
	{
		if(!is_resource($stateLock)) return($result);
		$state = erasedataReadDrainState($listPath);
		if(!is_array($state) || $state['user'] !== $user) return($result);
		$entry = isset($state['journal'][$generation]) ? $state['journal'][$generation] : null;
		$drop = array();
		foreach($job['hashes'] as $hash)
		{
			$record = is_array($entry) && isset($entry['staging'][$hash]) ? $entry['staging'][$hash] : null;
			$bound = is_array($record) ? erasedataNativeReadBoundStaging($record, $hash) : false;
			if(!is_array($bound) || $bound['manifest']['native_intent']['generation'] !== $generation
				|| $bound['manifest']['native_intent']['user'] !== $user
				|| $bound['manifest']['force'] !== $job['force'])
			{
				$notes[] = array('native-binding-unavailable', $generation, $members,
					'exact-manifest-and-payload-retained-manual-inspection-required', $hash);
				continue;
			}
			$intent = $bound['manifest']['native_intent'];
			$native = erasedataNativeCall('state', $hash, $intent);
			if(!is_array($native))
			{
				$notes[] = array('rpc-unknown', $generation, $members, 'obligation-retained-nothing-erased', $hash);
				continue;
			}
			if($entry['phase'] === 'prepared')
			{
				if(!erasedataNativeCancelIntent($hash, $intent)
					|| !erasedataCancelPreparedStaging($record, $filesystem)
					|| !erasedataDischargePendingMarker($listPath, $hash, $generation)) continue;
				$drop[] = $hash;
				$result['cancelled']++;
				$result['retained']--;
				continue;
			}
			if(in_array($native['phase'], array('missing', 'mismatch'), true)
				|| $native['live_relation'] === 'different')
			{
				// No public path recovery is permitted after re-add revocation.
				$notes[] = array('native-authority-revoked', $generation, $members,
					'manifest-and-captured-payload-retained-manual-inspection-required', $hash, basename($bound['path']));
				continue;
			}
			$committed = $native['phase'] === 'committed' && $native['live_relation'] === 'absent';
			if(!$committed && in_array($native['phase'], array('prepared', 'erase-started'), true))
				$committed = erasedataNativeEraseIntent($hash, $intent);
			if(!$committed) continue;
			if(substr($bound['path'], -4) === '.tmp'
				&& !ErasedataManifestCodec::publishStaging($bound['path'], $hash, $filesystem, false)) continue;
			if(!erasedataDischargePendingMarker($listPath, $hash, $generation)) continue;
			$result['published']++;
			$result['retained']--;
		}
		if(is_array($entry))
		{
			foreach($drop as $hash) unset($entry['staging'][$hash]);
			$entry['hashes'] = array_values(array_diff($entry['hashes'], $drop));
			if(!count($entry['hashes'])) unset($state['journal'][$generation]);
			else
			{
				if(!$result['retained'] && !$result['cancelled']) $entry['phase'] = 'published';
				$state['journal'][$generation] = $entry;
			}
			if(!erasedataWriteDrainState($listPath, $state))
				$notes[] = array('journal-write', $generation, $members, 'native-recovery-bookkeeping-retained', null);
		}
		return($result);
	}
	finally { erasedataReleaseAdmissionLocks($stateLock, $locks); }
}

function erasedataNativeCompletedCandidates($listPath, $onlyHash = null, $intent = null)
{
	$entries = @scandir($listPath);
	$result = array();
	if(!is_array($entries)) return($result);
	foreach($entries as $name)
	{
		if(!preg_match('/^([A-F0-9]{40})\.([a-f0-9]{16})\.[A-Za-z0-9._-]+\.list\.completed$/D', $name, $m)
			|| ($onlyHash !== null && $onlyHash !== $m[1])) continue;
		$path = $listPath.'/'.$name;
		clearstatcache(true, $path);
		if(!erasedataNativeManifestStatSafe(@lstat($path)))
		{ erasedataNativeTerminalDiagnostic($path, 'unsafe-metadata'); continue; }
		$handle = @fopen($path, 'rb');
		if(!is_resource($handle)) { erasedataNativeTerminalDiagnostic($path, 'unreadable-manifest'); continue; }
		try
		{
			$stat = @fstat($handle); $current = @lstat($path);
			if(is_link($path) || !erasedataNativeManifestStatSafe($stat) || !is_array($current)
				|| !erasedataSameStatIdentity($stat, $current))
			{ erasedataNativeTerminalDiagnostic($path, 'unsafe-metadata'); continue; }
			$bytes = ErasedataManifestCodec::readBoundedHandle($handle);
			$manifest = is_string($bytes) ? ErasedataManifestCodec::decodeBytes($bytes, $m[1]) : false;
			if(!is_array($manifest) || $manifest['version'] !== ErasedataManifestCodec::BOUND_PAYLOAD_VERSION
				|| $manifest['native_intent']['generation'] !== $m[2]
				|| ($intent !== null && $intent !== $manifest['native_intent']))
			{ erasedataNativeTerminalDiagnostic($path, 'native-binding-mismatch'); continue; }
			$result[] = array('path' => $path, 'stat' => $stat, 'manifest' => $manifest);
		}
		finally { @fclose($handle); }
	}
	return($result);
}

function erasedataNativeRemoveCompleted(array $completed, ErasedataFilesystemOps $filesystem)
{
	// Metadata-only unlink. Never resume a payload or follow a manifest path.
	return(erasedataSameStatIdentity($completed['stat'], @lstat($completed['path']))
		&& erasedataUnlinkExactStagedFile($completed['path'], $completed['stat'], null, $filesystem));
}

function erasedataNativeManifestStatSafe($stat)
{
	return(is_array($stat) && ($stat['mode'] & 0170000) === 0100000
		&& $stat['uid'] === erasedataEffectiveUid() && $stat['nlink'] === 1
		&& ($stat['mode'] & 07777) === erasedataSharedFileMode());
}

function erasedataNativeSyncQueue($listPath)
{
	// PHP 7.4 retains the established process-crash recovery boundary.
	// Native revocation remains durable; no power-loss guarantee is added.
	if(!function_exists('fsync')) return(true);
	$handle = @fopen($listPath, 'r');
	if(!is_resource($handle)) return(false);
	try
	{
		$stat = @fstat($handle); $current = @lstat($listPath);
		return(!is_link($listPath) && is_array($stat) && is_array($current)
			&& ($stat['mode'] & 0170000) === 0040000
			&& $stat['uid'] === erasedataEffectiveUid()
			&& erasedataSameStatIdentity($stat, $current) && @fsync($handle));
	}
	finally { @fclose($handle); }
}

function erasedataNativePreparedBindingsMatch($state, $listPath, $user, $generation,
	array $hashes, $force, array $intents)
{
	if(!erasedataPreparedBindingStillMatches($state, $listPath, $user, $generation, $hashes, $force))
		return(false);
	foreach($hashes as $hash)
	{
		$record = $state['journal'][$generation]['staging'][$hash];
		$bound = erasedataNativeReadBoundStaging($record, $hash);
		if(!is_array($bound) || $bound['path'] !== $record['path']
			|| !isset($intents[$hash]) || $bound['manifest']['native_intent'] !== $intents[$hash]) return(false);
	}
	return(true);
}

function erasedataNativeTerminalDiagnostic($path, $reason)
{
	static $reported = array();
	$key = $path.'|'.$reason;
	if(isset($reported[$key])) return;
	$reported[$key] = true;
	FileUtil::toLog('erasedata: native manifest retained document='.erasedataLogPath($path)
		.' key=native_intent reason='.$reason.'; payload effects refused and native finish withheld; inspect exact document');
}

function erasedataNativeFinishIntent($hash, array $intent, $pid)
{
	if(erasedataNativeCall('collect_finish', $hash, $intent, $pid) === 'finished') return(true);
	$state = erasedataNativeCall('state', $hash, $intent);
	return(is_array($state) && $state['phase'] === 'missing');
}

function erasedataNativeLegacyStagingCandidates($listPath, $onlyHash = null)
{
	$result = array(); $entries = @scandir($listPath);
	if(!is_array($entries)) return($result);
	foreach($entries as $name)
	{
		if(!preg_match('/^([A-F0-9]{40})\.[a-f0-9]{16}\.[A-Za-z0-9._-]+\.tmp$/D', $name, $m)
			|| ($onlyHash !== null && $onlyHash !== $m[1])) continue;
		$bytes = ErasedataManifestCodec::readBoundedFile($listPath.'/'.$name);
		$manifest = is_string($bytes) ? ErasedataManifestCodec::decodeBytes($bytes, $m[1]) : false;
		if(is_array($manifest) && $manifest['version'] === ErasedataManifestCodec::BOUND_PAYLOAD_VERSION)
			$result[] = array('hash'=>$m[1], 'path'=>$listPath.'/'.$name);
	}
	return($result);
}

// Called with every generation hash lock held. The state lock is released
// before presence RPCs; the hash locks remain held through legacy recovery.
function erasedataNativeLegacyDrainGuard($listPath, $generation, array $job, array &$notes)
{
	$lock = erasedataAcquireDrainStateLock($listPath);
	if(!is_resource($lock))
	{
		$notes[] = array('state-lock',$generation,count($job['hashes']),'obligations-retained',null);
		return(true);
	}
	try
	{
		$state = erasedataReadDrainState($listPath);
		if(!is_array($state))
		{
			$notes[] = array('state-unreadable',$generation,count($job['hashes']),'obligations-retained',null);
			return(true);
		}
		$entry = isset($state['journal'][$generation]) ? $state['journal'][$generation] : null;
		$paths = array();
		if(is_array($entry))
			foreach($entry['staging'] as $hash => $record)
				$paths[$hash] = array($record['path'], substr($record['path'],0,-4).'.list',
					substr($record['path'],0,-4).'.list.completed');
		foreach($job['hashes'] as $hash)
			foreach(erasedataNativeCompletedCandidates($listPath,$hash) as $completed)
				if($completed['manifest']['native_intent']['generation'] === $generation)
					$paths[$hash][] = $completed['path'];
		foreach($paths as $hash => $candidates)
			foreach($candidates as $path)
			{
				$bytes = ErasedataManifestCodec::readBoundedFile($path);
				$manifest = is_string($bytes) ? ErasedataManifestCodec::decodeBytes($bytes,$hash) : false;
				if(is_array($manifest) && $manifest['version'] === ErasedataManifestCodec::BOUND_PAYLOAD_VERSION)
				{
					$notes[] = array('native-authority-unavailable',$generation,count($job['hashes']),
						'native-bound-manifest-retained-nothing-erased',$hash,basename($path));
					return(true);
				}
			}
		return(false);
	}
	finally { erasedataReleaseDrainStateLock($lock); }
}
