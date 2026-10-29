<?php
require_once __DIR__ . '/../rutracker_check/TestLib.php';
require_once __DIR__ . '/../../php/FakeRtorrentDaemon.php';
require_once __DIR__ . '/MoveDaemonReplies.php';

function moveWorkerFixture($multi = false, $run = array(1,1,1))
{
    $root = sys_get_temp_dir() . '/mw-' . bin2hex(random_bytes(5));
    foreach (array('', '/session', '/dl', '/fin') as $dir) mkdir($root . $dir, 0700);
    $hash = str_repeat('A', 40); $qid = str_repeat('b', 32);
    $name = 'payload "q" \\ back & faultCode ';
    $base = $multi ? 'arbitrary root ' : $name;
    $source = $root . '/dl/' . $base;
    if ($multi) mkdir($source, 0700);
    file_put_contents($multi ? $source . '/item' : $source, 'payload');
    $bstr = function ($v) { return strlen($v) . ':' . $v; };
    $info = ($multi ? '5:filesld6:lengthi7e4:pathl4:itemeee' : '6:lengthi7e')
        . '4:name' . $bstr($name) . '12:piece lengthi16384e6:pieces20:' . str_repeat('x', 20);
    file_put_contents($root . '/session/' . $hash . '.torrent', 'd4:infod' . $info . 'ee');
    $helper = $root . '/rename';
    file_put_contents($helper, "#!/bin/sh\n[ \"\$1\" = --probe ] && exit 0\n"
        . "[ -e \"\$2\" ] || [ -L \"\$2\" ] && exit 1\n"
        . 'printf "%s\\n" "$1 -> $2" >> ' . escapeshellarg($root . '/renames') . "\nexec mv -- \"\$1\" \"\$2\"\n");
    chmod($helper, 0700);
    return array('root'=>$root, 'hash'=>$hash, 'qid'=>$qid, 'retry'=>'', 'token'=>str_repeat('c',32).':0000000000000001',
        'run'=>$run, 'multi'=>$multi, 'basename'=>$base, 'name'=>$name, 'source'=>$source,
        'destination'=>$root.'/fin/'.$base, 'directory'=>$multi ? $source : $root.'/dl',
        'lease'=>'absent', 'queued'=>true, 'mode'=>'S', 'open'=>$run[1], 'active'=>$run[2]);
}

