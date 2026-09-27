<?php

require_once(__DIR__.'/TestCase.php');

class LegacyConfigTest extends TestCase
{
    private $root;

    public function setUp()
    {
        $this->root = sys_get_temp_dir().'/rut-legacy-config-'.getmypid().'-'.uniqid();
        mkdir($this->root.'/php', 0700, true);
        mkdir($this->root.'/conf', 0700);
        copy(__DIR__.'/../../php/util.php', $this->root.'/php/util.php');
        symlink(__DIR__.'/../../php/utility', $this->root.'/php/utility');
        copy(__DIR__.'/fixtures/config-2026-08.php', $this->root.'/conf/config.php');
    }

    public function tearDown()
    {
        $this->removeTree($this->root);
    }

    private function removeTree($path)
    {
        if(!is_dir($path) || is_link($path)) { unlink($path); return; }
        chmod($path, 0700); // A failing legacy mask may have created a mode-000 directory.
        foreach(new DirectoryIterator($path) as $entry)
            if(!$entry->isDot()) $this->removeTree($entry->getPathname());
        rmdir($path);
    }

    private function probe($mask = '0770')
    {
        $script = 'putenv("RU_LOCALHOSTS=10.9.9.9"); '
            .'unset($_ENV["RU_LOCALHOSTS"]); '
            .'$_ENV["RU_PROFILE_MASK"] = '.var_export($mask, true).'; '
            .'$_ENV["RU_PROFILE_PATH"] = '.var_export($this->root.'/share', true).'; '
            .'$_SERVER["REMOTE_USER"] = "test"; '
            .'require '.var_export($this->root.'/php/util.php', true).'; '
            .'echo json_encode(array("mask" => $profileMask, '
            .'"mode" => @fileperms('.var_export($this->root.'/share/users/test', true).') & 0777, '
            .'"hosts" => $localhosts, '
            .'"local" => User::isLocalMode("10.9.9.9", 5000)));';
        $process = proc_open(array(PHP_BINARY, '-c', __DIR__.'/../php-test.ini',
            '-d', 'variables_order=GPCS', '-d', 'display_errors=stderr', '-r', $script),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, $this->root.'/php');
        if(!is_resource($process)) throw new RuntimeException('legacy config probe did not start');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        return array(proc_close($process), json_decode($output, true), $errors, $output);
    }

    public function testOldPersistedConfigUsesOctalMaskAndEnvironmentHost()
    {
        list($status, $result, $errors, $output) = $this->probe();
        $this->assertEquals(0, $status, 'old config loads without a PHP error: '.$errors);
        $this->assertEquals('', $errors, 'old getenv/$_ENV mismatch makes no warning');
        $this->assertTrue(is_array($result), 'legacy bootstrap returns its result: '.var_export($output, true));
        if(!is_array($result)) return;
        $this->assertEquals(0770, $result['mask'], 'old config mask is normalized to octal integer');
        $this->assertEquals(0770, $result['mode'], 'profile creation receives the octal file mode');
        $this->assertEquals('10.9.9.9', end($result['hosts']), 'environment host is included once');
        $this->assertEquals(true, $result['local'], 'the configured local SCGI host is recognized');
    }


    public function testMalformedOldMaskFallsBackWithAVisibleReason()
    {
        list($status, $result, $errors) = $this->probe('02770');
        $this->assertEquals(0, $status, 'malformed old mask does not crash profile creation');
        $this->assertTrue(strpos($errors, 'RU_PROFILE_MASK/profileMask is invalid') !== false,
            'the fallback names the configuration key: '.$errors);
        $this->assertTrue(is_array($result), 'malformed mask bootstrap returns its result');
        if(!is_array($result)) return;
        $this->assertEquals(0777, $result['mask'], 'malformed old mask uses the documented default');
        $this->assertEquals(0777, $result['mode'], 'profile creation uses the documented default');
    }

    public function testExplicitDecimalStringMaskKeepsItsMeaning()
    {
        // An operator may have compensated for the old decimal string behavior.
        file_put_contents($this->root.'/conf/config.local.php', '<?php $profileMask = "504";');
        list($status, $result, $errors) = $this->probe();
        $this->assertEquals(0, $status, 'old config with a string override loads: '.$errors);
        $this->assertTrue(is_array($result), 'string override bootstrap returns its result');
        if(!is_array($result)) return;
        $this->assertEquals('504', $result['mask'], 'operator string override is not parsed as octal');
        $this->assertEquals(0770, $result['mode'], 'operator string retains its existing decimal mode');
    }

    public function testExplicitOperatorOverridesRemainAuthoritative()
    {
        file_put_contents($this->root.'/conf/config.local.php',
            '<?php $profileMask = 0700; $localhosts = array("operator.example");');
        list($status, $result, $errors, $output) = $this->probe();
        $this->assertEquals(0, $status, 'old config with explicit override loads: '.$errors);
        $this->assertEquals('', $errors, 'explicit override emits no warning');
        $this->assertTrue(is_array($result), 'legacy override bootstrap returns its result: '.var_export($output, true));
        if(!is_array($result)) return;
        $this->assertEquals(0700, $result['mask'], 'explicit integer mask is preserved');
        $this->assertEquals(0700, $result['mode'], 'explicit mask controls profile creation');
        $this->assertEquals(array('operator.example'), $result['hosts'],
            'explicit host list is preserved');
        $this->assertEquals(false, $result['local'], 'environment host cannot override the explicit list');
    }
}
