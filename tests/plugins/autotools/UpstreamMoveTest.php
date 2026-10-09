<?php
define('MOVE_WORKER_TEST_LIBRARY',true);
require_once __DIR__.'/MoveWorkerTest.php';
$suite=new StrictTestSuite();
foreach(array(false,true)as$multi)
    $suite->test('upstream synchronous actor moves raw names with zero RPC multi='.(int)$multi,function()use($multi){
        $state=moveWorkerFixture($multi);
        try{
            list($exit,$after,$calls,$output)=moveWorkerRun($state,'upstream-check');
            strictAssertSame(0,$exit,'check exit: '.$output);
            strictAssertSame($multi?$state['destination'].'/':$state['root'].'/fin/',$output,'destination');
            strictAssertSame(array(),$calls,'check never calls blocked daemon');
            list($exit,$after,$calls,$output)=moveWorkerRun($state,'upstream-move');
            strictAssertSame(0,$exit,'move exit: '.$output);
            strictAssertSame(array(),$calls,'move never calls blocked daemon');
            strictAssertSame('payload',file_get_contents($multi?$state['destination'].'/item':$state['destination']),'payload moved');
            strictAssertSame(false,file_exists($state['source']),'source removed');
        }finally{strictRemoveTree($state['root']);}
    });
$suite->test('upstream collision check keeps original daemon root',function(){
    $state=moveWorkerFixture(true);
    try{
        mkdir($state['destination'],0700);file_put_contents($state['destination'].'/item','foreign');
        list($exit,$after,$calls,$output)=moveWorkerRun($state,'upstream-check');
        strictAssertSame($state['source'].'/',$output,'original source on collision');
        strictAssertSame(array(),$calls,'no RPC');
        strictAssertSame('foreign',file_get_contents($state['destination'].'/item'),'foreign preserved');
    }finally{strictRemoveTree($state['root']);}
});
exit($suite->run());