function moveWorkerRun($state, $actor = 'worker')
{
    $root = $state['root']; $repo = testFindRepoRoot();
    $daemon = new FakeRtorrentDaemon(new AutoToolsMoveDaemonReplies($state), $root . '/calls', false,
        array(__DIR__ . '/MoveDaemonReplies.php', __DIR__ . '/../../php/ScgiToolPeer.php'));
    $driver = $root . '/driver.php';
    // The settle pause gives a remote filesystem time to show a renamed name.
    // A local name is there at once, and SettlingRenameFiles reveals one by
    // lookup count, never by time, so a short pause changes no answer and
    // spares each missing or hidden lookup its 50 ms.
    $source = '<?php define("ERASEDATA_RENAME_SETTLE_PAUSE_US", 1000); '
        . '$_SERVER["REMOTE_USER"]="tester"; $_ENV["RU_PROFILE_PATH"]=' . var_export($root.'/profile', true)
        . '; $_ENV["RU_LOG_FILE"]=' . var_export($root.'/errors.log',true) . '; chdir('
        . var_export($repo.'/plugins/autotools',true) . '); require_once "../../conf/config.php"; require_once "../../php/settings.php";'
        . '$r=new ReflectionClass("rTorrentSettings"); $s=$r->newInstanceWithoutConstructor();'
        . '$s->iVersion=0x1019; $s->aliases=array(); $s->linkExist=true; $s->directory=' . var_export($root.'/dl',true)
        . '; $s->session=' . var_export($root.'/session',true) . '; $p=$r->getProperty("theSettings"); $p->setAccessible(true); $p->setValue(null,$s);'
        . '$scgi_host="127.0.0.1"; $scgi_port=' . $daemon->port() . '; $rpcTimeOut=1; $rpcLogCalls=false;'
        . 'require_once "./move_job_lib.php"; $at=new rAutoTools(); $at->enable_move=1; $at->fileop_type="Move"; $at->skip_move_for_files="";'
        . '$at->path_to_finished=' . var_export($root.'/fin',true) . ';'
        . (isset($state['autoFilter']) ? '$at->automove_filter=' . var_export($state['autoFilter'],true) . ';' : '')
        . (isset($state['skipFilter']) ? '$at->skip_move_for_files=' . var_export($state['skipFilter'],true) . ';' : '')
        . '$at->store();'
        . 'class MoveTestFiles extends ErasedataFilesystemOps { public static function renameHelperPath() { return '
        . var_export($root.'/rename',true) . '; }} $p=new ReflectionProperty("AutoToolsMoveJob","files"); $p->setAccessible(true); $p->setValue(null,new MoveTestFiles());'
        // array(pattern, revealAt, foreign): a rename whose new name answers
        // ENOENT for a while; see SettlingRenameFiles.php.
        . (isset($state['settle']) ? 'require ' . var_export(__DIR__ . '/SettlingRenameFiles.php', true)
            . '; SettlingRenameFiles::$helper=' . var_export($root.'/rename', true) . '; $p->setValue(null,new SettlingRenameFiles('
            . var_export($state['settle'][0], true) . ',' . (int) $state['settle'][1] . ','
            . var_export(!empty($state['settle'][2]), true) . ',' . var_export($root.'/settle-lookups.json', true) . '));' : '')
        . '$argv=array("move_worker.php",' . var_export($state['hash'],true) . ',' . var_export($state['qid'],true)
        . ',"tester",' . var_export(str_repeat('d',32),true) . '); require "./move_worker.php";';
    if(isset($state['aliasVersion'])) {
        $versions=$state['aliasVersion']==='legacy'?array('0.9.4'):array('0.9.4','0.10.2','0.16.0','0.16.16','0.16.18');
        $aliases='$s->iVersion=' . ($state['aliasVersion']==='legacy' ? '0x908' : '0x1019') . ';(function(){';
        foreach($versions as$version)$aliases.='require '.var_export($repo.'/php/methods-'.$version.'.php',true).';';
        $aliases.='})->call($s);';
        $source=str_replace('$scgi_host=', $aliases.'$scgi_host=', $source);
    }
    if ($actor === 'notify') {
        $setup = substr($source, 0, strpos($source, 'require_once "./move_job_lib.php"'));
        file_put_contents($root . '/notify-prepend.php', $setup);
        $php = $root . "/php ' notifier";
        file_put_contents($php, "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . " -d auto_prepend_file="
            . escapeshellarg($root . '/notify-prepend.php') . ' "$@"' . "\n");
        chmod($php, 0700);
        $source = substr($source, 0, strpos($source, '$argv=array("move_worker.php"'));
        $source .= '$pathToExternals["php"]=' . var_export($php, true) . '; require "./hook_lib.php";'
            . '$m=new ReflectionMethod("AutoToolsFinishedHook","notifyLater");$m->setAccessible(true);'
            . '$m->invoke(null,' . var_export($state['hash'],true) . ',"Copy","journal-write-failed","tester","");';
    } elseif (strpos($actor, 'upstream-') === 0) {
        $source = substr($source, 0, strpos($source, '$argv=array("move_worker.php"'));
        $args = array('upstream_move.php', substr($actor, 9));
        if ($actor === 'upstream-move' || ($actor === 'upstream-check' && empty($state['upstreamCheckWithoutHash'])))
            $args[] = $state['hash'];
        $args = array_merge($args, array($state['source'], $state['basename'], $state['multi'] ? '1' : '0',
            isset($state['label']) ? $state['label'] : '', $state['name']));
        if ($actor === 'upstream-move' && empty($state['upstreamMoveWithoutDirectory']))
            $args[] = isset($state['upstreamDirectory']) ? $state['upstreamDirectory']
                : ($state['multi'] ? rtrim($state['destination'], '/') : dirname($state['destination']));
        $args[] = isset($state['upstreamUser']) ? $state['upstreamUser'] : 'tester';
        $source .= '$argv=' . var_export($args, true) . '; require "./upstream_move.php";';
    } elseif (strpos($actor, 'hooks') === 0) {
        $source = substr($source, 0, strpos($source, '$argv=array("move_worker.php"'));
        $source .= '$pathToExternals["php"]=' . var_export($root . '/php "quoted" \\ binary',true) . ';';
        if ($actor === 'hooks-disabled') $source .= '$at->enable_move=0;';
        $source .= '$at->setHandlers(); echo $at->moveStatus;';
    } elseif ($actor === 'prepare') {
        $source = substr($source, 0, strpos($source, '$argv=array("move_worker.php"'));
        $source .= '$prepare=new ReflectionMethod("AutoToolsMoveJob","prepare");$prepare->setAccessible(true);'
            . 'echo JSON::encodeBytes($prepare->invoke(null,' . var_export($state['hash'], true) . ','
            . var_export($state['qid'], true) . '));';
    } elseif ($actor === 'file-paths') {
        $source = substr($source, 0, strpos($source, '$argv=array("move_worker.php"'));
        $source .= '$torrent=new Torrent(' . var_export($root.'/session/'.$state['hash'].'.torrent', true)
            . ');echo JSON::encodeBytes(AutoToolsFilePaths::read(' . var_export($state['hash'], true)
            . ',$torrent->info,' . ($state['multi'] ? 'true' : 'false') . ',' . var_export($state['basename'], true) . '));';
    } elseif ($actor === 'inventory-rows') {
        $source = substr($source, 0, strpos($source, '$argv=array("move_worker.php"'));
        $source .= 'echo json_encode(AutoToolsMoveQueue::downloadRows(array("d.name=")));';
    } elseif ($actor === 'terminal-count') {
        $source = substr($source, 0, strpos($source, '$argv=array("move_worker.php"'));
        $source .= 'echo json_encode(AutoToolsMoveQueue::terminalCount("tester"));';
    } elseif ($actor === 'reset-report') {
        $source = substr($source, 0, strpos($source, '$argv=array("move_worker.php"'));
        $source .= '$argv=array("autotools-move-reset.php","--user","tester");require "../../tools/autotools-move-reset.php";';
    } elseif ($actor === 'capability') {
        $source = substr($source, 0, strpos($source, '$argv=array("move_worker.php"'));
        $source .= 'echo rAutoTools::moveMode();';
    } elseif ($actor === 'readiness') {
        $source = substr($source, 0, strpos($source, '$argv=array("move_worker.php"'));
        $source .= '$argv=array("autotools-move-readiness.php","--user","tester");require "../../tools/autotools-move-readiness.php";';
    } elseif ($actor === 'recover') {
        $source = substr($source, 0, strpos($source, '$argv=array("move_worker.php"'));
        $source .= '$argv=array("recover.php","All","tester");require "./recover.php";';
    } elseif ($actor === 'sweep') {
        $source = substr($source, 0, strpos($source, '$argv=array("move_worker.php"'));
        $source .= 'AutoToolsMoveJob::recoverCompleted();';
    }
    $driverArgs=array(PHP_BINARY, '-d', 'display_errors=1');
    if (isset($state['disabledFunctions'])) {
        $driverArgs[] = '-d';
        $driverArgs[] = 'disable_functions=' . $state['disabledFunctions'];
    }
    $driverArgs[] = $driver;
    if(in_array($actor,array('cold-readiness','cold-reset'),true)) {
        $source='<?php $_ENV["RU_PROFILE_PATH"]='.var_export($root.'/cold-profile',true)
            .'; $_ENV["RU_LOG_FILE"]='.var_export($root.'/errors.log',true).'; chdir('
            .var_export($repo.'/plugins/autotools',true).'); require_once "../../conf/config.php";'
            .'$scgi_host="127.0.0.1";$scgi_port='.$daemon->port().';$rpcTimeOut=1;';
        $tool=$actor==='cold-reset'?'reset':'readiness';
        $source.='require "../../tools/autotools-move-'.$tool.'.php";';
        $driverArgs[]='--user';$driverArgs[]=isset($state['toolUser'])?$state['toolUser']:'tester';
    }
    file_put_contents($driver,$source);
    $proc = proc_open($driverArgs, array(0=>array('file','/dev/null','r'),1=>array('pipe','w'),2=>array('pipe','w')), $pipes);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($proc);
    if ($actor === 'notify') {
        $deadline = microtime(true) + 5;
        do { $calls = $daemon->calls(); if (in_array('d.save_full_session', $calls, true)) break; usleep(20000); } while (microtime(true) < $deadline);
    }
    $calls = $daemon->calls(); $bodies = $daemon->bodies(); $daemon->stop();
    return array($exit, (is_file($root.'/native.json') ? JSON::decodeBytes(file_get_contents($root.'/native.json')) : $state),
        $calls, $out.$err, $bodies, $out, $err);
}

