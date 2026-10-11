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
// rtMoveFile() evaluated in a namespace: rename() and touch() resolve to the
// recorders below first, every other call to the real global function.
function upstreamMoveFileCut()
{
    if(!class_exists('UpstreamMoveFileCut\\AutoToolsUpstreamMove',false))
        eval('namespace UpstreamMoveFileCut;'
            .'class LFS { public static function stat($path) { return @\\stat($path); } }'
            .'class Cut { public static $renameFails=false; public static $renameCopies=false; public static $hidden=null; public static $touches=array(); }'
            // PHP's own EXDEV path: copy, unlink the source, report success.
            .'function rename($from,$to) { if(Cut::$renameFails) return false;'
            .'if(Cut::$renameCopies) return \\copy($from,$to) && \\unlink($from); return \\rename($from,$to); }'
            // drvfs over 9p: the just-renamed name answers ENOENT once.
            .'function stat($path) { if($path===Cut::$hidden) { Cut::$hidden=null; return false; } return \\stat($path); }'
            .'function touch($path,$mtime=null,$atime=null) { Cut::$touches[]=$path; return \\touch($path,$mtime,$atime); }'
            .loadClassDefinition(dirname(__DIR__,3).'/plugins/autotools/upstream_move_lib.php','AutoToolsUpstreamMove'));
    \UpstreamMoveFileCut\Cut::$touches=array();
    \UpstreamMoveFileCut\Cut::$renameFails=false;
    \UpstreamMoveFileCut\Cut::$renameCopies=false;
    \UpstreamMoveFileCut\Cut::$hidden=null;
    $root=(getenv('TMPDIR')?:sys_get_temp_dir()).'/upstream-movefile-'.bin2hex(random_bytes(6));
    mkdir($root,0700);
    file_put_contents($root.'/source','payload');
    touch($root.'/source',1000000000,1000000100);
    clearstatcache();
    return $root;
}
// On drvfs over 9p a just-renamed name once answered ENOENT, and PHP's touch()
// then opens it with O_TRUNC. A rename keeps the times, so nothing is touched.
$suite->test('upstream rtMoveFile never touches a name it has just renamed',function(){
    $root=upstreamMoveFileCut();
    try{
        \UpstreamMoveFileCut\Cut::$renameFails=false;
        strictAssertSame(true,\UpstreamMoveFileCut\AutoToolsUpstreamMove::rtMoveFile($root.'/source',$root.'/moved'),'rename succeeds');
        strictAssertSame(array(),\UpstreamMoveFileCut\Cut::$touches,'no touch after a successful rename');
        clearstatcache();
        strictAssertSame('payload',file_get_contents($root.'/moved'),'payload intact');
        strictAssertSame(1000000000,filemtime($root.'/moved'),'rename kept the source mtime');
    }finally{strictRemoveTree($root);}
});
$suite->test('upstream rtMoveFile copy fallback restores the source times',function(){
    $root=upstreamMoveFileCut();
    try{
        \UpstreamMoveFileCut\Cut::$renameFails=true;
        strictAssertSame(true,\UpstreamMoveFileCut\AutoToolsUpstreamMove::rtMoveFile($root.'/source',$root.'/moved'),'copy fallback succeeds');
        strictAssertSame(array($root.'/moved'),\UpstreamMoveFileCut\Cut::$touches,'the copy gets the source times back');
        clearstatcache();
        strictAssertSame('payload',file_get_contents($root.'/moved'),'payload copied');
        strictAssertSame(false,file_exists($root.'/source'),'source removed after the copy');
        strictAssertSame(1000000000,filemtime($root.'/moved'),'copy carries the source mtime');
    }finally{\UpstreamMoveFileCut\Cut::$renameFails=false;strictRemoveTree($root);}
});
$suite->test('upstream rtMoveFile restores the times of a cross-device rename that PHP copied',function(){
    $root=upstreamMoveFileCut();
    try{
        \UpstreamMoveFileCut\Cut::$renameCopies=true;
        strictAssertSame(true,\UpstreamMoveFileCut\AutoToolsUpstreamMove::rtMoveFile($root.'/source',$root.'/moved'),'the copying rename succeeds');
        strictAssertSame(array($root.'/moved'),\UpstreamMoveFileCut\Cut::$touches,'a copy under another inode gets the source times back');
        clearstatcache();
        strictAssertSame('payload',file_get_contents($root.'/moved'),'payload copied');
        strictAssertSame(1000000000,filemtime($root.'/moved'),'the copy carries the source mtime');
    }finally{strictRemoveTree($root);}
});
$suite->test('upstream rtMoveFile never touches a renamed name that answers missing',function(){
    $root=upstreamMoveFileCut();
    try{
        \UpstreamMoveFileCut\Cut::$renameCopies=true;
        \UpstreamMoveFileCut\Cut::$hidden=$root.'/moved';
        strictAssertSame(true,\UpstreamMoveFileCut\AutoToolsUpstreamMove::rtMoveFile($root.'/source',$root.'/moved'),'the rename succeeds');
        strictAssertSame(array(),\UpstreamMoveFileCut\Cut::$touches,'a name that answers missing is never touched');
        strictAssertSame('payload',file_get_contents($root.'/moved'),'payload intact');
    }finally{strictRemoveTree($root);}
});
exit($suite->run());
