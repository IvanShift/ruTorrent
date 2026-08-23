<?php

define('TESTLIB_HANDLER_STUBS', 1);
require_once(__DIR__ . '/TestLib.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/trackers/nnmclub.php');

function nnmReset()
{
    ruTrackerChecker::reset();
    strictSetPrivateStatic('NNMClubCheckImpl', 'donor', false);
    rTorrentSettings::get()->session = '/nonexistent/';
}

function nnmCreates()
{
    return ruTrackerChecker::callsFor('createTorrent');
}

function nnmDynamicScrapeUrl($host, $passkey, $hash)
{
    return 'http://' . $host . '/' . $passkey
        . '/scrape?info_hash=' . rawurlencode(hex2bin($hash));
}

function nnmStaticScrapeUrl($host, $passkey, $hash)
{
    return 'http://' . $host . '/scrape?uk=' . rawurlencode($passkey)
        . '&info_hash=' . rawurlencode(hex2bin($hash));
}

function nnmTopicUrl($topicId)
{
    return 'https://nnmclub.to/forum/viewtopic.php?t=' . $topicId;
}

function nnmDownloadUrl($downloadId)
{
    return 'https://nnmclub.to/forum/download.php?id=' . $downloadId;
}

$suite = new StrictTestSuite();
$realPasskey = 'AbCdEf0123456789AbCdEf0123456789';
$dummyPasskey = str_repeat('f', 32);

$suite->test('scrape hit returns up to date without guest request', function () use ($realPasskey) {
    $rows = array(
        array(
            'label' => 'path-style credential on official tracker host',
            'name' => 'current.bin',
            'announce' => 'http://bt02.nnm-club.cc:2710/' . $realPasskey . '/announce',
            'comment' => nnmTopicUrl(42),
            'url' => nnmTopicUrl(42),
            'scrapeHost' => 'bt02.nnm-club.cc:2710',
            'scrapeMode' => 'dynamic',
        ),
        array(
            'label' => 'announce-only torrent is confirmed by its tracker scrape',
            'name' => 'announce-only.bin',
            'announce' => 'http://bt.searchtor.to/announce?uk=' . $realPasskey,
            'comment' => '',
            'url' => 'http://bt.searchtor.to/announce?uk=' . $realPasskey,
            'scrapeHost' => 'bt.searchtor.to',
            'scrapeMode' => 'static',
        ),
        array(
            'label' => 'documented legacy static tracker host bt.nnm-club.ru',
            'name' => 'legacy-ru.bin',
            'announce' => 'http://bt.nnm-club.ru:2710/announce?uk=' . $realPasskey,
            'comment' => nnmTopicUrl(42),
            'url' => nnmTopicUrl(42),
            'scrapeHost' => 'bt.nnm-club.ru:2710',
            'scrapeMode' => 'static',
        ),
        array(
            'label' => 'documented legacy static tracker host nnm-club.info',
            'name' => 'legacy-info.bin',
            'announce' => 'http://nnm-club.info:2710/announce?uk=' . $realPasskey,
            'comment' => nnmTopicUrl(42),
            'url' => nnmTopicUrl(42),
            'scrapeHost' => 'nnm-club.info:2710',
            'scrapeMode' => 'static',
        ),
        array(
            'label' => 'current searchtor dynamic credential scrapes its own hash',
            'name' => 'current-searchtor.bin',
            'announce' => 'http://bt.searchtor.to/' . $realPasskey . '/announce',
            'comment' => nnmTopicUrl(42),
            'url' => nnmTopicUrl(42),
            'scrapeHost' => 'bt.searchtor.to',
            'scrapeMode' => 'dynamic',
        ),
        array(
            'label' => 'www topic host and static searchtor credential',
            'name' => 'www-topic.bin',
            'announce' => 'http://bt.searchtor.to/announce?uk=' . $realPasskey,
            'comment' => 'https://www.nnmclub.to/forum/viewtopic.php?t=42',
            'url' => 'https://www.nnmclub.to/forum/viewtopic.php?t=42',
            'scrapeHost' => 'bt.searchtor.to',
            'scrapeMode' => 'static',
        ),
    );

    foreach ($rows as $row) {
        nnmReset();
        $raw = strictTorrentRaw(
            $row['name'],
            $row['announce'],
            $row['comment'],
            isset($row['announceList']) ? $row['announceList'] : null
        );
        $torrent = @new Torrent($raw);
        strictAssertTrue(!$torrent->errors(), $row['label'] . ': fixture must parse');
        $hash = $torrent->hash_info();
        $scrapeUrl = $row['scrapeMode'] === 'dynamic'
            ? nnmDynamicScrapeUrl($row['scrapeHost'], $realPasskey, $hash)
            : nnmStaticScrapeUrl($row['scrapeHost'], $realPasskey, $hash);
        Snoopy::queue($scrapeUrl, 200, strictScrapePayload($hash, true));

        $result = NNMClubCheckImpl::download_torrent($row['url'], $hash, $torrent);

        strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, $row['label'] . ': scrape hit is up to date');
        strictAssertSame(
            array(array('fetchComplex', $scrapeUrl)),
            Snoopy::$requests,
            $row['label'] . ': exactly the expected scrape request, no guest request'
        );
        strictAssertSame(0, count(nnmCreates()), $row['label'] . ': up-to-date torrent is not replaced');
    }
});

$suite->test('a comment-dispatch URL cannot supply tracker credentials outside typed announce fields', function () use ($realPasskey) {
    nnmReset();
    $dispatchUrl = 'http://bt.searchtor.to/announce?uk=' . $realPasskey;
    $torrent = @new Torrent(strictTorrentRaw(
        'foreign-announce.bin',
        'http://tracker.example.test/announce',
        $dispatchUrl
    ));
    strictAssertTrue(!$torrent->errors(), 'The foreign-announce fixture must parse');

    $result = NNMClubCheckImpl::download_torrent(
        $dispatchUrl,
        $torrent->hash_info(),
        $torrent
    );

    strictAssertSame(
        ruTrackerChecker::STE_CANT_REACH_TRACKER,
        $result,
        'An untyped dispatch string proves neither tracker auth nor a tracker verdict'
    );
    strictAssertSame(
        array(),
        Snoopy::$requests,
        'A token from the handler URL/comment is never sent to a scrape endpoint'
    );
    strictAssertSame(0, count(nnmCreates()), 'No replacement is authenticated from free text');
});