function moveWorkerSeed(&$state, $phase, $lease, $moved = false)
{
    $root = $state['root']; $src = $state['source']; $dst = $state['destination'];
    $stat = lstat($src);
    $file = $state['multi'] ? $src . '/item' : $src;
    $fstat = lstat($file);
    $job = array('version'=>2, 'qid'=>$state['qid'], 'kind'=>'Move',
        'hash'=>$state['hash'], 'phase'=>$phase, 'src'=>$src, 'dst'=>$dst,
        'identity'=>$stat['dev'].':'.$stat['ino'], 'source_dir'=>$state['directory'],
        'dest_dir'=>dirname($dst), 'daemon_dir'=>$state['multi'] ? $dst : dirname($dst),
        'multi'=>$state['multi'], 'files'=>array($state['multi'] ? 'item' : $state['basename']),
        'cross'=>false, 'stage'=>dirname($dst).'/.stage', 'quarantine'=>dirname($src).'/.source',
        'token'=>$state['token'], 'finished_root'=>$root.'/fin', 'torrent_name'=>$state['name'],
        'run_state'=>$state['run'][0], 'run_open'=>$state['run'][1], 'run_active'=>$state['run'][2],
        'receipt'=>array('files'=>array(($state['multi'] ? 'item' : '')=>array('id'=>$fstat['dev'].':'.$fstat['ino'],
            'hash'=>hash_file('sha256',$file),'size'=>filesize($file))), 'dirs'=>array()));
    if ($phase === 'aborting') $job['abort_identity']=$job['identity'];
    $state['lease']=$lease;
    if ($moved) { rename($src,$dst); $state['directory']=$job['daemon_dir']; }
    mkdir($root.'/session/.autotools-file-jobs',0700);
    file_put_contents($root.'/session/.autotools-file-jobs/'.$state['hash'].'.move.json',json_encode($job));
    return $job;
}

