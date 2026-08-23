<?php

/**
 * Kinozal handler: every answer that only proves "could not check" must stay
 * retryable, and only the tracker's own "no such torrent" may end as a
 * deletion. Fixtures are the bodies the live site returned on 2026-08-07.
 */

define('TESTLIB_HANDLER_STUBS', 1);
require_once(__DIR__ . '/TestLib.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/trackers/kinozal.php');

function kinozalReset()
{
    ruTrackerChecker::reset();
    // The latch is per-process, and one test process stands in for many
    // production cycles, so it is cleared between them the same way the
    // other private statics in this suite are.
    strictSetPrivateStatic('KinozalCheckImpl', 'sessionDead', false);
    strictSetPrivateStatic('KinozalCheckImpl', 'detailsGuestAnswers', 0);
    strictSetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers', 0);
}

function kinozalTopicUrl($id)
{
    return 'https://kinozal.me/details.php?id=' . $id;
}

function kinozalDetailsUrl($id)
{
    return 'https://kinozal.guru/get_srv_details.php?action=2&id=' . $id;
}

function kinozalDownloadUrl($id)
{
    return 'https://dl.kinozal.guru/download.php?id=' . $id;
}

// get_srv_details.php answers in UTF-8 (Content-Type: text/html; charset=UTF-8)
// even though the rest of the site is windows-1251.
function kinozalDetailsBody($hash)
{
    return '<ul><li>Инфо хеш: ' . $hash . '</li><li>Размер части торрента: 2 МБ</li>'
        . '<li><div class=\'b ing\'>movie.mkv <i>26.75 ГБ (28721509590)</i></div></li></ul>';
}

function kinozalUnauthorizedBody()
{
    return 'Вы не зарегистрированный пользователь или не авторизированы, чтобы '
        . 'зарегистрироваться пройдите <a href=\'/signup.php\' class=\'sba\'>сюда</a>.';
}

function kinozalMissingBody()
{
    return 'Торрент файл не найден.';
}

function kinozalLoginPage()
{
    return '<ul class=lis><li class=mn><a href="/login.php">Вход</a></li>'
        . '<li><a href="/signup.php">Регистрация в Кинозал.GURU</a></li></ul>'
        . '<form method=post action="/takelogin.php">'
        . '<input type=password size=35 id="password" name="password" value=""></form>';
}

// Builds a parseable Kinozal torrent plus its hash and topic URL.
function kinozalTorrent($name, $id)
{
    $raw = strictTorrentRaw($name, 'http://tr2.torrent4me.com/ann?uk=K0I5ZrJ6If1', kinozalTopicUrl($id));
    $torrent = @new Torrent($raw);
    strictAssertTrue(!$torrent->errors(), 'torrent fixture must parse');
    return array($raw, (string) $torrent->hash_info(), $torrent);
}

const KINOZAL_FIXTURE_TOPIC_ID = 2148020;

function kinozalFixture($name = 'current.mkv', $id = KINOZAL_FIXTURE_TOPIC_ID)
{
    list($raw, $hash, $torrent) = kinozalTorrent($name, $id);
    return array(
        'raw' => $raw,
        'hash' => $hash,
        'torrent' => $torrent,
        'topic_url' => kinozalTopicUrl($id),
        'details_url' => kinozalDetailsUrl($id),
        'download_url' => kinozalDownloadUrl($id),
    );
}

function kinozalCase($name = 'current.mkv', $id = KINOZAL_FIXTURE_TOPIC_ID)
{
    kinozalReset();
    return kinozalFixture($name, $id);
}

$suite = new StrictTestSuite();

$suite->test('a guest answer from the details endpoint is a reachability error', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200, kinozalUnauthorizedBody());

    $result = KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'a login wall proves nothing about the topic');
    strictAssertSame(
        array(array('fetchComplex', $case['details_url'])),
        Snoopy::$requests,
        'the chain stops at the details request'
    );
    strictAssertSame(0, count(ruTrackerChecker::callsFor('createTorrent')),
        'the replacement path is never entered');
});

