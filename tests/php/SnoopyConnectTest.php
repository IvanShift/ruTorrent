<?php

namespace SnoopyConnectFixture {
    // Replace only the system connection calls so real errno values are deterministic.
    function fsockopen($host, $port, &$errno, &$error, $timeout)
    {
        $GLOBALS['snoopyConnectCall'] = 'fsockopen';
        $errno = $GLOBALS['snoopyConnectErrno'];
        $error = 'Controlled socket failure';
        return false;
    }

    function stream_socket_client($address, &$errno, &$error, $timeout, $flags, $context)
    {
        $GLOBALS['snoopyConnectCall'] = 'stream_socket_client';
        $errno = $GLOBALS['snoopyConnectErrno'];
        $error = 'Controlled socket failure';
        return false;
    }
}

namespace {
    require_once(__DIR__ . '/TestCase.php');

    // Load the complete production classes without application bootstrap or network I/O.
    foreach (array(
        'php/Snoopy.class.inc' => 'class Snoopy',
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
                0 => 'transport=no-status reason=unset',
                -3 => 'transport=socket status=-3 reason=socket-create',
                -4 => 'transport=socket status=-4 reason=dns',
                -5 => 'transport=socket status=-5 reason=connect',
            ) as $errno => $detail) {
                $GLOBALS['snoopyConnectErrno'] = $errno;
                $client = new \SnoopyConnectFixture\Snoopy();
                testAssertSame(false, $client->connect(), 'The socket connection failed');
                testAssertSame($errno, $client->status, 'Existing nonpositive statuses are preserved');
                testAssertSame($detail,
                    \SnoopyConnectFixture\ruTrackerChecker::fetchStatusDetail($client->status),
                    'Existing transport classification is preserved');
            }
        },
    );

    exit(testRunCases($tests));
}
