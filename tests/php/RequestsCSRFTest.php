<?php

require_once(__DIR__ . '/TestCase.php');

/**
 * Requests::makeCSRFCheck() answers a refused POST with 403 and exits, so
 * each case runs it in a child PHP that is given the request's headers and
 * the configured origins, and reads back ALLOW or Forbidden. The child is
 * started without a shell and exits on its own.
 *
 * The request's own origin is scheme, host and port; a forwarded host is
 * never a grant, and an opaque "null" Origin is refused. Upstream ruTorrent
 * admits a POST on a matching host alone, counts a forwarded host as the
 * request's own and admits "null", so the cases taken from its file keep
 * only the assertions that hold here.
 */
class RequestsCSRFTest extends TestCase
{
    private $probeErrors = "";

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
        $process = proc_open(array(PHP_BINARY, '-d', 'log_errors=1', '-r', $code),
            array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')),
            $pipes, null, $environment);
        $this->assertTrue(is_resource($process), 'child PHP started');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $this->probeErrors = $errors;
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

    public function testExplicitTrustedOriginWorksForForwardedProtocolList()
    {
        $headers = array('HTTP_HOST'=>'backend:8080', 'HTTP_X_FORWARDED_PROTO'=>'https, http',
            'HTTP_ORIGIN'=>'https://panel.example:8443', 'HTTP_X_REQUESTED_WITH'=>'XMLHttpRequest');
        $trusted = array('https://panel.example:8443');
        $this->assertEquals('ALLOW', $this->probe($headers, array(), true, false, $trusted),
            'explicit origin grants work independently of an ambiguous proxy scheme');
        $this->assertEquals('ALLOW', $this->probe($headers, array(), true, false, $trusted, true),
            'bootstrap and POST apply the same explicit origin grant');
        unset($headers['HTTP_ORIGIN']);
        $this->assertEquals('Forbidden', $this->probe($headers, array(), true, false, $trusted, true),
            'ambiguous proxy scheme without a source origin cannot authorize bootstrap');
    }

    public function testMalformedTrustedOriginNamesConfigurationRefusal()
    {
        foreach (array('panel.example', 'https://panel.example/path') as $grant) {
            $this->assertEquals('Forbidden', $this->probe(array('HTTP_ORIGIN'=>'https://panel.example'),
                array(), true, false, array($grant)), 'invalid setting cannot authorize a request');
            $this->assertTrue(strpos($this->probeErrors, 'trusted-origin-invalid') !== false,
                'invalid origin configuration has a classified diagnostic');
            $this->assertTrue(strpos($this->probeErrors, 'trustedOrigins') !== false,
                'diagnostic names the configuration to repair');
        }
    }

    // The cases below come from upstream's tests/php/RequestsCSRFTest.php
    // (#3374, #3383). Two assertions there admit a POST through the host an
    // untrusted proxy forwarded; this check never takes a forwarded host as
    // a grant (testForwardedHostCannotAuthorizeForeignOrigin), so those two
    // are not carried over.

