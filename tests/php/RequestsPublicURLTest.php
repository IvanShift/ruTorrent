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
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'trap.test';
        $_SERVER['PHP_SELF'] = '/other/plugins/feeds/action.php/path-info';
        $this->assertSame('http://public.test:8443/app/', Requests::publicBaseURL('/plugins/feeds/action.php'),
            'HTTPS=off selects HTTP and SCRIPT_NAME takes precedence over PHP_SELF');
        $_SERVER['HTTP_HOST'] = '[::1]:8443';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertSame('https://[::1]:8443/app/', Requests::publicBaseURL('/plugins/feeds/action.php'),
            'a single forwarded scheme and valid IPv6 authority are preserved');
        unset($_SERVER['SCRIPT_NAME']);
        $_SERVER['PHP_SELF'] = '/fallback/plugins/feeds/action.php';
        $this->assertSame('https://[::1]:8443/fallback/', Requests::publicBaseURL('/plugins/feeds/action.php'),
            'PHP_SELF is a compatibility fallback when SCRIPT_NAME is absent');
        unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
        $_SERVER['HTTPS'] = 'on';
        $this->assertSame('https://[::1]:8443/fallback/', Requests::publicBaseURL('/plugins/feeds/action.php'),
            'a directly secured request uses HTTPS');
        unset($_SERVER['HTTPS']);
        $_SERVER['REQUEST_SCHEME'] = 'HTTPS';
        $this->assertSame('https://[::1]:8443/fallback/', Requests::publicBaseURL('/plugins/feeds/action.php'),
            'the request scheme is normalized when HTTPS is absent');
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
        $publicURL = 'https://config.test/app/';
        putenv('RU_PUBLIC_URL=https://user:secret@public.test/');
        $this->assertSame(false, Requests::publicBaseURL('/plugins/feeds/action.php'),
            'an invalid environment override refuses instead of using local configuration');
    }

    public function testInvalidDirectAuthorityOrScriptIsRefused()
    {
        $valid = $_SERVER;
        foreach (array(array('HTTP_HOST' => 'user@trap.test'), array('HTTP_HOST' => 'public.test/path'),
            array('HTTP_HOST' => "public.test\r\nHeader: x"),
            array('SCRIPT_NAME' => '/app/plugins/feeds/action.php/extra')) as $bad) {
            $_SERVER = array_merge($valid, $bad);
            $this->assertSame(false, Requests::publicBaseURL('/plugins/feeds/action.php'),
                'malformed request metadata cannot manufacture a public base');
        }
        $_SERVER = $valid;
        $this->assertSame(false, Requests::publicBaseURL(''), 'an empty endpoint suffix is refused');
    }

    private function resetRequest()
    {
        global $publicURL;
        putenv('RU_PUBLIC_URL');
        $publicURL = '';
        $_SERVER = array('HTTP_HOST' => 'public.test:8443', 'HTTPS' => 'off',
            'SCRIPT_NAME' => '/app/plugins/feeds/action.php');
    }

    /**
     * The scheme of a generated link is the one the server reports for the
     * connection. X-Forwarded-Proto is written by whatever spoke to the
     * server last, the client included, and is read only where the server
     * reports no TLS of its own: a request can raise a link to https, which
     * is what a TLS-terminating proxy needs, and can never lower one.
     */
    public function testServerTLSStateOutranksTheForwardedScheme()
    {
        $this->resetRequest();
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $this->assertTrue(Requests::publicBaseURL('/plugins/feeds/action.php') === 'https://public.test:8443/app/',
            'A forwarded scheme cannot downgrade a request the server itself secured');
        $this->resetRequest();
        unset($_SERVER['HTTPS']);
        $_SERVER['REQUEST_SCHEME'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'http';
        $this->assertTrue(Requests::publicBaseURL('/plugins/feeds/action.php') === 'https://public.test:8443/app/',
            'Nor the scheme the server reports for the connection');
        $this->resetRequest();
        $_SERVER['HTTPS'] = 'OFF';
        $this->assertTrue(Requests::publicBaseURL('/plugins/feeds/action.php') === 'http://public.test:8443/app/',
            'HTTPS is read without regard to case, so OFF is not TLS');
        $this->resetRequest();
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $this->assertTrue(Requests::publicBaseURL('/plugins/feeds/action.php') === 'https://public.test:8443/app/',
            'A proxy that terminates TLS is believed where the server reports none');
        $this->resetRequest();
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = ' HTTPS ,http';
        $this->assertTrue(Requests::publicBaseURL('/plugins/feeds/action.php') === 'https://public.test:8443/app/',
            'Each proxy of a chain appends to the header, and the client scheme is the first entry');
        $this->resetRequest();
        unset($_SERVER['HTTPS']);
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'ftp';
        $this->assertTrue(Requests::publicBaseURL('/plugins/feeds/action.php') === 'http://public.test:8443/app/',
            'A forwarded value naming no web scheme is passed over, not fatal to the feed');
        $this->resetRequest();
        unset($_SERVER['HTTPS']);
        $this->assertTrue(Requests::publicBaseURL('/plugins/feeds/action.php') === 'http://public.test:8443/app/',
            'A plain request with nothing forwarded is HTTP');
        $this->resetRequest();
        $_SERVER['HTTPS'] = array('on');
        $_SERVER['REQUEST_SCHEME'] = array('https');
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = array('https');
        $this->assertTrue(Requests::publicBaseURL('/plugins/feeds/action.php') === 'http://public.test:8443/app/',
            'Metadata that is not a string at all names no scheme, and is not fatal to the feed');
        $_SERVER['HTTP_HOST'] = array('public.test');
        $this->assertTrue(Requests::publicBaseURL('/plugins/feeds/action.php') === false,
            'Nor does it name a host');
    }

    public function testPublicSchemeFallbackDoesNotAuthorizeAjax()
    {
        foreach (array('https,http', 'ftp', '') as $scheme) {
            $this->resetRequest();
            $_SERVER['HTTP_X_FORWARDED_PROTO'] = $scheme;
            $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
            $this->assertTrue(Requests::publicBaseURL('/plugins/feeds/action.php') !== false,
                'display links can use the public scheme fallback');
            $this->assertSame(false, Requests::isSameOriginAjaxRequest(),
                'malformed or ambiguous forwarded metadata cannot grant headerless AJAX');
        }
    }
}