$suite->test('scrape miss downloads guest torrent and patches real passkey', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $oldRaw = strictTorrentRaw(
        'old.bin',
        'http://bt.searchtor.to/announce?uk=' . $realPasskey,
        nnmTopicUrl(42)
    );
    $oldTorrent = @new Torrent($oldRaw);
    strictAssertTrue(!$oldTorrent->errors(), 'Old torrent fixture must parse');
    $oldHash = $oldTorrent->hash_info();

    $guestRaw = strictTorrentRaw(
        'new.bin',
        'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
        nnmTopicUrl(42),
        array(
            array('http://ipv6.bt.searchtor.to/' . $dummyPasskey . '/announce'),
            array('http://bt.nnmclub.example/' . $dummyPasskey . '/announce'),
            array('https://example.test/announce'),
        )
    );
    $guestTorrent = @new Torrent($guestRaw);
    strictAssertTrue(!$guestTorrent->errors(), 'Guest torrent fixture must parse');
    $guestHash = $guestTorrent->hash_info();
    strictAssertTrue($guestHash !== $oldHash, 'Guest fixture must represent an update');

    Snoopy::queue(
        nnmStaticScrapeUrl('bt.searchtor.to', $realPasskey, $oldHash),
        200,
        strictScrapePayload($oldHash, false)
    );
    Snoopy::queue(nnmTopicUrl(42), 200, '<a href="download.php?id=7">download</a>');
    Snoopy::queue(nnmDownloadUrl(7), 200, $guestRaw);
    ruTrackerChecker::queueResult('createTorrent', null);

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $oldHash, $oldTorrent);

    strictAssertSame(null, $result, 'Successful replacement propagates createTorrent result');
    $creates = nnmCreates();
    strictAssertSame(1, count($creates), 'Changed guest torrent is replaced once');
    strictAssertSame($oldTorrent, $creates[0]['arguments'][2],
        'the handler reuses the predecessor it already parsed');
    strictAssertSame($oldHash, $creates[0]['arguments'][1], 'the replacement targets the old hash');
    $patched = @new Torrent($creates[0]['arguments'][0]);
    strictAssertTrue(!$patched->errors(), 'Patched replacement torrent must remain valid');
    strictAssertSame($guestHash, $patched->hash_info(), 'Passkey patch must not change info hash');
    strictAssertTrue(
        strpos($patched->announce(), 'http://bt.searchtor.to/' . $realPasskey . '/announce') !== false,
        'Primary announce keeps the path form the tracker served and carries the account passkey'
    );
    $patchedRaw = (string) $patched;
    strictAssertTrue(
        strpos($patchedRaw, 'http://ipv6.bt.searchtor.to/' . $realPasskey . '/announce') !== false,
        'Official alternate announce gets the account passkey in the same form'
    );
    strictAssertTrue(
        strpos($patchedRaw, 'http://bt.nnmclub.example/' . $dummyPasskey . '/announce') !== false,
        'Lookalike tracker URL remains unchanged'
    );
    strictAssertTrue(
        strpos($patchedRaw, 'bt.nnmclub.example/announce?uk=' . $realPasskey) === false,
        'Reusable profile passkey is never sent to a lookalike tracker host'
    );
    strictAssertTrue(strpos($patchedRaw, 'https://example.test/announce') !== false, 'Unrelated announce URL remains unchanged');
});

// NNMClub serves one account passkey per user and writes it into the announce
// URL of every torrent that account downloads: `/PASSKEY/announce` in
// currently served .torrents, `announce?uk=PASSKEY` in older downloads. A
// torrent's own passkey is therefore the right key for its own replacement,
// in whichever form the tracker served the replacement's URLs.
$suite->test('a torrent path-form passkey is reused for its own replacement', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $oldRaw = strictTorrentRaw(
        'old-dynamic.bin',
        'http://bt.searchtor.to/' . $realPasskey . '/announce',
        nnmTopicUrl(42)
    );
    $oldTorrent = @new Torrent($oldRaw);
    $oldHash = $oldTorrent->hash_info();
    $guestRaw = strictTorrentRaw(
        'new-dynamic.bin',
        'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
        nnmTopicUrl(42),
        array(
            array('http://bt02.nnm-club.cc:2710/' . $dummyPasskey . '/announce'),
            array('http://bt.nnmclub.example/' . $dummyPasskey . '/announce'),
        )
    );
    Snoopy::queue(
        nnmDynamicScrapeUrl('bt.searchtor.to', $realPasskey, $oldHash),
        200,
        strictScrapePayload($oldHash, false)
    );
    Snoopy::queue(nnmTopicUrl(42), 200, '<a href="download.php?id=7">download</a>');
    Snoopy::queue(nnmDownloadUrl(7), 200, $guestRaw);
    ruTrackerChecker::queueResult('createTorrent', null);

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $oldHash, $oldTorrent);

    strictAssertSame(null, $result, 'Successful replacement propagates createTorrent result');
    $creates = nnmCreates();
    strictAssertSame(1, count($creates), 'Changed guest torrent is replaced once');
    $patched = @new Torrent($creates[0]['arguments'][0]);
    strictAssertTrue(!$patched->errors(), 'Patched replacement torrent must remain valid');
    $patchedRaw = (string) $patched;
    strictAssertTrue(
        strpos($patchedRaw, 'http://bt.searchtor.to/' . $realPasskey . '/announce') !== false,
        'The path form is preserved and carries the account passkey'
    );
    strictAssertTrue(
        strpos($patchedRaw, 'http://bt02.nnm-club.cc:2710/' . $realPasskey . '/announce') !== false,
        'Every official host of the replacement gets the same account passkey'
    );
    strictAssertTrue(
        strpos($patchedRaw, 'http://bt.nnmclub.example/' . $dummyPasskey . '/announce') !== false,
        'A lookalike tracker host keeps the dummy passkey'
    );
    strictAssertTrue(
        strpos($patchedRaw, 'bt.nnmclub.example/' . $realPasskey) === false,
        'The account passkey is never written to a lookalike tracker host'
    );
});

