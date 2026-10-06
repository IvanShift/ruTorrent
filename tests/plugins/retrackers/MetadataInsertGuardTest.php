<?php
require_once(__DIR__.'/../../php/TestCase.php');
function getCmd($command) { return $command; }
class rTorrent {
    public static function quoteCommandArg($value) {
        return('"'.str_replace(array('\\','"'), array('\\\\','\\"'), $value).'"');
    }
}
define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__.'/../../../plugins/retrackers/update.php');
class MetadataInsertGuardTest extends TestCase
{
    public function testEmptyAndExcludedPrivateSettingsGateClaimBeforeMutation()
    {
        $select = function ($action, $private) use (&$select) {
            if (strncmp($action, 'branch=', 7) !== 0) return $action;
            $parts = json_decode('[' . substr($action, 7) . ']', true);
            if (!is_array($parts) || count($parts) !== 3) throw new RuntimeException('Invalid insert branch');
            $condition = $parts[0] === 'd.is_private=' && $private;
            return $select($parts[$condition ? 1 : 2], $private);
        };
        foreach (array(array(false,false,true), array(true,true,true),
            array(true,true,false), array(true,false,true)) as $case) {
            list($hasWork,$private,$exclude)=$case;
            $settings=(object)array('list'=>$hasWork ? array(array('http://retracker.invalid/announce')) : array(),
                'todelete'=>array(),'dontAddPrivate'=>$exclude ? 1 : 0);
            $action=retrackersBuildInsertAction('/plugin/run.sh','/usr/bin/php','alice',true,true,$settings);
            $selected=$select($action,$private);
            $wanted=$hasWork && (!$private || !$exclude);
            $this->assertTrue((strpos($selected,'d.retrackers.enqueue_original')!==false)===$wanted &&
                strpos($selected,'execute.throw.bg')===false,
                'only eligible metainfo reaches durable enqueue without launching a PHP process');
        }
    }
    public function testMetadataIsTheFirstConditionBeforeAnyInsertMutation()
    {
        $actions = array(
            retrackersBuildInsertAction('/plugin/run.sh','/usr/bin/php','alice',true),
            retrackersBuildInsertAction('/plugin/run.sh','/usr/bin/php','alice',false),
            retrackersBuildDeferOnlyInsertAction(),
            retrackersBuildSafetyOnlyInsertAction(),
        );
        foreach ($actions as $action) {
            $outer = json_decode('['.substr($action, strlen('branch=')).']', true);
            $this->assertTrue(is_array($outer) && count($outer) === 3 &&
                $outer[0] === 'd.is_meta=' && $outer[1] === 'cat=',
                'metadata exits at the outermost branch before any receipt, claim or worker');
            if (is_array($outer) && isset($outer[2])) {
                $this->assertTrue(strpos($outer[2], 'd.custom=retrackers-recovery') !== false,
                    'ordinary metainfo retains the previous recovery-marker branch');
            }
        }
    }
    public function testDeferredReplayCannotClaimAMetadataDownload()
    {
        $callback=RetrackersLifecycleCallbacks::replayDeferred(str_repeat('a',32),
            str_repeat('b',32),'i',str_repeat('c',64),str_repeat('A',40),
            str_repeat('B',40),'1','handoff','/plugin/run.sh','/usr/bin/php','alice',true);
        $wire=implode('\n',$callback->params());
        $this->assertTrue(strpos($wire,'d.is_meta=') !== false && strpos($wire,'value=0') !== false,
            'native deferred claim is guarded by daemon metadata state at the mutation');
        $this->assertTrue(method_exists('RetrackersLifecycleCallbacks','skipDeferredMetadata'),
            'a deferred metadata record has a bounded non-worker retirement callback');
        if (!method_exists('RetrackersLifecycleCallbacks','skipDeferredMetadata')) return;
        $skip=RetrackersLifecycleCallbacks::skipDeferredMetadata(str_repeat('a',32),
            str_repeat('b',32),'i',str_repeat('c',64),str_repeat('A',40),str_repeat('B',40));
        $wire=implode('\n',$skip->params());
        $this->assertTrue(strpos($wire,'d.is_meta=') !== false && strpos($wire,'value=1') !== false &&
            strpos($wire,'d.retrackers.begin_original') === false && strpos($wire,'execute.') === false,
            'metadata retirement only removes the exact local-id deferred receipt');
    }
    public function testMetadataRpcAcceptsOnlyCanonicalDaemonFlags()
    {
        $this->assertTrue(method_exists('RetrackersLifecycleRpcAdapter','metadataDownload'),
            'the replay reader queries actual daemon d.is_meta before source and receipt work');
        if (!method_exists('RetrackersLifecycleRpcAdapter','metadataDownload')) return;
        foreach (array('0','1','2','01',true,array()) as $value) {
            $adapter=new class($value) extends RetrackersLifecycleRpcAdapter {
                private $reply;
                public function getFamily() { return 2; }
                public function __construct($reply) { $this->reply=$reply; }
                protected function send($payload,$plan,&$failure=null,$historical=false) {
                    if (strpos($payload,'<methodName>d.is_meta</methodName>')===false)
                        throw new RuntimeException('metadata read sent another RPC');
                    if ($plan !== retrackersRestrictedPlanDirectScalar('i8'))
                        throw new RuntimeException('metadata read must use the native integer wire tag');
                    return array('ok'=>true,'family'=>2,'value'=>$this->reply);
                }
            };
            $failure=null;
            $actual=$adapter->metadataDownload(str_repeat('A',40),$failure);
            $valid=is_string($value) && in_array($value,array('0','1'),true);
            $this->assertTrue($valid ? $actual === ($value==='1') :
                $actual===null && $failure==='hook-deferred-replay-pending',
                'only canonical zero/one flags distinguish ordinary and metadata downloads');
        }
    }
    public function testBothDaemonFamiliesDecodeActualMetadataI8Flags()
    {
        foreach (array(1,2) as $family) foreach (array('0','1','2','01','-0','+1',' 1') as $value) {
            $nl=$family===1 ? "\r\n" : '';
            $xml=($family===1 ? '<?xml version="1.0" encoding="UTF-8"?>' : '<?xml version="1.0"?>').$nl.
                '<methodResponse>'.$nl.'<params>'.$nl.'<param><value><i8>'.$value.'</i8></value></param>'.$nl.
                '</params>'.$nl.'</methodResponse>'.$nl;
            $raw="Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: ".strlen($xml)."\r\n\r\n".$xml;
            $adapter=new class($family,$raw) extends RetrackersLifecycleRpcAdapter {
                private $raw;
                public function __construct($family,$raw) { $this->family=$family; $this->raw=$raw; }
                protected function send($payload,$plan,&$failure=null,$historical=false) {
                    return retrackersDecodeRestrictedRawResponse($this->raw,$plan);
                }
            };
            $failure=null; $actual=$adapter->metadataDownload(str_repeat('A',40),$failure);
            $valid=in_array($value,array('0','1'),true);
            $this->assertTrue($valid ? $actual===($value==='1') && $failure===null :
                $actual===null && $failure==='hook-deferred-replay-pending',
                'family '.$family.' uses i8 metadata values and rejects noncanonical flags');
        }
    }

}
