<?php

require_once(__DIR__ . '/TestCase.php');

class RequestsCSRFTest extends TestCase
{
    private function probe($changes, $origins = array(), $enabled = true, $requirePost = false)
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
            . 'require ' . var_export(__DIR__ . '/../../php/urlhost.php', true) . ';'
            . 'require ' . var_export(__DIR__ . '/../../php/utility/requests.php', true) . ';'
            . 'Requests::makeCSRFCheck(); if (getenv("CSRF_TEST_REQUIRE_POST") === "1") Requests::requirePost(); echo "ALLOW";';
        $environment = array(
            'CSRF_TEST_SERVER' => json_encode($server),
            'CSRF_TEST_ORIGINS' => json_encode($origins),
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
}
