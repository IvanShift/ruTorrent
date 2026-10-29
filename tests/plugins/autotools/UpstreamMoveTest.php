<?php
define('MOVE_WORKER_TEST_LIBRARY',true);
require_once __DIR__.'/MoveWorkerTest.php';
require_once testFindRepoRoot().'/plugins/erasedata/filesystem.php';
$suite=new StrictTestSuite();
foreach(array('legacy','native') as $version)
    $suite->test('upstream check hook captures the torrent identity before its source '.$version,function()use($version){
        $state=moveWorkerFixture();
        $state['capabilities']=array('system.listMethods');
        $state['aliasVersion']=$version;
        try {
            list($exit,$after,$calls,$output,$bodies)=moveWorkerRun($state,'hooks');
            strictAssertSame(0,$exit,'upstream hook installation completes');
            strictAssertSame('upstream',$output,'the stock daemon selects the synchronous fallback');
            $wire=html_entity_decode(implode('', $bodies),ENT_QUOTES|ENT_XML1,'UTF-8');
            strictAssertSame(1,preg_match('/check,\$d\.(?:hash|get_hash)=,\$d\.(?:base_path|get_base_path)=/', $wire),
                'the registered callback supplies its hash before any directory setter can run');
            strictAssertSame(1,preg_match('/move,\$d\.(?:hash|get_hash)=,.*?\$d\.(?:directory|get_directory)=,/s', $wire),
                'Move captures the daemon directory after its check has published or retained it');
        } finally { strictRemoveTree($state['root']); }
    });
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

function upstreamAliasFixture($multi)
{
    $state = moveWorkerFixture($multi);
    $raw = $multi ? 'item' : $state['basename'];
    $alias = 'selected серия.bin';
    $string = function ($value) { return strlen($value).':'.$value; };
    if ($multi) {
        rename($state['source'].'/item', $state['source'].'/'.$alias);
        file_put_contents($state['source'].'/'.$raw, 'foreign');
        $info = '5:filesld6:lengthi7e4:pathl'.$string($raw).'e10:path.utf-8l'.$string($alias).'eee'
            .'4:name'.$string($state['name']);
    } else {
        $state['source'] = $state['root'].'/dl/'.$alias;
        rename($state['root'].'/dl/'.$raw, $state['source']);
        file_put_contents($state['root'].'/dl/'.$raw, 'foreign');
        $state['basename'] = $alias;
        $state['name'] = $alias;
        $state['destination'] = $state['root'].'/fin/'.$alias;
        $info = '6:lengthi7e4:name'.$string($raw).'10:name.utf-8'.$string($alias);
    }
    $info .= '12:piece lengthi16384e6:pieces20:'.str_repeat('x', 20);
    file_put_contents($state['root'].'/session/'.$state['hash'].'.torrent', 'd4:infod'.$info.'ee');
    $state['raw_twin'] = ($multi ? $state['source'] : $state['root'].'/dl').'/'.$raw;
    $state['selected_file'] = $multi ? $state['source'].'/'.$alias : $state['source'];
    return $state;
}

$suite->test('upstream single-file move uses the captured daemon basename instead of its raw-name twin',function(){
    $state = upstreamAliasFixture(false);
    try {
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-check');
        strictAssertSame(0,$exit,'selected single-file check completes: '.$output);
        strictAssertSame($state['root'].'/fin/',$output,'the captured single-file path has a usable destination');
        strictAssertSame(array(),$calls,'the synchronous check performs no RPC');
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-move');
        strictAssertSame(0,$exit,'selected single-file move completes: '.$output);
        strictAssertSame(array(),$calls,'the synchronous move performs no RPC');
        strictAssertSame('payload',@file_get_contents($state['destination']),'the daemon-selected file is moved');
        strictAssertSame('foreign',file_get_contents($state['raw_twin']),'the raw-name sibling remains untouched');
        strictAssertSame(false,file_exists($state['source']),'the selected source name is consumed');
    } finally { strictRemoveTree($state['root']); }
});

