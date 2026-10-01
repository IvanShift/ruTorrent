<?php
// Run only in a disposable daemon container. Exercise the generated event hooks.
function getCmd($value) { return $value; }
class rTorrent {
    public static function quoteCommandArg($value) {
        return '"'.str_replace(array('\\','"'), array('\\\\','\\"'), $value).'"';
    }
}
define('RETRACKERS_IMPORT_ONLY', true);
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
$native=($argv[1] ?? '') === 'native';
rpc('method.insert','','rr.receipts.v1','multi|private');
file_put_contents('/data/insert-worker.sh',"#!/bin/sh\nprintf '%s\\n' \"\$@\" > /data/worker-called\n");
foreach (array('safety','defer','functional') as $kind) {
    foreach (rpc('method.list_keys','','rr.receipts.v1') as $key) rpc('method.set_key','','rr.receipts.v1',$key);
    @unlink('/data/worker-called');
    $action=$kind === 'safety' ? retrackersBuildSafetyOnlyInsertAction() :
        ($kind === 'defer' ? retrackersBuildDeferOnlyInsertAction() :
        retrackersBuildInsertAction('/data/insert-worker.sh','/usr/bin/php','alice',$native));
    rpc('method.set_key','','event.download.inserted_new','metadata-smoke',$action);
    $name='metadata-'.$kind;
    $info='d6:lengthi8e4:name'.bstr($name).'12:piece lengthi16384e6:pieces20:'.sha1('xxxxxxxx',true).'e';
    $hash=strtoupper(sha1($info));
    rpc('load.normal','','magnet:?xt=urn:btih:'.$hash);
    waitFor(function() use ($hash) { try { return rpc('d.is_meta',$hash)==='1'; } catch (Exception $e) { return false; } },'metadata inserted');
    usleep(300000);
    check(rpc('d.custom',$hash,'retrackers-recovery')==='', $kind.' metadata published a marker');
    check(rpc('d.custom',$hash,'retrackers-recovery-ack')==='', $kind.' metadata published an ack');
    check(rpc('method.list_keys','','rr.receipts.v1')===array(),$kind.' metadata published a ledger receipt');
    check(!file_exists('/data/worker-called'),$kind.' metadata launched a worker');
    if ($native) check(!glob('/data/.session/.retrackers-migration/'.$hash.'*'),$kind.' metadata acquired a native claim');
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
        waitFor(function() { return file_exists('/data/worker-called'); }, 'ordinary worker launch');
        check(rpc('d.custom',$hash,'retrackers-recovery')!=='','ordinary functional did not publish marker');
        if ($native) check((bool)glob('/data/.session/.retrackers-migration/'.$hash.'*'),'ordinary functional did not acquire native claim');
    } else {
        rpc('d.custom3.set',$hash,'1');
        rpc('d.multicall2','', 'main', $action);
        check(rpc('d.custom3',$hash)==='', 'ordinary safety did not clear the legacy marker');
    }
    if ($kind !== 'functional') rpc('d.erase',$hash);
    printf("ok - %s %s metadata stays untouched; ordinary hook executes\n",$native?'native':'legacy',$kind);
}
