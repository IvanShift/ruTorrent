<?php

if (!defined('RETRACKERS_DISPATCH_IMPORT_ONLY')) {
    require_once(__DIR__ . '/../../php/cli-only.php');
    rutorrent_require_cli(__FILE__);
    // RetrackersProfileName::valid(), inline: REMOTE_USER precedes the profile load.
    if (!isset($argv) || count($argv)!==2 || !is_string($argv[1]) ||
        preg_match('/^[a-z0-9_-]*$/D',$argv[1])!==1) exit(1);
    $_SERVER['REMOTE_USER']=$argv[1];
}
if (!defined('RETRACKERS_IMPORT_ONLY')) define('RETRACKERS_IMPORT_ONLY',true);
require_once(__DIR__ . '/update.php');

class RetrackersQueueRpcAdapter extends RetrackersWorkerRpcAdapter
{
    public function queueRows(&$failure=null) {
        $decoded=$this->send(retrackersBuildDirectRequest('d.multicall2',array('', 'main',
            'd.hash=', 'd.local_id=', 'd.custom=retrackers-queued',
            'd.custom=retrackers-recovery', 'd.custom=retrackers-recovery-ack', 'cat=$d.state=')),
            retrackersRestrictedPlanQueueRows(),$failure);
        return is_array($decoded) && isset($decoded['ok'],$decoded['value']) &&
            $decoded['ok']===true ? $decoded['value'] : false;
    }
    public function queueCursor($userHash,&$failure=null) {
        return $this->nativeOriginalScalar('system.retrackers.queue_cursor',array('',$userHash),$failure);
    }
    public function setQueueCursor($userHash,$hash,&$failure=null) {
        return $this->nativeOriginalScalar('system.retrackers.queue_cursor.set',array('',$userHash,$hash),$failure);
    }
    // Minimal operator snapshot, using the existing restricted scalar and
    // tracker codecs. Only admission fields are read; user metadata is not rewritten.
    public function applyExistingSnapshot($hash,&$failure=null) {
        $methods=$this->methodNames($failure);
        if (!is_array($methods)) return false;
        $fields=array('local_id'=>array('d.local_id',array($hash),'string'),
            'state'=>array('d.state',array($hash),'i8'),
            'private'=>array('d.is_private',array($hash),'i8'),
            'name'=>array('d.name',array($hash),'string'),
            'queued'=>array('d.custom',array($hash,'retrackers-queued'),'string'),
            'recovery'=>array('d.custom',array($hash,'retrackers-recovery'),'string'),
            'ack'=>array('d.custom',array($hash,'retrackers-recovery-ack'),'string'));
        $calls=array(); $types=array();
        foreach($fields as $field) { $calls[]=array($field[0],$field[1]); $types[]=$field[2]; }
        $decoded=$this->send(retrackersBuildSystemMulticallRequest($calls),retrackersRestrictedPlanScalarBatch($types),$failure);
        if (!is_array($decoded) || !isset($decoded['ok'],$decoded['value']) || $decoded['ok']!==true) return false;
        $job=array(); $index=0;
        foreach($fields as $name=>$field) {
            $slot=$decoded['value'][$index++];
            if (!isset($slot['ok'],$slot['value']) || $slot['ok']!==true || !is_string($slot['value'])) {
                $probeFailure=null;
                $failure=$this->nativeTargetAbsent($hash,$probeFailure)===true?'target-absent':'rpc-unconfirmed';
                return false;
            }
            $job[$name]=$slot['value'];
        }
        $job['claim']=$this->queueClaim($hash,$failure);
        if ($job['claim']===false) return false;
        $job['lease']=in_array('d.stop_close_claim_held',$methods,true)?
            $this->nativeOriginalScalar('d.stop_close_claim_held',array($hash),$failure):'';
        if ($job['lease']===false) return false;
        $job['move']=in_array('system.autotools.move_state',$methods,true)?
            $this->nativeOriginalScalar('system.autotools.move_state',array('',$hash),$failure):'none';
        if ($job['move']===false) return false;
        $trackers=$this->sourceTrackerSnapshot($hash,$failure);
        if (!is_array($trackers)) return false;
        $job['trackers']=array();
        foreach($trackers['rows'] as $row) $job['trackers'][]=$row[0];
        $failure=null; return $job;
    }
    public function enqueueOriginal($hash,$id,$userHash,$state,&$failure=null,$guarded=false) {
        if (!$guarded) return $this->nativeOriginalScalar('d.retrackers.enqueue_original',array($hash,$id,$userHash,$state),$failure);
        $methods=$this->methodNames($failure);
        if (!is_array($methods)) return false;
        $callback=RetrackersLifecycleCallbacks::applyExisting($hash,$id,$userHash,$state,
            in_array('system.autotools.move_state',$methods,true));
        if ($callback===false) { $failure='invalid-request'; return false; }
        $reply=$this->executeLifecycleCallback($callback,$failure);
        // CHANGED proves enqueue never ran. A native fault may follow durable
        // Q or preclaim publication, so it cannot attest a no-effect refusal.
        if ($reply===$callback->changedSentinel()) return 'refused:changed';
        return $reply;
    }
    public function startQueueWorker($hash,$tx,$incarnation,$user,&$failure=null) {
        return $this->nativeOriginalScalar('system.retrackers.worker_start',array('',$hash,$tx,$incarnation,$user),$failure);
    }
    public function dequeueOriginal($hash,$tx,$incarnation,$queueId,&$failure=null) {
        return $this->nativeOriginalScalar('d.retrackers.dequeue_original',array($hash,$tx,$incarnation,$queueId),$failure);
    }
    public function queueAdmissionActive($userHash,&$failure=null) {
        $keys=$this->getLedgerKeys($failure);
        if (!is_array($keys)) return false;
        $claims=0; $epochs=0; $caller=false;
        foreach($keys as $key) {
            if ($key==='ta:1' || $key==='ma:1' || strncmp($key,'to:',3)===0) return false;
            if (preg_match('/^pv:[0-9a-f]{32}$/D',$key)===1) $epochs++;
            if (strncmp($key,'pf:',3)===0) {
                $claims++;
                if (preg_match('/^pf:'. $userHash . ':[0-9a-f]{64}$/D',$key)===1) $caller=true;
            }
        }
        // Native worker_start repeats this admission under its daemon lock.
        return $epochs===1 && $claims===1 && $caller;
    }
}

