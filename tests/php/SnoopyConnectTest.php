<?php

namespace SnoopyConnectFixture {
    // Replace only the system connection calls so real errno values are deterministic.
    function fsockopen($host, $port, &$errno, &$error, $timeout)
    {
        $GLOBALS['snoopyConnectCall'] = 'fsockopen';
        $errno = $GLOBALS['snoopyConnectErrno'];
        $error = $GLOBALS['snoopyConnectError'] ?? 'Controlled socket failure';
        return false;
    }

    function stream_socket_client($address, &$errno, &$error, $timeout, $flags, $context)
    {
        $GLOBALS['snoopyConnectCall'] = 'stream_socket_client';
        $errno = $GLOBALS['snoopyConnectErrno'];
        $error = $GLOBALS['snoopyConnectError'] ?? 'Controlled socket failure';
        return false;
    }
}

namespace {
    require_once(__DIR__ . '/TestCase.php');

    // Load the complete production classes without application bootstrap or network I/O.
    foreach (array(
        'php/Snoopy.class.inc' => 'class Snoopy',
        'plugins/rutracker_check/fetcherror.php' => 'class RuTrackerFetchError',
        'plugins/rutracker_check/check.php' => 'class ruTrackerChecker',
        'php/torrentfetch.php' => 'class TorrentFetch',
    ) as $path => $declaration) {
        $source = file_get_contents(__DIR__ . '/../../' . $path);
        $start = strpos($source, $declaration);
        if ($start === false) throw new \RuntimeException('Production class not found: ' . $path);
        eval('namespace SnoopyConnectFixture; ' . substr($source, $start));
    }

    $tests = array(
        'positive socket errno cannot become an HTTP status or another transport failure' => function () {
            foreach (array('' => 'fsockopen', '127.0.0.1' => 'stream_socket_client') as $bind => $call) {
                foreach (array(110, 111, 100, 101, 10061) as $errno) {
                    $GLOBALS['snoopyConnectErrno'] = $errno;
                    $GLOBALS['snoopyConnectCall'] = null;
                    $client = new \SnoopyConnectFixture\Snoopy();
                    $client->IP = $bind === '' ? null : $bind;
                    testAssertSame(false, $client->connect(), 'The socket connection failed');
                    testAssertSame($call, $GLOBALS['snoopyConnectCall'], 'The selected system call ran');
                    testAssertSame('connection failed (' . $errno . ')', $client->error,
                        'The original OS errno remains available for detailed diagnostics');
                    testAssertSame('transport=socket status=-102 reason=socket',
                        \SnoopyConnectFixture\ruTrackerChecker::fetchStatusDetail($client->status),
                        $call . ' errno ' . $errno . ' is a socket failure, never HTTP, read timeout or body refusal');
                    testAssertSame('connect-failed errno=' . $errno,
                        \SnoopyConnectFixture\TorrentFetch::rejectionReason($client, false),
                        'Torrent download diagnostics retain the exact OS errno');
                }
            }
        },
        'zero and legacy negative socket statuses retain their diagnostic contract' => function () {
            foreach (array(
                0 => array('transport=no-status reason=unset', 'connection failed (0)', 'connect-errno', 'connect-failed'),
                -3 => array('transport=socket status=-3 reason=socket-create', 'socket creation failed (-3)', 'socket-create', 'socket-create-failed'),
                -4 => array('transport=socket status=-4 reason=dns', 'dns lookup failure (-4)', 'dns-lookup', 'dns-failed'),
                -5 => array('transport=socket status=-5 reason=connect', 'connection refused or timed out (-5)', 'connect-refused', 'connect-failed'),
            ) as $errno => $expected) {
                list($detail, $message, $classification, $downloadReason) = $expected;
                $GLOBALS['snoopyConnectErrno'] = $errno;
                $client = new \SnoopyConnectFixture\Snoopy();
                testAssertSame(false, $client->connect(), 'The socket connection failed');
                testAssertSame($errno, $client->status, 'Existing nonpositive statuses are preserved');
                testAssertSame($detail,
                    \SnoopyConnectFixture\ruTrackerChecker::fetchStatusDetail($client->status),
                    'Existing transport classification is preserved');
                testAssertSame($message, $client->error,
                    'A specific socket error must not fall through to the generic message');
                testAssertSame($classification,
                    \SnoopyConnectFixture\ruTrackerChecker::classifyFetchError($client->error),
                    'The checker receives the socket failure category');
                testAssertSame($downloadReason . ' errno=' . $errno,
                    \SnoopyConnectFixture\TorrentFetch::rejectionReason($client, false),
                    'Torrent download diagnostics keep the category and exact errno');
            }
        },
        'PHP resolver failure with errno zero is classified without leaking the resolver text' => function () {
            // PHP's real fsockopen answer for an invalid hostname carries this
            // marker with errno 0. The suffix is deliberately sensitive fixture
            // text: consumers must receive only the fixed category and errno.
            $GLOBALS['snoopyConnectErrno'] = 0;
            try {
                foreach (array('' => 'fsockopen', '127.0.0.1' => 'stream_socket_client') as $bind => $call) {
                    foreach (array(
                        'php_network_getaddresses: getaddrinfo for private-host.test failed: Name or service not known'
                            => array('dns lookup failure (0)', 'dns-lookup', 'dns-failed errno=0'),
                        'Unclassified failure mentioning php_network_getaddresses: private-host.test'
                            => array('connection failed (0)', 'connect-errno', 'connect-failed errno=0'),
                        '' => array('connection failed (0)', 'connect-errno', 'connect-failed errno=0'),
                    ) as $rawError => $expected) {
                        $GLOBALS['snoopyConnectError'] = $rawError;
                        $GLOBALS['snoopyConnectCall'] = null;
                        $client = new \SnoopyConnectFixture\Snoopy();
                        $client->IP = $bind === '' ? null : $bind;
                        testAssertSame(false, $client->connect(), 'The socket connection failed');
                        testAssertSame($call, $GLOBALS['snoopyConnectCall'], 'The selected system call ran');
                        testAssertSame(0, $client->status, 'Resolver classification does not invent an OS errno');
                        testAssertSame($expected[0], $client->error, 'Only the known PHP resolver marker identifies DNS');
                        testAssertSame($expected[1],
                            \SnoopyConnectFixture\ruTrackerChecker::classifyFetchError($client->error),
                            'The checker logs a fixed category without resolver text');
                        testAssertSame($expected[2],
                            \SnoopyConnectFixture\TorrentFetch::rejectionReason($client, false),
                            'Torrent downloads retain the same classified reason');
                    }
                }
            } finally {
                unset($GLOBALS['snoopyConnectError']);
            }
        },
    );

    exit(testRunCases($tests));
}
