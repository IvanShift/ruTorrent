<?php
define('MOVE_WORKER_TEST_LIBRARY', true);
require_once __DIR__ . '/MoveWorkerTest.php';
require_once __DIR__ . '/../../php/ScgiToolPeer.php';
$suite = new StrictTestSuite();
$suite->test('literal wildcard path sweeps only its own completed journal', function () {
    $state=moveWorkerFixture(); $old=$state['root']; $new=$old.'[x]*'; rename($old,$new);
    foreach(array('root','source','destination','directory') as $key) $state[$key]=str_replace($old,$new,$state[$key]);
    $sibling=$old.'xother'; mkdir($sibling.'/session/.autotools-file-jobs',0700,true);
    $foreign=$sibling.'/session/.autotools-file-jobs/'.$state['hash'].'.move.json'; file_put_contents($foreign,'foreign');
    try {
        moveWorkerSeed($state,'completed','absent',true); $state['queued']=false;
        list($exit,$after,$calls,$output)=moveWorkerRun($state,'sweep');
        strictAssertSame(0,$exit,'literal sweep completes: '.$output);
        strictAssertSame(false,file_exists($new.'/session/.autotools-file-jobs/'.$state['hash'].'.move.json'),'own journal retired');
        strictAssertSame('foreign',file_get_contents($foreign),'sibling untouched');
    } finally { strictRemoveTree($new); strictRemoveTree($sibling); }
});
$suite->test('M-R5 corrupt Move does not starve completed Move or Copy recovery',function(){
    $state=moveWorkerFixture();moveWorkerSeed($state,'completed','absent',true);$state['queued']=false;
    $root=$state['root'].'/session/.autotools-file-jobs';
    $bad=$root.'/'.str_repeat('0',40).'.move.json';file_put_contents($bad,'{bad');
    $copy=$state['root'].'/fin/copied';file_put_contents($copy,'copy payload');$stat=lstat($copy);
    $id=str_repeat('e',24);$receipt=$root.'/'.$id.'.nonmove.json';
    file_put_contents($receipt,json_encode(array('id'=>$id,'phase'=>'committed','op'=>'Copy',
        'src'=>$state['root'].'/dl','dst'=>$state['root'].'/fin','files'=>array('copied'),
        'old'=>array(null),'new'=>array(array($stat['dev'],$stat['ino'],$stat['mode'] & 0170000)))));
    try{
        list($exit,$after,$calls,$output)=moveWorkerRun($state,'recover');
        strictAssertSame(0,$exit,'scheduled recovery completes: '.$output);
        strictAssertSame('{bad',file_get_contents($bad),'corrupt record remains named and held');
        strictAssertSame(false,file_exists($root.'/'.$state['hash'].'.move.json'),'unrelated completed Move retires');
        strictAssertSame(false,file_exists($receipt),'unrelated committed Copy retires');
        strictAssertSame('copy payload',file_get_contents($copy),'Copy payload remains exact');
        strictAssertSame(true,strpos(file_get_contents($state['root'].'/errors.log'),'completed recovery held:')!==false,'sweep refusal visible');
    }finally{strictRemoveTree($state['root']);}
});
foreach(array(false,true)as$pending)
    $suite->test('Move inventory includes native queue absent from main '.(int)$pending,function()use($pending){
        $state=moveWorkerFixture();$state['queued']=$pending;
        if($pending){mkdir($state['root'].'/session/.rutorrent-stop-intents',0700);
            file_put_contents($state['root'].'/session/.rutorrent-stop-intents/'.$state['hash'].'.move',
                'm1:'.hash('sha256','tester').':'.$state['qid'].':'.$state['hash'].':'.time().":\n");
            chmod($state['root'].'/session/.rutorrent-stop-intents/'.$state['hash'].'.move',0600);}
        try{
            list($exit,$after,$calls,$output,$bodies)=moveWorkerRun($state,'readiness');
            strictAssertSame($pending?1:0,$exit,'inventory status: '.$output);
            $report=json_decode($output,true);strictAssertSame(!$pending,$report['clear'],'clear only without obligation');
            strictAssertSame($pending?1:0,count($report['nativeRecords']),'saved native queue inventoried');
            strictAssertSame($pending?1:0,count($report['native']),'native queried despite absent main');
            strictAssertSame(false,is_dir($state['root'].'/session/.autotools-file-jobs'),'read-only inventory creates no journal');
        }finally{strictRemoveTree($state['root']);}
    });
