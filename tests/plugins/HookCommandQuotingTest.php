<?php

require_once(__DIR__.'/../php/TestCase.php');
require_once(__DIR__.'/../php/FakeRtorrentDaemon.php');
require_once(__DIR__.'/../../php/rtorrent.php');
require_once(__DIR__.'/../../plugins/extratio/rules.php');
require_once(__DIR__.'/../../plugins/unpack/unpack.php');
require_once(__DIR__.'/../../plugins/xmpp/xmpp.php');
require_once(__DIR__.'/../../plugins/history/history.php');
require_once(__DIR__.'/../../plugins/ratio/ratio.php');

/** The registered hook commands, captured at the real XML-RPC boundary. */
class HookCommandQuotingTest extends TestCase
{
    private $daemon;
    private $base;
    private $settingsProperty;
    private $previousSettings;
    private $globalsBefore = array();

    public function setUp()
    {
        $this->base = sys_get_temp_dir().'/hook-quote-'.bin2hex(random_bytes(4));
        mkdir($this->base, 0700, true);
        $this->settingsProperty = self::makeAccessible(new ReflectionProperty('rTorrentSettings', 'theSettings'));
        $this->previousSettings = $this->settingsProperty->getValue();
        $settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
        $settings->iVersion = 0x1019;
        $settings->aliases = array();
        $this->settingsProperty->setValue(null, $settings);
        foreach(array('rootPath', 'pathToExternals', 'scgi_host', 'scgi_port', 'rpcTimeOut') as $name)
            $this->globalsBefore[$name] = $GLOBALS[$name] ?? null;
        $GLOBALS['rootPath'] = '/app "quoted",semi; apost\'rophe \\root';
        $GLOBALS['pathToExternals']['php'] = '/bin/php "quoted",semi; apost\'rophe \\exe';
        $GLOBALS['rpcTimeOut'] = 2;
    }

    public function tearDown()
    {
        if($this->daemon !== null) $this->daemon->stop();
        $this->daemon = null;
        foreach($this->globalsBefore as $name => $value)
        {
            if($value === null) unset($GLOBALS[$name]);
            else $GLOBALS[$name] = $value;
        }
        $this->settingsProperty->setValue(null, $this->previousSettings);
        foreach(glob($this->base.'/*') ?: array() as $file) unlink($file);
        rmdir($this->base);
    }

    private function commands($plugin)
    {
        $this->daemon = new FakeRtorrentDaemon(array(array(0, 0, 0)), $this->base.'/calls');
        $GLOBALS['scgi_host'] = '127.0.0.1';
        $GLOBALS['scgi_port'] = $this->daemon->port();
        $this->assertTrue($plugin->setHandlers(), 'the real plugin hook registration succeeds');
        $commands = array();
        foreach($this->daemon->bodies() as $body)
        {
            preg_match_all('~<string>(.*?)</string>~s', $body, $matches);
            foreach($matches[1] as $value)
            {
                $value = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
                if(strpos($value, 'execute') !== false) $commands[] = $value;
            }
        }
        $this->assertTrue(count($commands) > 0, 'the captured registration contains executable hooks');
        return $commands;
    }

    private function arguments($text)
    {
        $method = self::makeAccessible(new ReflectionMethod('rTorrent', 'additionArguments'));
        return $method->invoke(null, $text);
    }

    private function assertArgv($command, $helper)
    {
        $this->assertTrue(preg_match('~execute(?:[._]nothrow)?=\{sh,-c,.*? &,((?:.|\n)*)\}$~', $command, $match) === 1,
            'the asynchronous hook has the expected argv boundary: '.$command);
        if(!isset($match[1])) return;
        $args = $this->arguments($match[1]);
        $this->assertTrue(is_array($args), 'the hook argv is one well-formed command argument list');
        if(!is_array($args)) return;
        $this->assertSame(Utility::getPHP(), $args[0], 'the configured PHP executable stays one exact argument');
        $this->assertSame($helper, $args[1], 'the helper path stays one exact argument');
    }

    public function testRatioEraseHelperQuotesConfiguredExecutableAndArgv()
    {
        $command = (new rRatio())->getEraseWithDataCommand('1');
        $prefix = 'execute.nothrow.bg={';
        $at = strpos($command, $prefix);
        $this->assertTrue($at !== false, 'ratio delegates deletion to the background helper');
        if($at === false) return;
        $args = $this->arguments(substr($command, $at + strlen($prefix), -1));
        $this->assertTrue(is_array($args), 'the delete helper argv is well-formed');
        if(!is_array($args)) return;
        $this->assertSame(Utility::getPHP(), $args[0], 'ratio preserves the configured PHP executable');
        $this->assertSame(realpath(__DIR__.'/../../plugins/erasedata').'/erase.php', $args[1],
            'ratio passes the actual helper path as one argument');
        $this->assertSame(5, count($args), 'the anonymous user remains an explicit final argument');
        $this->assertSame('', $args[4], 'the anonymous profile keeps its empty identity');
    }

    public function testUnpackHookQuotesConfiguredExecutableAndRoot()
    {
        $plugin = new rUnpack();
        $plugin->enabled = 1;
        foreach($this->commands($plugin) as $command)
            $this->assertArgv($command, $GLOBALS['rootPath'].'/plugins/unpack/update.php');
    }

    public function testXmppHookQuotesConfiguredExecutable()
    {
        $plugin = new rXmpp();
        $plugin->message = 'message';
        foreach($this->commands($plugin) as $command)
            $this->assertArgv($command, realpath(__DIR__.'/../../plugins/xmpp').'/notify.php');
    }

    public function testAllHistoryHooksQuoteConfiguredExecutableAndRoot()
    {
        $commands = $this->commands(new rHistory());
        $this->assertSame(3, count($commands), 'insert, finish and erase each register their own hook');
        foreach($commands as $command)
            $this->assertArgv($command, $GLOBALS['rootPath'].'/plugins/history/update.php');
    }

    public function testExtratioQuotesBothLevelsOfItsSynchronousCapture()
    {
        $plugin = new rRatioRulesList();
        $plugin->lst = array(new rRatioRule('rule', RR_LABEL_CONTAIN, 'test', 1, 0, 'rat_1', 'thr_1'));
        $commands = $this->commands($plugin);
        $this->assertSame(2, count($commands), 'ratio and throttle each register one capture');
        foreach($commands as $command)
        {
            $captureEnd = strpos($command, ' ; ');
            $capture = substr($command, strpos($command, '=') + 1, $captureEnd - strpos($command, '=') - 1);
            $outer = $this->arguments($capture);
            $this->assertTrue(is_array($outer) && count($outer) === 2,
                'the custom key and capture expression remain two arguments');
            if(!is_array($outer) || count($outer) !== 2) continue;
            $at = strpos($outer[1], '={');
            $inner = $this->arguments(substr($outer[1], $at + 2, -1));
            $this->assertTrue(is_array($inner), 'the nested capture argv is well-formed');
            if(!is_array($inner)) continue;
            $this->assertSame(Utility::getPHP(), $inner[0], 'nested PHP path keeps its exact bytes');
            $this->assertSame($GLOBALS['rootPath'].'/plugins/extratio/update.php', $inner[1],
                'nested helper path keeps its exact bytes');
            $this->assertSame(6, count($inner), 'paths cannot inject extra capture arguments');
        }
    }
}
