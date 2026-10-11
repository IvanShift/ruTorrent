<?php

// The native owner grants deletion authority. These helpers only validate exact
// replies and never turn a missing hash, transport failure or unknown method
// into an accepted incarnation or a successful erase.
define('ERASEDATA_NATIVE_RETRY_MAX_HINTS', 1024);
define('ERASEDATA_NATIVE_RETRY_ATTEMPTS_PER_PASS', 8);

function erasedataNativeRetryValid($retry)
{
	if(!is_array($retry) || count($retry)!==3 || !isset($retry['jobs'],$retry['cursor'],$retry['overflow'])
		|| !is_array($retry['jobs']) || count($retry['jobs'])>ERASEDATA_NATIVE_RETRY_MAX_HINTS
		|| !is_string($retry['cursor']) || ($retry['cursor']!=='' && !erasedataIsCanonicalPendingHash($retry['cursor']))
		|| !is_bool($retry['overflow'])) return(false);
	foreach($retry['jobs'] as $key=>$entry)
		if(!is_string($key) || preg_match('/^[a-f0-9]{64}$/D',$key)!==1
			|| !is_array($entry) || array_keys($entry)!==array(0,1,2,3)
			|| !is_int($entry[0]) || $entry[0]<0 || !is_int($entry[1]) || $entry[1]<1 || $entry[1]>60
			|| !is_int($entry[2]) || $entry[2]<1 || $entry[2]>16
			|| !is_string($entry[3]) || preg_match('/^[a-f0-9]{64}$/D',$entry[3])!==1) return(false);
	return(true);
}

function erasedataNativeRetryKey($hash, $path, array $stat, $bytes, ?array $intent = null)
{
	if(!erasedataIsCanonicalPendingHash($hash) || !is_string($bytes)) return(false);
	if($intent!==null && ($intent=ErasedataManifestCodec::canonicalNativeIntent($intent))===false) return(false);
	$identity=array($hash, preg_replace('/\.completed$/D','',$path), $intent, hash('sha256',$bytes));
	foreach(array('dev','ino','mode','uid','nlink','size') as $field)
	{
		if(!isset($stat[$field])) return(false);
		$identity[]=(string)$stat[$field];
	}
	return(hash('sha256',serialize($identity)));
}

// The keys of this exact document's hints: its bytes or the unreadable
// spelling, its own bound intent or none, and for a list also the staging name
// it was published from. Forgetting a superset only brings a retry sooner; a
// hint never grants authority.
function erasedataNativeRetryDocumentKeys($hash, $path, array $stat, $bytes, ?array $intent)
{
	$names=array($path);
	$list=preg_replace('/\.completed$/D','',$path);
	if(substr($list,-5)==='.list') $names[]=substr($list,0,-5).'.tmp';
	$keys=array();
	foreach($names as $name)
		foreach(array_unique(array(is_string($bytes) ? $bytes : '','')) as $variant)
			foreach(array($intent,null) as $bound)
			{
				$key=erasedataNativeRetryKey($hash,$name,$stat,$variant,$bound);
				if(is_string($key)) $keys[$key]=true;
			}
	return($keys);
}

// Edits the caller's locked snapshot only; the caller persists it.
function erasedataNativeRetryForgetDocument(array &$state, $hash, $path, array $stat, $bytes, ?array $intent)
{
	if(!isset($state['native_retry'])) return(false);
	$jobs=array_diff_key($state['native_retry']['jobs'],
		erasedataNativeRetryDocumentKeys($hash,$path,$stat,$bytes,$intent));
	if(count($jobs)===count($state['native_retry']['jobs'])) return(false);
	$state['native_retry']['jobs']=$jobs;
	return(true);
}

