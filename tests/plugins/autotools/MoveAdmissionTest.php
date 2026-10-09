<?php
define('MOVE_WORKER_TEST_LIBRARY', true);
require_once __DIR__ . '/MoveWorkerTest.php';
$native = array('system.autotools.move_queue', 'system.autotools.move_dispatch', 'system.autotools.move_state', 'system.autotools.move_owner',
    'system.autotools.move_reset', 'd.autotools.move_enqueue', 'd.autotools.move_begin', 'd.autotools.move_defer',
    'd.autotools.move_finish', 'd.autotools.move_cancel');
$lease = array('d.stop_close_claim_state', 'd.stop_close_claim', 'd.replay_stop_close_claim', 'd.ack_stop_close_claim',
    'd.directory.set_if_stop_close_claim', 'd.directory.base.set_if_stop_close_claim', 'd.start_if_stop_close_claim',
    'd.open_if_stop_close_claim', 'd.release_stop_close_claim');
$suite = new StrictTestSuite();
foreach (array('stock'=>array(array(), 'upstream'), 'full'=>array(array_merge($native, $lease), 'queue'),
    'partial'=>array(array_slice($native, 1), 'paused'), 'unrecognized-native'=>array(array('system.autotools.future'), 'paused'),
    'malformed-empty'=>array(array(''), 'paused'), 'malformed-space'=>array(array('bad method'), 'paused'), 'missing-lease'=>array($native, 'paused')) as $name=>$case)
    $suite->test('capability mode ' . $name, function () use ($case) {
        $state=moveWorkerFixture();$state['capabilities']=array_merge(array('system.listMethods'),$case[0]);
        try {
            list($exit,$after,$calls,$output)=moveWorkerRun($state,'capability');
            strictAssertSame(0,$exit,'capability CLI completes');
            strictAssertSame($case[1],$output,'mode follows complete capabilities');
            strictAssertSame(array('system.listMethods'),$calls,'one read-only probe');
        } finally { strictRemoveTree($state['root']); }
    });
$suite->test('unknown capability pauses instead of using upstream fallback', function () {
    $state=moveWorkerFixture();$state['drop']='system.listMethods';
    try {
        list($exit,$after,$calls,$output)=moveWorkerRun($state,'capability');
        strictAssertSame('paused',$output,'unknown is not unsupported');
    } finally { strictRemoveTree($state['root']); }
});
foreach(array('queue','upstream','paused','disabled')as$mode)
    $suite->test('real parser handler setup '.$mode,function()use($mode,$native,$lease){
        $state=moveWorkerFixture();
        $state['capabilities']=array_merge(array('system.listMethods'),$mode==='upstream'?array():
            ($mode==='paused'?array($native[0]):array_merge($native,$lease)));
        try {
            list($exit,$after,$calls,$output,$bodies)=moveWorkerRun($state,$mode==='disabled'?'hooks-disabled':'hooks');
            strictAssertSame(0,$exit,'handler setup completes: '.$output);
            $wire=html_entity_decode(implode('',array_slice($bodies,1)),ENT_QUOTES|ENT_XML1,'UTF-8');
            strictAssertSame(in_array($mode,array('queue','disabled'),true),strpos($wire,'system.autotools.move_dispatch=')!==false,'native drain schedule');
            // Captured packaged native 2c77a784 rejects integer delay/interval:
            // command_events.cc apply_schedule reads name, delay and interval as strings.
            if(in_array($mode,array('queue','disabled'),true)) {
                strictAssertSame(1,preg_match('~<name>methodName</name>\s*<value><string>schedule\.if_absent</string></value>.*?<name>params</name>\s*<value><array><data>(.*?)</data>~s',$wire,$schedule),'native schedule present');
                preg_match_all('~<value><(string|i4|i8|double|boolean)>(.*?)</\\1></value>~s',$schedule[1],$parameters);
                strictAssertSame(array_fill(0,5,'string'),$parameters[1],'native schedule argument types');
                strictAssertSame(array('', 'am.queue.'.hash('sha256','tester'), '1', '1', 'system.autotools.move_dispatch="tester"'),$parameters[2],'native schedule argument values');
            }
            strictAssertSame($mode==='queue',strpos($wire,'d.autotools.move_enqueue=')!==false,'pure enqueue admission');
            strictAssertSame($mode==='upstream',strpos($wire,'upstream_move.php')!==false,'upstream hook only when absent ABI');
            strictAssertSame(true,strpos($wire,'php \\"quoted\\" \\\\ binary')!==false,'configured PHP bytes quoted by shared helper');
        } finally { strictRemoveTree($state['root']); }
    });
exit($suite->run());
