<?php

define('TESTLIB_HANDLER_STUBS', 1);
require_once(__DIR__ . '/TestLib.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/trackers/nnmclub.php');

function nnmReset()
{
    ruTrackerChecker::reset();
    rTorrentSettings::get()->session = '/nonexistent/';
}

function nnmCreates()
{
    return ruTrackerChecker::callsFor('createTorrent');
}

// The handler patches the guest torrent it downloaded and hands that very
// object across the replacement boundary. Re-serialising it and decoding the
// bytes again would be a second parse of metainfo already in memory, so this
// insists on the object and never rebuilds one from a string.
function nnmHandedOverTorrent($create)
{
    strictAssertTrue($create['arguments'][0] instanceof Torrent,
        'the already patched guest Torrent is handed over, not re-serialised bytes');
    return $create['arguments'][0];
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

// The guest download's bytes become metainfo in exactly one place --
// ruTrackerChecker::parseMetainfo() -- and TestLib scripts that method, so
// every test that gets as far as the download has to say what the owner
// answers, exactly as KinozalHandlerTest does. The queued object is a fresh
// Torrent over the very bytes the download serves, which is what the owner
// would have returned; the handler then patches THAT object and hands it
// across the replacement boundary, as it does in production.
function nnmQueueGuestParse($raw)
{
    ruTrackerChecker::queueResult('parseMetainfo', @new Torrent($raw));
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
    nnmQueueGuestParse($guestRaw);
    ruTrackerChecker::queueResult('createTorrent', null);

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $oldHash, $oldTorrent);

    strictAssertSame(null, $result, 'Successful replacement propagates createTorrent result');
    // The downloaded bytes become metainfo in the one place that owns that --
    // the same route kinozal.php takes -- and not a second time here. This is
    // the assertion that fails if the three checks ever move back inline: the
    // owner would simply never be asked.
    $parses = ruTrackerChecker::callsFor('parseMetainfo');
    strictAssertSame(1, count($parses),
        'the guest bytes are handed to the owner of the metainfo parse exactly once');
    strictAssertSame(array($guestRaw), $parses[0]['arguments'],
        'and it is handed the bytes the download served, unaltered');
    $creates = nnmCreates();
    strictAssertSame(1, count($creates), 'Changed guest torrent is replaced once');
    strictAssertSame($oldTorrent, $creates[0]['arguments'][2],
        'the handler reuses the predecessor it already parsed');
    strictAssertSame($oldHash, $creates[0]['arguments'][1], 'the replacement targets the old hash');
    $patched = nnmHandedOverTorrent($creates[0]);
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
    nnmQueueGuestParse($guestRaw);
    ruTrackerChecker::queueResult('createTorrent', null);

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $oldHash, $oldTorrent);

    strictAssertSame(null, $result, 'Successful replacement propagates createTorrent result');
    $creates = nnmCreates();
    strictAssertSame(1, count($creates), 'Changed guest torrent is replaced once');
    $patched = nnmHandedOverTorrent($creates[0]);
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
    nnmQueueGuestParse($guestRaw);
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
    $patchedRaw = (string) nnmHandedOverTorrent($creates[0]);
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
    nnmQueueGuestParse($guestRaw);
    ruTrackerChecker::queueResult('createTorrent', null);

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(46), $oldHash, $oldTorrent);

    strictAssertSame(null, $result, 'An already-authenticated replacement is loaded, not refused');
    $creates = nnmCreates();
    strictAssertSame(1, count($creates), 'The replacement is loaded exactly once');
    $patched = nnmHandedOverTorrent($creates[0]);
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
    nnmQueueGuestParse($guestRaw);

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