// The keys every document now in the queue can reproduce, or false when a
// listed entry cannot be inspected.
function erasedataNativeRetryLiveKeys($listPath)
{
	$names=@scandir($listPath);
	if(!is_array($names)) return(false);
	$live=array();
	foreach($names as $name)
	{
		if(!preg_match('/^([A-F0-9]{40})\./',$name,$m)) continue;
		$stat=erasedataListedEntryStat($listPath,$name);
		if($stat===null) continue;
		if(!is_array($stat)) return(false);
		$path=$listPath.'/'.$name;
		$bytes=ErasedataManifestCodec::readBoundedFile($path);
		$manifest=is_string($bytes) ? ErasedataManifestCodec::decodeBytes($bytes,$m[1]) : false;
		$live+=erasedataNativeRetryDocumentKeys($m[1],$path,$stat,$bytes,
			is_array($manifest) && isset($manifest['native_intent']) ? $manifest['native_intent'] : null);
	}
	return($live);
}

// Every recorder files its hint under a document in this queue. A hint that no
// current document reproduces is dropped: its job has left the queue by some
// route, or it was filed under a spelling no pass consults again. Dropping
// advice only brings a retry and its log line sooner. The queue is read before
// the state lock; a hint filed or refreshed since then is kept. Cursor,
// overflow and every authority key stay as they are, and an unreadable
// listing, absent state or garbled native_retry changes nothing.
function erasedataNativeRetryPruneOrphans($listPath, $user, $now = null)
{
	if($now===null) $now=time();
	$live=erasedataNativeRetryLiveKeys($listPath);
	if(!is_array($live)) return(false);
	$lock=erasedataAcquireDrainStateLock($listPath);
	if(!is_resource($lock)) return(false);
	try
	{
		$state=erasedataNativeRetryWritableState($listPath,$user);
		if(!is_array($state)) return(false);
		if(!isset($state['native_retry'])) return(true);
		$jobs=array();
		foreach($state['native_retry']['jobs'] as $key=>$entry)
			if(isset($live[$key]) || $entry[0]>=$now) $jobs[$key]=$entry;
		if(count($jobs)===count($state['native_retry']['jobs'])) return(true);
		$state['native_retry']['jobs']=$jobs;
		return(erasedataWriteDrainState($listPath,$state));
	}
	finally {erasedataReleaseDrainStateLock($lock);}
}

function erasedataNativeRetrySnapshot($listPath, $user)
{
	$state=erasedataReadDrainState($listPath);
	return(is_array($state) && $state['user']===$user && isset($state['native_retry'])
		? $state['native_retry'] : array('jobs'=>array(),'cursor'=>'','overflow'=>false));
}

function erasedataNativeRetryDue(array $retry, $key, $now)
{
	if(!is_string($key) || !isset($retry['jobs'][$key])) return(true);
	$entry=$retry['jobs'][$key];
	// A backward clock or an old hint never creates an indefinite hold.
	return($now<$entry[0] || $now-$entry[0]>60 || $now-$entry[0]>=$entry[1]);
}

function erasedataNativeRetryOrder(array $hashes, $cursor, $now)
{
	global $garbageCheckInterval;
	sort($hashes,SORT_STRING);$count=count($hashes);
	if(!$count) return($hashes);
	$position=array_search($cursor,$hashes,true);
	// Both schedules use this collector. With no writable cursor, rotate one
	// budget window per configured ordinary slot; the faster drain may revisit
	// a window but cannot skip it because of interval/count common factors.
	$interval=max(ERASEDATA_DRAIN_INTERVAL,isset($garbageCheckInterval) ? (int)$garbageCheckInterval : 0);
	$start=$position===false
		? (int)((floor($now/$interval)*ERASEDATA_NATIVE_RETRY_ATTEMPTS_PER_PASS)%$count) : ($position+1)%$count;
	return(array_merge(array_slice($hashes,$start),array_slice($hashes,0,$start)));
}

function erasedataNativeRetryMetadataRefused($listPath)
{
	static $reported=array();
	if(isset($reported[$listPath])) return;
	$reported[$listPath]=true;
	FileUtil::toLog('erasedata: retry metadata refused document='.erasedataLogPath(erasedataDrainStatePath($listPath))
		.' key=native_retry reason=state-unavailable; authority unchanged; bounded fair retries continue without suppression');
}