if (defined('MOVE_WORKER_TEST_LIBRARY')) return;

$suite = new StrictTestSuite();
foreach (array(false,true) as $multi)
    foreach (array(array(1,1,1),array(0,1,0),array(0,0,0)) as $run)
        $suite->test('real worker preserves ' . json_encode($run) . ' multi=' . (int)$multi, function () use ($multi,$run) {
            $state=moveWorkerFixture($multi,$run);
            try {
                touch($multi ? $state['source'].'/item' : $state['source'], 1000000000, 1000000100);
                list($exit,$after,$calls,$error)=moveWorkerRun($state);
                strictAssertSame(0,$exit,'worker completes: '.$error.' '.@file_get_contents($state['root'].'/errors.log'));
                strictAssertSame(false,$after['queued'],'durable queue retired');
                strictAssertSame('absent',$after['lease'],'lease acknowledged');
                strictAssertSame($run[1],$after['open'],'original open intent');
                strictAssertSame($run[2],$after['active'],'original active intent');
                strictAssertSame('payload',file_get_contents($multi?$state['destination'].'/item':$state['destination']),'payload bytes');
                strictAssertSame(1000000000,filemtime($multi?$state['destination'].'/item':$state['destination']),'native same-mount Move preserves mtime');
                strictAssertSame(false,file_exists($state['source']),'source retired');
                strictAssertSame($multi, in_array('d.directory.base.set_if_stop_close_claim',$calls,true),'exact multi root setter');
            } finally { strictRemoveTree($state['root']); }
        });
foreach (array('d.autotools.move_begin','d.directory.set_if_stop_close_claim','d.start_if_stop_close_claim',
    'd.ack_stop_close_claim','d.autotools.move_finish') as $drop)
    $suite->test('lost reply reconciles without repeating mutation: '.$drop,function() use($drop) {
        $state=moveWorkerFixture(); $state['drop']=$drop;
        try {
            list($exit,$after,$calls,$error)=moveWorkerRun($state);
            if ($drop === 'd.autotools.move_begin') {
                strictAssertSame(1,$exit,'unknown begin leaves payload for next native worker');
                strictAssertSame(true,file_exists($state['source']),'no mutation before quiescence reply');
                list($exit,$after,$replay,$error)=moveWorkerRun($after);
                strictAssertSame(true,in_array('d.autotools.move_begin',$replay,true),'recovery attests quiescence');
            }
            strictAssertSame(0,$exit,'lost reply resolves: '.$error.' '.@file_get_contents($state['root'].'/errors.log'));
            strictAssertSame(false,$after['queued'],'queue finished');
            strictAssertSame(1,count(array_keys($calls,$drop,true)),'mutation sent once');
        } finally { strictRemoveTree($state['root']); }
    });
