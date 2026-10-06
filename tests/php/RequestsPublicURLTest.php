<?php

require_once(__DIR__.'/TestCase.php');
require_once(__DIR__.'/../../php/utility/requests.php');

final class RequestsPublicURLTest extends TestCase
{
    private $server;
    private $environment;
    private $setting;
    private $log;

    public function setUp()
    {
        global $publicURL;
        $this->server = $_SERVER;
        $this->environment = getenv('RU_PUBLIC_URL');
        $this->setting = $publicURL ?? null;
        $this->log = ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir().'/public-url-'.getmypid().'.log');
        putenv('RU_PUBLIC_URL');
        $publicURL = '';
        $_SERVER = array('HTTP_HOST' => 'public.test:8443', 'HTTPS' => 'off',
            'SCRIPT_NAME' => '/app/plugins/feeds/action.php');
    }

    public function tearDown()
    {
        global $publicURL;
        $_SERVER = $this->server;
        $publicURL = $this->setting;
        putenv($this->environment === false ? 'RU_PUBLIC_URL' : 'RU_PUBLIC_URL='.$this->environment);
        @unlink(ini_get('error_log'));
        ini_set('error_log', $this->log);
    }

    public function testExplicitEnvironmentOverridesConfigWithoutTrustingForwardedHost()
    {
        global $publicURL;
        $publicURL = 'https://config.test/app/';
        putenv('RU_PUBLIC_URL=https://public.test:9443/proxy');
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'trap.test';
        $this->assertSame('https://public.test:9443/proxy/', Requests::publicBaseURL('/plugins/feeds/action.php'),
            'the operator explicitly selects origin, port and prefix');
    }

    public function testDirectFallbackHandlesHttpsOffAndIPv6()
    {
        $this->assertSame('http://public.test:8443/app/', Requests::publicBaseURL('/plugins/feeds/action.php'),
            'HTTPS=off selects HTTP');
        $_SERVER['HTTP_HOST'] = '[::1]:8443';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertSame('https://[::1]:8443/app/', Requests::publicBaseURL('/plugins/feeds/action.php'),
            'a single forwarded scheme and valid IPv6 authority are preserved');
    }

    public function testEmptyContainerEnvironmentKeepsTheLocalPublicBaseOverride()
    {
        global $publicURL;
        $publicURL = 'https://config.test/proxy/';
        putenv('RU_PUBLIC_URL=');
        $this->assertSame('https://config.test/proxy/', Requests::publicBaseURL('/plugins/feeds/action.php'),
            'the image default empty environment does not hide config.local.php');
    }

    public function testInvalidExplicitBaseIsVisibleAndNeverFallsBackToInternalHost()
    {
        global $publicURL;
        foreach (array('https://user:secret@public.test/app/', 'https://public.test/app/?a=b',
            'https://public.test/app/#anchor', 'ftp://public.test/', '//public.test/app/',
            "https://public.test/\r\nInjected: value", 'https://public.test\\trap/', array('https://public.test/')) as $bad) {
            $publicURL = $bad;
            $this->assertSame(false, Requests::publicBaseURL('/plugins/feeds/action.php'),
                'invalid public configuration is refused without exposing its contents');
        }
        $log = file_get_contents(ini_get('error_log'));
        $this->assertTrue(strpos($log, 'public-url: refused setting=publicURL') !== false
            && strpos($log, 'secret') === false && strpos($log, 'Injected') === false,
            'configuration refusal is classified without echoing a credential or input');
    }

    public function testInvalidDirectAuthoritySchemeOrScriptIsRefused()
    {
        $valid = $_SERVER;
        foreach (array(array('HTTP_HOST' => 'user@trap.test'), array('HTTP_HOST' => 'public.test/path'),
            array('HTTP_HOST' => "public.test\r\nHeader: x"), array('HTTP_X_FORWARDED_PROTO' => 'https,http'),
            array('SCRIPT_NAME' => '/app/plugins/feeds/action.php/extra')) as $bad) {
            $_SERVER = array_merge($valid, $bad);
            $this->assertSame(false, Requests::publicBaseURL('/plugins/feeds/action.php'),
                'malformed request metadata cannot manufacture a public base');
        }
    }
}