$suite->test('the query form is preserved when the tracker still serves it', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $oldRaw = strictTorrentRaw(
        'old-mixed.bin',
        'http://bt02.nnm-club.cc:2710/' . $realPasskey . '/announce',
        nnmTopicUrl(43)
    );
    $oldTorrent = @new Torrent($oldRaw);
    strictAssertTrue(!$oldTorrent->errors(), 'Old torrent fixture must parse');
    $oldHash = $oldTorrent->hash_info();
    $guestRaw = strictTorrentRaw(
        'new-mixed.bin',
        'http://bt.nnm-club.ru:2710/announce?uk=' . $dummyPasskey,
        nnmTopicUrl(43)
    );
    // The passkey is the account's key on every host, so a failed scrape on
    // the torrent's own host may consult the current official endpoint in the
    // same path form before falling through to the guest download.
    Snoopy::queue(
        nnmDynamicScrapeUrl('bt02.nnm-club.cc:2710', $realPasskey, $oldHash),
        200,
        strictScrapePayload($oldHash, false)
    );
    Snoopy::queue(
        nnmDynamicScrapeUrl('bt.searchtor.to', $realPasskey, $oldHash),
        200,
        strictScrapePayload($oldHash, false)
    );
    Snoopy::queue(nnmTopicUrl(43), 200, '<a href="download.php?id=8">download</a>');
    Snoopy::queue(nnmDownloadUrl(8), 200, $guestRaw);
    ruTrackerChecker::queueResult('createTorrent', null);

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(43), $oldHash, $oldTorrent);

    strictAssertSame(null, $result, 'Successful replacement propagates createTorrent result');
    strictAssertSame(
        array(
            array('fetchComplex', nnmDynamicScrapeUrl('bt02.nnm-club.cc:2710', $realPasskey, $oldHash)),
            array('fetchComplex', nnmDynamicScrapeUrl('bt.searchtor.to', $realPasskey, $oldHash)),
            array('fetch', nnmTopicUrl(43)),
            array('fetch', nnmDownloadUrl(8)),
        ),
        Snoopy::$requests,
        'A failed scrape on the torrent\'s own host consults the official fallback first'
    );
    $creates = nnmCreates();
    strictAssertSame(1, count($creates), 'A legacy-form replacement is still replaced');
    $patchedRaw = (string) @new Torrent($creates[0]['arguments'][0]);
    strictAssertTrue(
        strpos($patchedRaw, 'announce?uk=' . $realPasskey) !== false,
        'A query-form announce keeps that form and carries the account passkey'
    );
    strictAssertTrue(
        strpos($patchedRaw, $dummyPasskey) === false,
        'The dummy passkey is gone from the replacement'
    );
});

$suite->test('a replacement already carrying the account passkey is accepted', function () use ($realPasskey) {
    nnmReset();
    $oldRaw = strictTorrentRaw(
        'old-samekey.bin',
        'http://bt.searchtor.to/' . $realPasskey . '/announce',
        nnmTopicUrl(46)
    );
    $oldTorrent = @new Torrent($oldRaw);
    strictAssertTrue(!$oldTorrent->errors(), 'Old torrent fixture must parse');
    $oldHash = $oldTorrent->hash_info();
    // download.php can serve URLs that already hold the account passkey in
    // canonical form; "nothing to change" must not read as "no URLs found".
    $guestRaw = strictTorrentRaw(
        'new-samekey.bin',
        'http://bt.searchtor.to/' . $realPasskey . '/announce',
        nnmTopicUrl(46)
    );
    $guestTorrent = @new Torrent($guestRaw);
    strictAssertTrue(!$guestTorrent->errors(), 'Guest torrent fixture must parse');
    $guestHash = $guestTorrent->hash_info();
    strictAssertTrue($guestHash !== $oldHash, 'Guest fixture must represent an update');

    Snoopy::queue(
        nnmDynamicScrapeUrl('bt.searchtor.to', $realPasskey, $oldHash),
        200,
        strictScrapePayload($oldHash, false)
    );
    Snoopy::queue(nnmTopicUrl(46), 200, '<a href="download.php?id=11">download</a>');
    Snoopy::queue(nnmDownloadUrl(11), 200, $guestRaw);
    ruTrackerChecker::queueResult('createTorrent', null);

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(46), $oldHash, $oldTorrent);

    strictAssertSame(null, $result, 'An already-authenticated replacement is loaded, not refused');
    $creates = nnmCreates();
    strictAssertSame(1, count($creates), 'The replacement is loaded exactly once');
    $patched = @new Torrent($creates[0]['arguments'][0]);
    strictAssertTrue(!$patched->errors(), 'Loaded replacement torrent must remain valid');
    strictAssertSame($guestHash, $patched->hash_info(), 'An unchanged payload keeps its info hash');
    strictAssertSame(
        'http://bt.searchtor.to/' . $realPasskey . '/announce',
        $patched->announce(),
        'The already-correct announce URL is untouched'
    );
});

$suite->test('a changed torrent without any passkey anywhere is refused', function () use ($dummyPasskey) {
    nnmReset();
    $oldRaw = strictTorrentRaw(
        'old-nokey.bin',
        'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
        nnmTopicUrl(44)
    );
    $oldTorrent = @new Torrent($oldRaw);
    $oldHash = $oldTorrent->hash_info();
    $guestRaw = strictTorrentRaw(
        'new-nokey.bin',
        'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
        nnmTopicUrl(44)
    );
    Snoopy::queue(nnmTopicUrl(44), 200, '<a href="download.php?id=9">download</a>');
    Snoopy::queue(nnmDownloadUrl(9), 200, $guestRaw);

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(44), $oldHash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_ERROR, $result, 'Without a passkey the replacement is refused');
    strictAssertSame(0, count(nnmCreates()), 'An unauthenticated replacement is never loaded');
});

$suite->test('an unavailable or empty download response is a tracker reachability error', function () use ($dummyPasskey) {
    $oldRaw = strictTorrentRaw(
        'download-failure.bin',
        'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
        nnmTopicUrl(48)
    );
    $oldTorrent = @new Torrent($oldRaw);
    strictAssertTrue(!$oldTorrent->errors(), 'Download failure fixture must parse');
    $oldHash = $oldTorrent->hash_info();

    foreach (array(
        'Cloudflare refusal' => array(403, '<html>forbidden</html>'),
        'tracker maintenance' => array(503, '<html>maintenance</html>'),
        'empty successful response' => array(200, ''),
    ) as $label => $response) {
        nnmReset();
        Snoopy::queue(nnmTopicUrl(48), 200, '<a href="download.php?id=13">download</a>');
        Snoopy::queue(nnmDownloadUrl(13), $response[0], $response[1]);

        $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(48), $oldHash, $oldTorrent);

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
            $label . ' says nothing about either the topic or rTorrent');
        strictAssertSame(0, count(nnmCreates()),
            $label . ' never reaches torrent replacement');
    }
});

$suite->test('array topic parameters are rejected without warnings', function () use ($dummyPasskey) {
    nnmReset();
    $url = 'https://nnmclub.to/forum/viewtopic.php?t[]=42';
    $raw = strictTorrentRaw(
        'malformed-topic.bin',
        'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
        $url
    );
    $torrent = @new Torrent($raw);
    set_error_handler(function ($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        $result = NNMClubCheckImpl::download_torrent($url, $torrent->hash_info(), $torrent);
    } finally {
        restore_error_handler();
    }

    // Retryable, not dismissed. STE_NOT_NEED is the scheduler's PERMANENT
    // answer -- a torrent stamped with it stops being sent for a full check --
    // and the handler only runs at all because the torrent is nnmclub's, so
    // "I could not work out which topic this is" cannot also mean "this one
    // needs no checking". The substance of this case is the two assertions
    // below it: no PHP warning, and nothing sent to the network.
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'a topic reference that cannot be read establishes nothing');
    strictAssertSame(0, count(Snoopy::$requests), 'Invalid topic references must not trigger network requests');
});

