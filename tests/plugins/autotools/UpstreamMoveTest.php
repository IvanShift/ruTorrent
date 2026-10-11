<?php
define('MOVE_WORKER_TEST_LIBRARY',true);
require_once __DIR__.'/MoveWorkerTest.php';
require_once testFindRepoRoot().'/plugins/erasedata/filesystem.php';
$suite=new StrictTestSuite();
foreach(array(false,true)as$multi)
    $suite->test('upstream synchronous actor moves raw names with zero RPC multi='.(int)$multi,function()use($multi){
        $state=moveWorkerFixture($multi);
        try{
            touch($multi?$state['source'].'/item':$state['source'],1000000000,1000000100);
            list($exit,$after,$calls,$output)=moveWorkerRun($state,'upstream-check');
            strictAssertSame(0,$exit,'check exit: '.$output);
            strictAssertSame($multi?$state['destination'].'/':$state['root'].'/fin/',$output,'destination');
            strictAssertSame(array(),$calls,'check never calls blocked daemon');
            list($exit,$after,$calls,$output)=moveWorkerRun($state,'upstream-move');
            strictAssertSame(0,$exit,'move exit: '.$output);
            strictAssertSame(array(),$calls,'move never calls blocked daemon');
            strictAssertSame('payload',file_get_contents($multi?$state['destination'].'/item':$state['destination']),'payload moved');
            strictAssertSame(1000000000,filemtime($multi?$state['destination'].'/item':$state['destination']),'the synchronous actor preserves mtime');
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
// rtMoveFile() evaluated in a namespace: rename(), stat() and touch() resolve
// to the recorders below, and identity lookup uses the real shared settle.
function upstreamMoveFileCut()
{
    if(!class_exists('UpstreamMoveFileCut\\AutoToolsUpstreamMove',false))
        eval('namespace UpstreamMoveFileCut;'
            .'class LFS { public static function stat($path) { return @\\stat($path); } }'
            .'class Cut { public static $renameFails=false; public static $renameCopies=false; public static $hidden=null; public static $revealAt=2; public static $lookups=0; public static $touches=array();'
            .'public static function visible($path) { if($path!==self::$hidden) return true; self::$lookups++; if(self::$lookups<self::$revealAt) return false; self::$hidden=null; return true; } }'
            // PHP's own EXDEV path: copy, unlink the source, report success.
            .'function rename($from,$to) { if(Cut::$renameFails) return false;'
            .'if(Cut::$renameCopies) return \\copy($from,$to) && \\unlink($from); return \\rename($from,$to); }'
            // drvfs over 9p: the just-renamed name answers ENOENT once.
            .'function stat($path) { return Cut::visible($path) ? \\stat($path) : false; }'
            .'class ErasedataFilesystemOps extends \\ErasedataFilesystemOps { public function entryIdentity($path) { return Cut::visible($path) ? parent::entryIdentity($path) : false; } }'
            .'function touch($path,$mtime=null,$atime=null) { if($path===Cut::$hidden) throw new \\RuntimeException("touch before destination visibility"); Cut::$touches[]=$path; return \\touch($path,$mtime,$atime); }'
            .loadClassDefinition(dirname(__DIR__,3).'/plugins/autotools/upstream_move_lib.php','AutoToolsUpstreamMove'));
    \UpstreamMoveFileCut\Cut::$touches=array();
    \UpstreamMoveFileCut\Cut::$renameFails=false;
    \UpstreamMoveFileCut\Cut::$renameCopies=false;
    \UpstreamMoveFileCut\Cut::$hidden=null;
    \UpstreamMoveFileCut\Cut::$revealAt=2;
    \UpstreamMoveFileCut\Cut::$lookups=0;
    $root=(getenv('TMPDIR')?:sys_get_temp_dir()).'/upstream-movefile-'.bin2hex(random_bytes(6));
    mkdir($root,0700);
    file_put_contents($root.'/source','payload');
    touch($root.'/source',1000000000,1000000100);
    clearstatcache();
    return $root;
}
// On drvfs over 9p a just-renamed name once answered ENOENT, and PHP's touch()
// then opens it with O_TRUNC. A same-filesystem rename keeps the times, so
// nothing is touched.
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
$suite->test('upstream rtMoveFile preserves times through PHP actual cross-device rename',function(){
    $root=upstreamMoveFileCut();
    $destination='/dev/shm/upstream-move-'.bin2hex(random_bytes(6));
    try{
        if(!is_dir('/dev/shm') || stat('/dev/shm')['dev']===stat($root)['dev']) {
            echo "scope - separate tmpfs unavailable\n";
            return;
        }
        mkdir($destination,0700);
        strictAssertSame(true,\UpstreamMoveFileCut\AutoToolsUpstreamMove::rtMoveFile($root.'/source',$destination.'/moved'),'actual PHP EXDEV rename succeeds');
        clearstatcache();
        strictAssertSame(false,file_exists($root.'/source'),'PHP removed the copied source');
        strictAssertSame(1000000000,filemtime($destination.'/moved'),'actual copied rename preserves mtime');
        strictAssertSame(1000000100,fileatime($destination.'/moved'),'actual copied rename preserves atime');
        strictAssertSame('payload',file_get_contents($destination.'/moved'),'actual copy preserves payload');
    }finally{strictRemoveTree($root);strictRemoveTree($destination);}
});
$suite->test('upstream rtMoveFile settles a delayed copied name before restoring its times',function(){
    $root=upstreamMoveFileCut();
    try{
        \UpstreamMoveFileCut\Cut::$renameCopies=true;
        \UpstreamMoveFileCut\Cut::$hidden=$root.'/moved';
        strictAssertSame(true,\UpstreamMoveFileCut\AutoToolsUpstreamMove::rtMoveFile($root.'/source',$root.'/moved'),'the rename succeeds');
        strictAssertSame(2,\UpstreamMoveFileCut\Cut::$lookups,'the destination is confirmed on its second lookup');
        strictAssertSame(array($root.'/moved'),\UpstreamMoveFileCut\Cut::$touches,'only the confirmed copied inode is touched');
        clearstatcache();
        strictAssertSame(1000000000,filemtime($root.'/moved'),'a copying rename restores mtime after a delayed lookup');
        strictAssertSame(1000000100,fileatime($root.'/moved'),'a copying rename restores atime after a delayed lookup');
        strictAssertSame('payload',file_get_contents($root.'/moved'),'payload intact');
    }finally{strictRemoveTree($root);}
});
$suite->test('upstream rtMoveFile never touches a copied name that stays missing past the bound',function(){
    $root=upstreamMoveFileCut();
    try{
        \UpstreamMoveFileCut\Cut::$renameCopies=true;
        \UpstreamMoveFileCut\Cut::$hidden=$root.'/moved';
        \UpstreamMoveFileCut\Cut::$revealAt=ERASEDATA_RENAME_SETTLE_ATTEMPTS+1;
        strictAssertSame(true,\UpstreamMoveFileCut\AutoToolsUpstreamMove::rtMoveFile($root.'/source',$root.'/moved'),'the rename succeeds');
        strictAssertSame(ERASEDATA_RENAME_SETTLE_ATTEMPTS,\UpstreamMoveFileCut\Cut::$lookups,'the visibility check stops at the shared bound');
        strictAssertSame(array(),\UpstreamMoveFileCut\Cut::$touches,'an unconfirmed name is never touched');
        strictAssertSame('payload',file_get_contents($root.'/moved'),'payload intact');
    }finally{strictRemoveTree($root);}
});
exit($suite->run());