$suite->test('a keyless torrent skips scrape and resolves up to date via guest download without session donation', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $tempDir = sys_get_temp_dir() . '/nnmclub-no-donor-' . getmypid() . '-' . mt_rand();
    mkdir($tempDir, 0700, true);

    try {
        $targetUrl = 'http://bt.searchtor.to/' . $dummyPasskey . '/announce';
        $targetRaw = strictTorrentRaw('target.bin', $targetUrl, nnmTopicUrl(77));
        $target = @new Torrent($targetRaw);
        strictAssertTrue(!$target->errors(), 'Target torrent fixture must parse');
        $targetHash = $target->hash_info();

        // Foreign torrent in session directory with valid passkey
        $donorRaw = strictTorrentRaw(
            'foreign-donor.bin',
            'http://bt.searchtor.to/announce?uk=' . $realPasskey,
            nnmTopicUrl(88)
        );
        file_put_contents($tempDir . '/donor.torrent', $donorRaw);
        rTorrentSettings::get()->session = $tempDir . '/';

        // Guest topic and guest download returning the same info hash
        Snoopy::queue(nnmTopicUrl(77), 200, '<a href="download.php?id=77">download</a>');
        Snoopy::queue(nnmDownloadUrl(77), 200, $targetRaw);
        nnmQueueGuestParse($targetRaw);

        $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(77), $targetHash, $target);

        strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result,
            'keyless torrent checks up to date via guest download without using other session passkeys');
        strictAssertSame(
            array(
                array('fetch', nnmTopicUrl(77)),
                array('fetch', nnmDownloadUrl(77)),
            ),
            Snoopy::$requests,
            'zero scrape requests issued: foreign session passkey was not harvested'
        );
    } finally {
        strictRemoveTree($tempDir);
    }
});