$suite->test('two guest answers in a row stop the rest of the cycle from asking again', function () {
    $firstCase = kinozalCase('first.mkv');
    $secondCase = kinozalFixture('second.mkv', 2144802);
    $thirdCase = kinozalFixture('third.mkv', 2144913);
    Snoopy::queue($firstCase['details_url'], 200, kinozalUnauthorizedBody());
    Snoopy::queue($secondCase['details_url'], 200, kinozalUnauthorizedBody());

    $first = KinozalCheckImpl::download_torrent(
        $firstCase['topic_url'], $firstCase['hash'], $firstCase['torrent']);
    $second = KinozalCheckImpl::download_torrent(
        $secondCase['topic_url'], $secondCase['hash'], $secondCase['torrent']);
    $third = KinozalCheckImpl::download_torrent(
        $thirdCase['topic_url'], $thirdCase['hash'], $thirdCase['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $first, 'a login wall proves nothing');
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $second, 'and neither does the second one');
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $third,
        'a skipped topic keeps the same retryable verdict it would have got the hard way');
    strictAssertSame(
        array(
            array('fetchComplex', $firstCase['details_url']),
            array('fetchComplex', $secondCase['details_url']),
        ),
        Snoopy::$requests,
        'the third topic costs no request: the session is by then known to be gone'
    );
});

$suite->test('the download guest streak resets on metainfo and then latches independently of healthy details', function () {
    $oldA = kinozalCase('old-a.mkv');
    $oldB = kinozalFixture('old-b.mkv', 2144802);
    $oldC = kinozalFixture('old-c.mkv', 2144913);
    $oldD = kinozalFixture('old-d.mkv', 2130523);
    $oldE = kinozalFixture('old-e.mkv', 2135114);
    $newA = kinozalFixture('new-a.mkv');
    $newB = kinozalFixture('new-b.mkv', 2144802);
    $newC = kinozalFixture('new-c.mkv', 2144913);
    $newD = kinozalFixture('new-d.mkv', 2130523);

    Snoopy::queue($oldA['details_url'], 200, kinozalDetailsBody($newA['hash']));
    Snoopy::queue($oldA['download_url'], 200, kinozalLoginPage());
    Snoopy::queue($oldB['details_url'], 200, kinozalDetailsBody($newB['hash']));
    Snoopy::queue($oldB['download_url'], 200, $newB['raw']);
    Snoopy::queue($oldC['details_url'], 200, kinozalDetailsBody($newC['hash']));
    Snoopy::queue($oldC['download_url'], 200, kinozalLoginPage());
    Snoopy::queue($oldD['details_url'], 200, kinozalDetailsBody($newD['hash']));
    Snoopy::queue($oldD['download_url'], 200, kinozalLoginPage());
    // The old implementation reaches this response and returns UPTODATE. The
    // correct latch leaves it untouched; the request count guards that too.
    Snoopy::queue($oldE['details_url'], 200, kinozalDetailsBody($oldE['hash']));
    foreach (array(false, true, false, false) as $isMetainfo)
        ruTrackerChecker::queueResult('isMetainfo', $isMetainfo);
    ruTrackerChecker::queueResult('createTorrent', null);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($oldA['topic_url'], $oldA['hash'], $oldA['torrent']),
        'the first download login wall is retryable');
    strictAssertSame(null,
        KinozalCheckImpl::download_torrent($oldB['topic_url'], $oldB['hash'], $oldB['torrent']),
        'valid metainfo breaks the download guest streak');
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($oldC['topic_url'], $oldC['hash'], $oldC['torrent']),
        'the next guest download starts a fresh streak');
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($oldD['topic_url'], $oldD['hash'], $oldD['torrent']),
        'the second consecutive guest download trips the latch');
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($oldE['topic_url'], $oldE['hash'], $oldE['torrent']),
        'the rest of the cycle is skipped with the same retryable verdict');
    strictAssertSame(8, count(Snoopy::$requests),
        'healthy details do not erase the download streak, and the fifth topic costs no request');
    $classified = ruTrackerChecker::callsFor('isMetainfo');
    strictAssertSame(4, count($classified), 'each downloaded 200 body is classified exactly once');
    strictAssertSame($newB['raw'], $classified[1]['arguments'][0],
        'the valid body occupies its FIFO position between guest pages');
    strictAssertSame(1, count(ruTrackerChecker::callsFor('createTorrent')),
        'only the valid body reaches the replacement seam');
});

$suite->test('a single guest answer is a blink and does not cost the cycle', function () {
    $blinked = kinozalCase('blinked.mkv');
    $healthy = kinozalFixture('healthy.mkv', 2144802);
    Snoopy::queue($blinked['details_url'], 200, kinozalUnauthorizedBody());
    Snoopy::queue($healthy['details_url'], 200, kinozalDetailsBody($healthy['hash']));

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($blinked['topic_url'], $blinked['hash'], $blinked['torrent']),
        'the blink itself is still unproven, so it stays retryable');
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($healthy['topic_url'], $healthy['hash'], $healthy['torrent']),
        'the next topic is checked for real: one answer is not proof of a lost session');
    strictAssertSame(2, count(Snoopy::$requests), 'both topics were asked about');
});