$suite->test('upstream check retains the original directory when a multi-file alias needs daemon evidence',function(){
    $state = upstreamAliasFixture(true);
    try {
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-check');
        strictAssertSame(0,$exit,'an intentional refusal still answers the synchronous check');
        strictAssertSame($state['source'].'/',$output,'the directory setter receives the original source');
        strictAssertSame(array(),$calls,'the blocked daemon is never queried');
        strictAssertSame('payload',file_get_contents($state['selected_file']),'the selected payload remains at its source');
        strictAssertSame('foreign',file_get_contents($state['raw_twin']),'the raw-name sibling remains at its source');
        strictAssertSame(false,file_exists($state['destination']),'no destination is created during refusal');
        strictAssertTrue(strpos((string)@file_get_contents($state['root'].'/errors.log'),'file-list-needs-daemon')!==false,
            'the operator sees why synchronous Move cannot select a file list');
    } finally { strictRemoveTree($state['root']); }
});

$suite->test('upstream move refuses an unconfirmed multi-file alias before touching either twin',function(){
    $state = upstreamAliasFixture(true);
    try {
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-move');
        strictAssertSame(0,$exit,'the actor records a conservative refusal');
        strictAssertSame(array(),$calls,'the synchronous move performs no RPC');
        strictAssertSame('payload',file_get_contents($state['selected_file']),'the selected payload remains untouched');
        strictAssertSame('foreign',file_get_contents($state['raw_twin']),'the raw-name sibling is never mistaken for payload');
        strictAssertSame(false,file_exists($state['destination']),'refusal creates no destination');
        strictAssertTrue(strpos((string)@file_get_contents($state['root'].'/errors.log'),'file-list-needs-daemon')!==false,
            'the file-list refusal is visible');
    } finally { strictRemoveTree($state['root']); }
});

$suite->test('an old upstream check without a torrent hash preserves the current source',function(){
    $state = moveWorkerFixture(true);
    $state['upstreamCheckWithoutHash'] = true;
    try {
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-check');
        strictAssertSame(0,$exit,'the old capture contract still receives a source directory');
        strictAssertSame($state['source'].'/',$output,'an unattested file list cannot change d.directory_base');
        strictAssertSame(array(),$calls,'the compatibility refusal performs no RPC');
        strictAssertSame('payload',file_get_contents($state['source'].'/item'),'payload remains in place');
        strictAssertTrue(strpos((string)@file_get_contents($state['root'].'/errors.log'),'file-list-needs-daemon')!==false,
            'the old handler receives the file-list admission diagnosis');
    } finally { strictRemoveTree($state['root']); }
});

$suite->test('an old check followed by its old move cannot move data after keeping the daemon source',function(){
    $state = moveWorkerFixture(true);
    $state['upstreamCheckWithoutHash'] = true;
    $state['upstreamMoveWithoutDirectory'] = true;
    try {
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-check');
        strictAssertSame(0,$exit,'the old check remains a controlled refusal');
        strictAssertSame($state['source'].'/',$output,'the old hook keeps the daemon at the original directory');
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-move');
        strictAssertSame(0,$exit,'the old move remains a controlled refusal');
        strictAssertSame('payload',file_get_contents($state['source'].'/item'),
            'a following old move cannot consume files while the daemon still names the source');
        strictAssertSame(false,file_exists($state['destination']),'no destination is published by an old handler');
        strictAssertSame(array(),$calls,'the old handler chain performs no RPC');
    } finally { strictRemoveTree($state['root']); }
});

$suite->test('an old check followed by a new move cannot bypass its retained daemon directory',function(){
    $state = moveWorkerFixture(true);
    $state['upstreamCheckWithoutHash'] = true;
    $state['upstreamDirectory'] = $state['source'];
    try {
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-check');
        strictAssertSame(0,$exit,'the old check remains a controlled refusal');
        strictAssertSame($state['source'].'/',$output,'the check keeps the old directory');
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-move');
        strictAssertSame(0,$exit,'a move without the directory publication is refused');
        strictAssertSame('payload',file_get_contents($state['source'].'/item'),'the refused publication keeps its source bytes');
        strictAssertSame(false,file_exists($state['destination']),'no destination is published');
        strictAssertSame(array(),$calls,'the publication guard performs no RPC');
        strictAssertTrue(strpos((string)@file_get_contents($state['root'].'/errors.log'),'directory-publication-unconfirmed')!==false,
            'the missing directory publication is diagnosed');
    } finally { strictRemoveTree($state['root']); }
});

