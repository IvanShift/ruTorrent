<?php
// Run only in a disposable daemon container. Exercise the generated event hooks.
$native=($argv[1] ?? '') === 'native';
define('RETRACKERS_IMPORT_ONLY', true);
if ($native) {
    // The modern hook queues intent; only the daemon's fixed application actor runs it.
    // docker exec does not inherit exports made by the fixture's daemon shell.
    foreach (array('RU_PROFILE_PATH=/data/profile','RU_TEMP_DIRECTORY=/data/temp','RU_TOP_DIR=/data/',
        'RU_SCGI_HOST=unix:///run/rtorrent/rtorrent.sock','RU_SCGI_PORT=0','RU_LOG_FILE=/data/application.log') as $env)
        putenv($env);
    $_SERVER['REMOTE_USER']='alice';
    require_once(__DIR__.'/../../../plugins/retrackers/retrackers.php');
    require_once(__DIR__.'/../../../php/xmlrpc.php');
    require_once(__DIR__.'/../../../php/rtorrent.php');
} else {
    function getCmd($value) { return $value; }
    class rTorrent {
        public static function quoteCommandArg($value) {
            return '"'.str_replace(array('\\','"'), array('\\\\','\\"'), $value).'"';
        }
    }
}
require_once(__DIR__.'/../../../plugins/retrackers/update.php');
function decodeValue($value) {
    if (isset($value->array)) {
        $result=array(); foreach ($value->array->data->value as $child) $result[]=decodeValue($child);
        return $result;
    }
    foreach (array('string','i8','i4','int') as $tag) if (isset($value->$tag)) return (string)$value->$tag;
    throw new RuntimeException('unsupported RPC value');
}
function rpc($method, ...$params) {
    $xml=retrackersBuildDirectRequest($method,$params);
    $socket=stream_socket_client('unix:///run/rtorrent/rtorrent.sock',$errno,$error,3);
    if (!$socket) throw new RuntimeException('RPC socket: '.$error);
    stream_set_timeout($socket,5);
    $headers='CONTENT_LENGTH'."\0".strlen($xml)."\0SCGI\0"."1\0REQUEST_METHOD\0POST\0REQUEST_URI\0/RPC2\0";
    $wire=strlen($headers).':'.$headers.','.$xml;
    while ($wire !== '') {
        $size=fwrite($socket,$wire); if (!$size) throw new RuntimeException('RPC write');
        $wire=substr($wire,$size);
    }
    $reply=stream_get_contents($socket,1048576); fclose($socket);
    $offset=strpos($reply,'<?xml');
    $result=$offset === false ? false : simplexml_load_string(substr($reply,$offset),SimpleXMLElement::class,LIBXML_NONET);
    if (!$result || isset($result->fault)) throw new RuntimeException($method.': '.$reply);
    return decodeValue($result->params->param->value);
}
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
function waitFor($predicate,$message) {
    $deadline=microtime(true)+15;
    do { if ($predicate()) return; usleep(100000); } while (microtime(true)<$deadline);
    throw new RuntimeException('timeout: '.$message);
}
function bstr($value) { return strlen($value).':'.$value; }
function fixtureKeys() {
    global $native;
    return array_values(array_filter(rpc('method.list_keys','','rr.receipts.v1'), function ($key) use ($native) {
        return !$native || (strncmp($key,'pf:',3)!==0 && strncmp($key,'pv:',3)!==0);
    }));
}
function clearFixtureReceipts() {
    foreach (fixtureKeys() as $key) rpc('method.set_key','','rr.receipts.v1',$key);
}
function nativeChildren() {
    $count=0;
    foreach (glob('/proc/[0-9]*/cmdline') as $path) {
        $args=explode("\0",(string)@file_get_contents($path));
        if (in_array($args[1] ?? '', array('/rutorrent/app/plugins/retrackers/update.php',
            '/rutorrent/app/plugins/retrackers/dispatch.php'), true)) $count++;
    }
    return $count;
}
function insertAction($settings=null) {
    global $native;
    return retrackersBuildInsertAction($native ? '/rutorrent/app/plugins/retrackers/run.sh' : '/data/insert-worker.sh',
        $native ? '/usr/bin/php85' : '/usr/bin/php','alice',$native,true,$settings);
}
function nativeEnqueued($hash) {
    $queue=rpc('d.custom',$hash,'retrackers-queued');
    $claim=explode(':',rpc('system.retrackers.original_claim','',$hash));
    check(count($claim)===7 && $claim[0]==='original' &&
        $claim[2]===rpc('d.incarnation',$hash) && $claim[3]===rpc('d.local_id',$hash) &&
        $claim[4]===hash('sha256','alice') && $queue==='q1:'.$claim[4].':'.$claim[6],
        'ordinary hook did not attest an exact queued original');
    check(rpc('d.custom',$hash,'retrackers-recovery')!=='' &&
        rpc('d.custom',$hash,'retrackers-recovery-ack')!=='','queued original recovery markers absent');
    check((bool)glob('/data/.session/.retrackers-migration/'.$hash.'*'),'queued original durable sidecar absent');
    check(nativeChildren()===0,'insert hook launched an actor before the dispatcher');
}
function nativeDrain($hash) {
    $failure=null;
    $adapter=new RetrackersLifecycleRpcAdapter(); $adapter->setFamily(2);
    check($adapter->armNativeQueueDispatcher('alice',$failure),'fixed dispatcher cadence refused: '.$failure);
    // An admitted profile answers idle when the cadence already started this job.
    check(in_array(rpc('system.retrackers.dispatcher_start','','alice'),array('spawned','active','idle'),true),
        'fixed dispatcher was not admitted');
    waitFor(function() use ($hash) {
        try { return rpc('d.custom',$hash,'retrackers-queued')==='' && nativeChildren()===0; }
        catch (Exception $e) { return false; }
    },'fixed application worker drains the queued original');
    rpc('schedule.remove','','rr.queue.'.hash('sha256','alice'));
    check(rpc('d.custom',$hash,'retrackers-recovery')==='' &&
        rpc('d.custom',$hash,'retrackers-recovery-ack')==='','fixed worker did not retire recovery markers');
    $urls=array_map(function ($row) { return $row[0]; },rpc('t.multicall',$hash,'','t.url='));
    check(in_array('http://127.0.0.1:9/retracker',$urls,true),'fixed worker did not apply the actual tracker edit');
}
rpc('method.insert','','rr.receipts.v1','multi|private');
if ($native) {
    $settings=new rRetrackers(); $settings->list=array(array('http://127.0.0.1:9/retracker'));
    $settings->todelete=array(); $settings->dontAddPrivate=0;
    check($settings->store(),'native fixture settings store failed');
    $failure=null;
    check(RetrackersLifecycleCoordinator::init('alice','/rutorrent/app/plugins/retrackers/run.sh',
        '/usr/bin/php85',$failure,null,$settings),'native fixture lifecycle init failed: '.$failure);
    rpc('schedule.remove','','rr.queue.'.hash('sha256','alice'));
    // Replace only this disposable fixture's insert callbacks while preserving profile admission.
    foreach (rpc('method.list_keys','','event.download.inserted_new') as $key)
        rpc('method.set_key','','event.download.inserted_new',$key);
    $keys=rpc('method.list_keys','','rr.receipts.v1');
    check(count(array_filter($keys,function ($key) { return strncmp($key,'pf:',3)===0; }))===1 &&
        count(array_filter($keys,function ($key) { return strncmp($key,'pv:',3)===0; }))===1,
        'native fixture lacks sole profile and epoch admission');
} else {
    file_put_contents('/data/insert-worker.sh',"#!/bin/sh\nprintf '%s\\n' \"\$@\" > /data/worker-called\n");
}
if (($argv[2] ?? '') === 'eligibility') {
    foreach (array(array(false,false,true), array(true,true,true),
        array(true,true,false), array(true,false,true)) as $case) {
        list($work,$private,$exclude)=$case;
        clearFixtureReceipts();
        @unlink('/data/worker-called');
        $settings=(object)array('list'=>$work ? array(array('http://retracker.invalid/announce')) : array(),
            'todelete'=>array(),'dontAddPrivate'=>$exclude ? 1 : 0);
        $action=insertAction($settings);
        rpc('method.set_key','','event.download.inserted_new','eligibility-smoke',$action);
        $name='eligibility-'.(int)$work.'-'.(int)$private.'-'.(int)$exclude;
        $info='d6:lengthi8e4:name'.bstr($name).'12:piece lengthi16384e6:pieces20:'.sha1('xxxxxxxx',true).
            ($private ? '7:privatei1e' : '').'e';
        $hash=strtoupper(sha1($info));
        file_put_contents('/data/'.$name.'.torrent','d8:announce'.bstr('http://127.0.0.1:9/announce').'4:info'.$info.'e');
        rpc('load.normal','','/data/'.$name.'.torrent');
        waitFor(function() use ($hash) { try { return rpc('d.hash',$hash)===$hash; } catch (Exception $e) { return false; } },'eligibility torrent inserted');
        $wanted=$work && (!$private || !$exclude);
        if ($wanted && !$native) waitFor(function() { return file_exists('/data/worker-called'); },'eligible worker launch');
        else usleep(300000);
        $marker=rpc('d.custom',$hash,'retrackers-recovery');
        $ack=rpc('d.custom',$hash,'retrackers-recovery-ack');
        check(($marker!=='')===$wanted && ($ack!=='')===$wanted,'eligibility claim mismatch');
        if (!$native) check(file_exists('/data/worker-called')===$wanted,'eligibility worker mismatch');
        if (!$wanted) check(fixtureKeys()===array(),'ineligible torrent published a receipt');
        if ($native) {
            check((bool)glob('/data/.session/.retrackers-migration/'.$hash.'*')===$wanted,'eligibility native sidecar mismatch');
            if ($wanted) { nativeEnqueued($hash); nativeDrain($hash); }
            else check(rpc('d.custom',$hash,'retrackers-queued')==='' && nativeChildren()===0,
                'ineligible torrent queued work or launched an actor');
        }
        rpc('d.erase',$hash);
        printf("ok - %s %s claim=%d worker=%d\n",$native?'native':'legacy',$name,(int)$wanted,(int)$wanted);
    }
    exit(0);
}
foreach (array('safety','defer','functional') as $kind) {
    clearFixtureReceipts();
    @unlink('/data/worker-called');
    $action=$kind === 'safety' ? retrackersBuildSafetyOnlyInsertAction() :
        ($kind === 'defer' ? retrackersBuildDeferOnlyInsertAction() :
        insertAction());
    rpc('method.set_key','','event.download.inserted_new','metadata-smoke',$action);
    $name='metadata-'.$kind;
    $info='d6:lengthi8e4:name'.bstr($name).'12:piece lengthi16384e6:pieces20:'.sha1('xxxxxxxx',true).'e';
    $hash=strtoupper(sha1($info));
    rpc('load.normal','','magnet:?xt=urn:btih:'.$hash);
    waitFor(function() use ($hash) { try { return rpc('d.is_meta',$hash)==='1'; } catch (Exception $e) { return false; } },'metadata inserted');
    usleep(300000);
    check(rpc('d.custom',$hash,'retrackers-recovery')==='', $kind.' metadata published a marker');
    check(rpc('d.custom',$hash,'retrackers-recovery-ack')==='', $kind.' metadata published an ack');
    check(fixtureKeys()===array(),$kind.' metadata published a ledger receipt');
    check(!file_exists('/data/worker-called'),$kind.' metadata launched a worker');
    if ($native) {
        check(!glob('/data/.session/.retrackers-migration/'.$hash.'*'),$kind.' metadata acquired a native claim');
        check(rpc('d.custom',$hash,'retrackers-queued')==='' && nativeChildren()===0,
            $kind.' metadata queued work or launched an actor');
    }
    rpc('d.custom3.set',$hash,'1');
    rpc('d.multicall2','', 'main', $action);
    check(rpc('d.custom3',$hash)==='1',$kind.' metadata reached the legacy-marker mutation');
    rpc('d.erase',$hash);
    file_put_contents('/data/'.$kind.'.torrent','d4:info'.$info.'e');
    rpc('load.normal','','/data/'.$kind.'.torrent');
    waitFor(function() use ($hash) { try { return rpc('d.is_meta',$hash)==='0'; } catch (Exception $e) { return false; } },'ordinary inserted');
    if ($kind === 'defer') {
        $keys=rpc('method.list_keys','','rr.receipts.v1');
        check(in_array('dq:1',$keys,true), 'ordinary defer did not set dirty');
        check(count(array_filter($keys, function ($key) { return strncmp($key,'di:',3)===0; }))===1, 'ordinary defer did not capture local id');
    } elseif ($kind === 'functional') {
        if ($native) { nativeEnqueued($hash); nativeDrain($hash); }
        else {
            waitFor(function() { return file_exists('/data/worker-called'); }, 'ordinary worker launch');
            check(rpc('d.custom',$hash,'retrackers-recovery')!=='','ordinary functional did not publish marker');
        }
    } else {
        rpc('d.custom3.set',$hash,'1');
        rpc('d.multicall2','', 'main', $action);
        check(rpc('d.custom3',$hash)==='', 'ordinary safety did not clear the legacy marker');
    }
    if ($kind !== 'functional') rpc('d.erase',$hash);
    printf("ok - %s %s metadata stays untouched; ordinary hook executes\n",$native?'native':'legacy',$kind);
}
