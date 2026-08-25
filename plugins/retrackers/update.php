<?php

function retrackersHasValidPreStopState($arguments)
{
	return is_array($arguments) && count($arguments)>3 && is_string($arguments[3])
		&& ($arguments[3] === '0' || $arguments[3] === '1');
}

if(!defined('RETRACKERS_TEST_MODE') && !retrackersHasValidPreStopState($argv))
	return;

if(!defined('RETRACKERS_TEST_MODE'))
{
	if(count($argv)>2)
		$_SERVER['REMOTE_USER'] = $argv[2];

	require_once( 'retrackers.php' );
	require_once( dirname(__FILE__)."/../../php/xmlrpc.php" );
	require_once( dirname(__FILE__)."/../../php/rtorrent.php" );
}
require_once( dirname(__FILE__).'/guard.php' );

function clearTracker($addition,$tracker)
{
	foreach( $addition as $kg=>$group )
	{
		foreach( $group as $kt=>$trk )
		{
			if($trk==$tracker)
				unset($addition[$kg][$kt]);
		}
		if(!count($addition[$kg]))
			unset($addition[$kg]);
	}
	return($addition);
}

function deleteTrackers(&$lst,$todelete)
{
	$ret = false;
	foreach( $lst as $kg=>$group )
	{
		foreach( $group as $kt=>$trk )
		{
			foreach ( $todelete as $kd )
			{
				if(stristr($trk,$kd))
				{
					unset($lst[$kg][$kt]);
					if(!count($lst[$kg]))
						unset($lst[$kg]);
					$ret = true;
				}
			}
		}
	}
	return($ret);
}

function retrackersLogInitialFailure($hash, $reason)
{
	FileUtil::toLog('retrackers: ' . retrackersSafeHashForLog($hash)
		. ' initial-read-failed reason=' . $reason);
}

function retrackersHashMatchesExpected($actual, $expected)
{
	return is_string($actual) && is_string($expected)
		&& preg_match('/^[0-9A-F]{40}$/D', $expected) === 1
		&& $actual === $expected;
}

function retrackersClassifyPresence($request, $runResult, $hash)
{
	if(!$runResult)
		return('unknown');
	if($request->fault)
		return retrackersIsCompleteMissingHashFault($request) ? 'absent' : 'unknown';
	if(!is_array($request->val) || count($request->val) !== 1 || !is_string($request->val[0]))
		return('unknown');
	return retrackersHashMatchesExpected($request->val[0], $hash) ? 'present' : 'unknown';
}

function retrackersLogReloadFailure($hash, $reason)
{
	FileUtil::toLog('retrackers: ' . retrackersSafeHashForLog($hash) . ' ' . $reason);
}

// Rollback needs decoded Torrent state for validation and sendTorrent metadata,
// but its load payload must remain the exact bytes captured before d.erase.
final class RetrackersRawMetainfoTorrent extends Torrent
{
	private $rawMetainfo;

	public function __construct($metainfo)
	{
		$this->rawMetainfo = $metainfo;
		parent::__construct($metainfo);
	}

	public function __toString()
	{
		return($this->rawMetainfo);
	}
}

function retrackersRestoreStartedInitialState($hash, $snapshot)
{
	if($snapshot !== '1')
		return(true);
	$req = new rXMLRPCRequest( new rXMLRPCCommand("d.start", $hash) );
	$req->important = false;
	$runResult = $req->run();
	if(!$runResult || $req->fault)
	{
		FileUtil::toLog('retrackers: ' . retrackersSafeHashForLog($hash)
			. ' state-recovery-failed reason=' . (!$runResult ? 'transport-failure' : 'rpc-fault'));
		return(false);
	}
	return(true);
}