final class RetrackersBoundedDispatcher
{
    const MAX_VISITS=32;
    private static function error(&$result,$hash,$reason) {
        $result['errors'][]=array('hash'=>$hash,'reason'=>$reason);
    }
    private static function claim($wire,$row,$userHash,$queueId) {
        $claim=RetrackersQueueClaim::parse($wire);
        if ($claim===false || !hash_equals($userHash,$claim['user_hash']) ||
            !hash_equals($queueId,$claim['queue_id']) ||
            ($claim['kind']==='original' && !hash_equals($row[1],$claim['local_id']))) return false;
        return $claim;
    }
    // A pass reports only classified errors; production reads nothing else.
    public static function run($adapter,$user,$now=null) {
        $result=array('errors'=>array());
        if (!RetrackersProfileName::valid($user)) {
            self::error($result,'','invalid-caller-input'); return $result;
        }
        if ($now===null) $now=time();
        $userHash=hash('sha256',$user); $failure=null;
        $rows=$adapter->queueRows($failure);
        $cursor=$adapter->queueCursor($userHash,$failure);
        if (!is_array($rows) || count($rows)>16384 || !is_string($cursor) ||
            ($cursor!=='' && preg_match('/^[0-9A-F]{40}$/D',$cursor)!==1)) {
            self::error($result,'','queue-read-unconfirmed'); return $result;
        }
        $queued=array();
        foreach($rows as $row) {
            if (!is_array($row) || array_keys($row)!==array(0,1,2,3,4,5) ||
                count(array_filter($row,'is_string'))!==6 ||
                preg_match('/^[0-9A-F]{40}$/D',$row[0])!==1 || preg_match('/^[0-9A-F]{40}$/D',$row[1])!==1 ||
                !in_array($row[5],array('0','1'),true)) {
                self::error($result,'','queue-row-malformed'); return $result;
            }
            if ($row[2]==='') continue;
            $marker=RetrackersQueueMarker::parse($row[2]);
            if ($marker===false) {
                self::error($result,$row[0],'queue-marker-malformed'); continue;
            }
            if (hash_equals($userHash,$marker['user_hash'])) $queued[$row[0]]=array($row,$marker['queue_id']);
        }
        ksort($queued,SORT_STRING);
        // A bounded pass resumes at the successor even after full admission.
        // Uncertain old jobs remain held and cannot starve a later runnable job.
        $after=array(); $before=array();
        foreach($queued as $hash=>$job) {
            if (strcmp($hash,$cursor)>0) $after[$hash]=$job; else $before[$hash]=$job;
        }
        $active=$adapter->queueAdmissionActive($userHash,$failure);
        $visits=0;
        foreach($after+$before as $hash=>$job) {
            if ($visits>=self::MAX_VISITS) break;
            $visits++; list($row,$queueId)=$job;
            if ($adapter->setQueueCursor($userHash,$hash,$failure)!==$hash) {
                self::error($result,$hash,'queue-cursor-unconfirmed'); break;
            }
            $wire=$adapter->queueClaim($hash,$failure);
            if ($wire==='held') continue;
            if ($wire==='none' || (is_string($wire) && strncmp($wire,'original:',9)===0)) {
                $state=$adapter->queueRetryState($hash,$failure);
                // No answer is not corruption: this job is simply revisited.
                if ($state===false) { self::error($result,$hash,'queue-retry-state-unconfirmed'); continue; }
                $retry=RetrackersQueueRetry::parse($state,$queueId);
                if ($retry===false) { self::error($result,$hash,'queue-retry-state-invalid'); continue; }
                if (RetrackersQueueRetry::isTerminal($retry) || $retry['due']>$now) continue;
            }
            if ($wire==='none') {
                if (!$active) continue;
                // Restart may repair the original and clear recovery markers;
                // durable Q still owes work. A marked unknown is never retried.
                if ($row[3]!=='' || $row[4]!=='') {
                    self::error($result,$hash,'queue-owner-unconfirmed'); continue;
                }
                if ($adapter->enqueueOriginal($hash,$row[1],$userHash,$row[5],$failure)===false) {
                    self::error($result,$hash,'queue-enqueue-unconfirmed'); continue;
                }
                $wire=$adapter->queueClaim($hash,$failure);
            }
            $claim=self::claim($wire,$row,$userHash,$queueId);
            if ($claim===false) { self::error($result,$hash,'queue-claim-unconfirmed'); continue; }
            if ($claim['kind']==='finalized') {
                if ($adapter->dequeueOriginal($hash,$claim['tx'],$claim['incarnation'],$queueId,$failure)!=='dequeued')
                    self::error($result,$hash,'queue-dequeue-unconfirmed');
                continue;
            }
            if (!$active) continue;
            $admission=$adapter->startQueueWorker($hash,$claim['tx'],$claim['incarnation'],$user,$failure);
            if ($admission==='full') break;
            if (!in_array($admission,array('spawned','active','held'),true))
                self::error($result,$hash,'queue-worker-unconfirmed');
        }
        return $result;
    }
}

// One dispatcher pass. A capability refusal and every per-job error share the
// rate-limited diagnostics, so an SCGI outage cannot write a line per tick.
function retrackersDispatchMain($adapter,$user,$cache,$now) {
    $failure=null;
    if (!$adapter->nativeQueueCapability($failure)) {
        RetrackersQueueDiagnostics::emit(array(array('hash'=>'','reason'=>
            RetrackersBoundedRead::noAnswer($failure)?'queue-transport-unavailable':
            'native-queue-capability-unconfirmed')),$cache,$now);
        return 1;
    }
    $result=RetrackersBoundedDispatcher::run($adapter,$user,$now);
    RetrackersQueueDiagnostics::emit($result['errors'],$cache,$now);
    return $result['errors']===array()?0:1;
}

if (!defined('RETRACKERS_DISPATCH_IMPORT_ONLY')) {
    // Profile configuration is imported at file scope for the SCGI globals.
    require_once(__DIR__ . '/retrackers.php');
    require_once(__DIR__ . '/../../php/xmlrpc.php');
    require_once(__DIR__ . '/../../php/rtorrent.php');
    exit(retrackersDispatchMain(new RetrackersQueueRpcAdapter(),$argv[1],new rCache(),time()));
}
