<?php
require_once __DIR__.'/../rutracker_check/TestLib.php';
require_once testFindRepoRoot().'/plugins/autotools/move_queue.php';
$suite=new StrictTestSuite();$qid=str_repeat('a',32);
foreach(array('source-not-ready','transport-unavailable','preflight-refused','storage-failed','worker-exit')as$reason)
    $suite->test('canonical queue receipt '.$reason,function()use($qid,$reason){
        $count=in_array($reason,AutoToolsMoveQueueRecord::WAIT,true)?0:1;
        $raw='m1:'.str_repeat('b',64).':'.$qid.':'.str_repeat('C',40).':100:r1:'.$qid.':'.$count.':200:'.$reason."\n";
        strictAssertSame($qid,AutoToolsMoveQueueRecord::parse($raw)['qid'],'identity retained');
    });
foreach(array('r2:Q:1:200:worker-exit','r1:Q:0:200:worker-exit','r1:Q:01:200:worker-exit',
    'r1:Q:6:200:worker-exit','r1:Q:5:0:worker-exit','r1:Q:1:0200:worker-exit',
    'r1:Q:1:200:foreign-reason')as$raw)
    $suite->test('invalid queue receipt rejected '.$raw,function()use($raw,$qid){
        $caught=false;try{AutoToolsMoveQueueRecord::retry(str_replace('Q',$qid,$raw),$qid);}catch(RuntimeException $e){$caught=true;}
        strictAssertSame(true,$caught,'closed grammar refuses');
    });
$suite->test('native record requires newline and exact bounded fields',function()use($qid){
    $valid='m1:'.str_repeat('b',64).':'.$qid.':'.str_repeat('C',40).':100:';
    foreach(array($valid,$valid."\n\n",str_replace(':100:',':0100:',$valid)."\n",str_replace('m1:','m2:',$valid)."\n")as$raw){
        $caught=false;try{AutoToolsMoveQueueRecord::parse($raw);}catch(RuntimeException $e){$caught=true;}
        strictAssertSame(true,$caught,'malformed record never normalized');
    }
});
exit($suite->run());
