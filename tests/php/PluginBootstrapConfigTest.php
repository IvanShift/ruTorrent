<?php
require_once __DIR__.'/TestCase.php';

class PluginBootstrapConfigTest extends TestCase
{
    private $root;
    public function setUp()
    {
        $this->root = sys_get_temp_dir().'/rut-bootstrap-config-'.getmypid().'-'.uniqid();
        foreach(array('php/utility', 'conf') as $directory)
            mkdir($this->root.'/'.$directory, 0700, true);
        $repo = dirname(__DIR__, 2);
        foreach(array('php/getplugins.php', 'php/environment.php', 'php/utility/requests.php',
            'php/utility/urlhost.php', 'conf/config.php') as $file)
            copy($repo.'/'.$file, $this->root.'/'.$file);
        // This is the first dependency after the real gate. It proves bootstrap
        // was admitted without running plugins, profiles or daemon requests.
        file_put_contents($this->root.'/php/which.php', '<?php echo "BOOTSTRAP_ALLOWED"; exit;');
    }
    public function tearDown() { $this->removeTree($this->root); }
    private function removeTree($path)
    {
        if(!is_dir($path) || is_link($path)) { unlink($path); return; }
        foreach(new DirectoryIterator($path) as $entry)
            if(!$entry->isDot()) $this->removeTree($entry->getPathname());
        rmdir($path);
    }
    private function probe($source, $environment = array())
    {
        $server = array('REQUEST_METHOD'=>'GET', 'HTTP_HOST'=>'backend:8080',
            'REQUEST_SCHEME'=>'http', 'HTTP_ORIGIN'=>$source,
            'HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest', 'HTTP_SEC_FETCH_SITE'=>'same-origin');
        $code = '$_SERVER = '.var_export($server, true).'; require '
            .var_export($this->root.'/php/getplugins.php', true).';';
        $process = proc_open(array(PHP_BINARY, '-d', 'variables_order=GPCS', '-r', $code),
            array(1=>array('pipe','w'), 2=>array('pipe','w')), $pipes, $this->root.'/php', $environment);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertEquals(0, proc_close($process), 'real bootstrap entrypoint exits cleanly: '.$err);
        return $out;
    }
    public function testDefaultSameOriginBootstrapAndForeignRefusal()
    {
        $this->assertEquals('BOOTSTRAP_ALLOWED', $this->probe('http://backend:8080'),
            'default same-origin bootstrap remains available');
        $this->assertEquals('Forbidden', $this->probe('https://foreign.example'),
            'foreign bootstrap stops before the first plugin dependency');
    }
    public function testProcessOriginGrantPrecedesBootstrapGuard()
    {
        $this->assertEquals('BOOTSTRAP_ALLOWED', $this->probe('https://panel.example:8443',
            array('RU_TRUSTED_ORIGINS'=>'https://panel.example:8443')),
            'server origin grant reaches actual GET entrypoint before its gate');
    }
    public function testLocalConfigOriginGrantPrecedesBootstrapGuard()
    {
        file_put_contents($this->root.'/conf/config.local.php',
            '<?php $trustedOrigins = array("https://panel.example:8443");');
        $this->assertEquals('BOOTSTRAP_ALLOWED', $this->probe('https://panel.example:8443'),
            'operator origin grant reaches actual GET entrypoint before its gate');
    }
    public function testServerOriginGrantOverridesLocalConfig()
    {
        file_put_contents($this->root.'/conf/config.local.php',
            '<?php $trustedOrigins = array("https://local.example");');
        $environment = array('RU_TRUSTED_ORIGINS'=>'https://panel.example:8443');
        $this->assertEquals('BOOTSTRAP_ALLOWED', $this->probe('https://panel.example:8443', $environment),
            'server policy authorizes its configured public origin');
        $this->assertEquals('Forbidden', $this->probe('https://local.example', $environment),
            'local config cannot widen authoritative process policy');
    }
}
