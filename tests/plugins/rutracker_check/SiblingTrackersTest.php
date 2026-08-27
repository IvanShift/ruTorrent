<?php

/**
 * The three sibling handlers this branch already touches, plus anidub, all
 * decided "the topic was deleted" from the SECOND fetch's status with the test
 * `$client->status < 0`. That catches almost nothing: Snoopy stores curl's
 * exit code on the https path and the socket errno on the plain one, both
 * positive (php/Snoopy.class.inc), and an HTTP 403 / 429 / 5xx is positive by
 * definition. So the everyday failure -- a Cloudflare challenge on a download
 * link, which is exactly why these handlers pace their fetches -- was reported
 * as a deletion, painting a live torrent as an error row and parking it out of
 * the automatic pass for a week (updatepass.php's SETTLED_RECHECK).
 *
 * Every fetch now follows the same transport rule: a non-200 or malformed
 * HTTP-200 body is "could not fetch", never "deleted". Deletion requires a
 * tracker-specific structural signal, such as Tapochek's measured Information
 * system table; handlers without such a signal remain retryable.
 */

define('TESTLIB_HANDLER_STUBS', 1);
require_once(__DIR__ . '/TestLib.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/trackers/tfile.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/trackers/tapocheknet.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/trackers/anidub.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/trackers/toloka.php');

$suite = new StrictTestSuite();

// The infohash the client holds; the page will advertise a different one, so
// every handler goes on to fetch the .torrent -- the fetch under test.
define('SIB_OLD_HASH', str_repeat('A', 40));
define('SIB_NEW_HASH', str_repeat('B', 40));
// Measured 2026-08-21 against the live site: the exact sentence tapochek.net
// serves, with HTTP 200, for a topic it no longer has. The page is
// windows-1251, so the handler has to find it in both encodings.
define('TAP_GONE_UTF8', 'Темы, которую вы запросили, не существует');
define('TAP_GONE_CP1251', iconv('UTF-8', 'CP1251//IGNORE', TAP_GONE_UTF8));

// Every status a live tracker answers a download link with, none of which is
// evidence the topic is gone.
function sibNotFound()
{
    return array(
        'a Cloudflare challenge'      => 403,
        'rate limiting'               => 429,
        'a bad gateway'               => 502,
        'maintenance'                 => 503,
        'a curl exit code'            => 28,     // https path: timeout
        'a socket errno'              => 111,    // plain path: ECONNREFUSED
        "Snoopy's read-timeout"       => -100,
    );
}

// --- the three handlers with no sleep in their path -------------------------
//
// One table, three handlers: the seven statuses and the assertion are the same
// for each because the RULE is the same, and only the pages and URLs differ.
// anidub picks its download link by the quality tag in the torrent's NAME, so
// it is the one that needs an $old_torrent.
class SibAniDubTorrent
{
    public static $torrentName = 'Show_[720p]_ep01';
    public function name() { return self::$torrentName; }
}

function sibHandlers($hash = SIB_NEW_HASH)
{
    return array(
        'tfile' => array(
            'call'  => array('TfileCheckImpl', 'download_torrent'),
            'topic' => 'http://tfile.me/forum/viewtopic.php?p=7',
            'page'  => 'http://megatfile.cc/forum/viewtopic.php?p=7',
            'down'  => 'http://megatfile.cc/forum/download.php?id=99',
            'body'  => 'Info hash:</td><td><strong>' . $hash . '</strong></td> <a href="download.php?id=99">get</a>',
            'old'   => null,
        ),
        'tapochek' => array(
            'call'  => array('TapochekNetCheckImpl', 'download_torrent'),
            'topic' => 'https://tapochek.net/viewtopic.php?p=7',
            'page'  => 'https://tapochek.net/viewtopic.php?p=7',
            'down'  => 'https://tapochek.net/download.php?id=99',
            'body'  => 'btih:' . $hash . '&dn=x "download.php?id=99"',
            'old'   => null,
        ),
        'anidub' => array(
            'call'  => array('AniDUBCheckImpl', 'download_torrent'),
            'topic' => 'http://tr.anidub.com/?newsid=7',
            'page'  => 'http://tr.anidub.com/?newsid=7',
            'down'  => 'http://tr.anidub.com/engine/download.php?id=99',
            'body'  => '<div id="tv720"><div id=\'x1\'> <div class="torrent_h"> <a href="/engine/download.php?id=99" ',
            'old'   => 'SibAniDubTorrent',
        ),
    );
}

