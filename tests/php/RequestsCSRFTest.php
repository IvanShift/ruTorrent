<?php

require_once(__DIR__ . '/TestCase.php');

class RequestsCSRFTest extends TestCase
{
    private function probe($changes, $origins = array(), $enabled = true, $requirePost = false, $trusted = array(), $ajax = false)
    {
        $server = array_merge(array(
            'REQUEST_METHOD' => 'POST',
            'REQUEST_SCHEME' => 'http',
            'HTTP_HOST' => 'localhost:19082',
            'SCRIPT_NAME' => '/plugins/loginmgr/action.php',
        ), $changes);
        $code = '$_SERVER = json_decode(getenv("CSRF_TEST_SERVER"), true);'
            . '$enableCSRFCheck = getenv("CSRF_TEST_ENABLED") === "1";'
            . '$enabledOrigins = json_decode(getenv("CSRF_TEST_ORIGINS"), true);'
            . '$trustedOrigins = json_decode(getenv("CSRF_TEST_TRUSTED"), true);'
            . 'require ' . var_export(__DIR__ . '/../../php/urlhost.php', true) . ';'
            . 'require ' . var_export(__DIR__ . '/../../php/utility/requests.php', true) . ';'
            . 'if (getenv("CSRF_TEST_AJAX") === "1") { echo Requests::isSameOriginAjaxRequest() ? "ALLOW" : "Forbidden"; } else { Requests::makeCSRFCheck(); if (getenv("CSRF_TEST_REQUIRE_POST") === "1") Requests::requirePost(); echo "ALLOW"; }';
        $environment = array(
            'CSRF_TEST_SERVER' => json_encode($server),
            'CSRF_TEST_ORIGINS' => json_encode($origins),
            'CSRF_TEST_TRUSTED' => json_encode($trusted),
            'CSRF_TEST_AJAX' => $ajax ? '1' : '0',
            'CSRF_TEST_ENABLED' => $enabled ? '1' : '0',
            'CSRF_TEST_REQUIRE_POST' => $requirePost ? '1' : '0',
        );
        $process = proc_open(array(PHP_BINARY, '-d', 'log_errors=0', '-r', $code),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, null, $environment);
        $this->assertTrue(is_resource($process), 'child PHP started');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->assertEquals(0, proc_close($process), 'child PHP exited cleanly: ' . $errors);
        return trim($output);
    }

    public function testForeignOriginCannotBorrowSameSiteReferer()
    {
        $this->assertEquals('Forbidden', $this->probe(array(
            'HTTP_ORIGIN' => 'http://foreign.test',
            'HTTP_REFERER' => 'http://localhost:19082/panel',
        )), 'Origin takes precedence over Referer');
    }

    public function testOpaqueOriginIsRefused()
    {
        $this->assertEquals('Forbidden', $this->probe(array('HTTP_ORIGIN' => 'null')),
            'sandboxed and data pages may use an opaque Origin');
    }

    public function testSameHostDifferentPortIsRefused()
    {
        $this->assertEquals('Forbidden', $this->probe(array(
            'HTTP_ORIGIN' => 'http://localhost:19083',
        )), 'an origin includes its port');
    }

    public function testSameHostDifferentSchemeIsRefused()
    {
        $this->assertEquals('Forbidden', $this->probe(array(
            'HTTP_ORIGIN' => 'https://localhost:19082',
        )), 'an origin includes its scheme');
    }

    public function testForwardedHostCannotAuthorizeForeignOrigin()
    {
        $this->assertEquals('Forbidden', $this->probe(array(
            'HTTP_ORIGIN' => 'http://foreign.test',
            'HTTP_X_FORWARDED_HOST' => 'foreign.test',
        )), 'untrusted forwarded host is not an allowlist');
    }