$suite->test('an authenticated answer between two guest ones clears the count', function () {
    $first = kinozalCase('first.mkv');
    $healthy = kinozalFixture('healthy.mkv', 2144802);
    $third = kinozalFixture('third.mkv', 2144913);
    $fourth = kinozalFixture('fourth.mkv', 2130523);
    Snoopy::queue($first['details_url'], 200, kinozalUnauthorizedBody());
    Snoopy::queue($healthy['details_url'], 200, kinozalDetailsBody($healthy['hash']));
    Snoopy::queue($third['details_url'], 200, kinozalUnauthorizedBody());
    Snoopy::queue($fourth['details_url'], 200, kinozalDetailsBody($fourth['hash']));

    KinozalCheckImpl::download_torrent($first['topic_url'], $first['hash'], $first['torrent']);
    KinozalCheckImpl::download_torrent($healthy['topic_url'], $healthy['hash'], $healthy['torrent']);
    KinozalCheckImpl::download_torrent($third['topic_url'], $third['hash'], $third['torrent']);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($fourth['topic_url'], $fourth['hash'], $fourth['torrent']),
        'two guest answers separated by a healthy one are two blinks, not a lost session');
    strictAssertSame(4, count(Snoopy::$requests), 'every topic was asked about on its own merits');
});

$suite->test('a live session checks every topic on its own merits', function () {
    $first = kinozalCase('first.mkv');
    $second = kinozalFixture('second.mkv', 2144802);
    Snoopy::queue($first['details_url'], 200, kinozalDetailsBody($first['hash']));
    Snoopy::queue($second['details_url'], 200, kinozalDetailsBody($second['hash']));

    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($first['topic_url'], $first['hash'], $first['torrent']), 'first topic');
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($second['topic_url'], $second['hash'], $second['torrent']),
        'the latch must not trip on an authenticated answer');
    strictAssertSame(2, count(Snoopy::$requests), 'both topics were asked about');
});

$suite->test('the login page served with status 200 is a reachability error', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200, kinozalLoginPage());

    $result = KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'a followed redirect that lands on login.php is not a verdict');
    strictAssertSame(1, count(Snoopy::$requests), 'the chain stops at the details request');
});

$suite->test('the tracker\'s own "no such torrent" is a deletion', function () {
    $case = kinozalCase('gone.mkv');
    Snoopy::queue($case['details_url'], 200, kinozalMissingBody());

    $result = KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);

    strictAssertSame(ruTrackerChecker::STE_DELETED, $result,
        'an authenticated "not found" is the only authoritative deletion signal');
    strictAssertSame(1, count(Snoopy::$requests), 'a deleted topic needs no download attempt');
});

$suite->test('a windows-1251 "no such torrent" answer is recognised too', function () {
    $case = kinozalCase('gone-cp1251.mkv');
    Snoopy::queue($case['details_url'], 200, strictCp1251(kinozalMissingBody()));

    $result = KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);

    strictAssertSame(ruTrackerChecker::STE_DELETED, $result,
        'the site\'s own legacy charset must not hide the deletion signal');
});

$suite->test('a matching info hash is up to date without a download', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200, kinozalDetailsBody($case['hash']));

    $result = KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'the tracker still lists our hash');
    strictAssertSame(
        array(array('fetchComplex', $case['details_url'])),
        Snoopy::$requests,
        'an up-to-date topic is never downloaded'
    );
});

$suite->test('a changed info hash hands valid metainfo to the replacement', function () {
    $old = kinozalCase('old.mkv');
    $new = kinozalFixture('new.mkv');
    strictAssertTrue($old['hash'] !== $new['hash'], 'the fixtures must represent an update');

    Snoopy::queue($old['details_url'], 200, kinozalDetailsBody($new['hash']));
    Snoopy::queue($old['download_url'], 200, $new['raw']);
    ruTrackerChecker::queueResult('isMetainfo', true);
    ruTrackerChecker::queueResult('createTorrent', null);

    $result = KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']);

    strictAssertSame(null, $result, 'a successful replacement propagates createTorrent\'s result');
    $creates = ruTrackerChecker::callsFor('createTorrent');
    strictAssertSame(1, count($creates), 'the new torrent is handed over once');
    strictAssertSame($new['raw'], $creates[0]['arguments'][0], 'the downloaded bytes are passed through');
    strictAssertSame($old['hash'], $creates[0]['arguments'][1], 'the replacement targets the old hash');
    strictAssertSame($old['torrent'], $creates[0]['arguments'][2],
        'the handler reuses the predecessor it already parsed');
    strictAssertSame(array($new['raw']), ruTrackerChecker::callsFor('isMetainfo')[0]['arguments'],
        'the downloaded bytes are delegated to the metainfo seam');
});