$suite->test('Cloudflare challenge is a reachability error', function () use ($dummyPasskey) {
    nnmReset();
    $raw = strictTorrentRaw(
        'challenge.bin',
        'http://bt02.nnm-club.cc:2710/' . $dummyPasskey . '/announce',
        nnmTopicUrl(42)
    );
    $torrent = @new Torrent($raw);
    strictAssertTrue(!$torrent->errors(), 'Challenge torrent fixture must parse');
    Snoopy::queue(
        nnmTopicUrl(42),
        200,
        '<html><div id="cf-chl">Cloudflare Turnstile challenge</div></html>'
    );

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $torrent->hash_info(), $torrent);

    strictAssertSame(
        ruTrackerChecker::STE_CANT_REACH_TRACKER,
        $result,
        'Challenge page is temporary tracker unavailability'
    );
    strictAssertSame(0, count(nnmCreates()), 'Challenge page never replaces a torrent');
});

$suite->test('a session listing failure is logged and retried instead of being cached as no donor', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $tempDir = sys_get_temp_dir() . '/nnmclub-donor-retry-' . getmypid() . '-' . mt_rand();
    mkdir($tempDir, 0700, true);

    try {
        $targetUrl = 'http://bt.searchtor.to/' . $dummyPasskey . '/announce';
        $targetRaw = strictTorrentRaw('target-retry.bin', $targetUrl, '');
        $target = @new Torrent($targetRaw);
        strictAssertTrue(!$target->errors(), 'Target torrent fixture must parse');
        $targetHash = $target->hash_info();

        $donorRaw = strictTorrentRaw(
            'donor-retry.bin',
            'http://bt.searchtor.to/announce?uk=' . $realPasskey,
            nnmTopicUrl(77)
        );
        file_put_contents($tempDir . '/donor.torrent', $donorRaw);

        // PHP glob() deterministically returns false for an overlong path.
        // This exercises the real filesystem boundary without a test-only
        // production callback or permission bits that root can bypass.
        rTorrentSettings::get()->session = sys_get_temp_dir() . '/' . str_repeat('x', 5000);
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            NNMClubCheckImpl::download_torrent($targetUrl, $targetHash, $target),
            'the failed listing proves no tracker verdict');

        rTorrentSettings::get()->session = $tempDir . '/';
        Snoopy::queue(
            nnmStaticScrapeUrl('bt.searchtor.to', $realPasskey, $targetHash),
            200,
            strictScrapePayload($targetHash, true)
        );
        strictAssertSame(ruTrackerChecker::STE_UPTODATE,
            NNMClubCheckImpl::download_torrent($targetUrl, $targetHash, $target),
            'the next lookup retries the session directory and finds its donor');
        strictAssertOneLogMatching(ruTrackerChecker::$logs, 'Could not list session torrents',
            'the access failure is distinct from a valid empty directory');
    } finally {
        strictRemoveTree($tempDir);
    }
});

$suite->test('an inaccessible session directory is retried when it becomes available', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $tempDir = sys_get_temp_dir() . '/nnmclub-donor-late-dir-' . getmypid() . '-' . mt_rand();
    strictRemoveTree($tempDir);

    try {
        $targetUrl = 'http://bt.searchtor.to/' . $dummyPasskey . '/announce';
        $targetRaw = strictTorrentRaw('target-late-dir.bin', $targetUrl, '');
        $target = @new Torrent($targetRaw);
        strictAssertTrue(!$target->errors(), 'Target torrent fixture must parse');
        $targetHash = $target->hash_info();
        rTorrentSettings::get()->session = $tempDir . '/';

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            NNMClubCheckImpl::download_torrent($targetUrl, $targetHash, $target),
            'an inaccessible session directory proves no tracker verdict');

        mkdir($tempDir, 0700, true);
        file_put_contents($tempDir . '/donor.torrent', strictTorrentRaw(
            'donor-late-dir.bin',
            'http://bt.searchtor.to/announce?uk=' . $realPasskey,
            nnmTopicUrl(77)
        ));
        Snoopy::queue(
            nnmStaticScrapeUrl('bt.searchtor.to', $realPasskey, $targetHash),
            200,
            strictScrapePayload($targetHash, true)
        );

        strictAssertSame(ruTrackerChecker::STE_UPTODATE,
            NNMClubCheckImpl::download_torrent($targetUrl, $targetHash, $target),
            'the directory is scanned after access is restored');
        strictAssertOneLogMatching(ruTrackerChecker::$logs, 'Could not access session directory',
            'an inaccessible directory is distinct from a valid empty one');
    } finally {
        strictRemoveTree($tempDir);
    }
});

$suite->test('an unreadable donor candidate is logged and does not cache an incomplete scan', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $tempDir = sys_get_temp_dir() . '/nnmclub-donor-unreadable-' . getmypid() . '-' . mt_rand();
    mkdir($tempDir, 0700, true);

    try {
        $targetUrl = 'http://bt.searchtor.to/' . $dummyPasskey . '/announce';
        $targetRaw = strictTorrentRaw('target-unreadable.bin', $targetUrl, '');
        $target = @new Torrent($targetRaw);
        strictAssertTrue(!$target->errors(), 'Target torrent fixture must parse');
        $targetHash = $target->hash_info();
        $candidate = $tempDir . '/broken.torrent';
        symlink($tempDir . '/missing-source.torrent', $candidate);
        rTorrentSettings::get()->session = $tempDir . '/';

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            NNMClubCheckImpl::download_torrent($targetUrl, $targetHash, $target),
            'an unreadable candidate proves no tracker verdict');

        unlink($candidate);
        file_put_contents($candidate, strictTorrentRaw(
            'donor-after-repair.bin',
            'http://bt.searchtor.to/announce?uk=' . $realPasskey,
            nnmTopicUrl(77)
        ));
        Snoopy::queue(
            nnmStaticScrapeUrl('bt.searchtor.to', $realPasskey, $targetHash),
            200,
            strictScrapePayload($targetHash, true)
        );

        strictAssertSame(ruTrackerChecker::STE_UPTODATE,
            NNMClubCheckImpl::download_torrent($targetUrl, $targetHash, $target),
            'the repaired candidate is retried in the same process');
        $line = strictAssertOneLogMatching(ruTrackerChecker::$logs, 'Could not read session torrent',
            'the incomplete scan is visible in the debug log');
        strictAssertTrue(strpos($line, 'broken.torrent') !== false,
            'the diagnostic identifies the unreadable candidate');
    } finally {
        strictRemoveTree($tempDir);
    }
});