    public function testExactSameOriginAndRefererFallbackAreAllowed()
    {
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_ORIGIN' => 'http://localhost:19082',
        )), 'same-origin browser POST succeeds');
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_REFERER' => 'http://localhost:19082/panel',
        )), 'same-origin Referer is accepted only when Origin is absent');
    }

    public function testExplicitConfiguredHostIsAllowed()
    {
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_ORIGIN' => 'https://trusted.example:8443',
        ), array('trusted.example')), 'operator configured host remains an explicit exception');
    }

    public function testHeaderlessNonbrowserHttprpcRetainsCompatibility()
    {
        $this->assertEquals('ALLOW', $this->probe(array(
            'SCRIPT_NAME' => '/plugins/httprpc/action.php',
            'SCRIPT_FILENAME' => __DIR__ . '/../../plugins/httprpc/action.php',
        )), 'legacy raw httprpc clients can omit browser origin headers');
        $this->assertEquals('Forbidden', $this->probe(array()),
            'other browser action routes still require an origin');
    }

    public function testHeaderlessRpc2ClientUsesOnlyServerOwnedEndpointMarker()
    {
        $this->assertEquals('ALLOW', $this->probe(array(
            'SCRIPT_NAME' => '/RPC2',
            'SCRIPT_FILENAME' => __DIR__ . '/../../rpc2.php',
            'RUTORRENT_XMLRPC_ENDPOINT' => 'on',
        )), 'headerless external XMLRPC client remains compatible');
        $this->assertEquals('Forbidden', $this->probe(array(
            'SCRIPT_NAME' => '/RPC2',
            'SCRIPT_FILENAME' => __DIR__ . '/../../rpc2.php',
            'RUTORRENT_XMLRPC_ENDPOINT' => 'on',
            'HTTP_ORIGIN' => 'http://foreign.test',
        )), 'foreign XMLRPC origin cannot use the raw-client exception');
        $this->assertEquals('Forbidden', $this->probe(array(
            'SCRIPT_NAME' => '/RPC2',
            'SCRIPT_FILENAME' => __DIR__ . '/../../rpc2.php',
            'RUTORRENT_XMLRPC_ENDPOINT' => 'on',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        )), 'browser Fetch Metadata closes the raw-client exception');
    }

    public function testRpc2MarkerOnAnotherPhpScriptCannotGrantRawClientException()
    {
        $this->assertEquals('Forbidden', $this->probe(array(
            'SCRIPT_NAME' => '/plugins/loginmgr/action.php',
            'SCRIPT_FILENAME' => __DIR__ . '/../../plugins/loginmgr/action.php',
            'RUTORRENT_XMLRPC_ENDPOINT' => 'on',
        )), 'a globally configured marker does not exempt another PHP action');
        $this->assertEquals('Forbidden', $this->probe(array(
            'SCRIPT_NAME' => '/RPC2',
            'SCRIPT_FILENAME' => __DIR__ . '/../../rpc2.php',
        )), 'script identity alone cannot grant the raw-client exception');
    }

    public function testBrowserFetchMetadataDoesNotEnterRawRpcException()
    {
        $this->assertEquals('Forbidden', $this->probe(array(
            'SCRIPT_NAME' => '/plugins/httprpc/action.php',
            'SCRIPT_FILENAME' => __DIR__ . '/../../plugins/httprpc/action.php',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        )), 'a browser form without Origin cannot use the raw client exception');
    }

    public function testMutatingRoutesCanRequirePostWithoutDuplicatingMethodLogic()
    {
        $this->assertEquals('Method Not Allowed', $this->probe(array(
            'REQUEST_METHOD' => 'GET',
        ), array(), false, true), 'a GET mutation is rejected');
        $this->assertEquals('ALLOW', $this->probe(array(
            'REQUEST_METHOD' => 'POST',
        ), array(), false, true), 'the same route accepts POST');
    }

    public function testDisabledCheckRemainsCompatible()
    {
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_ORIGIN' => 'http://foreign.test',
        ), array(), false), 'operators may retain old default outside the Basic-auth image');
    }
    public function testHttprpcSuffixCannotAuthorizeAnotherScript()
    {
        $this->assertEquals('Forbidden', $this->probe(array(
            'SCRIPT_NAME' => '/plugins/throttle/action.php/plugins/httprpc/action.php',
            'SCRIPT_FILENAME' => __DIR__ . '/../../plugins/throttle/action.php',
        )), 'raw-client exception belongs to the actual httprpc script');
    }

    public function testConfiguredFullOriginAllowsProxyPostAndBootstrap()
    {
        $headers = array('HTTP_HOST' => 'backend:8080',
            'HTTP_ORIGIN' => 'https://panel.example:8443',
            'HTTP_REFERER' => 'https://panel.example:8443/rutorrent/',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest');
        $trusted = array('https://panel.example:8443');
        $this->assertEquals('ALLOW', $this->probe($headers, array(), true, false, $trusted),
            'configured frontend origin authorizes proxy POST');
        $headers['REQUEST_METHOD'] = 'GET';
        $this->assertEquals('ALLOW', $this->probe($headers, array(), true, false, $trusted, true),
            'the same frontend origin authorizes AJAX bootstrap');
    }

    public function testConfiguredFullOriginKeepsSchemeAndPortBoundaries()
    {
        foreach (array('http://panel.example:8443', 'https://panel.example:8444',
            'https://other.example:8443', 'null') as $source)
        {
            $headers = array('HTTP_ORIGIN' => $source, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest');
            $trusted = array('https://panel.example:8443');
            $this->assertEquals('Forbidden', $this->probe($headers, array(), true, false, $trusted),
                'configured origin does not grant another scheme/host/port: '.$source);
            $this->assertEquals('Forbidden', $this->probe($headers, array(), true, false, $trusted, true),
                'bootstrap applies the same boundary: '.$source);
        }
    }

    public function testOnlyExplicitExtensionOriginCanAuthorizePost()
    {
        $trusted = array('moz-extension://configured-extension');
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_ORIGIN' => 'moz-extension://configured-extension'), array(), true, false, $trusted),
            'operator can authorize one browser extension origin');
        $this->assertEquals('Forbidden', $this->probe(array(
            'HTTP_ORIGIN' => 'moz-extension://another-extension'), array(), true, false, $trusted),
            'another extension has no grant');
    }

    public function testConfiguredOriginsRejectAmbiguousUrlValues()
    {
        foreach (array('https://user@panel.example:8443',
            'https://panel.example:8443/path', 'https://panel.example:8443?query',
            'https://panel.example:8443#fragment', 'https://*.example:8443') as $grant)
        {
            $headers = array('HTTP_ORIGIN' => 'https://panel.example:8443',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest');
            $this->assertEquals('Forbidden', $this->probe($headers, array(), true, false, array($grant)),
                'a full origin setting cannot contain credentials/path/query/fragment/wildcard: '.$grant);
            $this->assertEquals('Forbidden', $this->probe($headers, array(), true, false, array($grant), true),
                'bootstrap rejects the same ambiguous configured value: '.$grant);
        }
    }

}