$suite->test('a download link that did not answer 200 is not a deletion', function () {
    foreach (sibHandlers(SIB_NEW_HASH) as $name => $h) {
        foreach (sibNotFound() as $why => $status) {
            ruTrackerChecker::reset();
            ruTrackerChecker::queueResult('createTorrentFromDownload',
                ruTrackerChecker::STE_CANT_REACH_TRACKER);
            Snoopy::queue($h['page'], 200, $h['body']);
            Snoopy::queue($h['down'], $status, '');
            $old = $h['old'] === null ? null : new $h['old']();

            strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
                call_user_func($h['call'], $h['topic'], SIB_OLD_HASH, $old),
                $name . ' / ' . $why . ' (status ' . $status . ') means "could not fetch"');
            strictAssertSame(1, count(ruTrackerChecker::callsFor('createTorrentFromDownload')),
                $name . ' / ' . $why . ': the shared download validator owns the verdict');
        }
    }

    // The contrast, where the shapes do differ: a page whose hash already
    // matches needs no second fetch at all. anidub has no such short-circuit
    // (it keys on the quality tag, not the hash), so only two are checked.
    Snoopy::reset();
    Snoopy::queue('http://megatfile.cc/forum/viewtopic.php?p=7',
        200, 'Info hash:</td><td><strong>' . SIB_OLD_HASH . '</strong></td>');
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        TfileCheckImpl::download_torrent('http://tfile.me/forum/viewtopic.php?p=7', SIB_OLD_HASH, null),
        'tfile: an unchanged hash is up to date');

    Snoopy::reset();
    Snoopy::queue('https://tapochek.net/viewtopic.php?p=7', 200, 'btih:' . SIB_OLD_HASH . '&dn=x');
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        TapochekNetCheckImpl::download_torrent('https://tapochek.net/viewtopic.php?p=7', SIB_OLD_HASH, null),
        'tapochek: an unchanged hash is up to date');
});

// anidub picks its download link by the quality tag in the torrent's NAME, and
// the tag parser got three of the four forms wrong. The alternation had no
// group, so '\_\[' bound only to the first branch and '\]\_' only to the last
// -- a bdrip matched nothing at all. And PHP fills every capturing group
// PRECEDING the matched one with '', so array_key_exists() was true for the
// tv group on a PSP match and the unconditional assignments ran in order,
// producing 'tvpsp'. Separately, the digit bound was three, so '1080p' -- the
// commonest tag AniDUB publishes -- never matched either.
$suite->test('anidub reads every quality tag it claims to support', function () {
    $saved = SibAniDubTorrent::$torrentName;
    try {
        foreach (array(
            'Show_[720p]_ep01'       => 'tv720',
            'Show_[1080p]_ep01'      => 'tv1080',
            'Show_[PSP]_ep01'        => 'psp',
            'Show_[HWP]_ep01'        => 'hwp',
            'Show_[bdrip720p]_ep01'  => 'bd720',
            'Show_[bdrip1080p]_ep01' => 'bd1080',
        ) as $name => $blockId) {
            SibAniDubTorrent::$torrentName = $name;
            ruTrackerChecker::reset();
            ruTrackerChecker::queueResult('createTorrentFromDownload',
                ruTrackerChecker::STE_CANT_REACH_TRACKER);
            // Only the block matching the parsed quality carries a link, so
            // the URL the handler asks for IS the parse result.
            Snoopy::queue('http://tr.anidub.com/?newsid=7', 200,
                '<div id="' . $blockId . '"><div id=\'x1\'> <div class="torrent_h"> '
                . '<a href="/engine/download.php?id=99" ');
            Snoopy::queue('http://tr.anidub.com/engine/download.php?id=99', 503, '');

            strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
                AniDUBCheckImpl::download_torrent('http://tr.anidub.com/?newsid=7',
                    SIB_OLD_HASH, new SibAniDubTorrent()),
                $name . ': the handler got as far as the download link');
            strictAssertSame(2, count(Snoopy::$requests),
                $name . ': which means it resolved the quality to block "' . $blockId . '"');
        }

        // The grouping matters beyond the bdrip branch: ungrouped, the bare
        // 'PSP' and 'HWP' alternatives are anchored to nothing and match
        // anywhere in the name, so a release that merely MENTIONS them is read
        // as that quality and sent to the wrong block.
        foreach (array(
            'PSP_Port_Show_[720p]_ep01'   => 'tv720',
            'Show_HWP_remux_[1080p]_ep01' => 'tv1080',
        ) as $name => $blockId) {
            SibAniDubTorrent::$torrentName = $name;
            ruTrackerChecker::reset();
            ruTrackerChecker::queueResult('createTorrentFromDownload',
                ruTrackerChecker::STE_CANT_REACH_TRACKER);
            Snoopy::queue('http://tr.anidub.com/?newsid=7', 200,
                '<div id="' . $blockId . '"><div id=\'x1\'> <div class="torrent_h"> '
                . '<a href="/engine/download.php?id=99" ');
            Snoopy::queue('http://tr.anidub.com/engine/download.php?id=99', 503, '');

            strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
                AniDUBCheckImpl::download_torrent('http://tr.anidub.com/?newsid=7',
                    SIB_OLD_HASH, new SibAniDubTorrent()),
                $name . ': the tag in the brackets decides, not a word elsewhere in the name');
            strictAssertSame(2, count(Snoopy::$requests),
                $name . ': resolved to block "' . $blockId . '"');
        }

    } finally {
        SibAniDubTorrent::$torrentName = $saved;
    }
});