// Caller owns the state lock. Trimming removes advisory hints only, never an
// obligation. The existing authority byte ceiling remains unchanged.
function erasedataNativeRetryStore($listPath, array $state, array $retry)
{
	$overflow=false;
	while(count($retry['jobs'])>ERASEDATA_NATIVE_RETRY_MAX_HINTS)
	{
		unset($retry['jobs'][array_key_first($retry['jobs'])]);$overflow=true;
	}
	while(true)
	{
		$state['native_retry']=$retry;$bytes=json_encode($state);
		if(is_string($bytes) && strlen($bytes)<=ERASEDATA_DRAIN_STATE_MAX_BYTES) break;
		if(!count($retry['jobs'])) return(false);
		unset($retry['jobs'][array_key_first($retry['jobs'])]);$overflow=true;
	}
	if($overflow && !$retry['overflow'])
	{
		// Emit before persistence: a crash may repeat this notice, never hide it.
		FileUtil::toLog('erasedata: retry hints bounded document='.erasedataLogPath(erasedataDrainStatePath($listPath))
			.' key=native_retry reason=hint-capacity; only hints evicted; bounded fair retries continue; obligations retained');
		$retry['overflow']=true;$state['native_retry']=$retry;
	}
	return(erasedataWriteDrainState($listPath,$state));
}

function erasedataNativeRetryWritableState($listPath, $user)
{
	$path=erasedataDrainStatePath($listPath);clearstatcache(true,$path);
	if(!erasedataNativeManifestStatSafe(@lstat($path))) return(false);
	$state=erasedataReadDrainState($listPath);
	return(is_array($state) && $state['user']===$user ? $state : false);
}

// Caller holds the hash lock. Native worker lifetime remains separately fenced
// by FD198; these records can delay a launch but grant no filesystem authority.
function erasedataNativeRetryRecordLocked($listPath, $user, $key, $path, $reason, $now = null)
{
	if($now===null) $now=time();
	if(!is_string($key) || preg_match('/^[a-f0-9]{64}$/D',$key)!==1
		|| !is_string($reason) || $reason==='' || !is_int($now) || $now<0) return(false);
	$lock=erasedataAcquireDrainStateLock($listPath);
	if(!is_resource($lock)) {erasedataNativeRetryMetadataRefused($listPath);return(false);}
	try
	{
		$state=erasedataNativeRetryWritableState($listPath,$user);
		if(!is_array($state))
		{
			FileUtil::toLog('erasedata: native manifest retained document='.erasedataLogPath($path)
				.' key=native_intent reason='.substr($reason,0,512).'; exact record retained for retry');
			erasedataNativeRetryMetadataRefused($listPath);return(false);
		}
		$retry=isset($state['native_retry']) ? $state['native_retry'] : array('jobs'=>array(),'cursor'=>'','overflow'=>false);
		$old=isset($retry['jobs'][$key]) ? $retry['jobs'][$key] : null;
		$digest=hash('sha256',$reason);
		$same=is_array($old) && $old[3]===$digest && $now>=$old[0];
		$attempts=$same ? min(16,$old[2]+1) : 1;
		if(!$same)
			FileUtil::toLog('erasedata: native manifest retained document='.erasedataLogPath($path)
				.' key=native_intent reason='.substr($reason,0,512).'; exact record retained for retry');
		unset($retry['jobs'][$key]);
		$retry['jobs'][$key]=array($now,min(60,5*(1<<min(4,$attempts-1))),$attempts,$digest);
		if(erasedataNativeRetryStore($listPath,$state,$retry)) return(true);
		erasedataNativeRetryMetadataRefused($listPath);return(false);
	}
	finally {erasedataReleaseDrainStateLock($lock);}
}