$suite->test('a download redirected to the login page is a reachability error', function () {
    $old = kinozalCase('old.mkv');
    $new = kinozalFixture('new.mkv');

    Snoopy::queue($old['details_url'], 200, kinozalDetailsBody($new['hash']));
    // What dl.kinozal.guru answers without a session, as seen when the
    // redirect chain does not end in a 200: the 302 itself, with the
    // login.php Location it carries on the live site.
    Snoopy::queue($old['download_url'], 302, '',
        array('Location: //kinozal.guru/login.php?to=%2Fdownload.php%3Fid%3D2148020'));

    $result = KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'a redirect to the login wall is not proof of deletion');
    strictAssertSame(0, count(ruTrackerChecker::callsFor('createTorrent')), 'createTorrent is never reached');
});

$suite->test('a login page instead of a torrent is a reachability error', function () {
    $old = kinozalCase('old.mkv');
    $new = kinozalFixture('new.mkv');

    Snoopy::queue($old['details_url'], 200, kinozalDetailsBody($new['hash']));
    Snoopy::queue($old['download_url'], 200, kinozalLoginPage());
    ruTrackerChecker::queueResult('isMetainfo', false);

    $result = KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'HTML where metainfo was expected is not proof of deletion');
    strictAssertSame(0, count(ruTrackerChecker::callsFor('createTorrent')),
        'createTorrent\'s "unparseable means deleted" contract is never invoked');
});

$suite->test('an unparseable download body is a reachability error', function () {
    $old = kinozalCase('old.mkv');
    $new = kinozalFixture('new.mkv');

    Snoopy::queue($old['details_url'], 200, kinozalDetailsBody($new['hash']));
    Snoopy::queue($old['download_url'], 200, 'not a torrent at all');
    ruTrackerChecker::queueResult('isMetainfo', false);

    $result = KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'bytes that are not metainfo are validated before the replacement');
    strictAssertSame(0, count(ruTrackerChecker::callsFor('createTorrent')), 'nothing is handed over');
});

$suite->test('an empty download body is a reachability error', function () {
    $old = kinozalCase('old.mkv');
    $new = kinozalFixture('new.mkv');

    Snoopy::queue($old['details_url'], 200, kinozalDetailsBody($new['hash']));
    Snoopy::queue($old['download_url'], 200, '');
    ruTrackerChecker::queueResult('isMetainfo', false);

    $result = KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, 'an empty body carries no verdict');
    strictAssertSame(0, count(ruTrackerChecker::callsFor('createTorrent')), 'nothing is handed over');
});

$suite->test('a transport failure is a reachability error', function () {
    $case = kinozalCase();
    // The https path stores curl's exit code (6 = DNS failure) as the status.
    Snoopy::queue($case['details_url'], 6, '');

    $result = KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, 'a dead socket is retryable');
});

$suite->test('a server error on the details endpoint is a reachability error', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 503, '<html>maintenance</html>');

    $result = KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'an HTTP error is never a deletion');
});

$suite->test('every Kinozal mirror in the comment is handled', function () {
    foreach (array('kinozal.tv', 'kinozal.me', 'kinozal.guru') as $host) {
        $case = kinozalCase();
        $url = 'https://' . $host . '/details.php?id=2148020';
        Snoopy::queue($case['details_url'], 200, kinozalDetailsBody($case['hash']));

        $result = KinozalCheckImpl::download_torrent($url, $case['hash'], $case['torrent']);

        strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, $host . ' must be recognised');
    }
});

$suite->test('a URL this handler does not own triggers no request', function () {
    $case = kinozalCase();

    $result = KinozalCheckImpl::download_torrent(
        'http://tr2.torrent4me.com/ann?uk=K0I5ZrJ6If1', $case['hash'], $case['torrent']);

    strictAssertSame(ruTrackerChecker::STE_DECLINED, $result, 'an announce URL carries no topic id');
    strictAssertSame(0, count(Snoopy::$requests), 'no request is made');
});

$suite->test('a filename containing the missing marker does not trigger deletion when valid hash is present', function () {
    $case = kinozalCase();
    $bodyWithMarkerInName = '<ul><li>Инфо хеш: ' . $case['hash'] . '</li><li>Размер части торрента: 2 МБ</li>'
        . '<li><div class=\'b ing\'>Торрент файл не найден.txt <i>100 КБ</i></div></li></ul>';
    Snoopy::queue($case['details_url'], 200, $bodyWithMarkerInName);

    $result = KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result,
        'a matching hash takes precedence over the missing marker in a filename');
});

$suite->test('a longer details response beginning with the missing marker is retryable', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200,
        '<div>Торрент файл не найден.txt</div>');

    $result = KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'only the complete measured short answer is authoritative');
});

exit($suite->run());