$suite->test('anidub: a missing or unsupported quality tag is retryable and logged without guessing', function () {
    $saved = SibAniDubTorrent::$torrentName;
    try {
        foreach (array('Show_without_a_tag', 'Show_[HDTVRip]_ep01') as $name) {
            ruTrackerChecker::reset();
            SibAniDubTorrent::$torrentName = $name;

            strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
                AniDUBCheckImpl::download_torrent('http://tr.anidub.com/?newsid=7',
                    SIB_OLD_HASH, new SibAniDubTorrent()),
                $name . ': an owned torrent the parser cannot classify remains retryable');
            strictAssertSame(0, count(Snoopy::$requests),
                $name . ': the handler does not guess a quality block or make a request');
            $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'unsupported or missing quality tag',
                $name . ': the reason for the retryable verdict is logged');
            strictAssertTrue(strpos($line, $name) !== false,
                $name . ': the diagnostic identifies the torrent name');
        }
    } finally {
        SibAniDubTorrent::$torrentName = $saved;
    }
});

// The handlers delegate their HTTP-200 payload guard to the shared checker and
// propagate its retryable verdict. The real checker validation itself is
// covered by CheckerMetaFetchIntegrationTest.php.
$suite->test('handlers delegate HTTP-200 payload validation and propagate its retryable verdict', function () {
    $wall = '<html><head><title>Вход</title></head><body><form action="login.php">'
          . '<input name="login_username"></form></body></html>';

    foreach (sibHandlers(SIB_NEW_HASH) as $name => $h) {
        foreach (array('a login wall' => $wall, 'an empty body' => '', 'truncated bytes' => 'd8:announce') as $why => $payload) {
            ruTrackerChecker::reset();
            ruTrackerChecker::queueResult('createTorrentFromDownload',
                ruTrackerChecker::STE_CANT_REACH_TRACKER);
            Snoopy::queue($h['page'], 200, $h['body']);
            Snoopy::queue($h['down'], 200, $payload);
            $old = $h['old'] === null ? null : new $h['old']();

            strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
                call_user_func($h['call'], $h['topic'], SIB_OLD_HASH, $old),
                $name . ' / ' . $why . ' served with 200 is "could not fetch", not "deleted"');
            $calls = ruTrackerChecker::callsFor('createTorrentFromDownload');
            strictAssertSame(1, count($calls), $name . ' / ' . $why . ': validation is delegated once');
            strictAssertSame(200, intval($calls[0]['arguments'][0]->status),
                $name . ' / ' . $why . ': the HTTP-200 client is passed through');
            strictAssertSame($payload, $calls[0]['arguments'][0]->results,
                $name . ' / ' . $why . ': the raw response body is preserved');
            strictAssertSame(SIB_OLD_HASH, $calls[0]['arguments'][1],
                $name . ' / ' . $why . ': the predecessor hash is preserved');
            strictAssertSame($old, $calls[0]['arguments'][2],
                $name . ' / ' . $why . ': the already-known predecessor object is preserved');
            // These handlers own no parse of their own. They hand the raw
            // client to the shared guard, which is the only place the bytes
            // are decoded, and never call the replacement boundary -- that one
            // takes an already parsed Torrent.
            strictAssertSame(0, count(ruTrackerChecker::callsFor('createTorrent')),
                $name . ' / ' . $why . ': the parsed-metainfo boundary is never called directly');
        }
    }
});