$suite->test('a keyless torrent whose hash changed refuses replacement and never transplants session passkeys', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $tempDir = sys_get_temp_dir() . '/nnmclub-no-transplant-' . getmypid() . '-' . mt_rand();
    mkdir($tempDir, 0700, true);

    try {
        $oldRaw = strictTorrentRaw(
            'old-keyless.bin',
            'http://bt02.nnm-club.cc:2710/' . $dummyPasskey . '/announce',
            nnmTopicUrl(45)
        );
        $oldTorrent = @new Torrent($oldRaw);
        strictAssertTrue(!$oldTorrent->errors(), 'Old torrent fixture must parse');
        $oldHash = $oldTorrent->hash_info();

        // Foreign torrent in session
        $donorRaw = strictTorrentRaw(
            'foreign.bin',
            'http://bt.searchtor.to/announce?uk=' . $realPasskey,
            nnmTopicUrl(99)
        );
        file_put_contents($tempDir . '/' . str_repeat('E', 40) . '.torrent', $donorRaw);
        rTorrentSettings::get()->session = $tempDir . '/';

        $guestRaw = strictTorrentRaw(
            'new-guest.bin',
            'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
            nnmTopicUrl(45)
        );

        Snoopy::queue(nnmTopicUrl(45), 200, '<a href="download.php?id=10">download</a>');
        Snoopy::queue(nnmDownloadUrl(10), 200, $guestRaw);
        nnmQueueGuestParse($guestRaw);

        $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(45), $oldHash, $oldTorrent);

        strictAssertSame(ruTrackerChecker::STE_ERROR, $result,
            'replacement without own passkey must fail with STE_ERROR');
        strictAssertSame(0, count(nnmCreates()),
            'createTorrent must not be called when replacement has no authenticated passkey');
        strictAssertSame(
            array(
                array('fetch', nnmTopicUrl(45)),
                array('fetch', nnmDownloadUrl(10)),
            ),
            Snoopy::$requests,
            'no scrape request attempted with foreign session key'
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

// The scrape parser moved onto the shared grammar (plugins/rutracker_check/
// bencode.php). Its ceilings did not change, and the two tests that follow
// measure them behaviourally -- this one states them, so a refactor cannot
// quietly hand the grammar a different set and still look green.
$suite->test('a scrape body is decoded under exactly the ceilings this handler has always enforced', function () {
    $limits = (new ReflectionClass('NNMClubCheckImpl'))->getConstant('BENCODE_LIMITS');
    strictAssertSame(
        array(
            'max_bytes' => 1048576,
            'max_depth' => 32,
            'max_tokens' => 4096,
            'max_integer_digits' => 19,
            'max_length_digits' => 7,
        ),
        $limits,
        'the scrape ceilings are exactly 1048576 / 32 / 4096 / 19 / 7'
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
            'd4:echo5:hello5:filesd20:' . $targetBin . 'd8:completei1e10:downloadedi1e10:incompletei0eee5:flagsi0ee',
            $targetBin,
            1,
        ),
        'multiple hashes under files, target is first' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e10:downloadedi1e10:incompletei0ee20:' . $otherBin . 'd8:completei5e10:downloadedi5e10:incompletei1eeee',
            $targetBin,
            1,
        ),
        'multiple hashes under files, target is second' => array(
            'd5:filesd20:' . $otherBin . 'd8:completei5e10:downloadedi5e10:incompletei1ee20:' . $targetBin . 'd8:completei1e10:downloadedi1e10:incompletei0eeee',
            $targetBin,
            1,
        ),
        'valid counters in any order plus unknown field' => array(
            'd5:filesd20:' . $targetBin . 'd10:incompletei0e4:name4:test8:completei1e5:extrai99e10:downloadedi2eeee',
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
            'd5:filesd20:' . $otherBin . 'd8:completei1e10:downloadedi1e10:incompletei0eeee',
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

        // Malformed bencode / invalid schema / malformed target -> FAILED (3)
        'scalar string target value is FAILED' => array(
            'd5:filesd20:' . $targetBin . '5:helloee',
            $targetBin,
            3,
        ),
        'scalar integer target value is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'i123eee',
            $targetBin,
            3,
        ),
        'scalar list target value is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'leee',
            $targetBin,
            3,
        ),
        'empty target dictionary is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'deee',
            $targetBin,
            3,
        ),
        // No single counter is mandatory. A row that carries any one of them is
        // a tracker saying "I hold this hash", which is the only thing the
        // caller asks. The live NNMClub tracker answers with complete and
        // incomplete and no downloaded at all -- see the dedicated test below.
        'complete missing, others present, is UPTODATE' => array(
            'd5:filesd20:' . $targetBin . 'd10:downloadedi1e10:incompletei0eeee',
            $targetBin,
            1,
        ),
        'downloaded missing, others present, is UPTODATE' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e10:incompletei0eeee',
            $targetBin,
            1,
        ),
        'incomplete missing, others present, is UPTODATE' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e10:downloadedi1eeee',
            $targetBin,
            1,
        ),
        'a lone counter is enough' => array(
            'd5:filesd20:' . $targetBin . 'd10:downloadedi1eeee',
            $targetBin,
            1,
        ),
        'wrong list counter for complete is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'd8:completele10:downloadedi1e10:incompletei0eeee',
            $targetBin,
            3,
        ),
        'wrong string counter for downloaded is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e10:downloaded5:hello10:incompletei0eeee',
            $targetBin,
            3,
        ),
        'wrong dict counter for incomplete is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e10:downloadedi1e10:incompletedeeee',
            $targetBin,
            3,
        ),
        'negative complete counter in target is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei-1e10:downloadedi1e10:incompletei0eeee',
            $targetBin,
            3,
        ),
        'negative downloaded counter in target is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e10:downloadedi-5e10:incompletei0eeee',
            $targetBin,
            3,
        ),
        'negative incomplete counter in target is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e10:downloadedi1e10:incompletei-1eeee',
            $targetBin,
            3,
        ),
        'noncanonical integer in target complete is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei01e10:downloadedi1e10:incompletei0eeee',
            $targetBin,
            3,
        ),
        'duplicate target key under files is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e10:downloadedi1e10:incompletei0ee20:' . $targetBin . 'd8:completei1e10:downloadedi1e10:incompletei0eeee',
            $targetBin,
            3,
        ),
        'duplicate complete counter in target dict is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e8:completei2e10:downloadedi1e10:incompletei0eeee',
            $targetBin,
            3,
        ),
        'duplicate downloaded counter in target dict is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e10:downloadedi1e10:downloadedi2e10:incompletei0eeee',
            $targetBin,
            3,
        ),
        'duplicate incomplete counter in target dict is FAILED' => array(
            'd5:filesd20:' . $targetBin . 'd8:completei1e10:downloadedi1e10:incompletei0e10:incompletei1eeee',
            $targetBin,
            3,
        ),
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