function erasedataNativeRetryCursorWrite($listPath, $user, $cursor)
{
	if(!erasedataIsCanonicalPendingHash($cursor)) return(false);
	$lock=erasedataAcquireDrainStateLock($listPath);
	if(!is_resource($lock)) {erasedataNativeRetryMetadataRefused($listPath);return(false);}
	try
	{
		$state=erasedataNativeRetryWritableState($listPath,$user);
		if(!is_array($state)) {erasedataNativeRetryMetadataRefused($listPath);return(false);}
		$retry=isset($state['native_retry']) ? $state['native_retry'] : array('jobs'=>array(),'cursor'=>'','overflow'=>false);
		$retry['cursor']=$cursor;
		if(erasedataNativeRetryStore($listPath,$state,$retry)) return(true);
		erasedataNativeRetryMetadataRefused($listPath);return(false);
	}
	finally {erasedataReleaseDrainStateLock($lock);}
}

function erasedataNativeRetryForgetCompleted(array $completed, $user, $hashLocked = false, $bytes = null)
{
	$path=$completed['path'];$listPath=dirname($path);$hash=$completed['manifest']['hash'];
	if(!is_string($bytes)) $bytes=ErasedataManifestCodec::readBoundedFile($path);
	$hashLock=$hashLocked ? null : erasedataAcquireHashLock($listPath,$hash);
	if(!$hashLocked && $hashLock===false) return(false);
	$lock=erasedataAcquireDrainStateLock($listPath);
	try
	{
		if(!is_resource($lock)) return(false);
		$state=erasedataNativeRetryWritableState($listPath,$user);
		if(!is_array($state)) return(false);
		// Retire every hint this job's record was filed under, not only the
		// spelling this caller computes.
		if(!erasedataNativeRetryForgetDocument($state,$hash,$path,$completed['stat'],$bytes,
			$completed['manifest']['native_intent'])) return(true);
		return(erasedataWriteDrainState($listPath,$state));
	}
	finally {erasedataReleaseDrainStateLock($lock);if(!$hashLocked)erasedataReleaseHashLock($hashLock);}
}

function erasedataNativeRetryRecordCompleted(array $completed, $reason)
{
	$path=$completed['path'];$hash=$completed['manifest']['hash'];$intent=$completed['manifest']['native_intent'];
	$key=erasedataNativeRetryKey($hash,$path,$completed['stat'],ErasedataManifestCodec::readBoundedFile($path),$intent);
	$lock=erasedataAcquireHashLock(dirname($path),$hash);
	if($lock===false) return(false);
	try
	{
		return(erasedataNativeRetryRecordLocked(dirname($path),$intent['user'],$key,$path,$reason));
	}
	finally {erasedataReleaseHashLock($lock);}
}

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

function erasedataNativePrepareIntent($hash, array $intent, &$reason = null)
{
	$reason = 'unresolved';
	$answer = erasedataNativeCall('prepare', $hash, $intent);
	if($answer === 'prepared') { $reason = null; return(true); }
	foreach(array('independent-file-operation-owner', 'partial-erase') as $known)
		if($answer === 'refused:'.$known)
		{
			$reason = $known;
			return(false);
		}
	// Reconcile the nonce minted before the call; never replay preparation.
	$state = erasedataNativeCall('state', $hash, $intent);
	if(is_array($state) && $state['phase'] === 'prepared' && $state['live_relation'] === 'exact')
	{
		$reason = null;
		return(true);
	}
	return(false);
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

// Both completion and revocation retire the same journal binding. This helper
// only edits the caller's locked snapshot; the caller persists before unlink.
function erasedataNativeDropJournalMember(array &$state, $hash, array $intent, $path, array $stat, &$changed)
{
	$generation=$intent['generation'];$changed=false;
	if(!isset($state['journal'][$generation])) return(true);
	$entry=$state['journal'][$generation];
	if(!in_array($hash,$entry['hashes'],true)) return(true);
	$expected=substr($path,-4)==='.tmp' ? $path : substr($path,0,-5).'.tmp';
	if(!isset($entry['staging'][$hash]) || $entry['staging'][$hash]['path']!==$expected
		|| $entry['staging'][$hash]['dev']!==(string)$stat['dev']
		|| $entry['staging'][$hash]['ino']!==(string)$stat['ino']) return(false);
	unset($entry['staging'][$hash]);$entry['hashes']=array_values(array_diff($entry['hashes'],array($hash)));
	if(count($entry['hashes'])) $state['journal'][$generation]=$entry;
	else unset($state['journal'][$generation]);
	$changed=true;return(true);
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
		$generation = $intent['generation'];$changed=false;
		if(!erasedataNativeDropJournalMember($state,$hash,$intent,substr($completed['path'],0,-10),$completed['stat'],$changed)
			|| ($changed && !erasedataWriteDrainState($listPath,$state))) return(false);
		return(erasedataDischargePendingMarker($listPath, $hash, $generation));
	}
	finally { erasedataReleaseDrainStateLock($lock); }
}

