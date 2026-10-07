<?php

if (!defined('RETRACKERS_DISPATCH_IMPORT_ONLY')) {
    require_once(__DIR__ . '/../../php/cli-only.php');
    rutorrent_require_cli(__FILE__);
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
    public function queueRetryState($hash,&$failure=null) {
        return $this->nativeOriginalScalar('d.custom',array($hash,'retrackers-retry'),$failure);
    }
    public function queueCursor($userHash,&$failure=null) {
        return $this->nativeOriginalScalar('system.retrackers.queue_cursor',array('',$userHash),$failure);
    }
    public function setQueueCursor($userHash,$hash,&$failure=null) {
        return $this->nativeOriginalScalar('system.retrackers.queue_cursor.set',array('',$userHash,$hash),$failure);
    }
    public function queueClaim($hash,&$failure=null) {
        return $this->nativeOriginalScalar('system.retrackers.original_claim',array('',$hash),$failure);
    }
    public function enqueueOriginal($hash,$id,$userHash,$state,&$failure=null) {
        return $this->nativeOriginalScalar('d.retrackers.enqueue_original',array($hash,$id,$userHash,$state),$failure);
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
    public static function run($adapter,$user) {
        $result=array('examined'=>0,'spawned'=>0,'active'=>0,'full'=>0,'held'=>0,'dequeued'=>0,'deferred'=>0,'terminal'=>0,'errors'=>array());
        if (!is_string($user) || preg_match('/^[a-z0-9_-]*$/D',$user)!==1) {
            self::error($result,'','invalid-caller-input'); return $result;
        }
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
            if (preg_match('/^q1:([0-9a-f]{64}):([0-9a-f]{32})$/D',$row[2],$parts)!==1) {
                self::error($result,$row[0],'queue-marker-malformed'); continue;
            }
            if (hash_equals($userHash,$parts[1])) $queued[$row[0]]=array($row,$parts[2]);
        }
        ksort($queued,SORT_STRING);
        // A bounded pass resumes at the successor even after full admission.
        // Uncertain old jobs remain held and cannot starve a later runnable job.
        $after=array(); $before=array();
        foreach($queued as $hash=>$job) {
            if (strcmp($hash,$cursor)>0) $after[$hash]=$job; else $before[$hash]=$job;
        }
        $active=$adapter->queueAdmissionActive($userHash,$failure);
        foreach($after+$before as $hash=>$job) {
            if ($result['examined']>=self::MAX_VISITS) break;
            $result['examined']++; list($row,$queueId)=$job;
            if ($adapter->setQueueCursor($userHash,$hash,$failure)!==$hash) {
                self::error($result,$hash,'queue-cursor-unconfirmed'); break;
            }
            $wire=$adapter->queueClaim($hash,$failure);
            if ($wire==='held') { $result['held']++; continue; }
            if ($wire==='none' || (is_string($wire) && strncmp($wire,'original:',9)===0)) {
                $retry=RetrackersQueueRetry::parse($adapter->queueRetryState($hash,$failure),$queueId);
                if ($retry===false) {
                    self::error($result,$hash,'queue-retry-state-invalid'); continue;
                }
                if ($retry['attempt']===RetrackersQueueRetry::MAX_ATTEMPTS) {
                    $result['terminal']++; continue;
                }
                if ($retry['due']>time()) { $result['deferred']++; continue; }
            }
            if ($wire==='none') {
                if (!$active) { $result['held']++; continue; }
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
                $done=$adapter->dequeueOriginal($hash,$claim['tx'],$claim['incarnation'],$queueId,$failure);
                if ($done==='dequeued') $result['dequeued']++;
                else self::error($result,$hash,'queue-dequeue-unconfirmed');
                continue;
            }
            if (!$active) { $result['held']++; continue; }
            $admission=$adapter->startQueueWorker($hash,$claim['tx'],$claim['incarnation'],$user,$failure);
            if ($admission==='spawned' || $admission==='active' || $admission==='held') $result[$admission]++;
            elseif ($admission==='full') { $result['full']++; break; }
            else self::error($result,$hash,'queue-worker-unconfirmed');
        }
        return $result;
    }
}

final class RetrackersQueueDiagnostics
{
    public $hash='retrackers-queue-diagnostics.dat';
    public $last=array();
    public static function emit($errors,$cache,$now) {
        if ($errors===array()) return;
        $state=new self(); $cache->get($state);
        if (!($state instanceof self) || !is_array($state->last) || count($state->last)>16) $state=new self();
        foreach($state->last as $reason=>$stamp)
            if (!is_string($reason) || preg_match('/^[a-z-]{1,64}$/D',$reason)!==1 ||
                !is_int($stamp) || $stamp<0) { $state=new self(); break; }
        $groups=array();
        foreach($errors as $error) {
            $reason=$error['reason'];
            if (!isset($groups[$reason])) $groups[$reason]=array('count'=>0,'hash'=>$error['hash']);
            $groups[$reason]['count']++;
        }
        $state->hash='retrackers-queue-diagnostics.dat';
        $messages=array();
        foreach($groups as $reason=>$group) {
            if (isset($state->last[$reason]) && $now>=$state->last[$reason] && $now-$state->last[$reason]<60) continue;
            $state->last[$reason]=$now;
            $messages[]='retrackers-queue: '.$reason.'; affected-in-pass='.$group['count'].
                '; example-hash='.$group['hash'].'; inspect torrent session d.custom=retrackers-retry and '.
                'd.custom=retrackers-queued; queued work remains pending';
        }
        if ($messages===array()) return;
        // One cache entry bounds diagnostics across short-lived dispatchers.
        // Keep classifications rather than hashes: the cursor rotates every pass.
        asort($state->last,SORT_NUMERIC);
        $state->last=array_slice($state->last,-16,null,true);
        $cache->set($state);
        foreach($messages as $message) FileUtil::toLog($message);
    }
}

if (!defined('RETRACKERS_DISPATCH_IMPORT_ONLY')) {
    // Profile configuration is imported at file scope for the SCGI globals.
    require_once(__DIR__ . '/retrackers.php');
    require_once(__DIR__ . '/../../php/xmlrpc.php');
    require_once(__DIR__ . '/../../php/rtorrent.php');
    $adapter=new RetrackersQueueRpcAdapter(); $failure=null;
    if (!$adapter->nativeQueueCapability($failure)) {
        FileUtil::toLog('retrackers-queue: native-queue-capability-unconfirmed; durable retrackers-queued jobs remain pending');
        exit(1);
    }
    $result=RetrackersBoundedDispatcher::run($adapter,$argv[1]);
    RetrackersQueueDiagnostics::emit($result['errors'],new rCache(),time());
    exit($result['errors']===array()?0:1);
}
