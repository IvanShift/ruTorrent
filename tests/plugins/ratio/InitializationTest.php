<?php

require_once(__DIR__.'/../../php/TestCase.php');
require_once(__DIR__.'/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__.'/../../../php/rtorrent.php');
require_once(__DIR__.'/../../../plugins/ratio/ratio.php');

class RatioInitializationTest extends TestCase
{
    private $base;
    private $daemon;
    private $property;
    private $previousSettings;
    private $globalsBefore = array();

    public function setUp()
    {
        $this->base = sys_get_temp_dir().'/ratio-init-'.bin2hex(random_bytes(4));
        mkdir($this->base, 0700);
        $this->property = self::makeAccessible(new ReflectionProperty('rTorrentSettings', 'theSettings'));
        $this->previousSettings = $this->property->getValue();
        $settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
        $settings->iVersion = 0x1019;
        $settings->aliases = array();
        $this->property->setValue(null, $settings);
        foreach(array('scgi_host', 'scgi_port', 'rpcTimeOut', 'rpcLogCalls', 'rpcLogFaults', 'log_file') as $name)
            $this->globalsBefore[$name] = $GLOBALS[$name] ?? null;
        $GLOBALS['scgi_host'] = '127.0.0.1';
        $GLOBALS['rpcTimeOut'] = 2;
        $GLOBALS['rpcLogCalls'] = false;
        $GLOBALS['rpcLogFaults'] = true;
        $GLOBALS['log_file'] = $this->base.'/errors.log';
    }

    public function tearDown()
    {
        if($this->daemon !== null) $this->daemon->stop();
        $this->daemon = null;
        $this->property->setValue(null, $this->previousSettings);
        foreach($this->globalsBefore as $name => $value)
        {
            if($value === null) unset($GLOBALS[$name]);
            else $GLOBALS[$name] = $value;
        }
        foreach(glob($this->base.'/*') ?: array() as $file) unlink($file);
        rmdir($this->base);
    }

    public function testConcurrentViewInsertIsExpectedAndKeepsTheRatioCallback()
    {
        $this->daemon = new FakeRtorrentDaemon(array(array(),
            new FakeRtorrentFault(-501, 'View with same name already inserted.'), array(0)), $this->base.'/calls');
        $GLOBALS['scgi_port'] = $this->daemon->port();
        $ratio = new rRatio();
        $ratio->fillArray();
        $ratio->default = 2;
        $this->assertTrue($ratio->flush(), 'a racing initializer does not refuse a valid ratio setup');
        $calls = $this->daemon->calls();
        $this->assertTrue(in_array('group.insert_persistent_view', $calls, true), 'the stale view.list actually triggers an insert');
        $this->assertSame('system.method.set_key', end($calls), 'registration continues through the intended insertion callback');
        $body = implode("\n", $this->daemon->bodies());
        $this->assertTrue(strpos($body, 'view.set_visible=rat_1') !== false, 'the callback preserves the selected default group');
        $log = is_file($GLOBALS['log_file']) ? file_get_contents($GLOBALS['log_file']) : '';
        $this->assertSame('', $log, 'an expected duplicate-view refusal emits no raw fault transcript');
    }

    public function testThrottleRuleUsesOneQuotedAdditionArgument()
    {
        $views = array();
        for($i = 0; $i < MAX_RATIO; $i++) $views[] = 'rat_'.$i;
        $this->daemon = new FakeRtorrentDaemon(array($views, array(0)), $this->base.'/calls');
        $GLOBALS['scgi_port'] = $this->daemon->port();
        $ratio = new rRatio();
        $ratio->fillArray();
        $ratio->rat[0]['action'] = RAT_FIRSTTHROTTLE + 1;
        $this->assertTrue($ratio->flush(), 'the throttle ratio rule is registered');
        $body = implode("\n", $this->daemon->bodies());
        $this->assertTrue(strpos($body, '$d.set_throttle_name="thr_1"') !== false,
            'the throttle value uses the shared quoted addition contract');
    }
}