$suite->test('late Stop remains stopped after payload commit',function(){
    $state=moveWorkerFixture();$state['lateStop']=true;
    try { list($exit,$after)=moveWorkerRun($state);strictAssertSame(0,$exit,'complete');strictAssertSame(0,$after['active'],'late Stop preserved'); }
    finally { strictRemoveTree($state['root']); }
});
$suite->test('collision after begin restores paused intent and durably defers',function(){
    $state=moveWorkerFixture(false,array(0,1,0));$state['collision']=true;
    try {
        list($exit,$after,$calls,$error)=moveWorkerRun($state);
        strictAssertSame(0,$exit,'abort complete: '.$error.' '.@file_get_contents($state['root'].'/errors.log'));
        strictAssertSame(true,$after['queued'],'queue retained');strictAssertSame('absent',$after['lease'],'lease released');
        strictAssertSame(1,$after['open'],'pause preserved');strictAssertSame(0,$after['active'],'not started');
        strictAssertSame(true,strpos($after['retry'],':1:')!==false,'one counted retry');
        strictAssertSame('foreign payload',file_get_contents($state['destination']),'destination not overwritten');
        strictAssertSame('payload',file_get_contents($state['source']),'source untouched');
    } finally { strictRemoveTree($state['root']); }
});

foreach (array(false,true) as $multi)
    foreach (array(array('intent','absent',false),array('claimed','active',false),array('receipted','active',false),
        array('moved','active',true),array('published','terminal',true),array('started','done',true),
        array('started','absent',true),array('notice-pending','absent',true),array('notice-attempted','absent',true),array('completed','absent',true)) as $cut)
        $suite->test('durable kill-cut replay '.implode('/',array_slice($cut,0,2)).' multi='.(int)$multi,function() use($cut,$multi){
            $state=moveWorkerFixture($multi);
            try {
                moveWorkerSeed($state,$cut[0],$cut[1],$cut[2]);
                list($exit,$after,$calls,$error)=moveWorkerRun($state);
                strictAssertSame(0,$exit,'replay: '.$error.' '.@file_get_contents($state['root'].'/errors.log'));
                strictAssertSame(false,$after['queued'],'queue completed');
                strictAssertSame('absent',$after['lease'],'lease released');
                strictAssertSame(false,file_exists($state['source']),'source absent');
                strictAssertSame('payload',file_get_contents($multi?$state['destination'].'/item':$state['destination']),'destination bytes');
                $renames=@file($state['root'].'/renames') ?: array();
                strictAssertSame($cut[2]?0:1,count($renames),'never repeat an already published rename');
            } finally { strictRemoveTree($state['root']); }
        });
foreach (array('active','terminal','done','absent') as $lease)
    foreach (array(false,true) as $multi)
        $suite->test('abort crash converges '.$lease.' multi='.(int)$multi,function() use($lease,$multi){
            $state=moveWorkerFixture($multi,array(0,1,0));
            try {
                moveWorkerSeed($state,'aborting',$lease);
                $state['mode']='O';
                // A collision is unrelated destination payload; it must not make abort ambiguous.
                file_put_contents($state['destination'],'foreign');
                list($exit,$after,$calls,$error)=moveWorkerRun($state);
                strictAssertSame(0,$exit,'abort replay: '.$error.' '.@file_get_contents($state['root'].'/errors.log'));
                strictAssertSame('absent',$after['lease'],'no permanent done lease');
                strictAssertSame(true,$after['queued'],'retry still durable');
                strictAssertSame(false,file_exists($state['root'].'/session/.autotools-file-jobs/'.$state['hash'].'.move.json'),'abort journal retired');
                strictAssertSame('foreign',file_get_contents($state['destination']),'collision preserved');
            } finally { strictRemoveTree($state['root']); }
        });