// Two guards in anidub computed a verdict and dropped it on the floor -- there
// was no `return` -- so a page matching neither pattern read an array key that
// does not exist and then fetched the bare host.
$suite->test('anidub: a page whose quality block is missing stops instead of fetching the bare host', function () {
    foreach (array(
        'no block for this quality' => '<div id="tv1080"><div id=\'x1\'> <div class="torrent_h"> <a href="/engine/download.php?id=99" ',
        'a block with a foreign link' => '<div id="tv720"><div id=\'x1\'> <div class="torrent_h"> <a href="/other/path.php?id=99" ',
    ) as $label => $page) {
        Snoopy::reset();
        Snoopy::queue('http://tr.anidub.com/?newsid=7', 200, $page);

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            AniDUBCheckImpl::download_torrent('http://tr.anidub.com/?newsid=7', SIB_OLD_HASH, new SibAniDubTorrent()),
            $label . ': the handler gives up rather than guessing');
        strictAssertSame(1, count(Snoopy::$requests),
            $label . ': and issues no second request at all');
    }
});

// toloka gets ONE case rather than the full matrix above: it sleeps 5 seconds
// before its first fetch to stay under Cloudflare's radar, so every case costs
// five seconds of suite time. This is the case worth paying for -- the one
// where the handler used to invent a deletion out of a missing link.
$suite->test('toloka: a page with no download link is not a deletion', function () {
    // The page proves the topic is alive (it carries a magnet) and offers no
    // .torrent -- a guest view of a login-gated tracker. The old test ANDed
    // the download id into the hash comparison, so this fell through to
    // download.php?id=0 and its unparseable answer became "deleted".
    Snoopy::reset();
    Snoopy::queue('https://toloka.to/p7', 200,
        'href="magnet:?xt=urn:btih:' . SIB_NEW_HASH . '"');

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        tolokaCheckImpl::download_torrent('https://toloka.to/p7', SIB_OLD_HASH, null),
        'no download link means "could not fetch"');
    strictAssertSame(1, count(Snoopy::$requests),
        'and no second request goes out against download.php?id=0');
});

// Tapochek is the one sibling with a MEASURED, tracker-specific removal marker:
// a topic it no longer serves comes back as HTTP 200 carrying this exact
// sentence. Everything else the handler cannot make sense of stays retryable --
// a login wall, a ratio gate and a protection page are all HTTP 200 too, and
// none of them proves a topic is gone.
$suite->test('tapochek: the tracker\'s own missing-topic sentence is a deletion', function () {
    foreach (array('utf-8' => false, 'windows-1251' => true) as $encoding => $legacy) {
        Snoopy::reset();
        $body = '<html><body><table class="forumline message"><tr><th>Информация</th></tr>'
            . '<tr><td>' . TAP_GONE_UTF8 . '.</td></tr></table></body></html>';
        Snoopy::queue('https://tapochek.net/viewtopic.php?p=7', 200,
            $legacy ? strictCp1251($body) : $body);

        strictAssertSame(ruTrackerChecker::STE_DELETED,
            TapochekNetCheckImpl::download_torrent('https://tapochek.net/viewtopic.php?p=7', SIB_OLD_HASH, null),
            'the measured removal marker is a deletion, served as ' . $encoding);
    }
});

$suite->test('tapochek: any other unreadable 200 topic page is retryable, not "no jurisdiction"', function () {
    // No btih, no download link, no removal marker: a challenge page, a login
    // wall, a layout change. STE_NOT_NEED would say "this handler has nothing
    // to do with the torrent", which is false and stops the plugin looking
    // again; STE_CANT_REACH_TRACKER says "ask later", which is the truth.
    Snoopy::reset();
    Snoopy::queue('https://tapochek.net/viewtopic.php?p=7', 200,
        '<html><body>Just a moment...</body></html>');

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        TapochekNetCheckImpl::download_torrent('https://tapochek.net/viewtopic.php?p=7', SIB_OLD_HASH, null),
        'an unrecognised 200 must stay retryable');
});

$suite->test('tapochek: a URL this handler does not own is still not its business', function () {
    Snoopy::reset();
    strictAssertSame(ruTrackerChecker::STE_DECLINED,
        TapochekNetCheckImpl::download_torrent('https://example.invalid/viewtopic.php?p=7', SIB_OLD_HASH, null),
        'STE_DECLINED keeps its one meaning: not this handler\'s jurisdiction');
    strictAssertSame(0, count(Snoopy::$requests), 'and no request goes out');
});