function retrackersRunWorker($arguments)
{
	if(!retrackersHasValidPreStopState($arguments))
		return(false);
	$hash = count($arguments)>1 ? $arguments[1] : '';
	$preStopState = $arguments[3];
	$processed = false;
	$trks = rRetrackers::load();
	if(count($arguments)<=1)
		return(false);

	$req = new rXMLRPCRequest( array(
		new rXMLRPCCommand("get_session"),
		new rXMLRPCCommand("d.get_tied_to_file",$hash),
		new rXMLRPCCommand("d.get_custom1",$hash),
		new rXMLRPCCommand("d.get_directory_base",$hash),
		new rXMLRPCCommand("d.is_private",$hash),
		new rXMLRPCCommand("d.get_name",$hash),
		new rXMLRPCCommand("d.get_custom",array($hash, RETRACKERS_SERVICE_MARKER)),
		) );
	// This detached worker may wake after another plugin replaced the hash.
	// Keep the expected stale-hash fault local so XMLRPC never dumps raw XML.
	$req->important = false;
	$runResult = $req->run();
	if(!$runResult || $req->fault)
	{
		$confirmedMissing = $runResult && $req->fault && retrackersIsCompleteMissingHashFault($req);
		if(!$confirmedMissing)
		{
			retrackersLogInitialFailure($hash, !$runResult ? 'transport-failure' : 'rpc-fault');
			retrackersRestoreStartedInitialState($hash, $preStopState);
		}
		return(false);
	}
	if(!is_array($req->val) || count($req->val)<7)
	{
		retrackersLogInitialFailure($hash, 'malformed-response');
		retrackersRestoreStartedInitialState($hash, $preStopState);
		return(false);
	}
	if(retrackersIsServiceTorrent($req->val[2], $req->val[6]))
		return(false);

	$isStart = ($preStopState === '1');
	$eraseIssued = false;
	if((count($trks->list) || count($trks->todelete)) && !($req->val[4] && $trks->dontAddPrivate) &&
		($req->val[5]!=$hash.".meta"))
	{
		$fname = $req->val[0].$hash.".torrent";
		if(empty($req->val[0]) || !is_readable($fname))
		{
			if(strlen($req->val[1]) && is_readable($req->val[1]))
				$fname = $req->val[1];
			else
				$fname = null;
		}
		if($fname)
		{
			$metainfo = @file_get_contents($fname);
			if($metainfo === false)
			{
				retrackersLogReloadFailure($hash, 'source-read-failed');
			}
			else
			{
				$origTorrent = new RetrackersRawMetainfoTorrent( $metainfo );
				$torrent = new Torrent( $metainfo );
				if($origTorrent->errors() || $torrent->errors())
				{
					retrackersLogReloadFailure($hash, 'source-decode-failed');
				}
				else if(!retrackersHashMatchesExpected($origTorrent->hash_info(), $hash)
					|| !retrackersHashMatchesExpected($torrent->hash_info(), $hash))
				{
					retrackersLogReloadFailure($hash, 'source-hash-mismatch');
				}
				else
				{
					$wasAddition = true;
					$lst = $torrent->announce_list();
					if(!$lst)
					{
						if(count($trks->list))
						{
							if($torrent->announce())
								$torrent->announce_list($trks->addToBegin ? array_merge($trks->list, array(array($torrent->announce()))) :
									array_merge(array(array($torrent->announce())),$trks->list));
							else
							{
								$torrent->announce($trks->list[0][0]);
								$torrent->announce_list($trks->list);
							}
						}
						else
						{
							$wasAddition = false;
						}
					}
					else
					{
						$addition = $trks->list;
						foreach( $lst as $group )
							foreach( $group as $tracker )
								$addition = clearTracker($addition,$tracker);
						if(count($addition))
						{
							$torrent->announce_list($trks->addToBegin ? array_merge($addition,$lst) : array_merge($lst,$addition));
						}
						else
						{
							$wasAddition = false;
						}
					}

					$wasDeletion = false;
					$lst = $torrent->announce_list();
					if($lst && count($trks->todelete) && deleteTrackers($lst,$trks->todelete))
					{
						$wasDeletion = true;
						$torrent->announce_list($lst);
					}

					if($wasAddition || $wasDeletion)
					{
						if(!retrackersHashMatchesExpected($torrent->hash_info(), $hash))
						{
							retrackersLogReloadFailure($hash, 'candidate-hash-mismatch');
						}
						else
						{
							if(isset($torrent->{'rtorrent'}))
								unset($torrent->{'rtorrent'});
							$eReq = new rXMLRPCRequest( new rXMLRPCCommand("d.erase", $hash ) );
							$eraseIssued = true;
							if($eReq->success())
							{
								$label = rawurldecode($req->val[2]);
								$candidateResult = rTorrent::sendTorrent($torrent, $isStart, false, $req->val[3], $label, false, false, false,
									array(getCmd("d.set_custom3")."=1") );
								if(retrackersHashMatchesExpected($candidateResult, $hash))
								{
									$processed = true;
								}
								else
								{
									$pReq = new rXMLRPCRequest( new rXMLRPCCommand("d.hash", $hash) );
									$pReq->important = false;
									$presence = retrackersClassifyPresence($pReq, $pReq->run(), $hash);
									if($presence === 'absent')
									{
										$rbResult = rTorrent::sendTorrent($origTorrent, $isStart, false, $req->val[3], $label, false, false, false,
											array(getCmd("d.set_custom3")."=1") );
										if(!retrackersHashMatchesExpected($rbResult, $hash))
											retrackersLogReloadFailure($hash, 'rollback-load-failed');
									}
									else
									{
										retrackersLogReloadFailure($hash, 'unconfirmed-send presence=' . $presence);
									}
								}
							}
						}
					}
				}
			}
		}
	}
	if(!$processed && !$eraseIssued && $isStart)
	{
		$req = new rXMLRPCRequest( new rXMLRPCCommand("d.start", $hash ) );
		$req->run();
	}
	return($processed);
}

if(!defined('RETRACKERS_TEST_MODE'))
	retrackersRunWorker($argv);