$suite->test('the live NNMClub scrape answer is accepted verbatim', function () {
    nnmReset();
    // Captured 2026-08-26 from bt02.nnm-club.cc and bt.searchtor.to, which
    // answer byte for byte alike: HTTP 200, Content-Type application/octet-stream,
    // 67 bytes, not compressed. The per-hash row carries complete and incomplete
    // and NO downloaded key -- opentracker-derived trackers commonly omit it, and
    // BEP-48 does not oblige one. A parser that demanded all three turned this
    // unambiguous "yes, 159 seeders hold it" into SCRAPE_RESULT_FAILED, which
    // download_torrent() reported as a tracker it could not reach, so the torrent
    // silently stopped being checked at all. The bytes are spelled out here rather
    // than assembled from a helper so that a future tightening of the schema has to
    // confront the real answer.
    $targetBin = hex2bin('8E0999F72AD56B77C6F30B77FFA3A492A6BEBC8E');
    $live = 'd5:filesd20:' . $targetBin . 'd8:completei159e10:incompletei1eeee';
    strictAssertSame(67, strlen($live), 'the captured answer is 67 bytes');
    strictAssertSame(
        1,
        strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($live, $targetBin)),
        'the live answer means the hash is on the tracker'
    );

    // Control: the three-counter answer the specification described is read
    // exactly as it always was. Relaxing WHICH keys are mandatory took nothing
    // away from the answer that carries all of them.
    $threeCounter = 'd5:filesd20:' . $targetBin
        . 'd8:completei159e10:downloadedi7e10:incompletei1eeee';
    strictAssertSame(
        1,
        strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($threeCounter, $targetBin)),
        'a well-formed three-counter answer is still accepted'
    );

    // The counters that ARE present stay fully validated: this is a relaxation of
    // which keys must appear, not of how a key that appears is read.
    $negative = 'd5:filesd20:' . $targetBin . 'd8:completei-1e10:incompletei1eeee';
    strictAssertSame(
        3,
        strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($negative, $targetBin)),
        'a negative counter is still malformed'
    );
    $empty = 'd5:filesd20:' . $targetBin . 'deee';
    strictAssertSame(
        3,
        strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($empty, $targetBin)),
        'a row with no counter at all is still malformed'
    );
    $scalar = 'd5:filesd20:' . $targetBin . '5:helloee';
    strictAssertSame(
        3,
        strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($scalar, $targetBin)),
        'a scalar row is still malformed'
    );
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

    // Body size 1048577 bytes rejected before parsing. Keep this valid bencode
    // so relaxing only the byte limit cannot still fail on malformed syntax.
    $overPadLen = $padLen + 1;
    $bodyOver1MiB = 'd5:filesde4:data' . $overPadLen . ':' . str_repeat('a', $overPadLen) . 'e';
    strictAssertSame(1048577, strlen($bodyOver1MiB), 'oversized fixture must be 1048577 bytes');
    strictAssertSame(
        3,
        strictInvoke('NNMClubCheckImpl', 'parseScrapeResult', array($bodyOver1MiB, $targetBin)),
        'body over 1 MiB is rejected'
    );
});

$suite->test('an unreadable primary answer plus an unavailable fallback still reaches guest download', function () use ($realPasskey, $dummyPasskey) {
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

    $guestRaw = strictTorrentRaw(
        'guest-malformed.bin',
        'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
        nnmTopicUrl(42)
    );

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
    Snoopy::queue(nnmTopicUrl(42), 200, '<a href="download.php?id=7">download</a>');
    Snoopy::queue(nnmDownloadUrl(7), 200, $guestRaw);
    nnmQueueGuestParse($guestRaw);
    ruTrackerChecker::queueResult('createTorrent', null);

    // One host answered and could not be understood; that is enough to say the
    // tracker is up, so the fast path is abandoned rather than the whole check.
    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $oldHash, $oldTorrent);

    strictAssertSame(null, $result, 'an unreadable answer falls through to the ordinary check');
    strictAssertSame(1, count(nnmCreates()), 'the guest path runs exactly once');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'No scrape host answered readably',
        'the fall-through is stated in the log');

    // Control: the well-formed three-counter answer still short-circuits on the
    // fast path and never touches the forum. Only the unreadable case moved.
    nnmReset();
    Snoopy::queue(
        nnmDynamicScrapeUrl('bt02.nnm-club.cc:2710', $realPasskey, $oldHash),
        200,
        'd5:filesd20:' . $oldBin . 'd8:completei9e10:downloadedi4e10:incompletei2eeee'
    );
    strictAssertSame(
        ruTrackerChecker::STE_UPTODATE,
        NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $oldHash, $oldTorrent),
        'a well-formed three-counter answer is still up to date'
    );
    strictAssertSame(0, count(nnmCreates()), 'the control makes no replacement');
    strictAssertSame(1, count(Snoopy::$requests), 'the control issues the scrape and nothing else');
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
    nnmQueueGuestParse($guestRaw);
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
    nnmQueueGuestParse($guestRaw);
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
    strictAssertTrue(strpos($failureLogs[0], 'host=nnmclub.to transport=curl-exit code=6 reason=dns') !== false,
        'the log line carries only the host and safe transport category');
    strictAssertTrue(strpos($failureLogs[0], '/forum/') === false
        && strpos($failureLogs[0], 't=1') === false,
        'the log line contains neither path nor query');
});