foreach (array('unreadable','invalid') as $copyState)
$suite->test('upstream check refuses an '.$copyState.' session copy before changing the daemon directory',function()use($copyState){
    $state = moveWorkerFixture(true);
    try {
        $path=$state['root'].'/session/'.$state['hash'].'.torrent';
        if ($copyState==='unreadable') unlink($path);
        else file_put_contents($path,'invalid metainfo');
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-check');
        strictAssertSame(0,$exit,'an unavailable session copy keeps the callback usable');
        strictAssertSame($state['source'].'/',$output,'no directory publication precedes the confirmed file list');
        strictAssertSame(array(),$calls,'session fallback does not query the blocked daemon');
        strictAssertSame('payload',file_get_contents($state['source'].'/item'),'payload remains in place');
        strictAssertTrue(strpos((string)@file_get_contents($state['root'].'/errors.log'),'session-copy-'.$copyState)!==false,
            'the session copy refusal retains its exact cause');
    } finally { strictRemoveTree($state['root']); }
});

$suite->test('upstream check refuses a malformed captured torrent identity before opening a session copy',function(){
    $state = moveWorkerFixture(true);
    $state['hash'] = 'invalid-hash';
    try {
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-check');
        strictAssertSame(0,$exit,'the invalid identity is a controlled refusal');
        strictAssertSame($state['source'].'/',$output,'the captured source is retained');
        strictAssertSame(array(),$calls,'no fallback RPC is attempted');
        strictAssertSame('payload',file_get_contents($state['source'].'/item'),'payload remains untouched');
        strictAssertTrue(strpos((string)@file_get_contents($state['root'].'/errors.log'),'torrent-hash-invalid')!==false,
            'an invalid identity is distinguished from a missing session copy');
    } finally { strictRemoveTree($state['root']); }
});

$suite->test('upstream filter accepts an exact raw label without requiring UTF-8',function(){
    $state = moveWorkerFixture(false);
    $state['label'] = rawurlencode("raw\xFF");
    $state['autoFilter'] = '/^raw/';
    try {
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-move');
        strictAssertSame(0,$exit,'raw-label move completes');
        strictAssertSame('payload',file_get_contents($state['destination']),'the selected payload is moved');
        strictAssertSame(array(),$calls,'moving the raw-label payload performs no RPC');
    } finally { strictRemoveTree($state['root']); }
});

foreach (array('check','move') as $mode)
$suite->test('upstream raw filename exclusion refuses '.$mode.' before publication',function()use($mode){
    $state = moveWorkerFixture(false);
    $raw = "payload\xFF.bin";
    $old = $state['source'];
    $state['basename'] = $raw;
    $state['name'] = $raw;
    $state['source'] = $state['root'].'/dl/'.$raw;
    $state['destination'] = $state['root'].'/fin/'.$raw;
    $state['skipFilter'] = '/\\.bin$/';
    rename($old,$state['source']);
    file_put_contents($state['root'].'/session/'.$state['hash'].'.torrent',
        'd4:infod6:lengthi7e4:name'.strlen($raw).':'.$raw
        .'12:piece lengthi16384e6:pieces20:'.str_repeat('x',20).'ee');
    try {
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-'.$mode);
        strictAssertSame(0,$exit,'raw exclusion remains a controlled refusal');
        if ($mode === 'check')
            strictAssertSame($state['root'].'/dl/',$output,'a skipped filename cannot change the daemon directory');
        strictAssertSame('payload',file_get_contents($state['source']),'the excluded payload is untouched');
        strictAssertSame(false,file_exists($state['destination']),'nothing is published');
        strictAssertSame(array(),$calls,'refusing the Move performs no RPC');
    } finally { strictRemoveTree($state['root']); }
});