$suite->test('donor passkey is used in memory without rewriting session torrent', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $tempDir = sys_get_temp_dir() . '/nnmclub-donor-red-' . getmypid() . '-' . mt_rand();
    mkdir($tempDir, 0700, true);

    try {
        $targetRaw = strictTorrentRaw(
            'target.bin',
            'http://bt02.nnm-club.cc:2710/' . $dummyPasskey . '/announce',
            nnmTopicUrl(42),
            null,
            array(
                'libtorrent_resume' => array('bitfield' => 1),
                'rtorrent' => array('state' => 1),
            )
        );
        $target = @new Torrent($targetRaw);
        strictAssertTrue(!$target->errors(), 'Target torrent fixture must parse');
        $targetHash = $target->hash_info();
        $targetPath = $tempDir . '/' . $targetHash . '.torrent';
        file_put_contents($targetPath, $targetRaw);

        $donorRaw = strictTorrentRaw(
            'donor.bin',
            'http://bt.searchtor.to/announce?uk=' . $realPasskey,
            nnmTopicUrl(77)
        );
        file_put_contents($tempDir . '/' . str_repeat('D', 40) . '.torrent', $donorRaw);

        rTorrentSettings::get()->session = $tempDir . '/';
        Snoopy::queue(
            nnmStaticScrapeUrl('bt.searchtor.to', $realPasskey, $targetHash),
            200,
            strictScrapePayload($targetHash, true)
        );

        $before = file_get_contents($targetPath);
        $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $targetHash, $target);
        $after = file_get_contents($targetPath);

        strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'Donor passkey can authenticate scrape');
        strictAssertSame(
            $before,
            $after,
            'Donor passkey lookup must not mutate the live rTorrent session file'
        );
    } finally {
        strictRemoveTree($tempDir);
    }
});

// The donor is the one remaining cross-torrent transplant: consulted only
// when the torrent being replaced carries no usable key of its own, and only
// for keys another torrent published in the profile-wide `uk=` form. A
// path-form key in a foreign torrent may belong to whoever downloaded that
// file (real sessions do carry torrents fetched from other accounts).
$suite->test('a donor query-form passkey patches a keyless replacement', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $tempDir = sys_get_temp_dir() . '/nnmclub-donor-patch-' . getmypid() . '-' . mt_rand();
    mkdir($tempDir, 0700, true);

    try {
        $oldRaw = strictTorrentRaw(
            'old-donorpatch.bin',
            'http://bt02.nnm-club.cc:2710/' . $dummyPasskey . '/announce',
            nnmTopicUrl(45)
        );
        $oldTorrent = @new Torrent($oldRaw);
        strictAssertTrue(!$oldTorrent->errors(), 'Old torrent fixture must parse');
        $oldHash = $oldTorrent->hash_info();

        $donorRaw = strictTorrentRaw(
            'donor.bin',
            'http://bt.searchtor.to/announce?uk=' . $realPasskey,
            nnmTopicUrl(77)
        );
        file_put_contents($tempDir . '/' . str_repeat('E', 40) . '.torrent', $donorRaw);
        rTorrentSettings::get()->session = $tempDir . '/';

        $guestRaw = strictTorrentRaw(
            'new-donorpatch.bin',
            'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
            nnmTopicUrl(45)
        );

        Snoopy::queue(
            nnmStaticScrapeUrl('bt.searchtor.to', $realPasskey, $oldHash),
            200,
            strictScrapePayload($oldHash, false)
        );
        Snoopy::queue(nnmTopicUrl(45), 200, '<a href="download.php?id=10">download</a>');
        Snoopy::queue(nnmDownloadUrl(10), 200, $guestRaw);
        ruTrackerChecker::queueResult('createTorrent', null);

        $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(45), $oldHash, $oldTorrent);

        strictAssertSame(null, $result, 'A donor-patched replacement propagates createTorrent result');
        $creates = nnmCreates();
        strictAssertSame(1, count($creates), 'The keyless replacement is patched and loaded');
        $patched = @new Torrent($creates[0]['arguments'][0]);
        strictAssertTrue(!$patched->errors(), 'Patched replacement torrent must remain valid');
        strictAssertTrue(
            strpos($patched->announce(), 'http://bt.searchtor.to/' . $realPasskey . '/announce') !== false,
            'The donor passkey is written in the form the replacement URL already uses'
        );
    } finally {
        strictRemoveTree($tempDir);
    }
});

$suite->test('a session path-form passkey is never donated to another torrent', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $tempDir = sys_get_temp_dir() . '/nnmclub-donor-path-' . getmypid() . '-' . mt_rand();
    mkdir($tempDir, 0700, true);

    try {
        $oldRaw = strictTorrentRaw(
            'old-nodonor.bin',
            'http://bt02.nnm-club.cc:2710/' . $dummyPasskey . '/announce',
            nnmTopicUrl(47)
        );
        $oldTorrent = @new Torrent($oldRaw);
        strictAssertTrue(!$oldTorrent->errors(), 'Old torrent fixture must parse');
        $oldHash = $oldTorrent->hash_info();

        $pathDonorRaw = strictTorrentRaw(
            'pathdonor.bin',
            'http://bt.searchtor.to/' . $realPasskey . '/announce',
            nnmTopicUrl(88)
        );
        file_put_contents($tempDir . '/' . str_repeat('F', 40) . '.torrent', $pathDonorRaw);
        rTorrentSettings::get()->session = $tempDir . '/';

        $guestRaw = strictTorrentRaw(
            'new-nodonor.bin',
            'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
            nnmTopicUrl(47)
        );

        Snoopy::queue(nnmTopicUrl(47), 200, '<a href="download.php?id=12">download</a>');
        Snoopy::queue(nnmDownloadUrl(12), 200, $guestRaw);

        $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(47), $oldHash, $oldTorrent);

        strictAssertSame(ruTrackerChecker::STE_ERROR, $result, 'A foreign path-form key must not authenticate a replacement');
        strictAssertSame(0, count(nnmCreates()), 'Nothing is loaded with a foreign path-form key');
        strictAssertSame(
            array(
                array('fetch', nnmTopicUrl(47)),
                array('fetch', nnmDownloadUrl(12)),
            ),
            Snoopy::$requests,
            'A path-form session key yields no credential, so no scrape is attempted'
        );
    } finally {
        strictRemoveTree($tempDir);
    }
});

