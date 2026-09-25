<?php

/**
 * Kinozal handler: every answer that only proves "could not check" must stay
 * retryable, and only the tracker's own "no such torrent" may end as a
 * deletion. Fixtures are the bodies the live site returned on 2026-08-07, and
 * the download endpoint's "no such id" page as captured on 2026-09-14.
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
    strictSetPrivateStatic('KinozalCheckImpl', 'cycleAbandoned', false);
    strictSetPrivateStatic('KinozalCheckImpl', 'downloadAbandoned', false);
    strictSetPrivateStatic('KinozalCheckImpl', 'detailsGuestAnswers', 0);
    strictSetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers', 0);
    strictSetPrivateStatic('KinozalCheckImpl', 'detailsTransportFailures', 0);
    strictSetPrivateStatic('KinozalCheckImpl', 'downloadTransportFailures', 0);
    strictSetPrivateStatic('KinozalCheckImpl', 'detailsWalled', false);
}

function kinozalTopics($count)
{
    kinozalReset();
    $cases = array();
    for ($i = 0; $i < $count; $i++) $cases[] = kinozalFixture('topic-' . $i . '.mkv', 1000 + $i);
    return $cases;
}

function kinozalRunAll($cases)
{
    return array_map(function ($case) {
        return KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);
    }, $cases);
}

function kinozalWalledDownloadVerdict($page)
{
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 403, kinozalChallengeBody());
    Snoopy::queue($case['download_url'], 200, $page);
    ruTrackerChecker::queueResult('parseMetainfo', null);
    return KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);
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

// What Cloudflare returns for a managed challenge: HTTP 403 carrying the
// "Just a moment..." interstitial. Trimmed from the live answer measured on
// 2026-09-12 against kinozal.guru and kinozal.me, which challenged every
// interactive path -- get_srv_details.php, details.php, browse.php and
// login.php -- while /, /index.php and /rss.xml still answered 200.
function kinozalChallengeBody()
{
    return '<!DOCTYPE html><html><head><title>Just a moment...</title></head><body>'
        . '<div class="main-content">Enable JavaScript and cookies to continue</div>'
        . '<script src="/cdn-cgi/challenge-platform/h/g/orchestrate/chl_page/v1?ray=a39f"></script>'
        . '</body></html>';
}

// A refusal with nothing to route around: the tracker's own error page. The
// challenge body above is deliberately not used for the unreachable latch --
// a details challenge has a second door and takes it; a download challenge
// still counts as a download refusal.
function kinozalServerErrorBody()
{
    return '<html><head><title>503 Service Unavailable</title></head>'
        . '<body><h1>Service Unavailable</h1></body></html>';
}

// What Cloudflare serves when the ORIGIN is the problem: its own 52x page. It
// names Cloudflare in its footer and its markup, and carries none of the
// interstitial's markers -- no challenge script, no "Just a moment". Shaped
// after the 5xx pages Cloudflare serves for every zone it fronts.
function kinozalCloudflareErrorBody()
{
    return '<!DOCTYPE html><html><head><title>kinozal.guru | 521: Web server is down</title></head>'
        . '<body><div id="cf-error-details"><h1>Web server is down</h1><span>Error code 521</span>'
        . '<p>The web server is not returning a connection. Cloudflare Ray ID: 8f3a2b1c</p>'
        . '<a href="https://www.cloudflare.com/5xx-error-landing">Performance &amp; security by Cloudflare</a>'
        . '</div></body></html>';
}

function kinozalMissingBlock()
{
    return '<div class=pad5x5>' . KinozalCheckImpl::DOWNLOAD_MISSING_CP1251 . '</div>';
}

// What dl.kinozal.guru serves for an id it does not have, verbatim. Captured
// 2026-09-14 from inside the production container on the stored loginmgr
// session, for id=99999999: HTTP 200, 4010 bytes, windows-1251, md5
// 203eba79aafd61051fe1552dec42febc -- the same md5 the page had that morning
// for id=2124498, a topic that was in the fleet and is gone, so the answer is
// about the id and not about one topic. The bytes are carried whole rather
// than assembled from a helper so that a change to the anchor or the marker
// has to confront the real page: one pad5x5 block on it, written
// <div class=pad5x5>, whose text is the marker and nothing else. No account
// name, cookie or passkey is on the page (checked before it was pinned).
//
// The marker is literal cp1251 bytes in the handler rather than converted,
// because the production container's PHP has no iconv(): a UTF-8 needle would
// never match this page there without the literal legacy marker.
function kinozalDownloadMissingBody()
{
    $page = base64_decode(''
        . 'PCFET0NUWVBFIEhUTUw+DQo8aHRtbCBsYW5nPSJydSI+DQo8aGVhZD4NCjxtZXRhIGh0dHAtZXF1aXY9IlgtVUEtQ29tcGF0'
        . 'aWJsZSIgY29udGVudD0iSUU9ZWRnZSI+DQo8bWV0YSBodHRwLWVxdWl2PSJDb250ZW50LVR5cGUiIGNvbnRlbnQ9InRleHQv'
        . 'aHRtbDsgY2hhcnNldD13aW5kb3dzLTEyNTEiPg0KPHRpdGxlPtLu8PDl7fIg8vDl6uXwIMro7e7n4OsuR1VSVTwvdGl0bGU+'
        . 'CjxtZXRhIG5hbWU9ImRlc2NyaXB0aW9uIiBjb250ZW50PSLS7vDw5e3yIPLw5erl8CDK6O3u5+DrLkdVUlUgLSD06Ov87Psg'
        . '6CDx5fDo4Ov7LCDs8+v88vTo6/zs+ywg6u3o4+gg6CDs8+f76uAsIPHq4Pfg8vwg4eXx7+vg8u3uIPEg8vDl6uXw4CI+Cjxt'
        . 'ZXRhIG5hbWU9IktleXdvcmRzIiBjb250ZW50PSIyNTAg6/P3+Oj1IPTo6/zs7uIsIDEwMCDr8/f46PUg9Ojr/Ozu4iwg6/P3'
        . '+OjlIOru7OXk6OgsIOvz9/jo5SD06Ov87Psg8+bg8e7iLCDr8/f46OUg5PDg7PssIOvz9/jo5SD06Ov87PsgK+Lx5fUg4vDl'
        . '7OXtLCBraW5vemFsLCDx6uD34PL8LCDy7vDw5e3yLfLw5erl8Cwg8u7w8OXt8iDx6uD34PL8IOHl8e/r4PLt7iI+CjxtZXRh'
        . 'IG5hbWU9InJvYm90cyIgY29udGVudD0iaW5kZXgsZm9sbG93Ij4NCjxsaW5rIHJlbD0ic2hvcnRjdXQgaWNvbiIgaHJlZj0i'
        . 'L3BpYy9mYXZpY29uLmljbyIgdHlwZT0iaW1hZ2UveC1pY29uIj4NCjxsaW5rIHJlbD0ic3R5bGVzaGVldCIgaHJlZj0iL3Bp'
        . 'Yy8wX2tpbm96YWwuZ3VydS5jc3M/dj0zLjMiIHR5cGU9InRleHQvY3NzIj4KPHNjcmlwdCB0eXBlPSJ0ZXh0L2phdmFzY3Jp'
        . 'cHQiIHNyYz0iL3BpYy9qcXVlcnktMy42LjMubWluLmpzP3Y9MSI+PC9zY3JpcHQ+CjxzY3JpcHQgdHlwZT0idGV4dC9qYXZh'
        . 'c2NyaXB0IiBzcmM9Ii9waWMvdXNlLmpzP3Y9My43Ij48L3NjcmlwdD4KDQoNCjwvaGVhZD4NCjxib2R5Pg0KDQo8ZGl2IGlk'
        . 'PSJib2R5X3dyYXBwZXIiPg0KPGRpdiBpZD0iaGVhZGVyIj4NCgk8dGFibGUgc3R5bGU9IndpZHRoOjEwMCU7cGFkZGluZzow'
        . 'O21hcmdpbjowO2JvcmRlcjowOyI+PHRyPg0KCQk8dGQgc3R5bGU9IndpZHRoOjQwJTsiPjxkaXYgY2xhc3M9ImxvZ29fbmV3'
        . 'Ij48YSBocmVmPSJodHRwczovL2tpbm96YWwuZ3VydSIgdGl0bGU9Isro7e7n4OsuR1VSVSI+PGltZyBzcmM9Ii9waWMvbG9n'
        . 'b19raW5vemFsX2d1cnUucG5nP3Y9MiIgYWx0PSLK6O3u5+DrLkdVUlUiPjwvYT48L2Rpdj48L3RkPg0KCQk8dGQgc3R5bGU9'
        . 'IndpZHRoOjYwJTsiIGFsaWduPXJpZ2h0PjxkaXYgY2xhc3M9InJiX25ldyIgc3R5bGU9ImhlaWdodDo4MHB4O292ZXJmbG93'
        . 'OiBoaWRkZW47Ij4NCg0KPGRpdiBzdHlsZT0icGFkZGluZzowIDVweCAwIDEwcHgiPjxkaXYgaWQ9J2ViNzUwZDQ5Y2YnPjwv'
        . 'ZGl2PjwvZGl2Pg0KDQo8L2Rpdj48L3RkPjwvdHI+PC90YWJsZT4NCjxkaXYgY2xhc3M9ImNsciI+PC9kaXY+DQoJPGRpdiBj'
        . 'bGFzcz0ibWVudSI+DQoJPHVsPg0KCQk8bGk+PGEgaHJlZj0iLyIgdGl0bGU9IsPr4OLt4P8iPsPr4OLt4P88L2E+PC9saT4N'
        . 'CgkJPGxpPjxhIGhyZWY9Imh0dHBzOi8vZm9ydW0ua2lub3phbC5ndXJ1IiB0aXRsZT0i1O7w8+wiPtTu8PPsPC9hPjwvbGk+'
        . 'DQoJCTxsaT48YSBocmVmPSIvYnJvd3NlLnBocCIgdGl0bGU9Isrg8uDr7uMg8ODn5OD3Ij7Q4Ofk4PfoPC9hPjwvbGk+DQoJ'
        . 'CTxsaT48YSBocmVmPSIvdG9wLnBocCIgdGl0bGU9ItLu7yDw4Ofk4PciPtLu7yDw4Ofk4Pc8L2E+PC9saT4NCgkJPGxpPjxh'
        . 'IGhyZWY9Ii9wZXJzb25zZWFyY2gucGhwIiB0aXRsZT0iz+Xw8e7t+yI+z+Xw8e7t+zwvYT48L2xpPg0KCQk8bGk+PGEgaHJl'
        . 'Zj0iL25vdmlua2kucGhwIiB0aXRsZT0ize7i6O3q6CDq6O3uIj7N7uLo7eroIOro7e48L2E+PC9saT4NCgkJPGxpPjxhIGhy'
        . 'ZWY9Ii9ncm91cGV4bGlzdC5waHAiIHRpdGxlPSLK4PLg6+7jIOPw8+/vIj7D8PPv7/s8L2E+PC9saT4NCgkJPGxpPjxhIGhy'
        . 'ZWY9Ii9yYWRpby5waHAiIHRpdGxlPSLQ4OTo7iI+0ODk6O48L2E+PC9saT4NCgk8L3VsPg0KCTxmb3JtIGFjdGlvbj0iL2Jy'
        . 'b3dzZS5waHAiIG1ldGhvZD0iZ2V0IiBpZD0ic3JjaGZvcm0iPg0KCQk8ZGl2PjxpbnB1dCB0eXBlPSJ0ZXh0IiBjbGFzcz0i'
        . 'aW5wIiBpZD0icyIgbmFtZT0icyIgc2l6ZT0iMTUiIHZhbHVlPSIiPjxpbnB1dCBjbGFzcz0ic19zdWJtaXQiIHR5cGU9InN1'
        . 'Ym1pdCIgdGl0bGU9Is/u6PHqIPDg5+Tg9yI+PC9kaXY+DQoJPC9mb3JtPg0KCTxkaXYgY2xhc3M9ImNsciI+PC9kaXY+DQoJ'
        . 'PC9kaXY+DQoJPHNwYW4gY2xhc3M9Inphbl9sIj48L3NwYW4+DQoJPHNwYW4gY2xhc3M9Inphbl9yIj48L3NwYW4+DQo8L2Rp'
        . 'dj4NCjxkaXYgY2xhc3M9ImNsciI+PC9kaXY+DQo8ZGl2IGlkPSJtYWluIj4NCjxkaXYgc3R5bGU9IndpZHRoOiAxMDAlOyB0'
        . 'ZXh0LWFsaWduOiBjZW50ZXI7Ij48ZGl2IHN0eWxlPSJ3aWR0aDogNzAwcHg7IGRpc3BsYXk6IGlubGluZS1ibG9jazt0ZXh0'
        . 'LWFsaWduOiBsZWZ0OyI+DQo8ZGl2IGNsYXNzPSJieDEiPjx1bCBjbGFzcz1tZW4+PGxpIGNsYXNzPWI+PHNwYW4gY2xhc3M9'
        . 'J2J1bGV0Jz48L3NwYW4+zvjo4ergPC9saT4KPGxpPjxkaXYgY2xhc3M9cGFkNXg1Ps3l8iDw4Ofk4PfoIPEg8uDq6OwgSUQu'
        . 'PC9kaXY+PC9saT48L3VsPjwvZGl2Pgo8ZGl2IGNsYXNzPWJ4MV8wPgoJPHA+z+Xw5enk6PLlIO3gIMPr4OLt8/4g8fLw4O3o'
        . '9vMgPGEgaHJlZj0iLyIgY2xhc3M9c2JhYj7n5OXx/DwvYT4uPC9wPgoJPHA+z+7v8O7h8+ny5SDi8PP37fP+IO/u6PHq4PL8'
        . 'IPDg5+Tg9+ggPGEgaHJlZj0iL2Jyb3dzZS5waHAiIGNsYXNzPXNiYWI+5+Tl8fw8L2E+LjwvcD4KCTxwPs/u8ezu8vDl8vwg'
        . '0u7vIPDg5+Tg9yA8YSBocmVmPSIvdG9wLnBocCIgY2xhc3M9c2JhYj7n5OXx/DwvYT4uPC9wPgoJPHA+z+7x5fLo8vwg8ODn'
        . '5OXrIM/l8PHu7SA8YSBocmVmPSIvcGVyc29uc2VhcmNoLnBocCIgY2xhc3M9c2JhYj7n5OXx/DwvYT4uPC9wPgoJPHA+z+7x'
        . '5fLo8vwg8ODn5OXrIMPw8+/vIDxhIGhyZWY9Ii9ncm91cGV4bGlzdC5waHAiIGNsYXNzPXNiYWI+5+Tl8fw8L2E+LjwvcD4K'
        . 'PC9kaXY+CjwvZGl2PjxkaXYgY2xhc3M9ImNsciI+PC9kaXY+DQo8L2Rpdj4NCjwvZGl2Pg0KPC9kaXY+CjxzY3JpcHQgdHlw'
        . 'ZT0ndGV4dC9qYXZhc2NyaXB0JyBzcmM9J2h0dHBzOi8vZGVsdGFyb2NrbWUuY29tL3NlcnZpY2VzLz9pZD0xNTM4MzUnPjwv'
        . 'c2NyaXB0Pg0KDQo8c2NyaXB0IHR5cGU9J3RleHQvamF2YXNjcmlwdCcgZGF0YS1jZmFzeW5jPSdmYWxzZSc+DQoJbGV0IGVi'
        . 'MzI5OWVkMmNfY250ID0gMDsNCglsZXQgZWIzMjk5ZWQyY19pbnRlcnZhbCA9IHNldEludGVydmFsKGZ1bmN0aW9uKCl7DQoJ'
        . 'CWlmICh0eXBlb2YgZWIzMjk5ZWQyY19jb3VudHJ5ICE9PSAndW5kZWZpbmVkJykgew0KCQkJY2xlYXJJbnRlcnZhbChlYjMy'
        . 'OTllZDJjX2ludGVydmFsKTsNCgkJCShmdW5jdGlvbigpew0KCQkJCXZhciB1ZDsNCgkJCQl0cnkgeyB1ZCA9IGxvY2FsU3Rv'
        . 'cmFnZS5nZXRJdGVtKCdlYjMyOTllZDJjX3VpZCcpOyB9IGNhdGNoIChlKSB7IH0NCgkJCQl2YXIgc2NyaXB0ID0gZG9jdW1l'
        . 'bnQuY3JlYXRlRWxlbWVudCgnc2NyaXB0Jyk7DQoJCQkJc2NyaXB0LnR5cGUgPSAndGV4dC9qYXZhc2NyaXB0JzsNCgkJCQlz'
        . 'Y3JpcHQuY2hhcnNldCA9ICd1dGYtOCc7DQoJCQkJc2NyaXB0LmFzeW5jID0gJ3RydWUnOw0KCQkJCXNjcmlwdC5zcmMgPSAn'
        . 'aHR0cHM6Ly8nICsgZWIzMjk5ZWQyY19kb21haW4gKyAnLycgKyBlYjMyOTllZDJjX3BhdGggKyAnLycgKyBlYjMyOTllZDJj'
        . 'X2ZpbGUgKyAnLmpzPzI1NjM1JnY9MyZ1PScgKyB1ZCArICcmYT0nICsgTWF0aC5yYW5kb20oKTsNCgkJCQlkb2N1bWVudC5i'
        . 'b2R5LmFwcGVuZENoaWxkKHNjcmlwdCk7DQoJCQl9KSgpOw0KCQl9IGVsc2Ugew0KCQkJZWIzMjk5ZWQyY19jbnQgKz0gMTsN'
        . 'CgkJCWlmIChlYjMyOTllZDJjX2NudCA+PSA2MCkgew0KCQkJCWNsZWFySW50ZXJ2YWwoZWIzMjk5ZWQyY19pbnRlcnZhbCk7'
        . 'DQoJCQl9DQoJCX0NCgl9LCA1MDApOw0KPC9zY3JpcHQ+DQo8L2JvZHk+PC9odG1sPgo='
    );
    strictAssertSame(4010, strlen($page), 'the captured page is 4010 bytes');
    return $page;
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
    // Download refusal must not suppress a healthy details verdict.
    Snoopy::queue($oldE['details_url'], 200, kinozalDetailsBody($oldE['hash']));
    foreach (array(null, $newB['torrent'], null, null) as $parsed)
        ruTrackerChecker::queueResult('parseMetainfo', $parsed);
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
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($oldE['topic_url'], $oldE['hash'], $oldE['torrent']),
        'a healthy details verdict remains available after download guest failures');
    strictAssertSame(9, count(Snoopy::$requests),
        'healthy details do not erase the download streak and remain independently queryable');
    strictAssertSame(2, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'),
        'a healthy details answer cannot reset the unrelated download guest streak');
    strictAssertSame(true, strictGetPrivateStatic('KinozalCheckImpl', 'downloadAbandoned'),
        'the download door remains latched after healthy details');
    $classified = ruTrackerChecker::callsFor('parseMetainfo');
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
    foreach (array('', '.') as $suffix) {
    $case = kinozalCase('gone-cp1251.mkv');
    Snoopy::queue($case['details_url'], 200, "\xd2\xee\xf0\xf0\xe5\xed\xf2 \xf4\xe0\xe9\xeb \xed\xe5 \xed\xe0\xe9\xe4\xe5\xed" . $suffix);

    $result = KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);

    strictAssertSame(ruTrackerChecker::STE_DELETED, $result,
        'the site\'s own legacy charset must not hide the deletion signal');
    }
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
    ruTrackerChecker::queueResult('parseMetainfo', $new['torrent']);
    ruTrackerChecker::queueResult('createTorrent', null);

    $result = KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']);

    strictAssertSame(null, $result, 'a successful replacement propagates createTorrent\'s result');
    $creates = ruTrackerChecker::callsFor('createTorrent');
    strictAssertSame(1, count($creates), 'the new torrent is handed over once');
    strictAssertSame($new['torrent'], $creates[0]['arguments'][0],
        'the parsed metainfo object is passed through, so the bytes are decoded only once');
    strictAssertSame($old['hash'], $creates[0]['arguments'][1], 'the replacement targets the old hash');
    strictAssertSame($old['torrent'], $creates[0]['arguments'][2],
        'the handler reuses the predecessor it already parsed');
    strictAssertSame(array($new['raw']), ruTrackerChecker::callsFor('parseMetainfo')[0]['arguments'],
        'the downloaded bytes are delegated to the single parse boundary');
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
    ruTrackerChecker::queueResult('parseMetainfo', null);

    $result = KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'HTML where metainfo was expected is not proof of deletion');
    strictAssertSame(0, count(ruTrackerChecker::callsFor('createTorrent')),
        'the replacement boundary is never reached: bytes that do not parse are a retryable error');
});

$suite->test('an unparseable download body is a reachability error', function () {
    $old = kinozalCase('old.mkv');
    $new = kinozalFixture('new.mkv');

    Snoopy::queue($old['details_url'], 200, kinozalDetailsBody($new['hash']));
    Snoopy::queue($old['download_url'], 200, 'not a torrent at all');
    ruTrackerChecker::queueResult('parseMetainfo', null);

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
    ruTrackerChecker::queueResult('parseMetainfo', null);

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
    foreach (KinozalCheckImpl::SITE_HOSTS as $host) {
        $ownerUrl = 'https://' . $host . '/details.php?id=1';
        strictAssertSame(1, preg_match(KinozalCheckImpl::topicPattern(), $ownerUrl),
            $host . ' has an owner topic pattern');
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

// ---------------------------------------------------------------------------
// A host that refuses everybody, as opposed to a session that has died.
// Socket failures and HTTP error pages trip the transport fuse. A details
// challenge instead takes the independently tested fallback route.
$suite->test('three details refusals stop that endpoint, including Cloudflare origin errors', function () {
    foreach (array('origin' => array(503, kinozalServerErrorBody()),
        'Cloudflare origin' => array(521, kinozalCloudflareErrorBody())) as $label => $response) {
        $cases = kinozalTopics(4);
        foreach (array_slice($cases, 0, 3) as $case)
            Snoopy::queue($case['details_url'], $response[0], $response[1]);
        foreach (kinozalRunAll($cases) as $result)
            strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, $label . ': no topic verdict');
        strictAssertSame(array_map(function ($case) { return array('fetchComplex', $case['details_url']); },
            array_slice($cases, 0, 3)), Snoopy::$requests, $label . ': no rerouting and no fourth request');
    }
});

$suite->test('an answer that did arrive clears the transport streak', function () {
    list($a, $b, $healthy, $d, $e, $f) = kinozalTopics(6);
    Snoopy::queue($a['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($b['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($healthy['details_url'], 200, kinozalDetailsBody($healthy['hash']));
    Snoopy::queue($d['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($e['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($f['details_url'], 503, kinozalServerErrorBody());

    $verdicts = kinozalRunAll(array($a, $b, $healthy, $d, $e, $f));

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $verdicts[2],
        'the healthy answer is judged on its own merits');
    strictAssertSame(6, count(Snoopy::$requests),
        'two refusals, then an answer, then two more refusals do not add up to a latch: '
        . 'the sixth topic still gets its request'
    );
});

$suite->test('the challenge is recognised by its header when the body says nothing', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 403, '<html><body>Forbidden</body></html>',
        array('HTTP/2 403', 'cf-mitigated: challenge', 'content-type: text/html; charset=UTF-8'));
    Snoopy::queue($case['download_url'], 200, $case['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', $case['torrent']);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);

    $result = KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);
    $next = kinozalFixture('second-header-challenge.mkv', KINOZAL_FIXTURE_TOPIC_ID + 1);
    Snoopy::queue($next['download_url'], 200, $next['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', $next['torrent']);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);
    $nextResult = KinozalCheckImpl::download_torrent($next['topic_url'], $next['hash'], $next['torrent']);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result, 'the check went through the open door');
    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $nextResult,
        'the header-only challenge routes later topics through download.php');
    strictAssertSame(
        array(
            array('fetchComplex', $case['details_url']),
            array('fetchComplex', $case['download_url']),
            array('fetchComplex', $next['download_url']),
        ),
        Snoopy::$requests,
        'cf-mitigated: challenge is the header Cloudflare sets for exactly this purpose, '
        . 'and it is believed without a body to read'
    );
});

$suite->test('a reused client counts an early failed download as transport, not stale details HTML', function () {
    $cases = kinozalTopics(3);
    foreach ($cases as $case) {
        Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('F', 40)));
        Snoopy::queueEarlyFailure($case['download_url'], 'Refusing to fetch: cannot resolve host');
        // The old path wrongly tries to parse the previous details page.
        ruTrackerChecker::queueResult('parseMetainfo', null);
    }
    foreach (kinozalRunAll($cases) as $verdict)
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdict,
            'a failed transfer still has a retryable verdict');
    strictAssertSame(true, strictGetPrivateStatic('KinozalCheckImpl', 'downloadAbandoned'),
        'three transfer refusals trip the download latch despite stale status=200');
    strictAssertSame(3, strictGetPrivateStatic('KinozalCheckImpl', 'downloadTransportFailures'),
        'the transfer refusals remain counted');
    strictAssertSame(0, count(ruTrackerChecker::callsFor('parseMetainfo')),
        'old details bytes are never parsed as a download');
});

$suite->test('parsed download settles an unclassified details 403 for this topic', function () {
    foreach (array(ruTrackerChecker::STE_NOT_NEED, ruTrackerChecker::STE_ERROR) as $outcome) {
        $cases = kinozalTopics(4);
        foreach ($cases as $case) {
            $new = kinozalFixture('replacement-' . $case['hash'] . '.mkv');
            Snoopy::queue($case['details_url'], 403, '<html>Forbidden</html>');
            Snoopy::queue($case['download_url'], 200, $new['raw']);
            ruTrackerChecker::queueResult('parseMetainfo', $new['torrent']);
            ruTrackerChecker::queueResult('createTorrent', $outcome);
        }
        foreach (kinozalRunAll($cases) as $verdict)
            strictAssertSame($outcome, $verdict,
                'a parsed metainfo result keeps the replacement verdict, even if it is not UPTODATE');
        strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'detailsTransportFailures'),
            'each topic was answered independently');
        strictAssertSame(false, strictGetPrivateStatic('KinozalCheckImpl', 'cycleAbandoned'),
            'parsed downloads do not abandon unrelated Kinozal topics');
        strictAssertSame(8, count(Snoopy::$requests), 'each topic reached both endpoints');
    }
});

$suite->test('unclassified details 403 accepts committed replacement and own missing marker', function () {
    foreach (array('committed' => null, 'missing' => ruTrackerChecker::STE_DELETED) as $label => $expected) {
        $case = kinozalCase();
        Snoopy::queue($case['details_url'], 403, '<html>Forbidden</html>');
        if ($label === 'committed') {
            $new = kinozalFixture('committed-403.mkv');
            Snoopy::queue($case['download_url'], 200, $new['raw']);
            ruTrackerChecker::queueResult('parseMetainfo', $new['torrent']);
            ruTrackerChecker::queueResult('createTorrent', null);
        } else {
            Snoopy::queue($case['download_url'], 200, kinozalDownloadMissingBody());
            ruTrackerChecker::queueResult('parseMetainfo', null);
        }
        strictAssertSame($expected,
            KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']),
            $label . ': independent download verdict is kept');
        strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'detailsTransportFailures'),
            $label . ': details 403 does not accumulate after the independent answer');
    }
});

$suite->test('unclassified details 403 checks each topic through the independent download door', function () {
    $cases = kinozalTopics(4);
    foreach (array_slice($cases, 0, 3) as $case) {
        Snoopy::queue($case['details_url'], '403', '<title>Подождите</title>');
        Snoopy::queue($case['download_url'], 200, $case['raw']);
        ruTrackerChecker::queueResult('parseMetainfo', $case['torrent']);
        ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);
    }
    Snoopy::queue($cases[3]['details_url'], '403', '<title>Подождите</title>');
    Snoopy::queue($cases[3]['download_url'], 200, $cases[3]['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', $cases[3]['torrent']);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);
    foreach (kinozalRunAll($cases) as $verdict)
        strictAssertSame(ruTrackerChecker::STE_UPTODATE, $verdict,
            'a valid independent torrent answer keeps the topic checked');
    strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'detailsTransportFailures'),
        'successful fallback does not accumulate details failures');
    strictAssertSame(false, strictGetPrivateStatic('KinozalCheckImpl', 'cycleAbandoned'),
        'a changed challenge page cannot end the cycle');
    strictAssertSame(8, count(Snoopy::$requests),
        'every topic asks details, then independently checks its own download');
});

$suite->test('three topic-specific 403s cannot hide a later details deletion', function () {
    $cases = kinozalTopics(4);
    foreach (array_slice($cases, 0, 3) as $case) {
        Snoopy::queue($case['details_url'], '403', '<html>Forbidden</html>');
        Snoopy::queue($case['download_url'], 200, $case['raw']);
        ruTrackerChecker::queueResult('parseMetainfo', $case['torrent']);
        ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);
    }
    Snoopy::queue($cases[3]['details_url'], 200, kinozalMissingBody());
    // A stale download would claim the old hash is still current if the
    // fourth topic were routed around its healthy details endpoint.
    Snoopy::queue($cases[3]['download_url'], 200, $cases[3]['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', $cases[3]['torrent']);
    $verdicts = kinozalRunAll($cases);
    strictAssertSame(ruTrackerChecker::STE_DELETED, $verdicts[3],
        'the fourth details answer is authoritative for its own topic');
    strictAssertSame(7, count(Snoopy::$requests),
        'three details/download pairs then the fourth details request only');
});

$suite->test('ordinary details 403 with a failed download still trips the bounded fuse', function () {
    $cases = kinozalTopics(4);
    foreach (array_slice($cases, 0, 3) as $case) {
        Snoopy::queue($case['details_url'], 403, '<html>Forbidden</html>');
        Snoopy::queue($case['download_url'], 503, kinozalServerErrorBody());
    }
    foreach (kinozalRunAll($cases) as $verdict)
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdict,
            'neither refusal proves a topic verdict');
    strictAssertSame(6, count(Snoopy::$requests),
        'only three details and three independent download probes are spent');
    strictAssertSame(true, strictGetPrivateStatic('KinozalCheckImpl', 'cycleAbandoned'),
        'a real two-door outage still stops the rest of this cycle');
});

$suite->test('an unclassified 403 with a login page never proves deletion or a wall', function () {
    $cases = kinozalTopics(4);
    foreach (array_slice($cases, 0, 2) as $case) {
        Snoopy::queue($case['details_url'], 403, '<html>Forbidden</html>');
        Snoopy::queue($case['download_url'], 200, kinozalLoginPage());
        ruTrackerChecker::queueResult('parseMetainfo', null);
    }
    Snoopy::queue($cases[2]['details_url'], 403, '<html>Forbidden</html>');
    foreach (kinozalRunAll($cases) as $verdict)
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdict,
            'a guest download never becomes a deletion or a successful bypass');
    strictAssertSame(5, count(Snoopy::$requests),
        'two download guest answers abandon that door, then the third details refusal fuses the cycle');
    strictAssertSame(false, strictGetPrivateStatic('KinozalCheckImpl', 'detailsWalled'),
        'ordinary refusal is not promoted to a challenge wall');
});

// A guest page is an answer that arrived: the host is reachable, whatever the
// session's state. The two streaks count different things and one must not
// finish the other's count.
$suite->test('a guest answer from the details endpoint clears the transport streak', function () {
    $c = kinozalTopics(5);
    Snoopy::queue($c[0]['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($c[1]['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($c[2]['details_url'], 200, kinozalUnauthorizedBody());
    Snoopy::queue($c[3]['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($c[4]['details_url'], 200, kinozalDetailsBody($c[4]['hash']));

    $verdicts = kinozalRunAll($c);

    strictAssertSame(5, count(Snoopy::$requests),
        'two refusals, a guest page, one refusal: no three refusals ran together, so the fifth topic is asked');
    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $verdicts[4], 'and answered on its merits');
});

$suite->test('a guest answer from the download endpoint clears the transport streak', function () {
    $c = kinozalTopics(5);
    Snoopy::queue($c[0]['details_url'], 403, kinozalChallengeBody());
    Snoopy::queue($c[0]['download_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($c[1]['download_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($c[2]['download_url'], 200, kinozalLoginPage());
    Snoopy::queue($c[3]['download_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($c[4]['download_url'], 200, $c[4]['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', null);
    ruTrackerChecker::queueResult('parseMetainfo', $c[4]['torrent']);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);

    $verdicts = kinozalRunAll($c);

    strictAssertSame(6, count(Snoopy::$requests),
        'the wall, then two refusals, a login page, one refusal: the fifth topic still gets its download');
    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $verdicts[4], 'and is answered on its merits');
});

// The download door has the same latch as the details door, and it is the
// only door while the details endpoint is walled -- so its count is what
// stops a cycle from spending 122 requests on a host that is down.
$suite->test('three refusals from the download endpoint stop the rest of the cycle', function () {
    $c = kinozalTopics(4);
    Snoopy::queue($c[0]['details_url'], 403, kinozalChallengeBody());
    foreach (array_slice($c, 0, 3) as $case)
        Snoopy::queue($case['download_url'], 503, kinozalServerErrorBody());

    $verdicts = kinozalRunAll($c);

    foreach ($verdicts as $index => $verdict)
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdict, 'topic ' . $index . ' proves nothing');
    strictAssertSame(4, count(Snoopy::$requests),
        'the wall once, three refused downloads, and the fourth topic costs no request');
});

$suite->test('a refusal that carries a challenge page says so in the log', function () {
    // On the download endpoint, where there is nothing to route around to.
    $old = kinozalCase('old.mkv');
    $new = kinozalFixture('new.mkv');
    Snoopy::queue($old['details_url'], 200, kinozalDetailsBody($new['hash']));
    Snoopy::queue($old['download_url'], 403, kinozalChallengeBody());

    $result = KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'a challenge proves nothing about the topic');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'Cloudflare challenge',
        'the log names the wall rather than only its status code, so an operator '
        . 'reading it knows no credential and no retry will get past it'
    );
});

// ---------------------------------------------------------------------------
// The details endpoint is behind a challenge, the download endpoint is not.
// Measured on the live instance 2026-09-12, through the production fetch path:
// get_srv_details.php answered 403 with the Cloudflare interstitial while
// dl.kinozal.guru/download.php answered 200 with 241474 bytes of torrent, on
// the loginmgr session that was already stored. So the check is not lost --
// it moves to the door that is open, and reads the answer out of the bytes.
//
// The download endpoint has a missing marker of its own (see
// DOWNLOAD_MISSING_CP1251), so a deletion can still be reported there -- but
// only there, and only when the details endpoint said nothing: the details
// endpoint's own marker is not looked for in the download answer, and an
// absent answer is never evidence of removal.
// ---------------------------------------------------------------------------

$suite->test('a walled details endpoint falls back to the download endpoint', function () {
    $old = kinozalCase('old.mkv');
    $new = kinozalFixture('new.mkv');
    Snoopy::queue($old['details_url'], 403, kinozalChallengeBody());
    Snoopy::queue($old['download_url'], 200, $new['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', $new['torrent']);
    ruTrackerChecker::queueResult('createTorrent', null);

    KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']);

    strictAssertSame(
        array(
            array('fetchComplex', $old['details_url']),
            array('fetchComplex', $old['download_url']),
        ),
        Snoopy::$requests,
        'the challenge sends the check to the download endpoint instead of ending it'
    );
    $created = ruTrackerChecker::callsFor('createTorrent');
    strictAssertSame(1, count($created),
        'a torrent whose infohash has moved on is handed to the replacement');
    strictAssertSame($old['hash'], $created[0]['arguments'][1],
        'the replacement is told which torrent it replaces');
});

$suite->test('the fallback passes an unchanged torrent through createTorrent without replacing it', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 403, kinozalChallengeBody());
    Snoopy::queue($case['download_url'], 200, $case['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', $case['torrent']);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);

    $result = KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result,
        "the equal hash keeps createTorrent's own UPTODATE verdict");
    $created = ruTrackerChecker::callsFor('createTorrent');
    strictAssertSame(1, count($created), 'one shared replacement boundary sees the parsed metainfo');
    strictAssertSame($case['torrent'], $created[0]['arguments'][0],
        'the same parsed torrent is passed to that boundary');
    strictAssertSame($case['hash'], $created[0]['arguments'][1],
        'the unchanged old hash reaches createTorrent for comparison');
});

$suite->test('once the details endpoint is known walled the rest of the cycle skips it', function () {
    $first = kinozalCase('first.mkv', 2102717);
    $second = kinozalFixture('second.mkv', 2026636);
    Snoopy::queue($first['details_url'], 403, kinozalChallengeBody());
    Snoopy::queue($first['download_url'], 200, $first['raw']);
    Snoopy::queue($second['download_url'], 200, $second['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', $first['torrent']);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);
    ruTrackerChecker::queueResult('parseMetainfo', $second['torrent']);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);

    KinozalCheckImpl::download_torrent($first['topic_url'], $first['hash'], $first['torrent']);
    $result = KinozalCheckImpl::download_torrent($second['topic_url'], $second['hash'], $second['torrent']);

    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $result,
        'the second topic is still checked, through the open door');
    strictAssertSame(
        array(
            array('fetchComplex', $first['details_url']),
            array('fetchComplex', $first['download_url']),
            array('fetchComplex', $second['download_url']),
        ),
        Snoopy::$requests,
        'the walled endpoint is asked once per cycle, not once per topic'
    );
});

$suite->test('a details challenge after two failures does not trip the endpoint fuse', function () {
    $cases = kinozalTopics(4);
    foreach (array_slice($cases, 0, 2) as $case) {
        Snoopy::queue($case['details_url'], 503, kinozalServerErrorBody());
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']), 'initial outage');
    }
    foreach (array_slice($cases, 2) as $i => $case) {
        if ($i === 0) Snoopy::queue($case['details_url'], 403, kinozalChallengeBody());
        Snoopy::queue($case['download_url'], 200, $case['raw']);
        ruTrackerChecker::queueResult('parseMetainfo', $case['torrent']);
        ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);
        strictAssertSame(ruTrackerChecker::STE_UPTODATE,
            KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']), 'fallback keeps checking');
    }
    strictAssertSame(5, count(Snoopy::$requests), 'two outages, one challenge, two healthy downloads');
});

$suite->test("the details endpoint's missing marker is not a verdict on the download endpoint", function () {
    $result = kinozalWalledDownloadVerdict(kinozalMissingBody());

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'MISSING_MARKER was measured on get_srv_details.php; the download endpoint speaks in its own words');
    strictAssertSame(0, count(ruTrackerChecker::callsFor('createTorrent')),
        'and nothing is replaced on bytes that are not a torrent');
});

$suite->test('the live download.php answer for a missing id is read as a deletion, verbatim', function () {
    $live = kinozalDownloadMissingBody();
    strictAssertSame('203eba79aafd61051fe1552dec42febc', md5($live), 'the bytes are the captured page, untouched');
    $result = kinozalWalledDownloadVerdict($live);

    strictAssertSame(ruTrackerChecker::STE_DELETED, $result,
        'the endpoint that answered says it has no such id, and nothing contradicts it');
    strictAssertSame(0, count(ruTrackerChecker::callsFor('createTorrent')),
        'and nothing is replaced');
});

$suite->test('the same page does not report a deletion when the details endpoint answered', function () {
    $old = kinozalCase('old.mkv');
    $new = kinozalFixture('new.mkv');
    Snoopy::queue($old['details_url'], 200, kinozalDetailsBody($new['hash']));
    Snoopy::queue($old['download_url'], 200, kinozalDownloadMissingBody());
    ruTrackerChecker::queueResult('parseMetainfo', null);

    $result = KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']);

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'the details endpoint just named a hash for this id, so "no such id" is a '
        . 'contradiction and the honest answer is that nothing was established');
});

// Ordering, not coincidence. With a live session the error page carries no
// registration link -- measured, the page pinned in kinozalDownloadMissingBody() -- but a session
// that dies while an id is also missing produces a login wall, and a wall that
// happened to carry the block below must still read as a wall. The details
// endpoint's own missing marker is ordered behind its guest test for the same
// reason; this is that rule on the other door.
$suite->test('a guest page carrying the missing block is still a guest page', function () {
    $hybrid = kinozalLoginPage() . kinozalMissingBlock();
    list($first, $second, $third) = kinozalTopics(3);
    Snoopy::queue($first['details_url'], 403, kinozalChallengeBody());
    Snoopy::queue($first['download_url'], 200, $hybrid);
    Snoopy::queue($second['download_url'], 200, kinozalLoginPage());
    ruTrackerChecker::queueResult('parseMetainfo', null);
    ruTrackerChecker::queueResult('parseMetainfo', null);

    $verdicts = kinozalRunAll(array($first, $second, $third));

    foreach ($verdicts as $index => $verdict)
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdict,
            'topic ' . $index . ': a lost session is not evidence that a topic is gone,'
            . ' whatever else the page says');
    // The observable consequence of it being counted as a guest answer: two in
    // a row latch, and the third topic costs no request. Read as a deletion
    // instead, these pages would increment nothing and the third would fetch.
    strictAssertSame(
        array(
            array('fetchComplex', $first['details_url']),
            array('fetchComplex', $first['download_url']),
            array('fetchComplex', $second['download_url']),
        ),
        Snoopy::$requests,
        'the guest streak latched, so the wall was believed rather than the block:'
        . ' the third topic costs no request at all'
    );
});

$suite->test('a page with an earlier pad5x5 block still reports the deletion', function () {
    // The class is padding, and the site is free to use it above the error
    // line too. Which block comes first is layout; what the block says is the
    // verdict.
    $page = str_replace('<div id="main">',
        '<div id="main"><div class=pad5x5><a href="/">' . "\xc3\xeb\xe0\xe2\xed\xe0\xff" . '</a></div>',
        kinozalDownloadMissingBody());
    strictAssertTrue(strpos($page, 'pad5x5') < strrpos($page, 'pad5x5'), 'the fixture carries two blocks');

    strictAssertSame(ruTrackerChecker::STE_DELETED,
        kinozalWalledDownloadVerdict($page),
        'the marker is looked for in every pad5x5 block, not only the first');
});

// The anchor is the verdict: the phrase somewhere else on a page -- a news
// item, a forum quote, a search result -- is not the tracker saying it about
// this id.
$suite->test('the marker outside a pad5x5 block is not a deletion', function () {
    $page = '<div id="main"><p>' . KinozalCheckImpl::DOWNLOAD_MISSING_CP1251 . '</p>'
        . '<div class=pad5x5>' . "\xce\xf8\xe8\xe1\xea\xe0" . '</div>'
        . '<span>' . KinozalCheckImpl::DOWNLOAD_MISSING_UTF8 . '</span></div>';

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        kinozalWalledDownloadVerdict($page),
        'the phrase is on the page, but no pad5x5 block says it, so nothing was established');
});

// The block has to say the marker and nothing else. A block that carries the
// phrase plus anything -- a hint, a second sentence, a different id -- is not
// the answer that was measured, and the handler is built never to guess.
$suite->test('the marker with anything else in the same block is not a deletion', function () {
    $page = '<div id="main"><li><div class=pad5x5>' . KinozalCheckImpl::DOWNLOAD_MISSING_CP1251
        . ' ' . "\xcf\xee\xef\xf0\xee\xe1\xf3\xe9\xf2\xe5 \xef\xee\xe7\xe6\xe5." . '</div></li></div>';

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        kinozalWalledDownloadVerdict($page),
        'the phrase is in the right block, but the block says more than the marker, so nothing was established');
});

// The production container has no iconv(). The handler runs here in a child
// PHP with iconv disabled and no ini, without TestLib, against the captured page.
$suite->test('the captured page is read as a deletion by a PHP that has no iconv', function () {
    $dir = sys_get_temp_dir() . '/kinozal-noiconv-' . bin2hex(random_bytes(4));
    strictAssertTrue(mkdir($dir, 0700), 'scratch directory');
    try {
        file_put_contents($dir . '/page.html', kinozalDownloadMissingBody());
        file_put_contents($dir . '/child.php', <<<'PHP'
<?php
list(, $repo, $pageFile) = $argv;
if (function_exists('iconv')) { fwrite(STDERR, "iconv is still available\n"); exit(2); }
class ruTrackerChecker {
    const STE_UPTODATE = 3; const STE_DELETED = 4; const STE_CANT_REACH_TRACKER = 5; const STE_DECLINED = -2;
    public static $page = '';
    public static $details = null;
    public static function registerTracker() {}
    public static function makeClient($url) {
        if (self::$details !== null) return (object) array('status' => 200, 'results' => self::$details);
        return strpos($url, 'get_srv_details.php') !== false
        ? (object) array('status' => 403, 'headers' => array('cf-mitigated: challenge'), 'results' => '')
        : (object) array('status' => 200, 'headers' => array(), 'results' => self::$page); }
    public static function parseMetainfo($payload) { return null; }
    public static function createTorrent() { return 0; }
    public static function logDebug($message) {}
}
ruTrackerChecker::$page = file_get_contents($pageFile);
require $repo . '/plugins/rutracker_check/trackers/kinozal.php';
ruTrackerChecker::$details = "\xd2\xee\xf0\xf0\xe5\xed\xf2 \xf4\xe0\xe9\xeb \xed\xe5 \xed\xe0\xe9\xe4\xe5\xed";
$detailsVerdict = KinozalCheckImpl::download_torrent('https://kinozal.guru/details.php?id=1', str_repeat('a', 40), null);
ruTrackerChecker::$details = null;
$verdict = KinozalCheckImpl::download_torrent('https://kinozal.guru/details.php?id=99999999', str_repeat('a', 40), null);
echo (int) function_exists('iconv'), '|', strlen(ruTrackerChecker::$page), '|', $verdict, "\n";
exit($verdict === ruTrackerChecker::STE_DELETED && $detailsVerdict === ruTrackerChecker::STE_DELETED ? 0 : 1);
PHP
        );
        $command = escapeshellarg(PHP_BINARY) . ' -n -d disable_functions=iconv,json_encode,json_decode '
            . escapeshellarg($dir . '/child.php') . ' ' . escapeshellarg(testFindRepoRoot()) . ' '
            . escapeshellarg($dir . '/page.html') . ' 2>&1';
        exec($command, $output, $code);
        $line = explode('|', (string) end($output));
        strictAssertSame(0, $code, 'the child PHP read the captured page as a deletion: ' . implode(' | ', $output));
        strictAssertSame('0', $line[0], 'and it really had no iconv()');
        strictAssertSame('4010', $line[1], 'on the captured bytes');
    } finally {
        strictRemoveTree($dir);
    }
});

// Defensive encoding case: the captured download answer was windows-1251.
$suite->test('a hypothetical UTF-8 deletion marker is recognised too', function () {

    strictAssertSame(ruTrackerChecker::STE_DELETED,
        kinozalWalledDownloadVerdict('<div class=pad5x5>Нет раздачи с таким ID.</div>'),
        'both spellings are literal bytes in the handler, so the UTF-8 page matches with no conversion');
});


$suite->test('a nested deletion block with trailing text is not a deletion', function () {
    $page = str_replace('<div class=pad5x5>', '<div class=pad5x5><div>', kinozalDownloadMissingBody());
    $page = str_replace(KinozalCheckImpl::DOWNLOAD_MISSING_CP1251,
        KinozalCheckImpl::DOWNLOAD_MISSING_CP1251 . '</div>Retry later', $page);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        kinozalWalledDownloadVerdict($page),
        'the entire block must be the measured marker');
});

$suite->test('a failed download endpoint leaves healthy details checks available', function () {
    $cases = kinozalTopics(5);
    foreach (array_slice($cases, 0, 3) as $case) {
        Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('A', 40)));
        Snoopy::queue($case['download_url'], 503, kinozalServerErrorBody());
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']), 'download failed');
    }
    $case = $cases[3];
    Snoopy::queue($case['details_url'], 200, kinozalDetailsBody($case['hash']));
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']), 'details still answers');
    $case = $cases[4];
    Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('A', 40)));
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']), 'download stays fused');
    strictAssertSame(8, count(Snoopy::$requests), 'only the failing endpoint stops receiving requests');
});


$suite->test('a refused download redirect to a login endpoint counts as a guest answer', function () {
    // Real Snoopy records lastredirectaddr even when it refuses the credential
    // redirect before fetching it; status may be the original redirect or a
    // followed login challenge. Its security suite pins that producer contract.
    foreach (array(302, 403, 0) as $status) {
        kinozalReset();
        $client = (object) array('status' => $status, 'results' => kinozalChallengeBody(), 'headers' => array(),
            'lastredirectaddr' => 'https://kinozal.guru/login.php');
        for ($i = 0; $i < 2; $i++)
            strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
                strictInvoke('KinozalCheckImpl', 'decideFromDownload', array($client, 1, str_repeat('A', 40), null, true)),
                'a fetch refusal with observed login redirect remains retryable');
        strictAssertSame(2, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'), 'redirect evidence proves a guest wall');
        strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'downloadTransportFailures'), 'guest wall is not transport outage');
    }
    foreach (array('https://evil.test/login.php', 'https://kinozal.guru.evil.test/login.php',
        'https://kinozal.guru/other.php', '') as $redirect) {
        kinozalReset();
        $client = (object) array('status' => 403, 'results' => kinozalChallengeBody(), 'headers' => array(),
            'lastredirectaddr' => $redirect);
        strictInvoke('KinozalCheckImpl', 'decideFromDownload', array($client, 1, str_repeat('A', 40), null, true));
        strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'), 'unrelated redirect is not guest evidence');
        strictAssertSame(1, strictGetPrivateStatic('KinozalCheckImpl', 'downloadTransportFailures'), 'ordinary failure keeps transport classification');
    }
});

$suite->test('a later details challenge cannot reopen the abandoned download endpoint', function () {
    $cases = kinozalTopics(4);
    foreach (array_slice($cases, 0, 3) as $case) {
        Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('A', 40)));
        Snoopy::queue($case['download_url'], 503, kinozalServerErrorBody());
    }
    Snoopy::queue($cases[3]['details_url'], 403, kinozalChallengeBody());
    foreach (kinozalRunAll($cases) as $result)
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result, 'no endpoint established a verdict');
    strictAssertSame(7, count(Snoopy::$requests), 'three failed downloads and the final details request only');
});

$suite->test('a redirect on details does not classify the next failed download as a guest', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('A', 40)), array(),
        'https://kinozal.guru/login.php');
    Snoopy::queue($case['download_url'], 503, kinozalServerErrorBody());
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']), 'download failed');
    strictAssertSame(1, strictGetPrivateStatic('KinozalCheckImpl', 'downloadTransportFailures'), 'download has its own transport failure');
    strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'), 'old redirect cannot prove a new guest answer');
});

$suite->test('a fresh guest answer is classified after fetchComplex returns false', function () {
    $cases = kinozalTopics(2);
    foreach ($cases as $case) {
        Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('A', 40)));
        Snoopy::queueAnsweredFailure($case['download_url'], 200, kinozalUnauthorizedBody());
        ruTrackerChecker::queueResult('parseMetainfo', null);
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']),
            'a fresh guest page is retryable');
    }
    strictAssertSame(2, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'),
        'both fresh guest responses must reach the login wall counter');
    strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'downloadTransportFailures'),
        'the guest responses did not refuse transport');
    strictAssertSame(true, strictGetPrivateStatic('KinozalCheckImpl', 'downloadAbandoned'),
        'the second consecutive guest response trips the login wall latch');
});

$suite->test('a fresh challenge answer is classified after fetchComplex returns false', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('A', 40)));
    Snoopy::queueAnsweredFailure($case['download_url'], 403, kinozalChallengeBody());
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']),
        'a challenge answer stays retryable');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'Cloudflare challenge',
        'a fresh challenge page is named in diagnostics even after a false return');
    strictAssertSame(1, strictGetPrivateStatic('KinozalCheckImpl', 'downloadTransportFailures'),
        'a challenged HTTP response counts as a download refusal');
});

$suite->test('a false download without a response does not reuse details bytes', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('A', 40)));
    Snoopy::queueEarlyFailure($case['download_url'], '');
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']),
        'no fresh answer is a retryable transport failure');
    strictAssertSame(1, strictGetPrivateStatic('KinozalCheckImpl', 'downloadTransportFailures'),
        'a failed fetch with no new bytes counts as transport failure');
    strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'),
        'the previous details response is not read as a download answer');
});

$suite->test('a loginmgr refusal before fetch cannot reuse a details redirect', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('A', 40)), array(),
        'https://kinozal.guru/login.php');
    Snoopy::queueBeforeFetchFailure($case['download_url']);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']),
        'a prefetch refusal stays retryable');
    strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'),
        'the prior details redirect cannot prove a download login wall');
    strictAssertSame(1, strictGetPrivateStatic('KinozalCheckImpl', 'downloadTransportFailures'),
        'a refusal without fresh download bytes counts as transport');
});

$suite->test('a catch-all answer clears an earlier Snoopy error', function () {
    Snoopy::reset();
    $client = new Snoopy();
    Snoopy::queueEarlyFailure('https://example.test/first', 'socket failed');
    strictAssertSame(false, $client->fetch('https://example.test/first'), 'the first request fails early');
    strictAssertSame('socket failed', $client->error, 'the first error was recorded');
    Snoopy::queueAny(200, 'fresh response');
    strictAssertSame(true, $client->fetch('https://example.test/second'), 'the second request succeeds');
    strictAssertSame('', $client->error, 'a new request starts without the previous error');
});

exit($suite->run());