// Revocation permits retiring exact application metadata only. Inspect every
// existing capture namespace read-only; an uncertain or partial recovery stays
// visible and cannot be promoted to deletion authority by this cleanup.
function erasedataNativeRevokedRecoveryAbsent($path, array $manifest, ErasedataFilesystemOps $filesystem)
{
	$keys=array($path);
	if(substr($path,-4)==='.tmp') $keys[]=substr($path,0,-4).'.list';
	$prefixes=array();
	foreach($keys as $key)
	{
		$intent=dirname($key).'/.erasedata-rmdir-intent-'.hash('sha256',$key);
		if(erasedataPathExists($intent) || !erasedataPathLookupAnswers($intent)) return(false);
		$prefixes[dirname($key)][]=basename(erasedataCapturedEntryPrefix($key,'manifest-consumption'));
		$paths=array($manifest['base']);
		foreach($manifest['files'] as $file)
		{
			$paths[]=$file;
			if($manifest['multi'])
				for($dir=dirname($file);$dir!==$manifest['base'] && erasedataPathContains($manifest['base'],$dir);$dir=dirname($dir))
					$paths[]=$dir;
		}
		foreach(array_unique($paths) as $public)
		{
			$prefixes[dirname($public)][]=basename(erasedataCapturedEntryPrefix($public,$key));
			$prefixes[dirname($public)][]=basename(erasedataDirectoryReservationPrefix($public,$key));
		}
	}
	foreach($prefixes as $parent=>$wanted)
	{
		$unsealed=false;$ancestor=null;
		if(!erasedataGuardParentChain($parent.'/entry',erasedataEffectiveUid(),false,$unsealed,false,$ancestor,false)
			|| $unsealed) return(false);
		$before=$filesystem->entryIdentity($parent);
		if(!is_array($before) || !$before['is_dir'] || $before['is_link']) return(false);
		$entries=$filesystem->scanDirectory($parent);
		if(!is_array($entries)) return(false);
		foreach($entries as $name)
		{
			if($name==='.' || $name==='..') continue;
			foreach($wanted as $prefix) if(strpos($name,$prefix)===0) return(false);
			$stat=erasedataListedEntryStat($parent,$name);
			if($stat===false) return(false);
			if($stat===null || ($stat['mode'] & 0170000)!==0120000) continue;
			$target=@readlink($parent.'/'.$name);
			if(!is_string($target)) return(false);
			foreach($wanted as $prefix) if(strpos($target,$prefix)!==false) return(false);
		}
		$after=$filesystem->entryIdentity($parent);
		if(!is_array($after) || !$after['is_dir'] || $after['is_link']
			|| !erasedataSameEntryIdentity($before,$after)
			|| !erasedataGuardParentChain($parent.'/entry',erasedataEffectiveUid(),false,$unsealed,false,$ancestor,false)
			|| $unsealed) return(false);
	}
	return(true);
}