$suite->test('same st_dev EXDEV uses staged copy and quarantine',function(){
    $state=moveWorkerFixture();$root=$state['root'];
    try {
        touch($state['source'],1000000000,1000000100);
        $helper=file_get_contents($root.'/rename');
        $helper=str_replace('[ -e "$2" ]', 'if [ "$1" = '.escapeshellarg($state['source']).' ] && [ "$2" = '.escapeshellarg($state['destination']).' ]; then echo "rename-noreplace-error: errno=18 Invalid cross-device link" >&2; exit 4; fi'."\n".'[ -e "$2" ]',$helper);
        file_put_contents($root.'/rename',$helper);
        list($exit,$after,$calls,$error)=moveWorkerRun($state);
        strictAssertSame(0,$exit,'EXDEV replay: '.$error.' '.@file_get_contents($root.'/errors.log'));
        strictAssertSame(false,$after['queued'],'completed');
        strictAssertSame('payload',file_get_contents($state['destination']),'copied bytes');
        strictAssertSame(1000000000,filemtime($state['destination']),'native cross-mount copy preserves mtime');
        strictAssertSame(false,file_exists($state['source']),'source retired');
        strictAssertSame(2,count(file($root.'/renames')),'publish stage then quarantine source');
    } finally { strictRemoveTree($root); }
});
$suite->test('lost defer reply preserves one abort retry',function(){
    $state=moveWorkerFixture();$state['collision']=true;$state['drop']='d.autotools.move_defer';
    try {
        list($exit,$after,$calls,$error)=moveWorkerRun($state);
        strictAssertSame(0,$exit,'defer resolved: '.$error.' '.@file_get_contents($state['root'].'/errors.log'));
        strictAssertSame(1,count(array_keys($calls,'d.autotools.move_defer',true)),'one CAS mutation');
    } finally { strictRemoveTree($state['root']); }
});
$suite->test('L4b-D6 actual detached notifier reaches durable outcome through quoted PHP path',function(){
    $state=moveWorkerFixture();
    try{
        list($exit,$after,$calls,$output)=moveWorkerRun($state,'notify');
        strictAssertSame(0,$exit,'spawner exit: '.$output);
        strictAssertSame(true,in_array('d.set_custom',$calls,true),'real notify.php sets durable outcome');
        strictAssertSame(true,in_array('d.message.set',$calls,true),'visible message');
        strictAssertSame(true,in_array('d.save_full_session',$calls,true),'session save reached');
    }finally{strictRemoveTree($state['root']);}
});

foreach(array(false,true) as $replace)
    $suite->test('source writer before publication aborts without moving replacement replace='.(int)$replace,function()use($replace){
        $state=moveWorkerFixture();
        try{
            moveWorkerSeed($state,'receipted','active');
            if($replace)rename($state['source'],$state['source'].'.old');
            file_put_contents($state['source'],'new writer bytes');
            list($exit,$after,$calls,$output)=moveWorkerRun($state);
            strictAssertSame(0,$exit,'abort complete: '.$output.' '.@file_get_contents($state['root'].'/errors.log'));
            strictAssertSame('absent',$after['lease'],'released');
            strictAssertSame(false,file_exists($state['destination']),'no rename of changed source');
            strictAssertSame('new writer bytes',file_get_contents($state['source']),'replacement preserved');
        }finally{strictRemoveTree($state['root']);}
    });
foreach (array('probe', 'publication') as $failure)
    $suite->test('M-R6 classified '.$failure.' refusal retains path and errno', function () use ($failure) {
        $state=moveWorkerFixture();
        $helper="#!/bin/sh\n";
        if($failure==='publication')$helper.='[ "$1" = --probe ] && exit 0'."\n";
        $helper.="printf '%s\\n' 'rename-noreplace-error: errno=13 Permission denied' >&2\nexit 4\n";
        file_put_contents($state['root'].'/rename',$helper);
        try {
            list($exit,$after,$calls,$output)=moveWorkerRun($state);
            strictAssertSame(0,$exit,'durably deferred: '.$output);
            $log=file_get_contents($state['root'].'/errors.log');
            strictAssertSame(true,strpos($log,$state['hash'])!==false,'hash named');
            strictAssertSame(true,strpos($log,'errno=13')!==false,'classified errno retained');
            strictAssertSame(true,strpos($log,$state['root'].'/dl')!==false,'failed parent or source path retained');
            strictAssertSame('payload',file_get_contents($state['source']),'payload remains in place');
        } finally { strictRemoveTree($state['root']); }
    });
