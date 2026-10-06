<?php
require_once __DIR__.'/TestCase.php';

class EnvironmentPropagationTest extends TestCase
{
    public function testOldConfigGetsAllProcessSettingsAtBothIndependentEntries()
    {
        $repo = dirname(__DIR__,2);
        $root = sys_get_temp_dir().'/env-entry-'.uniqid();
        mkdir($root.'/php',0700,true); mkdir($root.'/conf',0700,true);
        $values = array('RU_LOCALHOSTS'=>'127.0.0.1', 'RU_LOG_FILE'=>'', 'RU_TOP_DIR'=>'/data',
            'RU_SCGI_HOST'=>'unix:///absent-test.sock', 'RU_SCGI_PORT'=>'0',
            'RU_PROFILE_PATH'=>'/profile', 'RU_PROFILE_MASK'=>'0770', 'RU_TEMP_DIRECTORY'=>'/tmp');
        copy($repo.'/php/environment.php',$root.'/php/environment.php');
        file_put_contents($root.'/conf/config.php', '<?php echo "ENV_RESULT=".json_encode($_ENV); exit;');
        try {
            foreach(array('rpc2.php','env_check.php') as $entry) {
                copy($repo.'/'.$entry,$root.'/'.$entry);
                $code = '$_ENV=array(); putenv("RU_LOG_FILE="); $_SERVER["RUTORRENT_XMLRPC_ENDPOINT"]="on"; require '
                    .var_export($root.'/'.$entry,true).';';
                $process = proc_open(array(PHP_BINARY,'-d','variables_order=GPCS','-r',$code),
                    array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes,null,$values);
                $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
                $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
                $this->assertEquals(0,proc_close($process),'entry reaches persisted config: '.$entry.' '.$err);
                $offset = strpos($out,'ENV_RESULT=');
                $actual = $offset === false ? null : json_decode(substr($out,$offset+11),true);
                foreach($values as $key=>$value)
                    $this->assertTrue(is_array($actual) && array_key_exists($key,$actual) && $actual[$key]===$value,
                        $entry.' propagates process '.$key.' including explicit empty values');
            }
        } finally {
            foreach(array('rpc2.php','env_check.php','php/environment.php','conf/config.php') as $file)
                if(is_file($root.'/'.$file)) unlink($root.'/'.$file);
            rmdir($root.'/php'); rmdir($root.'/conf'); rmdir($root);
        }
    }
}