// Hash lock is held. Drain may additionally supply its already locked state.
// The record remains discoverable until journal/pending retirement is durable;
// a crash can retry metadata cleanup but can never replay payload consumption.
function erasedataNativeCancelRevoked($listPath, $hash, array $bound,
	ErasedataFilesystemOps $filesystem, &$state = null)
{
	$path=$bound['path'];$manifest=$bound['manifest'];$stat=$bound['stat'];
	$intent=$manifest['native_intent'];
	$bytes=ErasedataManifestCodec::readBoundedFile($path);
	$matches=function() use($path,$hash,$manifest,$stat,$bytes) {
		clearstatcache(true,$path);$current=@lstat($path);
		return(is_string($bytes) && erasedataNativeManifestStatSafe($current)
			&& erasedataSameStatIdentity($stat,$current)
			&& ErasedataManifestCodec::readBoundedFile($path)===$bytes
			&& ErasedataManifestCodec::decodeBytes($bytes,$hash)===$manifest);
	};
	$check=function() use($path,$hash,$intent,$manifest,$filesystem,$matches) {
		if(!$matches()) return(false);
		$native=erasedataNativeCall('state',$hash,$intent);
		return(is_array($native) && (in_array($native['phase'],array('missing','mismatch'),true)
			|| $native['live_relation']==='different')
			&& erasedataNativeRevokedRecoveryAbsent($path,$manifest,$filesystem) && $matches());
	};
	if(!$check()) return(false);
	$ownsLock=$state===null;$lock=$ownsLock ? erasedataAcquireDrainStateLock($listPath) : null;
	if($ownsLock && !is_resource($lock)) return(false);
	try
	{
		if($ownsLock) $state=erasedataReadDrainState($listPath);
		if(!is_array($state)) return(false);
		$statePath=erasedataDrainStatePath($listPath);
		$absent=!erasedataPathExists($statePath) && erasedataPathLookupAnswers($statePath);
		if(!$absent && $state['user']!==$intent['user']) return(false);
		$generation=$intent['generation'];$changed=false;$next=$state;
		if(!erasedataNativeDropJournalMember($next,$hash,$intent,$path,$stat,$changed)) return(false);
		if(erasedataNativeRetryForgetDocument($next,$hash,$path,$stat,$bytes,$intent)) $changed=true;
		if(!$check() || ($changed && !erasedataWriteDrainState($listPath,$next))) return(false);
		$state=$next;
		if(!erasedataDischargePendingMarker($listPath,$hash,$generation)) return(false);
		return(erasedataUnlinkExactStagedFile($path,$stat,$check,$filesystem));
	}
	finally {if($ownsLock) erasedataReleaseDrainStateLock($lock);}
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
				if(erasedataNativeCancelRevoked($listPath,$hash,$bound,$filesystem,$state))
				{
					$entry=isset($state['journal'][$generation]) ? $state['journal'][$generation] : null;
					$result['cancelled']++;$result['retained']--;
				}
				else $notes[] = array('native-authority-revoked', $generation, $members,
					'exact-manifest-and-recovery-retained-manual-inspection-required', $hash, basename($bound['path']));
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

// A completed record is published by a rename, by this collector a moment ago
// or by another worker. On drvfs over 9p (cache=0x5, the owner's Docker Desktop
// volumes) lstat of such a new name answered ENOENT once while the listing
// already held it, and erasedataListedEntryStat() re-reads only three times
// without a pause. A name the listing still holds is asked again through the
// shared bounded settle. null: positively absent; false: still listed and
// unreadable, which the caller reports as unreadable, never as a wrong type.
function erasedataNativeCompletedEntryStat($listPath, $name, ErasedataFilesystemOps $filesystem)
{
	$stat=erasedataListedEntryStat($listPath,$name);
	if($stat!==false) return($stat);
	$identity=$filesystem->renamedEntryIdentity($listPath.'/'.$name);
	if(is_array($identity)) return($identity['lstat']);
	$names=@scandir($listPath);
	return(is_array($names) && !in_array($name,$names,true) ? null : false);
}

function erasedataNativeCompletedCandidates($listPath, $onlyHash = null, $intent = null, $retention = null,
	?ErasedataFilesystemOps $filesystem = null)
{
	if($filesystem===null) $filesystem=new ErasedataFilesystemOps();
	$entries = @scandir($listPath);
	$result = array();
	if(!is_array($entries)) return($result);
	$report=function($path,$stat,$bytes,$reason) use($retention) {
		if(is_callable($retention)) call_user_func($retention,$path,$stat,$bytes,$reason);
		else erasedataNativeTerminalDiagnostic($path,$reason);
	};
	foreach($entries as $name)
	{
		if(!preg_match('/^([A-F0-9]{40})\.([a-f0-9]{16})\.[A-Za-z0-9._-]+\.list\.completed$/D', $name, $m)
			|| ($onlyHash !== null && $onlyHash !== $m[1])) continue;
		$path = $listPath.'/'.$name;
		$stat=erasedataNativeCompletedEntryStat($listPath,$name,$filesystem);
		if($stat===null) continue;
		$refusal=$stat===false ? 'unreadable-manifest' : erasedataNativeManifestStatRefusal($stat);
		if($refusal!==null)
		{ $report($path,$stat,ErasedataManifestCodec::readBoundedFile($path),$refusal); continue; }
		$handle = @fopen($path, 'rb');
		if(!is_resource($handle)) { $report($path,$stat,false,'unreadable-manifest'); continue; }
		try
		{
			$stat = @fstat($handle); $current = @lstat($path);
			if(is_link($path) || !erasedataNativeManifestStatSafe($stat) || !is_array($current)
				|| !erasedataSameStatIdentity($stat, $current))
			{ $report($path,$stat,false,'unsafe-metadata'); continue; }
			$bytes = ErasedataManifestCodec::readBoundedHandle($handle);
			$manifest = is_string($bytes) ? ErasedataManifestCodec::decodeBytes($bytes, $m[1]) : false;
			if(!is_array($manifest) || $manifest['version'] !== ErasedataManifestCodec::BOUND_PAYLOAD_VERSION
				|| $manifest['native_intent']['generation'] !== $m[2]
				|| ($intent !== null && $intent !== $manifest['native_intent']))
			{ $report($path,$stat,$bytes,'native-binding-mismatch'); continue; }
			$result[] = array('path' => $path, 'stat' => $stat, 'manifest' => $manifest);
		}
		finally { @fclose($handle); }
	}
	return($result);
}

function erasedataNativeRemoveCompleted(array $completed, ErasedataFilesystemOps $filesystem)
{
	// Metadata-only unlink. Never resume a payload or follow a manifest path.
	$check=function() use($completed) {
		clearstatcache(true,$completed['path']);$stat=@lstat($completed['path']);
		$bytes=ErasedataManifestCodec::readBoundedFile($completed['path']);
		return(erasedataNativeManifestStatSafe($stat) && erasedataSameStatIdentity($completed['stat'],$stat)
			&& is_string($bytes) && ErasedataManifestCodec::decodeBytes($bytes,$completed['manifest']['hash'])===$completed['manifest']);
	};
	return($check() && erasedataUnlinkExactStagedFile($completed['path'],$completed['stat'],$check,$filesystem));
}

function erasedataNativeManifestStatSafe($stat)
{
	return(erasedataNativeManifestStatRefusal($stat) === null);
}

function erasedataNativeManifestStatRefusal($stat)
{
	if(!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) return('manifest-type');
	if($stat['uid'] !== erasedataEffectiveUid()) return('manifest-owner');
	if($stat['nlink'] !== 1) return('manifest-links');
	if(($stat['mode'] & 07777) !== erasedataSharedFileMode())
		return('manifest-mode actual='.sprintf('%04o',$stat['mode'] & 07777)
			.' required='.sprintf('%04o',erasedataSharedFileMode()));
	return(null);
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
	// Only this reason is reported by a worker that has just consumed the
	// payload. Every other reason refuses an action before it changes a
	// payload, so its line keeps the refusal wording.
	$consequence = $reason === 'terminal-publication-unresolved'
		? 'payload consumed; terminal record publication unresolved; native finish withheld; retried automatically'
		: 'payload effects refused and native finish withheld; inspect exact document';
	FileUtil::toLog('erasedata: native manifest retained document='.erasedataLogPath($path)
		.' key=native_intent reason='.$reason.'; '.$consequence);
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