$suite->test('injectAuthIntoUrl updates every credential form and defaults to the query form', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $cases = array(
        'path and query forms present: both are updated' => array(
            'http://bt.searchtor.to/' . $dummyPasskey . '/announce?uk=' . $dummyPasskey,
            'http://bt.searchtor.to/' . $realPasskey . '/announce?uk=' . $realPasskey,
        ),
        'keyless URL gets the query form every host generation accepts' => array(
            'http://bt.nnm-club.ru:2710/announce',
            'http://bt.nnm-club.ru:2710/announce?uk=' . $realPasskey,
        ),
        'an unrecognized path segment is kept, never doubled' => array(
            'http://bt.searchtor.to/' . str_repeat('a', 40) . '/announce',
            'http://bt.searchtor.to/' . str_repeat('a', 40) . '/announce?uk=' . $realPasskey,
        ),
    );
    foreach ($cases as $label => $case) {
        strictAssertSame(
            $case[1],
            strictInvoke('NNMClubCheckImpl', 'injectAuthIntoUrl', array($case[0], $realPasskey)),
            $label
        );
    }
    strictAssertSame(
        null,
        strictInvoke('NNMClubCheckImpl', 'injectAuthIntoUrl', array('http://bt.nnmclub.example/' . $dummyPasskey . '/announce', $realPasskey)),
        'a lookalike host is not a patchable NNMClub announce URL'
    );
    strictAssertSame(
        null,
        strictInvoke('NNMClubCheckImpl', 'injectAuthIntoUrl', array('https://nnmclub.to/forum/viewtopic.php?t=42', $realPasskey)),
        'a non-announce URL is not patchable'
    );
});

$suite->test('structural parseScrapeResult enforces bencode schema and direct files key', function () {
    nnmReset();
    $targetHex = '0123456789ABCDEF0123456789ABCDEF01234567';
    $targetBin = hex2bin($targetHex);
    $otherHex = 'FEDCBA9876543210FEDCBA9876543210FEDCBA98';
    $otherBin = hex2bin($otherHex);

    $cases = array(
        // Direct key under top-level files -> UPTODATE (1)
        'valid direct key under files' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e10:downloadedi1e10:incompletei0eeee',
            $targetBin,
            1,
        ),
        'valid direct key with surrounding top-level keys' => array(
            'd4:echo5:hello5:filesd20:' . $targetBin . 'dee5:flagsi0ee',
            $targetBin,
            1,
        ),
        'multiple hashes under files, target is first' => array(
            'd5:filesd20:' . $targetBin . 'de20:' . $otherBin . 'deee',
            $targetBin,
            1,
        ),
        'multiple hashes under files, target is second' => array(
            'd5:filesd20:' . $otherBin . 'de20:' . $targetBin . 'deee',
            $targetBin,
            1,
        ),

        // Valid document, target key not under files -> NOT_FOUND (2)
        'valid empty files' => array(
            'd5:filesdee',
            $targetBin,
            2,
        ),
        'valid files with only different hash' => array(
            'd5:filesd20:' . $otherBin . 'deee',
            $targetBin,
            2,
        ),
        'target hash in top-level echo value' => array(
            'd4:echo20:' . $targetBin . '5:filesdee',
            $targetBin,
            2,
        ),
        'target hash in nested dictionary outside files' => array(
            'd5:extrad20:' . $targetBin . '5:valuee5:filesdee',
            $targetBin,
            2,
        ),
        'target hash in peer/stat value of different row' => array(
            'd5:filesd20:' . $otherBin . 'd4:peer20:' . $targetBin . 'eee',
            $targetBin,
            2,
        ),
        'target hash in list value' => array(
            'd4:listl20:' . $targetBin . 'e5:filesdee',
            $targetBin,
            2,
        ),
        'valid negative and large 19-digit integers' => array(
            'd5:filesde5:statsd3:negi-123e4:zeroi0e4:maxpi1234567890123456789e4:maxni-1234567890123456789eee',
            $targetBin,
            2,
        ),

        // Malformed bencode / invalid schema -> FAILED (3)
        'target hash in failure reason without files' => array(
            'd14:failure reason20:' . $targetBin . 'e',
            $targetBin,
            3,
        ),
        'missing files key in dict' => array(
            'd4:echo5:helloe',
            $targetBin,
            3,
        ),
        'scalar string files' => array(
            'd5:files5:helloe',
            $targetBin,
            3,
        ),
        'list files' => array(
            'd5:filesleee',
            $targetBin,
            3,
        ),
        'integer files' => array(
            'd5:filesi123ee',
            $targetBin,
            3,
        ),
        'duplicate top-level files key' => array(
            'd5:filesde5:filesdee',
            $targetBin,
            3,
        ),
        'non-dict root list' => array(
            'ld5:filesdeee',
            $targetBin,
            3,
        ),
        'non-dict root integer' => array(
            'i42e',
            $targetBin,
            3,
        ),
        'non-dict root string' => array(
            '5:hello',
            $targetBin,
            3,
        ),
        'non-string dictionary key' => array(
            'di1e5:filesdee',
            $targetBin,
            3,
        ),
        'truncated after files key' => array(
            'd5:files',
            $targetBin,
            3,
        ),
        'truncated container' => array(
            'd5:filesd',
            $targetBin,
            3,
        ),
        'trailing byte after root' => array(
            'd5:filesdeex',
            $targetBin,
            3,
        ),
        'target key followed by invalid integer proves no early return' => array(
            'd5:filesd20:' . $targetBin . 'i01ee',
            $targetBin,
            3,
        ),
        'target key followed by truncated container proves no early return' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e',
            $targetBin,
            3,
        ),
        'leading zero in string length' => array(
            'd5:filesd020:' . $targetBin . 'deee',
            $targetBin,
            3,
        ),
        'signed string length' => array(
            'd5:filesd+20:' . $targetBin . 'deee',
            $targetBin,
            3,
        ),
        'string length overflow' => array(
            'd5:filesd10000000:aeee',
            $targetBin,
            3,
        ),
        'integer with leading zero i01e' => array(
            'd5:filesde4:numi01ee',
            $targetBin,
            3,
        ),
        'integer minus zero i-0e' => array(
            'd5:filesde4:numi-0e',
            $targetBin,
            3,
        ),
        'integer plus sign i+1e' => array(
            'd5:filesde4:numi+1e',
            $targetBin,
            3,
        ),
        'empty integer ie' => array(
            'd5:filesde4:numie',
            $targetBin,
            3,
        ),
        'integer 20 digits (> 19 digits)' => array(
            'd5:filesde4:numi12345678901234567890e',
            $targetBin,
            3,
        ),
        'hostile deeply nested list' => array(
            'd' . str_repeat('l', 300000),
            $targetBin,
            3,
        ),
        'hostile deeply nested dict' => array(
            'd' . str_repeat('d', 300000),
            $targetBin,
            3,
        ),
        'invalid hash argument: empty string' => array(
            'd5:filesdee',
            '',
            3,
        ),
        'invalid hash argument: 19 bytes' => array(
            'd5:filesdee',
            substr($targetBin, 0, 19),
            3,
        ),
        'invalid hash argument: 21 bytes' => array(
            'd5:filesdee',
            $targetBin . 'X',
            3,
        ),
        'invalid hash argument: null' => array(
            'd5:filesdee',
            null,
            3,
        ),
    );

    foreach ($cases as $label => $spec) {
        $result = strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($spec[0], $spec[1]));
        strictAssertSame($spec[2], $result, $label);
    }
});