$suite->test('all scrape transport failures stay retryable and never expose the credential', function () use ($realPasskey) {
    nnmReset();
    $torrent = @new Torrent(strictTorrentRaw(
        'transport-failure.bin',
        'http://bt02.nnm-club.cc:2710/' . $realPasskey . '/announce',
        nnmTopicUrl(42)
    ));
    $hash = $torrent->hash_info();
    Snoopy::queue(nnmDynamicScrapeUrl('bt02.nnm-club.cc:2710', $realPasskey, $hash), 6, '');
    Snoopy::queue(nnmDynamicScrapeUrl('bt.searchtor.to', $realPasskey, $hash), 28, '');

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $hash, $torrent);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'transport-only scrape failure remains retryable');
    strictAssertSame(2, count(Snoopy::$requests),
        'only the two scrape hosts are tried; guest replacement never starts');
    $diagnostics = implode("\n", ruTrackerChecker::$logs);
    strictAssertTrue(strpos($diagnostics, 'Scrape failed on bt02.nnm-club.cc') !== false
        && strpos($diagnostics, 'reason=dns') !== false,
        'the primary host and DNS category are present');
    strictAssertTrue(strpos($diagnostics, 'Scrape failed on bt.searchtor.to') !== false
        && strpos($diagnostics, 'reason=timeout') !== false,
        'the fallback host and timeout category are present');
    strictAssertTrue(strpos($diagnostics, $realPasskey) === false,
        'neither query nor path credential reaches diagnostics');
    strictAssertSame(0, count(nnmCreates()), 'no replacement is attempted');
});

$suite->test('F-02: a bencoded guest download with official announce but no info dictionary returns STE_ERROR without calling createTorrent', function () use ($realPasskey) {
    nnmReset();
    $targetHash = str_repeat('b', 40);
    $targetTopic = 999;
    $targetUrl = nnmTopicUrl($targetTopic);
    $downloadId = 55555;
    $target = @new Torrent(strictTorrentRaw('target.bin', 'http://bt.searchtor.to/announce?uk=' . $realPasskey, $targetUrl));

    Snoopy::queue(
        nnmStaticScrapeUrl('bt.searchtor.to', $realPasskey, $targetHash),
        200,
        strictScrapePayload($targetHash, false)
    );
    Snoopy::queue(nnmTopicUrl($targetTopic), 200, '<a href="download.php?id=' . $downloadId . '">download</a>');
    // Malformed bencoded payload with announce but NO info dictionary:
    Snoopy::queue(nnmDownloadUrl($downloadId), 200, 'd8:announce64:http://bt.searchtor.to/00000000000000000000000000000000/announcee');
    ruTrackerChecker::queueResult('parseMetainfo', null); // no info dictionary: the owner answers null

    $result = NNMClubCheckImpl::download_torrent($targetUrl, $targetHash, $target);

    strictAssertSame(ruTrackerChecker::STE_ERROR, $result,
        'malformed metainfo without info dict is rejected with STE_ERROR');
    strictAssertSame(0, count(nnmCreates()),
        'createTorrent must not be called when downloaded metainfo has no info hash');
    strictAssertSame(1, count(ruTrackerChecker::callsFor('parseMetainfo')),
        'and the refusal is the owner\'s verdict, not a second rule spelled out here');
});