    public function testAnIPv6LiteralHostIsTheRequestsOwnOrigin()
    {
        // A Host header is host[:port], and an IPv6 host has colons of its
        // own. Read up to the first colon, "[fd00::5]:8080" left "[" to
        // compare the Origin's host with, and every POST made through an
        // IPv6 address answered 403 Forbidden.
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_HOST' => '[fd00::5]:8080',
            'HTTP_ORIGIN' => 'http://[fd00::5]:8080',
        )), 'a POST from the IPv6 origin the page was served on');
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_HOST' => '[::1]',
            'HTTP_REFERER' => 'http://[::1]/rutorrent/',
        )), 'the same by Referer, with a host that names no port');
    }

    public function testANameOrAnIPv4HostStillAdmitsItsOwnOrigin()
    {
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_HOST' => 'backend:8080',
            'HTTP_ORIGIN' => 'http://backend:8080',
        )), 'a name with a port');
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_HOST' => '192.0.2.7:8080',
            'HTTP_ORIGIN' => 'http://192.0.2.7:8080',
        )), 'an IPv4 address with a port');
        // The request's own origin carries its scheme, so the page that
        // sends an https Referer was served over https.
        $this->assertEquals('ALLOW', $this->probe(array(
            'REQUEST_SCHEME' => 'https',
            'HTTP_HOST' => 'rutorrent.example',
            'HTTP_REFERER' => 'https://rutorrent.example/rutorrent/',
        )), 'a name without a port, by Referer');
    }

    public function testAConfiguredHostIsComparedAsADnsName()
    {
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_HOST' => '[::1]:8080',
            'HTTP_ORIGIN' => 'http://rutorrent.example',
        ), array('RuTorrent.Example')), 'DNS names have no case');
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_HOST' => '[::1]:8080',
            'HTTP_ORIGIN' => 'http://rutorrent.example.',
        ), array('rutorrent.example')), 'and the root dot does not name another host');
        $this->assertEquals('Forbidden', $this->probe(array(
            'HTTP_HOST' => '[::1]:8080',
            'HTTP_ORIGIN' => 'http://www.rutorrent.example',
        ), array('rutorrent.example')), 'a subdomain is another host');
    }

    public function testAConfiguredHostIsComparedAsADnsNameByReferer()
    {
        // The Referer branch has a comparison of its own, and the cases around
        // it only ever handed it a Referer spelled exactly like the host it had
        // to match. These are the three questions the Origin case above asks.
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_HOST' => '[::1]:8080',
            'HTTP_REFERER' => 'https://RuTorrent.Example/rutorrent/',
        ), array('rutorrent.example')), 'the Referer spells the configured host in another case');
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_HOST' => '[::1]:8080',
            'HTTP_REFERER' => 'https://rutorrent.example/rutorrent/',
        ), array('RuTorrent.Example')), 'and the configured host is the one spelled differently');
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_HOST' => '[::1]:8080',
            'HTTP_REFERER' => 'https://rutorrent.example./rutorrent/',
        ), array('rutorrent.example')), 'and the root dot does not name another host here either');
        $this->assertEquals('Forbidden', $this->probe(array(
            'HTTP_HOST' => '[::1]:8080',
            'HTTP_REFERER' => 'https://www.rutorrent.example/rutorrent/',
        ), array('rutorrent.example')), 'a subdomain is another host by Referer too');
    }

    public function testAHostIsComparedAsAStringAndNotANumber()
    {
        // "127.0" and "127.00" are both numeric strings, which PHP compares as
        // numbers unless it is told not to: loosely, one is admitted for the
        // other. They name two different hosts, and the check is an identity
        // test on the spelling.
        $this->assertEquals('Forbidden', $this->probe(array(
            'HTTP_HOST' => '127.00',
            'HTTP_ORIGIN' => 'http://127.0',
        )), 'an Origin that equals the Host only as a number');
        $this->assertEquals('Forbidden', $this->probe(array(
            'HTTP_HOST' => '127.00',
            'HTTP_REFERER' => 'http://127.0/rutorrent/',
        )), 'and the same by Referer');
        $this->assertEquals('ALLOW', $this->probe(array(
            'HTTP_HOST' => '127.0',
            'HTTP_ORIGIN' => 'http://127.0',
        )), 'while the host the page was served on is still admitted');
    }

    public function testAForeignOriginIsRefusedWhateverHostItNames()
    {
        foreach (array(
            'http://evil.test' => 'another host',
            'http://rutorrent.example@evil.test' => 'our name in the userinfo',
            'http://rutorrent.example.evil.test' => 'our name as a leading label',
            'http://evil.test/rutorrent.example' => 'our name in the path',
            'http://evil.test/?rutorrent.example' => 'our name in the query',
        ) as $origin => $trap) {
            $this->assertEquals('Forbidden', $this->probe(array(
                'HTTP_HOST' => 'rutorrent.example',
                'HTTP_ORIGIN' => $origin,
            )), $trap . ', by Origin against the Host header');
            $this->assertEquals('Forbidden', $this->probe(array(
                'HTTP_HOST' => '[fd00::5]:8080',
                'HTTP_REFERER' => $origin . '/x',
            ), array('rutorrent.example')), $trap . ', by Referer against a configured host');
        }
        $this->assertEquals('Forbidden', $this->probe(array(
            'HTTP_HOST' => '[fd00::5]:8080',
        )), 'a POST that names no origin at all');
    }

    // The cases below come from upstream's #3389. Its header sets were
    // captured from real browsers and are refused here as they are there.

    public function testAnOpaqueOriginIsRefusedWithoutOurReferer()
    {
        // A browser sends "Origin: null" for a form POST from a sandboxed or
        // data: frame, a file: page, or any page whose referrer policy is
        // no-referrer -- one <meta> tag on a foreign page is enough. These
        // header sets were captured from Chrome 155 and Firefox 158 posting
        // such forms from another origin to http://127.0.0.1:18781, which
        // is potentially trustworthy and so gets Sec-Fetch-*, and to
        // http://rutorrent.lan:18781, a plain http name, which does not.
        foreach (array(
            'a no-referrer or file: page, by IP' => array(
                'HTTP_HOST' => '127.0.0.1:18781',
                'HTTP_ORIGIN' => 'null',
                'HTTP_SEC_FETCH_SITE' => 'cross-site',
                'HTTP_SEC_FETCH_MODE' => 'navigate',
                'HTTP_SEC_FETCH_DEST' => 'document',
            ),
            'a sandboxed or data: frame, by IP' => array(
                'HTTP_HOST' => '127.0.0.1:18781',
                'HTTP_ORIGIN' => 'null',
                'HTTP_SEC_FETCH_SITE' => 'cross-site',
                'HTTP_SEC_FETCH_MODE' => 'navigate',
                'HTTP_SEC_FETCH_DEST' => 'iframe',
            ),
            'any of those, by name' => array(
                'HTTP_HOST' => 'rutorrent.lan:18781',
                'HTTP_ORIGIN' => 'null',
            ),
            'a sandboxed frame in Firefox, by IP' => array(
                'HTTP_HOST' => '127.0.0.1:18781',
                'HTTP_ORIGIN' => 'null',
                'HTTP_REFERER' => 'http://127.0.0.2:18782/',
                'HTTP_SEC_FETCH_SITE' => 'cross-site',
                'HTTP_SEC_FETCH_MODE' => 'navigate',
                'HTTP_SEC_FETCH_DEST' => 'iframe',
            ),
            'a sandboxed frame in Firefox, by name' => array(
                'HTTP_HOST' => 'rutorrent.lan:18781',
                'HTTP_ORIGIN' => 'null',
                'HTTP_REFERER' => 'http://attacker.lan:18782/',
            ),
        ) as $page => $headers) {
            $this->assertEquals('Forbidden', $this->probe($headers), 'a form POST from ' . $page);
        }
    }

    // Upstream #3389 lets the Referer decide when the Origin is "null". This
    // check lets a present Origin decide alone (6ed49326), so "null" is
    // refused whatever the Referer says: it comes from a sandboxed frame, a
    // data: or file: page or a no-referrer page, and a sandboxed frame may
    // still send a Referer (the Firefox cases above). These are upstream's
    // inputs with this fork's verdict; each Referer alone is the control.
    public function testAnOpaqueOriginIsRefusedEvenWithOurReferer()
    {
        foreach (array(
            'a page on the host it was sent to' => array(array(
                'REQUEST_SCHEME' => 'https',
                'HTTP_HOST' => 'rutorrent.example',
                'HTTP_REFERER' => 'https://rutorrent.example/rutorrent/',
            ), array()),
            'a page on a configured host' => array(array(
                'HTTP_HOST' => '[::1]:8080',
                'HTTP_REFERER' => 'https://rutorrent.example/rutorrent/',
            ), array('rutorrent.example')),
        ) as $page => list($headers, $origins)) {
            $this->assertEquals('ALLOW', $this->probe($headers, $origins),
                'the Referer of ' . $page . ' alone is admitted');
            $headers['HTTP_ORIGIN'] = 'null';
            $this->assertEquals('Forbidden', $this->probe($headers, $origins),
                'and with "Origin: null" a POST from ' . $page . ' is refused');
        }
    }
}