foreach (array(false,true) as $multi)
$suite->test('upstream check keeps the captured source after a bootstrap error multi='.(int)$multi,function()use($multi){
    if (PHP_VERSION_ID < 80000) {
        echo "scope - malformed internal-function argument throws TypeError on PHP8\n";
        return;
    }
    $state = moveWorkerFixture($multi);
    $state['autoFilter'] = array('invalid-regex-type');
    try {
        list($exit,$after,$calls,$output) = moveWorkerRun($state,'upstream-check');
        strictAssertSame(1,$exit,'unexpected bootstrap failure is visible');
        $source = $multi ? rtrim($state['source'],'/') : dirname($state['source']);
        strictAssertSame(0,strpos($output,$source),'the stdout fallback names the captured source, not the prepended hash');
        strictAssertTrue(strpos($output,'bootstrap-or-operation-failed')!==false,'the bootstrap error is classified');
        strictAssertSame(array(),$calls,'the bootstrap refusal performs no RPC');
        strictAssertSame('payload',file_get_contents($multi?$state['source'].'/item':$state['source']),'payload remains untouched');
    } finally { strictRemoveTree($state['root']); }
});

foreach (array('array_pop','array_slice','array_shift') as $function)
foreach (array('check','move') as $mode)
$suite->test('upstream '.$mode.' keeps its argument layout with disabled '.$function,function()use($function,$mode){
    $state=moveWorkerFixture(true);
    $state['disabledFunctions']=$function;
    try {
        list($exit,$after,$calls,$output,$bodies,$stdout,$stderr)=moveWorkerRun($state,'upstream-'.$mode);
        strictAssertSame(array(),$calls,'argument failures never query the blocked daemon');
        strictAssertTrue($exit!==0,'an unavailable callback dependency is refused');
        strictAssertSame('',$stdout,'no guessed directory is returned to the native setter');
        strictAssertSame('payload',file_get_contents($state['source'].'/item'),'the original source is retained');
        strictAssertSame(false,file_exists($state['destination']),'no destination is published on refusal');
    } finally { strictRemoveTree($state['root']); }
});

$suite->test('a genuine empty upstream username preserves the captured source layout',function(){
    $state=moveWorkerFixture(true);
    $state['upstreamUser']='';
    try {
        list($exit,$after,$calls,$output,$bodies,$stdout,$stderr)=moveWorkerRun($state,'upstream-check');
        strictAssertSame(0,$exit,'an empty string username remains valid');
        strictAssertTrue(in_array($stdout,array($state['source'].'/',$state['destination'].'/'),true),
            'the profile may choose its source or target, never a shifted relative path');
        strictAssertSame(array(),$calls,'the anonymous callback performs no RPC');
        strictAssertSame('payload',file_get_contents($state['source'].'/item'),'checking does not move payload');
    } finally { strictRemoveTree($state['root']); }
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
// rtDestinationRoot() names a destination that is not there yet from its path
// elements. The name must not depend on the locale: upstream_move.php runs
// under rtorrent, in C when rtorrent runs without LANG, and there PHP 7.4's
// basename() drops the non-ASCII bytes of a path element.
$suite->test('upstream destination root keeps a non-ASCII name that is not there yet',function(){
    if(!class_exists('AutoToolsUpstreamMove',false)){
        require_once testFindRepoRoot().'/php/utility/fileutil.php';
        eval(loadFunctionDefinition(testFindRepoRoot().'/plugins/autotools/util_rt.php','rtRemoveTailSlash'));
        eval(loadClassDefinition(testFindRepoRoot().'/plugins/autotools/upstream_move_lib.php','AutoToolsUpstreamMove'));
    }
    $root=(getenv('TMPDIR')?:sys_get_temp_dir()).'/upstream-root-'.bin2hex(random_bytes(6));
    mkdir($root.'/fin',0700,true);
    $locale=setlocale(LC_CTYPE,'0');
    setlocale(LC_CTYPE,'C');
    try{
        $named=AutoToolsUpstreamMove::rtDestinationRoot($root.'/fin/Сериал/');
        $doubled=AutoToolsUpstreamMove::rtDestinationRoot($root.'/fin/TV//');
    }finally{setlocale(LC_CTYPE,$locale);strictRemoveTree($root);}
    strictAssertSame($root.'/fin/Сериал',$named,'the destination keeps its own name');
    strictAssertSame($root.'/fin/TV',$doubled,'and one that ends in two slashes is named as with one');
});
exit($suite->run());