$suite->test('bounded depth, token count, and body size limits in scrape parser', function () {
    nnmReset();
    $targetHex = '0123456789ABCDEF0123456789ABCDEF01234567';
    $targetBin = hex2bin($targetHex);

    // Depth 32 accepted (root is depth 1, files is depth 2, plus 30 nested dicts)
    $depth32 = 'd5:filesd' . str_repeat('1:ad', 30) . '1:ai0e' . str_repeat('e', 30) . 'ee';
    strictAssertSame(
        2,
        strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($depth32, $targetBin)),
        'depth 32 is within limit and parses'
    );

    // Depth 33 rejected (31 nested dicts under files -> depth 33)
    $depth33 = 'd5:filesd' . str_repeat('1:ad', 31) . '1:ai0e' . str_repeat('e', 31) . 'ee';
    strictAssertSame(
        3,
        strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($depth33, $targetBin)),
        'depth 33 exceeds MAX_SCRAPE_DEPTH and is rejected'
    );

    // Token count 4096 accepted:
    // root d (1) + files key (1) + files d (1) + list key (1) + list l (1) + 4091 i0e = 4096 tokens
    $tokens4096 = 'd5:filesde4:listl' . str_repeat('i0e', 4091) . 'ee';
    strictAssertSame(
        2,
        strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($tokens4096, $targetBin)),
        'token count 4096 is within limit and parses'
    );

    // Token count 4097 rejected:
    $tokens4097 = 'd5:filesde4:listl' . str_repeat('i0e', 4092) . 'ee';
    strictAssertSame(
        3,
        strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($tokens4097, $targetBin)),
        'token count 4097 exceeds MAX_SCRAPE_TOKENS and is rejected'
    );

    // Body size exactly 1048576 bytes accepted
    // d5:filesde4:data (16 bytes) + 1048551: (8 bytes) + 1048551 bytes of 'a' + e (1 byte) = 1048576 bytes
    $padLen = 1048551;
    $body1MiB = 'd5:filesde4:data' . $padLen . ':' . str_repeat('a', $padLen) . 'e';
    strictAssertSame(1048576, strlen($body1MiB), 'body length fixture must be exactly 1 MiB');
    strictAssertSame(
        2,
        strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($body1MiB, $targetBin)),
        'body exactly 1 MiB is parsed within limit'
    );

    // Body size 1048577 bytes rejected before parsing
    $bodyOver1MiB = $body1MiB . 'x';
    strictAssertSame(1048577, strlen($bodyOver1MiB), 'oversized fixture must be 1048577 bytes');
    strictAssertSame(
        3,
        strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($bodyOver1MiB, $targetBin)),
        'body over 1 MiB is rejected'
    );
});

$suite->test('malformed primary scrape plus malformed or unavailable fallback is retryable with zero guest replacement', function () use ($realPasskey) {
    nnmReset();
    $oldRaw = strictTorrentRaw(
        'old-malformed.bin',
        'http://bt02.nnm-club.cc:2710/' . $realPasskey . '/announce',
        nnmTopicUrl(42)
    );
    $oldTorrent = @new Torrent($oldRaw);
    strictAssertTrue(!$oldTorrent->errors(), 'Old torrent fixture must parse');
    $oldHash = $oldTorrent->hash_info();
    $oldBin = hex2bin($oldHash);

    // Primary scrape returns the review probe: echo containing hash without files dict (malformed)
    Snoopy::queue(
        nnmDynamicScrapeUrl('bt02.nnm-club.cc:2710', $realPasskey, $oldHash),
        200,
        'd4:echo20:' . $oldBin . 'e'
    );
    // Fallback scrape returns HTTP 503
    Snoopy::queue(
        nnmDynamicScrapeUrl('bt.searchtor.to', $realPasskey, $oldHash),
        503,
        '<html>service unavailable</html>'
    );

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $oldHash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'malformed primary plus unavailable fallback is retryable');
    strictAssertSame(0, count(nnmCreates()), 'no replacement is made when scrape fails');
    strictAssertSame(2, count(Snoopy::$requests), 'only scrape requests are issued, no guest fetch');
});

$suite->test('malformed primary scrape plus valid NOT_FOUND fallback proceeds to guest download', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $oldRaw = strictTorrentRaw(
        'old-fallback-ok.bin',
        'http://bt02.nnm-club.cc:2710/' . $realPasskey . '/announce',
        nnmTopicUrl(42)
    );
    $oldTorrent = @new Torrent($oldRaw);
    $oldHash = $oldTorrent->hash_info();

    $guestRaw = strictTorrentRaw(
        'guest-fallback-ok.bin',
        'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
        nnmTopicUrl(42)
    );

    // Primary scrape returns non-dict files (malformed)
    Snoopy::queue(
        nnmDynamicScrapeUrl('bt02.nnm-club.cc:2710', $realPasskey, $oldHash),
        200,
        'd5:files5:helloe'
    );
    // Fallback scrape returns valid empty files (NOT_FOUND)
    Snoopy::queue(
        nnmDynamicScrapeUrl('bt.searchtor.to', $realPasskey, $oldHash),
        200,
        'd5:filesdee'
    );
    Snoopy::queue(nnmTopicUrl(42), 200, '<a href="download.php?id=7">download</a>');
    Snoopy::queue(nnmDownloadUrl(7), 200, $guestRaw);
    ruTrackerChecker::queueResult('createTorrent', null);

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $oldHash, $oldTorrent);

    strictAssertSame(null, $result, 'guest download proceeds after valid NOT_FOUND fallback');
    strictAssertSame(1, count(nnmCreates()), 'replacement occurs once');
});