$suite->test('L4b-D2 foreign journal Q is visible and counted without adoption',function(){
    $state=moveWorkerFixture();
    try {
        moveWorkerSeed($state,'intent','absent');
        $path=$state['root'].'/session/.autotools-file-jobs/'.$state['hash'].'.move.json';
        $job=json_decode(file_get_contents($path),true);$job['qid']=str_repeat('e',32);
        file_put_contents($path,json_encode($job));$before=file_get_contents($path);
        list($exit,$after,$calls,$output)=moveWorkerRun($state);
        strictAssertSame(0,$exit,'foreign journal defers durably: '.$output);
        strictAssertSame($before,file_get_contents($path),'foreign journal remains exact');
        strictAssertSame(true,strpos($after['retry'],':1:')!==false,'one counted preflight retry');
        strictAssertSame(false,in_array('d.autotools.move_begin',$calls,true),'no new lease acquired');
        strictAssertSame(true,in_array('d.set_custom',$calls,true),'visible persistent refusal written');
    } finally { strictRemoveTree($state['root']); }
});
$suite->test('L4b-D2 changed intent source retires its stale payload plan',function(){
    $state=moveWorkerFixture();
    try {
        moveWorkerSeed($state,'intent','absent');
        rename($state['source'],$state['source'].'.old');file_put_contents($state['source'],'replacement');
        list($exit,$after,$calls,$output)=moveWorkerRun($state);
        strictAssertSame(0,$exit,'stale intent defers: '.$output);
        strictAssertSame(false,is_file($state['root'].'/session/.autotools-file-jobs/'.$state['hash'].'.move.json'),'stale plan retired for fresh planning');
        strictAssertSame(true,strpos($after['retry'],':1:')!==false,'one counted preflight retry');
        strictAssertSame(false,in_array('d.autotools.move_begin',$calls,true),'no capture of replacement');
        strictAssertSame('replacement',file_get_contents($state['source']),'replacement untouched');
    } finally { strictRemoveTree($state['root']); }
});
$suite->test('R5-LAB-B-2 reoccupied source retains captured obligation and reports failed worker',function(){
    $state=moveWorkerFixture();
    try {
        moveWorkerSeed($state,'moved','active',true);file_put_contents($state['source'],'new tenant');
        list($exit,$after,$calls,$output)=moveWorkerRun($state);
        strictAssertSame(1,$exit,'native watcher must count this ambiguous worker exit');
        strictAssertSame(true,$after['queued'],'durable queue retained');
        strictAssertSame('active',$after['lease'],'captured obligation retained');
        strictAssertSame('new tenant',file_get_contents($state['source']),'new tenant untouched');
        strictAssertSame('payload',file_get_contents($state['destination']),'published original untouched');
        strictAssertSame(true,is_file($state['root'].'/session/.autotools-file-jobs/'.$state['hash'].'.move.json'),'journal names retained obligation');
        strictAssertSame(false,in_array('d.ack_stop_close_claim',$calls,true),'ambiguous lease never acknowledged');
        strictAssertSame(true,strpos(file_get_contents($state['root'].'/errors.log'),'payload-identity-ambiguous')!==false,'classified worker failure names ambiguity');
    } finally { strictRemoveTree($state['root']); }
});
foreach (array('version'=>999, 'qid'=>'invalid', 'kind'=>'Other', 'phase'=>'unknown', 'quarantine_confirmed'=>'true') as $field=>$value)
    $suite->test('unsupported current journal '.$field.' stays held without lease mutation',function()use($field,$value){
        $state=moveWorkerFixture();$job=moveWorkerSeed($state,'intent','absent');$job[$field]=$value;
        $path=$state['root'].'/session/.autotools-file-jobs/'.$state['hash'].'.move.json';
        file_put_contents($path,json_encode($job));$before=file_get_contents($path);
        try {
            list($exit,$after,$calls,$output)=moveWorkerRun($state);
            strictAssertSame(1,$exit,'unsupported journal visibly holds worker');
            strictAssertSame($before,file_get_contents($path),'journal preserved exactly');
            strictAssertSame(false,in_array('d.autotools.move_begin',$calls,true),'no lease mutation');
            strictAssertSame(true,strpos(file_get_contents($state['root'].'/errors.log'),'journal-malformed')!==false,'classified hold');
        } finally {strictRemoveTree($state['root']);}
    });
exit($suite->run());