foreach(array('cold-readiness','cold-reset')as$actor)
  foreach(array('tester','')as$user)
    $suite->test('M-R8 cold-cache exact CLI remains read only '.$actor.' user='.$user,function()use($actor,$user){
        $state=moveWorkerFixture();$state['queued']=false;$state['toolUser']=$user;
        try {
            list($exit,$after,$calls,$output)=moveWorkerRun($state,$actor);
            strictAssertSame(0,$exit,'cold read-only CLI completes: '.$output);
            strictAssertSame(true,in_array('system.client_version',$calls,true),'cold settings obtain exercised');
            foreach($calls as$call)strictAssertSame(false,
                strpos($call,'.set')!==false||strpos($call,'execute')!==false||strpos($call,'save')!==false,
                'no daemon mutation during read-only bootstrap: '.$call);
            strictAssertSame(false,is_dir($state['root'].'/session/.autotools-file-jobs'),'no journal creation');
            strictAssertSame(false,is_dir($state['root'].'/cold-profile'),'no shared or per-user profile creation');
        }finally{strictRemoveTree($state['root']);}
    });

foreach (array('unsupported','hidden-unsupported','current-journal','nonmove-job','nonmove-request','symlink','directory','unreadable','untrusted-parent','invalid-utf8') as $kind)
    $suite->test('current inventory document classification '.$kind, function () use ($kind) {
        $state=moveWorkerFixture(); $state['queued']=false;
        $root=$state['root'].'/session/.autotools-file-jobs'; mkdir($root,0700);
        $name=str_repeat('e',24).($kind==='nonmove-request'?'.nonmove-request.json':'.nonmove.json');
        if ($kind==='unsupported') $name=$state['hash'].'.pending.json';
        if ($kind==='hidden-unsupported') $name='.pending.json';
        if ($kind==='current-journal') $name=$state['hash'].'.move.json';
        if ($kind==='invalid-utf8') $name="bad-\xff.json";
        $path=$root.'/'.$name;
        if ($kind==='symlink') symlink($state['source'],$path);
        elseif ($kind==='directory') mkdir($path,0700);
        else { file_put_contents($path,'{}'); chmod($path,0600); }
        if ($kind==='unreadable') chmod($path,0000);
        if ($kind==='untrusted-parent') chmod($root,0777);
        try {
            list($exit,$after,$calls,$output,$bodies)=moveWorkerRun($state,'readiness');
            $expected=in_array($kind,array('nonmove-job','nonmove-request'),true)?0:(in_array($kind,array('unsupported','hidden-unsupported','current-journal'),true)?1:2);
            strictAssertSame($expected,$exit,'visible inventory classification: '.$output);
            if (in_array($kind,array('unsupported','hidden-unsupported'),true)) strictAssertSame(array($path),json_decode($output,true)['unsupportedDocuments'],'exact unknown path named');
            if ($kind==='invalid-utf8') strictAssertSame(true,strpos($output,'report-encoding-failed')!==false,'encoding failure classified');
            strictAssertSame(true,file_exists($path)||is_link($path),'inventory never deletes documents');
        } finally { chmod($root,0700); if (is_file($path)&&!is_link($path)) chmod($path,0600); strictRemoveTree($state['root']); }
    });
foreach (array('known','unknown','empty','other','malformed','lost') as $kind)
    $suite->test('current inventory custom namespace '.$kind, function () use ($kind) {
        $state=moveWorkerFixture(); $state['queued']=false; $state['inventoryLoaded']=true;
        $state['customItems']=array();
        if ($kind==='known') foreach (array('x-autotools-nonmove-job','x-autotools-nonmove-source','x-autotools-nonmove-target','x-autotools-outcome') as $key) $state['customItems'][$key]='retained nonempty value';
        elseif ($kind==='unknown') $state['customItems']['x-autotools-nonmove-unrecognized']='opaque';
        elseif ($kind==='empty') $state['customItems']['x-autotools-unrecognized']='';
        elseif ($kind==='other') $state['customItems']['other-plugin']='opaque';
        elseif ($kind==='malformed') $state['customReply']='<array><data></data></array>';
        elseif ($kind==='lost') $state['customReply']=null;
        try {
            list($exit,$after,$calls,$output,$bodies)=moveWorkerRun($state,'readiness');
            $expected=$kind==='unknown'?1:(in_array($kind,array('malformed','lost'),true)?2:0);
            strictAssertSame($expected,$exit,'namespace classification: '.$output);
            if ($kind==='unknown') strictAssertSame(array(array('hash'=>$state['hash'],'key'=>'x-autotools-nonmove-unrecognized')),
                json_decode($output,true)['unsupportedMetadata'],'only exact hash/key shown');
            strictAssertSame(1,count(array_keys($calls,'d.custom.items',true)),'one direct custom map snapshot');
            foreach ($bodies as $body) if (strpos($body,'<methodName>d.custom.items</methodName>')!==false)
                strictAssertSame(array($state['hash']),ScgiToolPeer::params($body),'native custom getter receives exactly H and no trailing argument');
        } finally { strictRemoveTree($state['root']); }
    });
exit($suite->run());
