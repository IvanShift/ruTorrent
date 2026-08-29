<?php

function retrackersParseOriginalHandoff($arguments)
{
	if(!is_array($arguments) || array_keys($arguments) !== array(0, 1, 2, 3)
		|| !is_string($arguments[0]) || !is_string($arguments[1])
		|| !is_string($arguments[2]) || !is_string($arguments[3])
		|| preg_match('/^[0-9A-F]{40}$/D', $arguments[1]) !== 1
		|| preg_match('/^[a-z0-9_-]*$/D', $arguments[2]) !== 1
		|| preg_match('/^v1:original:([01]):([0-9A-F]{40})$/D', $arguments[3], $matches) !== 1)
		return(false);
	return array(
		'hash' => $arguments[1],
		'user' => $arguments[2],
		'state' => $matches[1],
		'local_id' => $matches[2],
		'marker' => $arguments[3],
	);
}

// isset, because under a web SAPI $argv does not exist and reading it bare
// emits a warning before this fails closed. It DOES fail closed either way --
// retrackersParseOriginalHandoff() starts with is_array() - but a guard that
// announces itself with an "Undefined variable" notice on the way is not the
// diagnostic anybody wants. Same shape as plugins/erasedata/update.php:8.
if(!defined('RETRACKERS_TEST_MODE')
	&& retrackersParseOriginalHandoff(isset($argv) ? $argv : null) === false)
	return;

if(!defined('RETRACKERS_TEST_MODE'))
{
	$retrackersHandoff = retrackersParseOriginalHandoff($argv);
	$_SERVER['REMOTE_USER'] = $retrackersHandoff['user'];

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

function retrackersInitialOwnershipMatches($values, $handoff)
{
	return is_array($values) && count($values) === 12
		&& isset($values[7], $values[8], $values[9], $values[10], $values[11])
		&& is_string($values[7]) && is_string($values[8])
		&& is_string($values[9]) && is_string($values[10]) && is_string($values[11])
		&& $values[7] === $handoff['marker'] && $values[8] === ''
		&& $values[9] === $handoff['state'] && $values[10] === '0'
		&& $values[11] === $handoff['local_id'];
}

function retrackersBuildOwnershipCondition($handoff, $state, $quiesced)
{
	$predicates = array(
		'equal=d.custom=' . RETRACKERS_RECOVERY_MARKER . ',cat=' . $handoff['marker'],
		'equal=d.custom=' . RETRACKERS_RECOVERY_ACK . ',cat=',
		'equal=d.local_id=,cat=' . $handoff['local_id'],
		'equal=d.state=,value=' . $state,
		'equal=d.hashing_failed=,value=0',
	);
	if($quiesced)
	{
		$predicates[] = 'equal=d.is_active=,value=0';
		$predicates[] = 'equal=d.is_open=,value=0';
	}
	return 'and=' . implode(',', array_map(function($predicate)
	{
		return rTorrent::quoteCommandArg($predicate);
	}, $predicates));
}

function retrackersBuildEraseCommit($hash, $handoff)
{
	$q = function($value)
	{
		return rTorrent::quoteCommandArg($value);
	};
	$postQuiesce = retrackersBuildOwnershipCondition($handoff, '0', true);
	$erase = 'cat=' . $q('$d.erase=') . ',RETRACKERS_ERASED';
	$inner = 'branch=' . $q($postQuiesce) . ',' . $q($erase)
		. ',' . $q('cat=RETRACKERS_QUIESCE_CHANGED');
	$steps = $handoff['state'] === '1'
		? array('$d.stop=', '$d.close=', '$' . $inner)
		: array('$d.close=', '$' . $inner);
	$trueBody = 'cat=' . implode(',', array_map($q, $steps));
	return new rXMLRPCCommand('branch', array(
		$hash,
		retrackersBuildOwnershipCondition($handoff, $handoff['state'], false),
		$trueBody,
		'cat=RETRACKERS_SKIPPED',
	));
}

function retrackersCommitErase($hash, $handoff)
{
	$req = new rXMLRPCRequest(retrackersBuildEraseCommit($hash, $handoff));
	$req->important = false;
	if(!$req->success() || $req->fault || !is_array($req->val)
		|| count($req->val) !== 1 || !is_string($req->val[0]))
	{
		retrackersLogReloadFailure($hash, 'commit-unknown');
		return(false);
	}
	if($req->val[0] === 'RETRACKERS_ERASED')
		return(true);
	$reason = $req->val[0] === 'RETRACKERS_SKIPPED'
		? 'commit-skipped'
		: ($req->val[0] === 'RETRACKERS_QUIESCE_CHANGED'
			? 'commit-quiesce-changed' : 'commit-unknown');
	retrackersLogReloadFailure($hash, $reason);
	return(false);
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

function retrackersRunWorker($arguments)
{
	$handoff = retrackersParseOriginalHandoff($arguments);
	if($handoff === false)
		return(false);
	$hash = $handoff['hash'];
	$preStopState = $handoff['state'];
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
		new rXMLRPCCommand("d.get_custom",array($hash, RETRACKERS_RECOVERY_MARKER)),
		new rXMLRPCCommand("d.get_custom",array($hash, RETRACKERS_RECOVERY_ACK)),
		new rXMLRPCCommand("d.get_state",$hash),
		new rXMLRPCCommand("d.get_hashing_failed",$hash),
		new rXMLRPCCommand("d.get_local_id",$hash),
		) );
	// This detached worker may wake after another plugin replaced the hash.
	// Keep the expected stale-hash fault local so XMLRPC never dumps raw XML.
	$req->important = false;
	$runResult = $req->run();
	if(!$runResult || $req->fault)
	{
		$confirmedMissing = $runResult && $req->fault && retrackersIsCompleteMissingHashFault($req);
		if(!$confirmedMissing)
			retrackersLogInitialFailure($hash, !$runResult ? 'transport-failure' : 'rpc-fault');
		return(false);
	}
	if(!is_array($req->val) || count($req->val) !== 12)
	{
		retrackersLogInitialFailure($hash, 'malformed-response');
		return(false);
	}
	if(!retrackersInitialOwnershipMatches($req->val, $handoff))
	{
		retrackersLogReloadFailure($hash, 'ownership-mismatch');
		return(false);
	}
	if(retrackersIsServiceTorrent($req->val[2], $req->val[6]))
		return(false);

	$isStart = ($preStopState === '1');
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
							if(retrackersCommitErase($hash, $handoff))
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
	return($processed);
}

if(!defined('RETRACKERS_TEST_MODE'))
	retrackersRunWorker($argv);