$suite->test('valid scrape with empty files and target hash in unrelated value proceeds to guest download', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $oldRaw = strictTorrentRaw(
        'old-echo-probe.bin',
        'http://bt.searchtor.to/' . $realPasskey . '/announce',
        nnmTopicUrl(42)
    );
    $oldTorrent = @new Torrent($oldRaw);
    $oldHash = $oldTorrent->hash_info();
    $oldBin = hex2bin($oldHash);

    $guestRaw = strictTorrentRaw(
        'guest-echo-probe.bin',
        'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
        nnmTopicUrl(42)
    );

    // Scrape contains hash in top-level echo value and empty files (valid NOT_FOUND)
    Snoopy::queue(
        nnmDynamicScrapeUrl('bt.searchtor.to', $realPasskey, $oldHash),
        200,
        'd4:echo20:' . $oldBin . '5:filesdee'
    );
    Snoopy::queue(nnmTopicUrl(42), 200, '<a href="download.php?id=7">download</a>');
    Snoopy::queue(nnmDownloadUrl(7), 200, $guestRaw);
    ruTrackerChecker::queueResult('createTorrent', null);

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $oldHash, $oldTorrent);

    strictAssertSame(null, $result, 'echo probe does not certify up to date; guest download runs');
    strictAssertSame(1, count(nnmCreates()), 'replacement occurs');
});


$suite->test('guest transport failure with a curl exit code is logged', function () {
    nnmReset();
    // The https path stores curl's exit code (6 = DNS failure) as the status.
    Snoopy::queue('https://nnmclub.to/forum/viewtopic.php?t=1', 6, '');
    $client = new Snoopy();
    strictInvoke('NNMClubCheckImpl', 'guestFetch', array($client, 'https://nnmclub.to/forum/viewtopic.php?t=1'));
    $failureLogs = array_values(array_filter(ruTrackerChecker::$logs, function ($line) {
        return strpos($line, 'Guest fetch failed') !== false;
    }));
    strictAssertSame(1, count($failureLogs), 'a curl exit-code status must be logged as a failed guest fetch');
    strictAssertTrue(strpos($failureLogs[0], 'status=6') !== false, 'the log line must carry the status');
});

$suite->test('donor credential is not extracted from comments or arbitrary bencode fields', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $tempDir = sys_get_temp_dir() . '/nnmclub-donor-comment-' . getmypid() . '-' . mt_rand();
    mkdir($tempDir, 0700, true);

    try {
        $targetUrl = 'http://bt.searchtor.to/' . $dummyPasskey . '/announce';
        $targetRaw = strictTorrentRaw('target.bin', $targetUrl, '');
        $target = @new Torrent($targetRaw);
        strictAssertTrue(!$target->errors(), 'Target torrent fixture must parse');
        $targetHash = $target->hash_info();

        // Foreign torrent whose announce is unrelated, but comment contains a fake/injected searchtor announce URL with uk=
        $donorRaw = strictTorrentRaw(
            'foreign.bin',
            'http://tracker.example.com/announce',
            'http://bt.searchtor.to/announce?uk=' . $realPasskey
        );
        file_put_contents($tempDir . '/foreign.torrent', $donorRaw);
        rTorrentSettings::get()->session = $tempDir . '/';

        $result = NNMClubCheckImpl::download_torrent($targetUrl, $targetHash, $target);

        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
            'comment credentials must not be used as donor');
        strictAssertSame(array(), Snoopy::$requests,
            'no scrape request must be issued with comment credentials');
    } finally {
        strictRemoveTree($tempDir);
    }
});

$suite->test('an oversized session torrent is skipped before a later normal donor', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $tempDir = sys_get_temp_dir() . '/nnmclub-donor-size-' . getmypid() . '-' . mt_rand();
    mkdir($tempDir, 0700, true);
    $oversizedPasskey = '0123456789abcdef0123456789ABCDEF';

    try {
        $targetUrl = 'http://bt.searchtor.to/' . $dummyPasskey . '/announce';
        $target = @new Torrent(strictTorrentRaw('target-size.bin', $targetUrl, ''));
        strictAssertTrue(!$target->errors(), 'Target torrent fixture must parse');
        $targetHash = $target->hash_info();

        $oversizedRaw = strictTorrentRaw(
            'oversized-donor.bin',
            'http://bt.searchtor.to/announce?uk=' . $oversizedPasskey,
            nnmTopicUrl(70)
        );
        $handle = fopen($tempDir . '/000-oversized.torrent', 'wb');
        fwrite($handle, $oversizedRaw);
        ftruncate($handle, 16 * 1024 * 1024 + 1);
        fclose($handle);

        file_put_contents($tempDir . '/999-donor.torrent', strictTorrentRaw(
            'normal-donor.bin',
            'http://bt.searchtor.to/announce?uk=' . $realPasskey,
            nnmTopicUrl(71)
        ));
        rTorrentSettings::get()->session = $tempDir . '/';

        Snoopy::queue(
            nnmStaticScrapeUrl('bt.searchtor.to', $oversizedPasskey, $targetHash),
            200,
            strictScrapePayload($targetHash, false)
        );
        Snoopy::queue(
            nnmStaticScrapeUrl('bt.searchtor.to', $realPasskey, $targetHash),
            200,
            strictScrapePayload($targetHash, true)
        );

        strictAssertSame(ruTrackerChecker::STE_UPTODATE,
            NNMClubCheckImpl::download_torrent($targetUrl, $targetHash, $target),
            'the bounded scan reaches the normal donor instead of trusting oversized bytes');
        strictAssertSame(
            array(array('fetchComplex', nnmStaticScrapeUrl('bt.searchtor.to', $realPasskey, $targetHash))),
            Snoopy::$requests,
            'only the later normal donor authenticates a scrape'
        );
    } finally {
        strictRemoveTree($tempDir);
    }
});

$suite->test('a malformed session torrent is retried when the same file becomes a valid donor', function () use ($realPasskey) {
    nnmReset();
    $tempDir = sys_get_temp_dir() . '/nnmclub-donor-retry-' . getmypid() . '-' . mt_rand();
    mkdir($tempDir, 0700, true);
    $path = $tempDir . '/changing.torrent';

    try {
        file_put_contents($path, 'd8:announce12:http://short');
        rTorrentSettings::get()->session = $tempDir . '/';

        strictAssertSame(null, strictInvoke('NNMClubCheckImpl', 'findDonorAuth'),
            'a malformed session copy supplies no credential yet');

        file_put_contents($path, strictTorrentRaw(
            'valid-donor.bin',
            'http://bt.searchtor.to/announce?uk=' . $realPasskey,
            nnmTopicUrl(72)
        ));
        $auth = strictInvoke('NNMClubCheckImpl', 'findDonorAuth');

        strictAssertTrue(is_array($auth),
            'an incomplete scan is not negatively cached, so the same path is inspected again');
        strictAssertSame('query', $auth['mode'], 'the replacement donor keeps its typed credential mode');
        strictAssertSame($realPasskey, $auth['token'], 'the second scan returns the now-valid donor');
    } finally {
        strictRemoveTree($tempDir);
    }
});

exit($suite->run());