// The same rule as tapochek's, in the two siblings that still broke it: inside
// a branch the handler OWNS, an answer it cannot read is "ask again later", not
// "this torrent is none of my business". STE_NOT_NEED is the verdict for a URL
// the handler does not own, and nothing else -- it stops the plugin looking.
$suite->test('tfile: a topic page it cannot read is retryable, not "no jurisdiction"', function () {
    // The handler routes tfile.me topics to megatfile.cc, which answered with a
    // parked-domain page (HTTP 200, no Info hash) when the review probed it on
    // 2026-08-21. Until a working canonical endpoint is known, that has to read
    // as "could not check", or a Tfile torrent silently stops being checked.
    Snoopy::reset();
    Snoopy::queue('http://megatfile.cc/forum/viewtopic.php?p=7', 200,
        '<html><body>This domain is parked.</body></html>');

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        TfileCheckImpl::download_torrent('http://tfile.me/forum/viewtopic.php?p=7', SIB_OLD_HASH, null),
        'a page with no Info hash must stay retryable');
});

$suite->test('tfile: a changed hash with no download link is retryable too', function () {
    Snoopy::reset();
    Snoopy::queue('http://megatfile.cc/forum/viewtopic.php?p=7', 200,
        'Info hash:</td><td><strong>' . SIB_NEW_HASH . '</strong></td>');

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        TfileCheckImpl::download_torrent('http://tfile.me/forum/viewtopic.php?p=7', SIB_OLD_HASH, null),
        'no download link means "could not fetch"');
    strictAssertSame(1, count(Snoopy::$requests), 'and no second request goes out');
});

// The rule, held across every handler at once rather than one at a time. A
// handler's URL gate comes from the torrent's own comment, which anybody who
// can hand you a .torrent controls, and an unescaped '.' in a host pattern
// matches any character: 'tolokaXto' claimed the Toloka handler for years
// because every sibling escaped and that one did not.
$suite->test('no handler claims a look-alike of its own host', function () {
    $cases = array(
        'toloka'   => array('call' => array('tolokaCheckImpl', 'download_torrent'),
                            'real' => 'https://toloka.to/p7'),
        'tapochek' => array('call' => array('TapochekNetCheckImpl', 'download_torrent'),
                            'real' => 'https://tapochek.net/viewtopic.php?p=7'),
        'tfile'    => array('call' => array('TfileCheckImpl', 'download_torrent'),
                            'real' => 'http://tfile.me/forum/viewtopic.php?p=7'),
        'anidub'   => array('call' => array('AniDUBCheckImpl', 'download_torrent'),
                            'real' => 'http://tr.anidub.com/?newsid=7'),
    );
    foreach ($cases as $name => $case) {
        // Kinozal has its own suite and is not loaded here.
        if (!class_exists($case['call'][0])) continue;
        // Every dot in the host, one at a time, replaced by a character that a
        // greedy '.' would happily match.
        $host = parse_url($case['real'], PHP_URL_HOST);
        $offsets = array();
        for ($i = 0; $i < strlen($host); $i++) if ($host[$i] === '.') $offsets[] = $i;
        strictAssertTrue(count($offsets) > 0, $name . ': its host has a dot to test');
        foreach ($offsets as $at) {
            $lookalike = $host;
            $lookalike[$at] = 'X';
            $url = str_replace('//' . $host, '//' . $lookalike, $case['real']);
            Snoopy::reset();
            strictAssertSame(ruTrackerChecker::STE_DECLINED,
                call_user_func($case['call'], $url, SIB_OLD_HASH, null),
                $name . ': ' . $lookalike . ' is not its host and must not be claimed');
            strictAssertSame(0, count(Snoopy::$requests),
                $name . ': and no request goes out for ' . $lookalike);
        }
    }
});

$suite->test('a tapochek post containing the missing marker does not trigger deletion when valid btih is present', function () {
    $hash = SIB_OLD_HASH;
    $bodyWithMarkerInPost = '<div class="post_body">Темы, которую вы запросили, не существует</div>'
        . 'btih:' . $hash . '&dn=x "download.php?id=99"';
    Snoopy::queue('https://tapochek.net/viewtopic.php?p=7', 200, $bodyWithMarkerInPost);

    $result = TapochekNetCheckImpl::download_torrent('https://tapochek.net/viewtopic.php?p=7', $hash, null);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result,
        'a matching btih takes precedence over the missing marker in user content');
});

$suite->test('a tapochek post containing the marker and download evidence is not a deletion', function () {
    $bodyWithMarkerInPost = '<div class="post_body">Темы, которую вы запросили, не существует</div>'
        . ' btih:not-a-hash&amp;dn=x <a href="download.php?id=99">download</a>';
    Snoopy::queue('https://tapochek.net/viewtopic.php?p=7', 200, $bodyWithMarkerInPost);

    $result = TapochekNetCheckImpl::download_torrent(
        'https://tapochek.net/viewtopic.php?p=7', SIB_OLD_HASH, null);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'user content plus live-page signals cannot authorize deletion');
});

exit($suite->run());