// Which rule decides, proved by making the two disagree. The bytes below are a
// perfectly good torrent: a handler carrying its own copy of the three checks
// would parse them, find a differing hash and go on to replace. Routed through
// the owner, the owner's answer is the only one that counts -- so when it
// refuses, so does this handler, and nothing is replaced. That is what "one
// metainfo parse" has to mean to be worth anything.
$suite->test('the owner\'s verdict is the one that decides, even against bytes that would parse here', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $oldRaw = strictTorrentRaw(
        'old-owner-decides.bin',
        'http://bt.searchtor.to/' . $realPasskey . '/announce',
        nnmTopicUrl(51)
    );
    $oldTorrent = @new Torrent($oldRaw);
    $oldHash = $oldTorrent->hash_info();
    $guestRaw = strictTorrentRaw(
        'new-owner-decides.bin',
        'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
        nnmTopicUrl(51)
    );
    // The control first: these very bytes DO parse, and are a different torrent.
    $wouldParse = @new Torrent($guestRaw);
    strictAssertTrue(!$wouldParse->errors(), 'the fixture is genuinely valid metainfo');
    strictAssertTrue(strtoupper($wouldParse->hash_info()) !== strtoupper($oldHash),
        'and genuinely a replacement, so an inline parse would have gone on to make one');

    // A scrape miss, so the fast path falls through to the guest download.
    Snoopy::queue(nnmDynamicScrapeUrl('bt.searchtor.to', $realPasskey, $oldHash),
        200, strictScrapePayload($oldHash, false));
    Snoopy::queue(nnmTopicUrl(51), 200, '<a href="download.php?id=51">download</a>');
    Snoopy::queue(nnmDownloadUrl(51), 200, $guestRaw);
    ruTrackerChecker::queueResult('parseMetainfo', null); // the owner refuses them

    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(51), $oldHash, $oldTorrent);

    strictAssertSame(ruTrackerChecker::STE_ERROR, $result,
        'the handler refuses because the owner did, not because it looked itself');
    strictAssertSame(0, count(nnmCreates()),
        'and no replacement is built out of bytes the owner would not vouch for');
    strictAssertSame(array($guestRaw), ruTrackerChecker::callsFor('parseMetainfo')[0]['arguments'],
        'the owner was asked, with the served bytes');
});

$suite->test('a malformed answer from every scrape host falls through to guest download', function () use ($realPasskey, $dummyPasskey) {
    nnmReset();
    $oldRaw = strictTorrentRaw(
        'old-malformed-target.bin',
        'http://bt02.nnm-club.cc:2710/' . $realPasskey . '/announce',
        nnmTopicUrl(42)
    );
    $oldTorrent = @new Torrent($oldRaw);
    $oldHash = $oldTorrent->hash_info();
    $oldBin = hex2bin($oldHash);

    $guestRaw = strictTorrentRaw(
        'guest-malformed-target.bin',
        'http://bt.searchtor.to/' . $dummyPasskey . '/announce',
        nnmTopicUrl(42)
    );

    // Primary scrape returns HTTP 200 with malformed target (scalar string value instead of dictionary)
    Snoopy::queue(
        nnmDynamicScrapeUrl('bt02.nnm-club.cc:2710', $realPasskey, $oldHash),
        200,
        'd5:filesd20:' . $oldBin . '5:helloee'
    );
    // Fallback scrape also returns HTTP 200 with malformed target (empty dictionary without counters)
    Snoopy::queue(
        nnmDynamicScrapeUrl('bt.searchtor.to', $realPasskey, $oldHash),
        200,
        'd5:filesd20:' . $oldBin . 'deee'
    );
    Snoopy::queue(nnmTopicUrl(42), 200, '<a href="download.php?id=7">download</a>');
    Snoopy::queue(nnmDownloadUrl(7), 200, $guestRaw);
    nnmQueueGuestParse($guestRaw);
    ruTrackerChecker::queueResult('createTorrent', null);

    // Both hosts are demonstrably up. A shape neither of them will stop sending
    // must not remove this torrent from the checking rotation for good; that is
    // exactly how the missing 'downloaded' counter went unnoticed. The scalar
    // and empty rows above are still refused as scrape evidence -- the half of
    // F07 that was right -- they simply no longer masquerade as an unreachable
    // tracker, and the forum check that follows remains the authority.
    $result = NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $oldHash, $oldTorrent);

    strictAssertSame(null, $result, 'an unreadable scrape does not end the check');
    strictAssertSame(1, count(nnmCreates()), 'the guest path runs exactly once');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'No scrape host answered readably',
        'the fall-through is stated in the log');

    // Control: a well-formed three-counter answer from the primary host is
    // still believed on the spot, with no forum traffic at all.
    nnmReset();
    Snoopy::queue(
        nnmDynamicScrapeUrl('bt02.nnm-club.cc:2710', $realPasskey, $oldHash),
        200,
        'd5:filesd20:' . $oldBin . 'd8:completei3e10:downloadedi2e10:incompletei1eeee'
    );
    strictAssertSame(
        ruTrackerChecker::STE_UPTODATE,
        NNMClubCheckImpl::download_torrent(nnmTopicUrl(42), $oldHash, $oldTorrent),
        'a well-formed three-counter answer is still up to date'
    );
    strictAssertSame(0, count(nnmCreates()), 'the control makes no replacement');
    strictAssertSame(1, count(Snoopy::$requests), 'the control issues the scrape and nothing else');
});

exit($suite->run());
