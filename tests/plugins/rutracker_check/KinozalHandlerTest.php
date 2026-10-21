<?php

/**
 * Kinozal handler: every answer that only proves "could not check" must stay
 * retryable, and only the tracker's own "no such torrent" may end as a
 * deletion. Fixtures are the bodies the live site returned on 2026-08-07, and
 * the download endpoint's "no such id" page as captured on 2026-09-14.
 */

define('TESTLIB_HANDLER_STUBS', 1);
// The login pause lets one credential POST out per two hours across
// processes; these cases count the POSTs a cycle may spend under it, so
// it is declared away. tests/php/KinozalRequestPacingTest.php pins it.
define('KINOZAL_LOGIN_PAUSE', 0);
// TestLib's Snoopy stub sends nothing, so the pace between Kinozal requests
// never applies here; tests/php/KinozalRequestPacingTest.php drives it.
require_once(__DIR__ . '/TestLib.php');
require_once(testFindRepoRoot() . '/plugins/rutracker_check/trackers/kinozal.php');

// Most cases here pin what one door does with an answer, so they run with a
// single configured door; the door-failover cases at the end of this file set
// two. StrictTestSuite restores $kinozalHosts after every case.
function kinozalReset($hosts = array('kinozal.jumpingcrab.com'))
{
    $GLOBALS['kinozalHosts'] = $hosts;
    ruTrackerChecker::reset();
    // Each test starts a fresh scheduler/manual cycle using the public owner.
    KinozalCheckImpl::resetCycle();
}

// The two official mirrors, for the door-failover cases.
const KINOZAL_TWO_DOORS = array('kinozal.jumpingcrab.com', 'kinozal.cloudns.nz');

function kinozalTopics($count, $hosts = array('kinozal.jumpingcrab.com'))
{
    kinozalReset($hosts);
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

// A topic the handler asked and could not settle is CANT_REACH. A topic it did
// not ask -- the cycle had stopped, or Kinozal refused the account or the IP
// rather than the topic -- keeps its stored verdict and chk-time (notAsked()).
function kinozalAssertAsked($verdicts, $asked, $message)
{
    strictAssertSame(array_merge(array_fill(0, $asked, ruTrackerChecker::STE_CANT_REACH_TRACKER),
        array_fill(0, count($verdicts) - $asked, ruTrackerChecker::STE_UNCHANGED)), $verdicts, $message);
}

function kinozalUnclassifiedDownloadVerdict($page)
{
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 403, '<html>Forbidden</html>');
    Snoopy::queue($case['download_url'], 200, $page);
    ruTrackerChecker::queueResult('parseMetainfo', null);
    return KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']);
}

function kinozalTopicUrl($id)
{
    return 'https://kinozal.me/details.php?id=' . $id;
}

// The handler asks one Kinozal door for both answers. The default is the
// first official mirror: kinozal.me and kinozal.guru answer a Cloudflare
// challenge, and dl.kinozal.* has served a maintenance page since 2026-10-13
// while the site's own details page links /download.php on the same host.
function kinozalDetailsUrl($id, $host = 'kinozal.jumpingcrab.com')
{
    return 'https://' . $host . '/get_srv_details.php?action=2&id=' . $id;
}

function kinozalDownloadUrl($id, $host = 'kinozal.jumpingcrab.com')
{
    return 'https://' . $host . '/download.php?id=' . $id;
}

// Door failures in a row of one endpoint at one door (an index into the
// configured hosts), and whether that endpoint is closed there.
function kinozalDoorFailures($endpoint, $door = 0)
{
    $failures = strictGetPrivateStatic('KinozalCheckImpl', 'failures');
    return isset($failures[$door . '|' . $endpoint]) ? $failures[$door . '|' . $endpoint] : 0;
}

function kinozalDoorClosed($endpoint, $door = 0)
{
    return isset(strictGetPrivateStatic('KinozalCheckImpl', 'closed')[$door . '|' . $endpoint]);
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

// The captured guest anchor with extra attributes to exhaust the parser's
// configured match budget. The surrounding missing/hash text must not gain
// authority when that parser cannot establish whether this is a guest page.
// Quoted values, because the parser consumes an unquoted run in one step:
// under pcre.backtrack_limit=1000 each value is one iteration of its budget.
function kinozalParserFailureBody()
{
    return str_replace('<a ', '<a ' . str_repeat('t="1" ', 2000), kinozalUnauthorizedBody());
}

// Runs $body under the parser budget that kinozalParserFailureBody() exhausts.
function kinozalWithParserBudget(callable $body)
{
    $limit = ini_get('pcre.backtrack_limit');
    try {
        ini_set('pcre.backtrack_limit', '1000');
        return $body();
    } finally {
        ini_set('pcre.backtrack_limit', $limit);
    }
}

// The shared predicate's shipped-level line for a parser failure.
function kinozalParserFailureLines($log)
{
    return array_values(array_filter(explode("\n", $log), function ($line) {
        return strpos($line, 'kinozal: guest-page check failed (pcre-') !== false;
    }));
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
// challenge body above is deliberately not used for the refusal latch:
// the first recognised challenge stops every Kinozal request immediately.
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
    strictAssertSame(ruTrackerChecker::STE_UNCHANGED, $third,
        'a skipped topic is not asked and keeps its stored verdict');
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
        'the scenario made nine fetch requests');
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

$suite->test('a valid torrent carrying challenge markup remains payload and keeps later checks reachable', function () {
    $cases = kinozalTopics(2);
    // The challenge markup travels in a free-text field of an otherwise
    // genuine replacement: same topic, same account passkey.
    $raw = strictTorrentRaw('new.mkv', 'http://tr2.torrent4me.com/ann?uk=K0I5ZrJ6If1', kinozalTopicUrl(1000), null,
        array('created by' => kinozalChallengeBody()));
    $torrent = Torrent::fromRawBytes($raw);
    strictAssertSame(false, $torrent->errors(), 'the challenge text is inside valid bencode');
    Snoopy::queue($cases[0]['details_url'], 200, kinozalDetailsBody((string) $torrent->hash_info()));
    Snoopy::queue($cases[0]['download_url'], 200, $raw);
    ruTrackerChecker::queueResult('parseMetainfo', $torrent);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);
    Snoopy::queue($cases[1]['details_url'], 200, kinozalDetailsBody($cases[1]['hash']));
    strictAssertSame(array(ruTrackerChecker::STE_UPTODATE, ruTrackerChecker::STE_UPTODATE), kinozalRunAll($cases),
        'body text inside metainfo cannot masquerade as an HTML challenge');
    strictAssertSame(3, count(Snoopy::$requests), 'the subsequent topic remains reachable');
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

$suite->test('every declared Kinozal mirror is handled', function () {
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

$suite->test('lookalike Kinozal hosts are declined without a request', function () {
    foreach (array(
        'https://kinozal.io/details.php?id=1',
        'https://kinozalXtv/details.php?id=1',
        'https://kinozal.tv.evil.test/details.php?id=1',
        'https://kinozal.tv@evil.test/details.php?id=1',
    ) as $url) {
        strictAssertSame(0, preg_match(KinozalCheckImpl::topicPattern(), $url),
            $url . ' must not match the topic pattern');
        $case = kinozalCase();
        $result = KinozalCheckImpl::download_torrent($url, $case['hash'], $case['torrent']);
        strictAssertSame(ruTrackerChecker::STE_DECLINED, $result,
            $url . ' must be declined by the handler');
        strictAssertSame(0, count(Snoopy::$requests),
            $url . ' must not trigger a request');
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
    strictAssertOneLogMatching(ruTrackerChecker::$logs,
        'details attempt ended with unrecognised content: id=',
        'unrecognised content belongs to the attempt, whose final endpoint may be loginmgr');
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
        kinozalAssertAsked(kinozalRunAll($cases), 3, $label . ': no topic verdict, the fourth is not asked');
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

$suite->test('an independent download answer clears a prior details refusal streak', function () {
    list($a, $b, $fallback, $d, $e, $last) = kinozalTopics(6);
    Snoopy::queue($a['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($b['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($fallback['details_url'], 403, '<html>Forbidden</html>');
    Snoopy::queue($fallback['download_url'], 200, $fallback['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', $fallback['torrent']);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);
    Snoopy::queue($d['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($e['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($last['details_url'], 200, kinozalDetailsBody($last['hash']));

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($a['topic_url'], $a['hash'], $a['torrent']),
        'the first details refusal remains retryable');
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($b['topic_url'], $b['hash'], $b['torrent']),
        'the second details refusal remains retryable');
    strictAssertSame(2, kinozalDoorFailures('details'),
        'two refusals built the details streak');
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($fallback['topic_url'], $fallback['hash'], $fallback['torrent']),
        'the unclassified 403 has an independent successful download answer');
    strictAssertSame(0, kinozalDoorFailures('details'),
        'that independent answer clears the old details streak');
    foreach (array($d, $e) as $case)
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
            KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']),
            'a later refusal starts a new streak');
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($last['topic_url'], $last['hash'], $last['torrent']),
        'two later refusals cannot hide the next healthy details answer');
    strictAssertSame(7, count(Snoopy::$requests),
        'all six topics reached details and the fallback reached download once');
});

$suite->test('a header-only challenge stops every route even on HTTP 200', function () {
    foreach (array(200, 403) as $status) {
        $cases = kinozalTopics(3);
        Snoopy::queue($cases[0]['details_url'], $status, '<html>Forbidden</html>',
            array('HTTP/2 ' . $status, 'cf-mitigated: challenge'));
        kinozalAssertAsked(kinozalRunAll($cases), 1, 'the challenge is retryable, later topics are not asked');
        strictAssertSame(array(array('fetchComplex', $cases[0]['details_url'])), Snoopy::$requests,
            'no download or later topic follows the first recognised challenge');
    }
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
    strictAssertSame(true, kinozalDoorClosed('download'),
        'three transfer refusals close download.php at this door despite stale status=200');
    strictAssertSame(3, kinozalDoorFailures('download'),
        'the transfer refusals remain counted');
    strictAssertSame(0, count(ruTrackerChecker::callsFor('parseMetainfo')),
        'old details bytes are never parsed as a download');
    strictAssertOneLogMatching(ruTrackerChecker::$logs,
        'download attempt failed: refused-unresolvable-host id=1000',
        'the classified failure belongs to the whole attempt, which can fail inside loginmgr');
});

$suite->test('parsed download settles an unclassified details 403 for this topic', function () {
    foreach (array(ruTrackerChecker::STE_NOT_NEED, ruTrackerChecker::STE_ERROR) as $outcome) {
        $cases = kinozalTopics(4);
        foreach ($cases as $i => $case) {
            $new = kinozalFixture('replacement-' . $case['hash'] . '.mkv', 1000 + $i);
            Snoopy::queue($case['details_url'], 403, '<html>Forbidden</html>');
            Snoopy::queue($case['download_url'], 200, $new['raw']);
            ruTrackerChecker::queueResult('parseMetainfo', $new['torrent']);
            ruTrackerChecker::queueResult('createTorrent', $outcome);
        }
        foreach (kinozalRunAll($cases) as $verdict)
            strictAssertSame($outcome, $verdict,
                'a parsed metainfo result keeps the replacement verdict, even if it is not UPTODATE');
        strictAssertSame(0, kinozalDoorFailures('details'),
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
        strictAssertSame(0, kinozalDoorFailures('details'),
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
    strictAssertSame(0, kinozalDoorFailures('details'),
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
    kinozalAssertAsked(kinozalRunAll($cases), 3, 'neither refusal proves a topic verdict');
    strictAssertSame(6, count(Snoopy::$requests),
        'only three details and three independent download probes are spent');
    strictAssertSame(true, strictGetPrivateStatic('KinozalCheckImpl', 'cycleAbandoned'),
        'a door refusing both endpoints still stops the rest of this cycle');
});

$suite->test('an unclassified 403 with a login page never proves deletion or a wall', function () {
    $cases = kinozalTopics(4);
    foreach (array_slice($cases, 0, 2) as $case) {
        Snoopy::queue($case['details_url'], 403, '<html>Forbidden</html>');
        Snoopy::queue($case['download_url'], 200, kinozalLoginPage());
        ruTrackerChecker::queueResult('parseMetainfo', null);
    }
    Snoopy::queue($cases[2]['details_url'], 403, '<html>Forbidden</html>');
    kinozalAssertAsked(kinozalRunAll($cases), 3,
        'a guest download never becomes a deletion or a successful bypass');
    strictAssertSame(5, count(Snoopy::$requests),
        'two download guest answers abandon that door, then the third details refusal fuses the cycle');
    strictAssertSame(false, KinozalResponse::requestsBlocked(kinozalDetailsUrl(1000)),
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
    foreach ($c as $case)
        Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('F', 40)));
    Snoopy::queue($c[0]['download_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($c[1]['download_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($c[2]['download_url'], 200, kinozalLoginPage());
    Snoopy::queue($c[3]['download_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($c[4]['download_url'], 200, $c[4]['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', null);
    ruTrackerChecker::queueResult('parseMetainfo', $c[4]['torrent']);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);

    $verdicts = kinozalRunAll($c);

    strictAssertSame(10, count(Snoopy::$requests),
        'healthy details and two refusals, a login page, one refusal still permit the fifth download');
    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $verdicts[4], 'and is answered on its merits');
});

// Downloads have their own refusal latch. Healthy details remain available
// after a download outage, without repeating that download for every topic.
$suite->test('three download refusals stop downloads while details remain available', function () {
    $c = kinozalTopics(4);
    foreach ($c as $case)
        Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('F', 40)));
    foreach (array_slice($c, 0, 3) as $case)
        Snoopy::queue($case['download_url'], 503, kinozalServerErrorBody());

    $verdicts = kinozalRunAll($c);

    foreach ($verdicts as $index => $verdict)
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdict, 'topic ' . $index . ' proves nothing');
    strictAssertSame(7, count(Snoopy::$requests),
        'four details checks and three refused downloads; the fourth download is skipped');
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
// The captured download missing page remains authoritative after an ordinary
// unclassified details refusal. A recognised challenge never opens this route.
// The historical 2026-09-12 independent-download observation motivated the
// former CF fallback; the owner now requires stopping at the first challenge.
// ---------------------------------------------------------------------------

$suite->test('an unclassified details refusal can use an independent downloaded torrent', function () {
    $old = kinozalCase('old.mkv');
    $new = kinozalFixture('new.mkv');
    Snoopy::queue($old['details_url'], 403, '<html>Forbidden</html>');
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
        'the ordinary refusal permits one independent download probe'
    );
    $created = ruTrackerChecker::callsFor('createTorrent');
    strictAssertSame(1, count($created),
        'a torrent whose infohash has moved on is handed to the replacement');
    strictAssertSame($old['hash'], $created[0]['arguments'][1],
        'the replacement is told which torrent it replaces');
});

$suite->test('the fallback passes an unchanged torrent through createTorrent without replacing it', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 403, '<html>Forbidden</html>');
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

$suite->test('a details challenge stops the cycle and a new cycle retries a recovered site', function () {
    $cases = kinozalTopics(3);
    Snoopy::queue($cases[0]['details_url'], 403, kinozalChallengeBody());
    $log = testCapturedAppLog(function () use ($cases) { kinozalRunAll($cases); });
    strictAssertSame(array(array('fetchComplex', $cases[0]['details_url'])), Snoopy::$requests,
        'the challenge costs exactly one request for the entire cycle');
    strictAssertSame(1, substr_count($log, 'Kinozal checks skipped for the rest of this cycle'),
        'the normal log explains the skip once');
    KinozalCheckImpl::resetCycle();
    Snoopy::queue($cases[1]['details_url'], 200, kinozalDetailsBody($cases[1]['hash']));
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($cases[1]['topic_url'], $cases[1]['hash'], $cases[1]['torrent']),
        'the new cycle is allowed to observe recovery');
    strictAssertSame(2, count(Snoopy::$requests), 'recovery performs a fresh details request');
});

$suite->test('a details challenge after two failures stops immediately without another route', function () {
    $cases = kinozalTopics(4);
    foreach (array_slice($cases, 0, 2) as $case)
        Snoopy::queue($case['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue($cases[2]['details_url'], 403, kinozalChallengeBody());
    kinozalAssertAsked(kinozalRunAll($cases), 3, 'no topic has an authoritative answer');
    strictAssertSame(3, count(Snoopy::$requests), 'two outages and the challenge are the entire trace');
    strictAssertSame(2, kinozalDoorFailures('details'),
        'a challenge does not masquerade as a third transport failure');
});

$suite->test("the details endpoint's missing marker is not a verdict on the download endpoint", function () {
    $result = kinozalUnclassifiedDownloadVerdict(kinozalMissingBody());

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $result,
        'MISSING_MARKER was measured on get_srv_details.php; the download endpoint speaks in its own words');
    strictAssertSame(0, count(ruTrackerChecker::callsFor('createTorrent')),
        'and nothing is replaced on bytes that are not a torrent');
});

// The same id at the mirror's download.php, captured with the details answer
// above: HTTP 200, windows-1251, 5124 bytes, one pad5x5 block holding the
// marker, and no account name, cookie or passkey on the page (checked before
// it was pinned).
$suite->test('the live mirror download.php answer for a missing id is read as a deletion, verbatim', function () {
    $live = (string) file_get_contents(__DIR__ . '/fixtures/kinozal-mirror-missing-download-2026-10-15.html');
    strictAssertSame('5dfd108abf813eb8d4785a3affed88ee', md5($live), 'the bytes are the captured page, untouched');
    strictAssertSame(ruTrackerChecker::STE_DELETED, kinozalUnclassifiedDownloadVerdict($live),
        'the mirror says it has no such id, and nothing contradicts it');
    strictAssertSame(0, count(ruTrackerChecker::callsFor('createTorrent')), 'and nothing is replaced');
});

$suite->test('the live download.php answer for a missing id is read as a deletion, verbatim', function () {
    $live = kinozalDownloadMissingBody();
    strictAssertSame('203eba79aafd61051fe1552dec42febc', md5($live), 'the bytes are the captured page, untouched');
    $result = kinozalUnclassifiedDownloadVerdict($live);

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
    foreach (array($first, $second, $third) as $case)
        Snoopy::queue($case['details_url'], 403, '<html>Forbidden</html>');
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
            array('fetchComplex', $second['details_url']),
            array('fetchComplex', $second['download_url']),
            array('fetchComplex', $third['details_url']),
        ),
        Snoopy::$requests,
        'the guest streak latched, so the wall was believed rather than the block:'
        . ' the third topic cannot request the download again'
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
        kinozalUnclassifiedDownloadVerdict($page),
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
        kinozalUnclassifiedDownloadVerdict($page),
        'the phrase is on the page, but no pad5x5 block says it, so nothing was established');
});

// The block has to say the marker and nothing else. A block that carries the
// phrase plus anything -- a hint, a second sentence, a different id -- is not
// the answer that was measured, and the handler is built never to guess.
$suite->test('the marker with anything else in the same block is not a deletion', function () {
    $page = '<div id="main"><li><div class=pad5x5>' . KinozalCheckImpl::DOWNLOAD_MISSING_CP1251
        . ' ' . "\xcf\xee\xef\xf0\xee\xe1\xf3\xe9\xf2\xe5 \xef\xee\xe7\xe6\xe5." . '</div></li></div>';

    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        kinozalUnclassifiedDownloadVerdict($page),
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
    const STE_UPTODATE = 3; const STE_DELETED = 4; const STE_CANT_REACH_TRACKER = 5; const STE_UNCHANGED = -1; const STE_DECLINED = -2;
    public static $page = '';
    public static $details = null;
    public static function registerTracker() {}
    public static function makeClient($url) {
        if (self::$details !== null) return (object) array('status' => 200, 'results' => self::$details);
        return strpos($url, 'get_srv_details.php') !== false
        ? (object) array('status' => 403, 'headers' => array(), 'results' => '<html>Forbidden</html>')
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
        kinozalUnclassifiedDownloadVerdict('<div class=pad5x5>Нет раздачи с таким ID.</div>'),
        'both spellings are literal bytes in the handler, so the UTF-8 page matches with no conversion');
});


$suite->test('a nested deletion block with trailing text is not a deletion', function () {
    $page = str_replace('<div class=pad5x5>', '<div class=pad5x5><div>', kinozalDownloadMissingBody());
    $page = str_replace(KinozalCheckImpl::DOWNLOAD_MISSING_CP1251,
        KinozalCheckImpl::DOWNLOAD_MISSING_CP1251 . '</div>Retry later', $page);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        kinozalUnclassifiedDownloadVerdict($page),
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
        $client = (object) array('status' => $status, 'results' => '<html>Forbidden</html>', 'headers' => array(),
            'lastredirectaddr' => 'https://kinozal.guru/login.php');
        for ($i = 0; $i < 2; $i++)
            strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
                strictInvoke('KinozalCheckImpl', 'decideFromDownload', array($client, 0, 1, str_repeat('A', 40), null, false)),
                'a fetch refusal with observed login redirect remains retryable');
        strictAssertSame(2, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'), 'redirect evidence proves a guest wall');
        strictAssertSame(0, kinozalDoorFailures('download'), 'guest wall is not transport outage');
    }
    foreach (array('https://evil.test/login.php', 'https://kinozal.guru.evil.test/login.php',
        'https://kinozal.guru/other.php', '') as $redirect) {
        kinozalReset();
        $client = (object) array('status' => 403, 'results' => '<html>Forbidden</html>', 'headers' => array(),
            'lastredirectaddr' => $redirect);
        strictInvoke('KinozalCheckImpl', 'decideFromDownload', array($client, 0, 1, str_repeat('A', 40), null, false));
        strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'), 'unrelated redirect is not guest evidence');
        strictAssertSame(1, kinozalDoorFailures('download'), 'ordinary failure keeps transport classification');
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
    strictAssertSame(1, kinozalDoorFailures('download'), 'download has its own transport failure');
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
    strictAssertSame(0, kinozalDoorFailures('download'),
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
    strictAssertSame(0, kinozalDoorFailures('download'),
        'a recognised challenge stops the cycle without spending the refusal tolerance');
});

$suite->test('a false download without a response does not reuse details bytes', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('A', 40)));
    Snoopy::queueEarlyFailure($case['download_url'], '');
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']),
        'no fresh answer is a retryable transport failure');
    strictAssertSame(1, kinozalDoorFailures('download'),
        'a failed fetch with no new bytes counts as transport failure');
    strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'),
        'the previous details response is not read as a download answer');
    strictAssertOneLogMatching(ruTrackerChecker::$logs,
        'download attempt failed: last-status=', 'an early refusal does not invent a download response');
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
    strictAssertSame(1, kinozalDoorFailures('download'),
        'a refusal without fresh download bytes counts as transport');
});

$suite->test('a prefetch refusal sees cleared details response fields', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('A', 40)),
        array('x-details: yes'), 'https://kinozal.guru/login.php', 'credential-redirect-refused');
    Snoopy::queueBeforeFetchFailure($case['download_url']);
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']),
        'the download refusal remains retryable');
    strictAssertSame(array(array(-1, '', array(), '', '')), Snoopy::$beforeFetchSnapshots,
        'no details status, body, headers, error or redirect reaches loginmgr as download metadata');
});

$suite->test('a loginmgr refusal does not attribute its last status to download.php', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('A', 40)));
    Snoopy::queueBeforeFetchFailure($case['download_url'], 403, '<html>login refused</html>');
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']),
        'a loginmgr refusal stays retryable');
    strictAssertOneLogMatching(ruTrackerChecker::$logs,
        'download attempt failed: last-status=403',
        'the status is named as the last client status, not a download response');
});

$suite->test('real fetchComplex failures attribute their final response to the download attempt', function () {
    // Run the real fetchComplex/account adapter in a separate PHP: TestLib's
    // Snoopy double cannot establish which request produced a redirect.
    $dir = sys_get_temp_dir() . '/kinozal-login-redirect-' . bin2hex(random_bytes(4));
    strictAssertTrue(mkdir($dir, 0700), 'private login profile');
    try {
        file_put_contents($dir . '/child.php', <<<'PHP'
<?php
list(, $repo, $profile, $mode) = $argv;
$_ENV['RU_PROFILE_PATH'] = $profile;
$_ENV['RU_LOG_FILE'] = $profile . '/errors.log';
require $repo . '/php/Snoopy.class.inc';
require $repo . '/plugins/loginmgr/accounts.php';
class LoginRedirectSnoopy extends Snoopy {
    public $requests = array();
    public $mode;
    public static $resolveCalls = 0;
    public static function resolveHost($host) {
        return ++self::$resolveCalls <= 2 ? array('8.8.8.8') : array();
    }
    // Transport and DNS answers are scripted. Snoopy owns target admission,
    // redirects and response metadata; commonAccount owns login refusal.
    public function _httpsrequest($url, $content_type = '', $body = '', $method = 'GET') {
        $this->requests[] = array($method, $url);
        $this->headers = array();
        $this->_redirectaddr = false;
        $this->results = '<html>landing page</html>';
        if ($url === 'https://kinozal.jumpingcrab.com/login.php' && $method === 'GET' && count($this->requests) === 1) {
            $this->status = 200;
            $this->results = '<form method=post action="/takelogin.php">'
                . '<input name=username><input name=password></form>';
        } elseif ($url === 'https://kinozal.jumpingcrab.com/takelogin.php' && $method === 'POST') {
            $this->status = $this->mode === 'redirect' ? 302 : 200;
            if ($this->mode === 'redirect')
                $this->_redirectaddr = 'https://kinozal.jumpingcrab.com/login.php';
            elseif ($this->mode === 'guest')
                $this->results = '<a href="/signup.php">Register</a>';
        } elseif ($url === 'https://kinozal.jumpingcrab.com/login.php' && $method === 'GET') {
            $this->status = 403;
            $this->headers = array();
            $this->results = '<html>Forbidden</html>';
        } else {
            throw new RuntimeException('unexpected request URL or method');
        }
        return true;
    }
}
class ruTrackerChecker {
    const STE_UPTODATE = 3; const STE_DELETED = 4; const STE_CANT_REACH_TRACKER = 5; const STE_UNCHANGED = -1; const STE_DECLINED = -2;
    public static $client; public static $fetched; public static $mode; public static $logs = array();
    public static function registerTracker() {}
    public static function makeClient($url) {
        self::$client = new LoginRedirectSnoopy();
        self::$client->mode = self::$mode;
        // The real caller-URL preflight refusal must discard the preceding
        // login response. No actual DNS or network is consulted.
        self::$client->block_private = self::$mode === 'no-metainfo';
        self::$client->private_allowlist = array();
        self::$fetched = self::$client->fetchComplex($url);
        return self::$client;
    }
    public static function logDebug($message) { self::$logs[] = $message; }
    public static function parseMetainfo($payload) { return null; }
}
ruTrackerChecker::$mode = $mode;
rTorrentSettings::get()->registerPlugin('loginmgr');
$manager = new accountManager();
$manager->accounts['KinozalTV'] = array('name' => 'KinozalTV',
    'path' => $repo . '/plugins/loginmgr/accounts/KinozalTV.php', 'object' => 'KinozalTVAccount',
    'login' => 'fixture-user', 'password' => 'fixture-password', 'enabled' => 1, 'auto' => 0);
if (!$manager->store()) throw new RuntimeException('cannot save isolated account');
// Real Snoopy below: no pace or 429 pause is wanted between scripted answers.
define('KINOZAL_REQUEST_PAUSE', 0);
define('KINOZAL_RATE_LIMIT_PAUSE', 0);
define('KINOZAL_LOGIN_PAUSE', 0);
require $repo . '/plugins/rutracker_check/trackers/kinozal.php';
$client = ruTrackerChecker::makeClient(KinozalCheckImpl::downloadUrl('1'));
$reader = new ReflectionMethod('KinozalCheckImpl', 'decideFromDownload');
if (PHP_VERSION_ID < 80100) $reader->setAccessible(true);
$verdict = $reader->invoke(null, $client, 0, 1, str_repeat('a', 40), null, true);
$guests = new ReflectionProperty('KinozalCheckImpl', 'downloadGuestAnswers');
if (PHP_VERSION_ID < 80100) $guests->setAccessible(true);
$abandoned = new ReflectionProperty('KinozalCheckImpl', 'downloadAbandoned');
if (PHP_VERSION_ID < 80100) $abandoned->setAccessible(true);
echo json_encode(array('requests' => ruTrackerChecker::$client->requests,
    'fetched' => ruTrackerChecker::$fetched, 'status' => ruTrackerChecker::$client->status,
    'body' => ruTrackerChecker::$client->results, 'headers' => ruTrackerChecker::$client->headers,
    'response_code' => ruTrackerChecker::$client->response_code,
    'redirect' => ruTrackerChecker::$client->lastredirectaddr,
    'response_url' => ruTrackerChecker::$client->lastResponseURL, 'verdict' => $verdict,
    'resolve_calls' => LoginRedirectSnoopy::$resolveCalls,
    'error_class' => RuTrackerFetchError::classify(ruTrackerChecker::$client->error),
    'guests' => $guests->getValue(), 'abandoned' => $abandoned->getValue(),
    'logs' => ruTrackerChecker::$logs)), "\n";
PHP
        );
        $cases = array(
            'redirect' => array(403, 'https://kinozal.jumpingcrab.com/login.php', 1,
                'download attempt: the credential POST failed: id=1'),
            'guest' => array(200, 'https://kinozal.jumpingcrab.com/takelogin.php', 1,
                'download attempt ended at a guest page, check the loginmgr account: id=1'),
            'no-metainfo' => array(Snoopy::RESPONSE_BODY_FAILED, '', 0,
                'download attempt failed: refused-unresolvable-host id=1'),
        );
        foreach ($cases as $mode => $expected) {
            strictAssertTrue(mkdir($dir . '/' . $mode, 0700), 'each trace has a cold account profile');
            $command = escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg(__DIR__ . '/../../php-test.ini')
                . ' ' . escapeshellarg($dir . '/child.php') . ' ' . escapeshellarg(testFindRepoRoot())
                . ' ' . escapeshellarg($dir . '/' . $mode) . ' ' . escapeshellarg($mode) . ' 2>&1';
            $output = array();
            exec($command, $output, $code);
            strictAssertSame(0, $code, $mode . ': the real login path completed: ' . implode(' | ', $output));
            $answer = json_decode(implode("\n", $output), true);
            strictAssertTrue(is_array($answer), $mode . ': the child returned response metadata');
            $requests = array(array('GET', 'https://kinozal.jumpingcrab.com/login.php'),
                array('POST', 'https://kinozal.jumpingcrab.com/takelogin.php'));
            if ($mode === 'redirect') $requests[] = array('GET', 'https://kinozal.jumpingcrab.com/login.php');
            strictAssertSame($requests, $answer['requests'], $mode . ': the exact request chain explains the refusal');
            strictAssertSame($mode === 'no-metainfo' ? 3 : 0, $answer['resolve_calls'],
                $mode . ': the real target guard refused the caller URL after the successful login exchange');
            // The POST was answered by a redirect to a refusal: KinozalTV marks
            // it, so the handler reads the account's failure, not a door's.
            $errorClass = array('redirect' => 'credential-post-failed', 'guest' => '',
                'no-metainfo' => 'refused-unresolvable-host');
            strictAssertSame($errorClass[$mode], $answer['error_class'],
                $mode . ': the final error names what refused the attempt');
            strictAssertSame(false, $answer['fetched'], $mode . ': fetchComplex refused the caller URL');
            strictAssertSame($expected[0], $answer['status'], $mode . ': status describes the final response or preflight refusal');
            if ($mode === 'no-metainfo')
                strictAssertSame(array('', array(), ''), array($answer['body'], $answer['headers'], $answer['response_code']),
                    'the refused download attempt exposes no earlier login response bytes or headers');
            strictAssertSame($mode === 'redirect' ? $expected[1] : '', $answer['redirect'],
                $mode . ': only the login redirect case records a redirect');
            strictAssertSame($expected[1], $answer['response_url'], $mode . ': the original download has no HTTP response');
            strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $answer['verdict'], $mode . ': the check remains retryable');
            strictAssertSame($expected[2], $answer['guests'], $mode . ': only an observed login wall counts');
            strictAssertSame(false, $answer['abandoned'], $mode . ': one refusal does not abandon the endpoint');
            strictAssertSame(array($expected[3]), $answer['logs'],
                $mode . ': the diagnostic names the attempt without inventing a download response');
        }
    } finally {
        strictRemoveTree($dir);
    }
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

// The endpoint lines are debug-gated. At the shipped default the operator must
// still read, once per cycle, that the details request chain met a challenge.
// fetchComplex can leave a login reply, so its final endpoint is unproven.
function kinozalSessionHintLines($log)
{
    return array_values(array_filter(explode("\n", $log), function ($line) {
        return strpos($line, 'rutracker_check: Kinozal answers as a guest') !== false;
    }));
}

$suite->test('a recognised challenge is explained once without diagnosing a guest session', function () {
    $cases = kinozalTopics(3);
    $log = testCapturedAppLog(function () use ($cases) {
        Snoopy::queue($cases[0]['details_url'], 403, kinozalChallengeBody());
        kinozalRunAll($cases);
    });
    strictAssertSame(1, substr_count($log, 'Kinozal checks skipped for the rest of this cycle'),
        'one ordinary-level skip line covers every later topic: ' . $log);
    strictAssertTrue(strpos($log, 'cloudflare-challenge') !== false, 'the cause is classified');
    strictAssertTrue(strpos($log, 'logging in again cannot bypass the challenge') !== false,
        'the ordinary line explains why changing credentials does not bypass Cloudflare');
    strictAssertSame(0, count(kinozalSessionHintLines($log)), 'a challenge is not proof of a guest session');
    strictAssertSame(1, count(Snoopy::$requests), 'the challenge is the last request in the cycle');
});

$suite->test('a guest session without a challenge asks for a new login once', function () {
    $c = kinozalTopics(3);
    $log = testCapturedAppLog(function () use ($c) {
        Snoopy::queue($c[0]['details_url'], 200, kinozalUnauthorizedBody());
        Snoopy::queue($c[1]['details_url'], 200, kinozalUnauthorizedBody());
        kinozalRunAll($c);
    });
    $lines = kinozalSessionHintLines($log);
    strictAssertSame(1, count($lines), 'one explanation for the whole cycle: ' . $log);
    strictAssertTrue(strpos($lines[0], 'Settings -> Accounts') !== false
        && strpos($lines[0], 'Cloudflare') === false,
        'the operator is told where to log in again: ' . $lines[0]);
});

$suite->test('details parser failure cannot certify a hash or deletion or latch a guest session', function () {
    $cases = kinozalTopics(2);
    Snoopy::queue($cases[0]['details_url'], 200,
        kinozalParserFailureBody() . kinozalDetailsBody($cases[0]['hash']));
    Snoopy::queue($cases[1]['details_url'], 200,
        kinozalParserFailureBody() . 'Торрент файл не найден.');
    $log = testCapturedAppLog(function () use ($cases) {
        kinozalWithParserBudget(function () use ($cases) {
            foreach (kinozalRunAll($cases) as $verdict)
                strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdict,
                    'an unparsed guest marker makes the answer retryable');
        });
    });
    strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'detailsGuestAnswers'),
        'unknown answers are not a proved guest streak');
    strictAssertSame(false, strictGetPrivateStatic('KinozalCheckImpl', 'cycleAbandoned'),
        'two unreadable pages do not skip later topics');
    strictAssertSame(2, count(array_filter(ruTrackerChecker::$logs, function ($line) {
        return strpos($line, 'guest-page-parse-failed') !== false;
    })), 'both parser refusals are classified');
    strictAssertSame(1, count(kinozalParserFailureLines($log)),
        'the shipped log level names the parser failure once per process: ' . $log);
    strictAssertSame(0, count(kinozalSessionHintLines($log)), 'and does not call it a lost session: ' . $log);
});

$suite->test('download parser failure cannot certify deletion or latch a guest session', function () {
    $cases = kinozalTopics(2);
    foreach ($cases as $case)
        Snoopy::queue($case['details_url'], 403, '<html>Forbidden</html>');
    foreach ($cases as $case) {
        Snoopy::queue($case['download_url'], 200, kinozalParserFailureBody() . kinozalDownloadMissingBody());
        ruTrackerChecker::queueResult('parseMetainfo', null);
    }
    $log = testCapturedAppLog(function () use ($cases) {
        kinozalWithParserBudget(function () use ($cases) {
            foreach (kinozalRunAll($cases) as $verdict)
                strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdict,
                    'a missing marker in an unparsed guest page is not deletion');
        });
    });
    strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'),
        'unknown answers are not a proved guest streak');
    strictAssertSame(false, strictGetPrivateStatic('KinozalCheckImpl', 'downloadAbandoned'),
        'two unreadable pages do not skip later downloads');
    strictAssertSame(2, count(array_filter(ruTrackerChecker::$logs, function ($line) {
        return strpos($line, 'guest-page-parse-failed') !== false;
    })), 'both parser refusals are classified');
    strictAssertSame(1, count(kinozalParserFailureLines($log)),
        'the shipped log level names the parser failure once per process: ' . $log);
});

$suite->test('a rescan-limit answer proves neither authentication nor deletion and later readable answers recover', function () {
    // The exact 4 MiB short-prefix/long-tail counterexample. These malformed
    // bytes are a robustness probe; the deletion block remains the captured
    // endpoint's marker shape, not a new claim about a live response.
    $tail = str_repeat('<a ', 6000) . '"' . str_repeat('x', 4 * 1024 * 1024 - 18001);
    foreach (array('details', 'download') as $endpoint) {
        $cases = kinozalTopics(3);
        foreach ($cases as $i => $case) {
            if ($endpoint === 'details') {
                Snoopy::queue($case['details_url'], 200,
                    ($i < 2 ? $tail : '') . kinozalDetailsBody($case['hash']));
            } else {
                Snoopy::queue($case['details_url'], 403, '<html>Forbidden</html>');
                Snoopy::queue($case['download_url'], 200, $i < 2 ? kinozalMissingBlock() . $tail : $case['raw']);
                ruTrackerChecker::queueResult('parseMetainfo', $i < 2 ? null : $case['torrent']);
                if ($i === 2) ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);
            }
        }
        $log = testCapturedAppLog(function () use ($cases, &$verdicts) { $verdicts = kinozalRunAll($cases); });
        strictAssertSame(array(ruTrackerChecker::STE_CANT_REACH_TRACKER, ruTrackerChecker::STE_CANT_REACH_TRACKER,
            ruTrackerChecker::STE_UPTODATE), $verdicts,
            $endpoint . ': unverified bytes cannot certify a hash/deletion, and recovery stays reachable');
        strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', $endpoint . 'GuestAnswers'),
            $endpoint . ': a rescan refusal is not a guest marker');
        strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', $endpoint . 'Unverified'),
            $endpoint . ': the readable answer clears its preceding unknown streak');
        strictAssertSame(1, substr_count($log, 'guest-page check failed (rescan-limit)'),
            $endpoint . ': the ordinary log classifies the refusal once');
    }
});

$suite->test('three unverified details answers in a row stop the handler for the cycle', function () {
    $cases = kinozalTopics(4);
    foreach (array_slice($cases, 0, 3) as $case)
        Snoopy::queue($case['details_url'], 200, kinozalParserFailureBody() . kinozalDetailsBody($case['hash']));
    $log = testCapturedAppLog(function () use ($cases, &$verdicts) {
        $verdicts = kinozalWithParserBudget(function () use ($cases) { return kinozalRunAll($cases); });
    });
    kinozalAssertAsked($verdicts, 3, 'unverified answers stay retryable, the skipped topic keeps its verdict');
    strictAssertSame(3, count(Snoopy::$requests), 'the fourth topic spends no request on the same failure');
    strictAssertSame(true, strictGetPrivateStatic('KinozalCheckImpl', 'cycleAbandoned'),
        'the details endpoint is skipped for the rest of this cycle');
    strictAssertSame(0, strictGetPrivateStatic('KinozalCheckImpl', 'detailsGuestAnswers'),
        'the bound is not a guest streak');
    strictAssertSame(1, substr_count($log, 'Kinozal checks skipped for the rest of this cycle'),
        'an unverified latch is visible once at the normal log level');
    strictAssertOneLogMatching(ruTrackerChecker::$logs, 'skipped for the rest of this cycle',
        'the latch is named in the classified line');
});

$suite->test('a readable answer between unverified ones clears their count', function () {
    $cases = kinozalTopics(5);
    foreach ($cases as $i => $case)
        Snoopy::queue($case['details_url'], 200, ($i === 2 ? '' : kinozalParserFailureBody())
            . kinozalDetailsBody($case['hash']));
    $verdicts = kinozalWithParserBudget(function () use ($cases) { return kinozalRunAll($cases); });
    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $verdicts[2], 'the readable answer is checked on its merits');
    strictAssertSame(5, count(Snoopy::$requests), 'two failures, a readable answer and two failures skip nothing');
    strictAssertSame(false, strictGetPrivateStatic('KinozalCheckImpl', 'cycleAbandoned'),
        'isolated unverified answers do not add up across a readable one');
});

$suite->test('a readable download answer between unverified ones clears their count', function () {
    // Both readable shapes: a page whose markers parsed, and metainfo.
    foreach (array('page', 'metainfo') as $readable) {
        $cases = kinozalTopics(5);
        foreach ($cases as $case)
            Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('F', 40)));
        foreach ($cases as $i => $case) {
            if ($i !== 2) {
                Snoopy::queue($case['download_url'], 200, kinozalParserFailureBody());
                ruTrackerChecker::queueResult('parseMetainfo', null);
            } elseif ($readable === 'page') {
                Snoopy::queue($case['download_url'], 200, '<html>maintenance</html>');
                ruTrackerChecker::queueResult('parseMetainfo', null);
            } else {
                Snoopy::queue($case['download_url'], 200, $case['raw']);
                ruTrackerChecker::queueResult('parseMetainfo', $case['torrent']);
                ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);
            }
        }
        $verdicts = kinozalWithParserBudget(function () use ($cases) { return kinozalRunAll($cases); });
        strictAssertSame($readable === 'page' ? ruTrackerChecker::STE_CANT_REACH_TRACKER : ruTrackerChecker::STE_UPTODATE,
            $verdicts[2], $readable . ': the readable answer is decided on its merits');
        strictAssertSame(10, count(Snoopy::$requests), $readable . ': healthy details and isolated unverified downloads skip nothing');
        strictAssertSame(false, strictGetPrivateStatic('KinozalCheckImpl', 'downloadAbandoned'),
            $readable . ': the count does not add up across a readable answer');
    }
});

$suite->test('three unverified download answers stop only the download endpoint', function () {
    $cases = kinozalTopics(4);
    foreach ($cases as $case)
        Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('F', 40)));
    foreach (array_slice($cases, 0, 3) as $case) {
        Snoopy::queue($case['download_url'], 200, kinozalParserFailureBody() . kinozalDownloadMissingBody());
        ruTrackerChecker::queueResult('parseMetainfo', null);
    }
    $verdicts = kinozalWithParserBudget(function () use ($cases) { return kinozalRunAll($cases); });
    strictAssertSame(array_fill(0, 4, ruTrackerChecker::STE_CANT_REACH_TRACKER), $verdicts,
        'no deletion is read from unverified download pages');
    strictAssertSame(7, count(Snoopy::$requests), 'all details are requested but the fourth download is not');
    strictAssertSame(true, strictGetPrivateStatic('KinozalCheckImpl', 'downloadAbandoned'),
        'the download endpoint is skipped for the rest of this cycle');
    strictAssertSame(false, strictGetPrivateStatic('KinozalCheckImpl', 'cycleAbandoned'),
        'the details latch is a separate decision');
});

$suite->test('a proved guest redirect breaks the unverified download streak', function () {
    $cases = kinozalTopics(6);
    foreach ($cases as $case)
        Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('F', 40)));
    foreach ($cases as $i => $case) {
        if ($i === 2) {
            Snoopy::queue($case['download_url'], 302, '', array(), 'https://kinozal.guru/login.php');
        } elseif ($i === 5) {
            Snoopy::queue($case['download_url'], 200, $case['raw']);
            ruTrackerChecker::queueResult('parseMetainfo', $case['torrent']);
            ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);
        } else {
            Snoopy::queue($case['download_url'], 200, kinozalParserFailureBody());
            ruTrackerChecker::queueResult('parseMetainfo', null);
        }
    }
    $verdicts = kinozalWithParserBudget(function () use ($cases) { return kinozalRunAll($cases); });
    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $verdicts[5],
        'two unverified answers on either side of a proved guest response cannot hide later metainfo');
    strictAssertSame(12, count(Snoopy::$requests), 'every topic reaches download after healthy details');
    strictAssertSame(false, strictGetPrivateStatic('KinozalCheckImpl', 'downloadAbandoned'),
        'only consecutive unverified answers can trip their latch');
});

// Real account orchestration with only HTTPS and DNS answers scripted. The
// historical login form and challenge markers are replayed, never sent live.
function kinozalRealAccountTrace($mode, $topics = 6, $hosts = array('kinozal.jumpingcrab.com'))
{
    $dir = sys_get_temp_dir() . '/kinozal-cycle-' . bin2hex(random_bytes(4));
    strictAssertTrue(mkdir($dir, 0700) && mkdir($dir . '/profile', 0700), 'private login profile');
    try {
        file_put_contents($dir . '/child.php', <<<'PHP'
<?php
list(, $repo, $profile, $mode, $topics, $hosts) = $argv;
$_ENV['RU_PROFILE_PATH'] = $profile;
$_ENV['RU_LOG_FILE'] = $profile . '/errors.log';
$kinozalHosts = explode(',', $hosts);
require $repo . '/php/Snoopy.class.inc';
require $repo . '/plugins/loginmgr/accounts.php';
class LoginBoundSnoopy extends Snoopy {
    public static $requests = array();
    public static $mode;
    public static $topic = 0;
    public static function resolveHost($host) { return array('8.8.8.8'); }
    public function _httpsrequest($url, $content_type = '', $body = '', $method = 'GET') {
        self::$requests[] = array($method, $url);
        $this->headers = array();
        $this->_redirectaddr = false;
        $this->status = 200;
        $this->results = '<html>signed in</html>';
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $host = parse_url($url, PHP_URL_HOST);
        // Two doors that fail in turn, one per topic, refusing the connection.
        if (self::$mode === 'dead-flapping'
            && $host === (self::$topic % 2 ? 'kinozal.jumpingcrab.com' : 'kinozal.cloudns.nz')) {
            $this->status = 7;
            $this->error = 'Error: cURL could not retrieve the document, error 7.';
            $this->results = '';
            return false;
        }
        if (self::$mode === 'post-timeout' && $path === '/takelogin.php') {
            $this->status = 28;
            $this->error = 'Error: cURL could not retrieve the document, error 28.';
            $this->results = '';
            return false;
        }
        if (self::$mode === 'post-error-redirect' && $path === '/takelogin.php') {
            $this->status = 302;
            $this->_redirectaddr = 'https://' . $host . '/userdetails.php';
            $this->results = '';
            return true;
        }
        if ($path === '/userdetails.php') {
            $this->status = 500;
            $this->results = '<html>Internal Server Error</html>';
            return true;
        }
        if ($path === '/' || $path === '/login.php') {
            // Reduced from the captured 2026-08-07 form, with its bare values.
            $this->results = '<form method=post action="/takelogin.php" name=upt>'
                . '<input type=text name="username"><input type=password name="password"></form>';
        } elseif ($path === '/get_srv_details.php') {
            $this->results = '<ul><li>Инфо хеш: ' . str_repeat('b', 40) . '</li></ul>';
        }
        // Credentials that no longer open a session: every answer is a guest's.
        if (self::$mode === 'dead-flapping' && ($path === '/takelogin.php' || $path === '/get_srv_details.php'))
            $this->results = '<a href="/signup.php">Register</a>';
        if (self::$mode === 'unverified' && $path === '/takelogin.php') {
            $this->results = str_replace('<a ', '<a ' . str_repeat('t="1" ', 2000),
                '<a href="/signup.php">Register</a>');
        }
        $challenge = (self::$mode === 'form-challenge' && ($path === '/' || $path === '/login.php'))
            || (self::$mode === 'post-challenge' && $path === '/takelogin.php')
            || (self::$mode === 'details-challenge' && $path === '/get_srv_details.php')
            || (self::$mode === 'first-door-challenge' && $path === '/get_srv_details.php'
                && parse_url($url, PHP_URL_HOST) === 'kinozal.jumpingcrab.com')
            || (self::$mode === 'download-challenge' && $path === '/download.php')
            || (self::$mode === 'header-200' && $path === '/get_srv_details.php');
        if ($challenge) {
            $this->status = self::$mode === 'header-200' ? 200 : 403;
            $this->headers = array('cf-mitigated: challenge');
            // Historical interstitial markers from the 2026-09-12 capture.
            $this->results = self::$mode === 'header-200' ? '<html>Forbidden</html>'
                : '<html><title>Just a moment...</title>'
                    . '<script src="/cdn-cgi/challenge-platform/h/g/orchestrate/chl_page/v1"></script></html>';
        }
        return true;
    }
}
class ruTrackerChecker {
    const USER_AGENT = 'fixture-agent';
    const STE_UPTODATE = 3; const STE_DELETED = 4; const STE_CANT_REACH_TRACKER = 5; const STE_UNCHANGED = -1; const STE_DECLINED = -2;
    public static $logs = array();
    public static function registerTracker() {}
    public static function makeClient($url) {
        $client = new LoginBoundSnoopy();
        $client->fetchComplex($url);
        return $client;
    }
    public static function logDebug($message) { self::$logs[] = $message; }
    public static function logUnrepairable($message) { self::$logs[] = 'unrepairable: ' . $message; }
    public static function parseMetainfo($payload) { return null; }
}
LoginBoundSnoopy::$mode = $mode;
rTorrentSettings::get()->registerPlugin('loginmgr');
$manager = new accountManager();
$manager->accounts['KinozalTV'] = array('name' => 'KinozalTV',
    'path' => $repo . '/plugins/loginmgr/accounts/KinozalTV.php', 'object' => 'KinozalTVAccount',
    'login' => 'fixture-user', 'password' => 'fixture-password', 'enabled' => 1, 'auto' => 0);
if (!$manager->store()) throw new RuntimeException('cannot save isolated account');
// Real Snoopy below: no pace or 429 pause is wanted between scripted answers.
define('KINOZAL_REQUEST_PAUSE', 0);
define('KINOZAL_RATE_LIMIT_PAUSE', 0);
define('KINOZAL_LOGIN_PAUSE', 0);
require $repo . '/plugins/rutracker_check/trackers/kinozal.php';
$verdicts = array();
for ($id = 1; $id <= (int) $topics; $id++) {
    LoginBoundSnoopy::$topic = $id;
    $verdicts[] = KinozalCheckImpl::download_torrent('https://kinozal.guru/details.php?id=' . $id,
        str_repeat('a', 40), null);
}
echo json_encode(array('verdicts' => $verdicts, 'trace' => LoginBoundSnoopy::$requests,
    'logs' => ruTrackerChecker::$logs,
    'app_log' => (string) @file_get_contents($profile . '/errors.log'))), "\n";
PHP
        );
        $output = array();
        exec(escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg(__DIR__ . '/../../php-test.ini')
            . ' -d pcre.backtrack_limit=1000 ' . escapeshellarg($dir . '/child.php')
            . ' ' . escapeshellarg(testFindRepoRoot()) . ' ' . escapeshellarg($dir . '/profile')
            . ' ' . escapeshellarg($mode) . ' ' . (int) $topics . ' ' . escapeshellarg(implode(',', $hosts))
            . ' 2>&1', $output, $code);
        strictAssertSame(0, $code, 'the real login path completed: ' . implode(' | ', $output));
        $answer = json_decode(implode("\n", $output), true);
        strictAssertTrue(is_array($answer), 'the child returned its trace: ' . implode(' | ', $output));
        return $answer;
    } finally {
        strictRemoveTree($dir);
    }
}

$suite->test('an unverifiable login answer spends a bounded number of credential POSTs per cycle', function () {
    $answer = kinozalRealAccountTrace('unverified');
    kinozalAssertAsked($answer['verdicts'], 3, 'an unverified login answer never becomes a verdict');
    strictAssertSame(3, count(array_filter($answer['trace'], function ($request) { return $request[0] === 'POST'; })),
        'three unverified answers stop the details requests and their logins');
    strictAssertSame(1, count(kinozalParserFailureLines($answer['app_log'])),
        'the operator reads the classified parser failure once: ' . $answer['app_log']);
    strictAssertSame(false, strpos($answer['app_log'], 'fixture-password') !== false,
        'no credential reaches the log');
});

function kinozalPosts($trace)
{
    return array_values(array_filter($trace, function ($request) { return $request[0] === 'POST'; }));
}

$suite->test('dead credentials behind two doors failing in turn spend two credential POSTs per cycle', function () {
    $answer = kinozalRealAccountTrace('dead-flapping', 10, KINOZAL_TWO_DOORS);
    kinozalAssertAsked($answer['verdicts'], 2, 'a dead session is never a verdict');
    strictAssertSame(array(array('POST', 'https://kinozal.cloudns.nz/takelogin.php'),
        array('POST', 'https://kinozal.jumpingcrab.com/takelogin.php')), kinozalPosts($answer['trace']),
        'one login at each door, and the second guest answer ends the cycle');
    strictAssertSame(1, count(array_filter($answer['logs'], function ($line) {
        return strpos($line, 'unrepairable: Kinozal answers as a guest') === 0;
    })), 'the operator is asked to check the account: ' . json_encode($answer['logs']));
});

$suite->test('a credential POST that fails after it went out is not sent to the next door', function () {
    $lines = array('post-error-redirect' => 'credential-post-http-500', 'post-timeout' => 'credential-post-unanswered');
    foreach ($lines as $mode => $reason) {
        $answer = kinozalRealAccountTrace($mode, 6, KINOZAL_TWO_DOORS);
        kinozalAssertAsked($answer['verdicts'], 2, $mode . ': nothing is concluded');
        strictAssertSame(array_fill(0, 2, array('POST', 'https://kinozal.jumpingcrab.com/takelogin.php')),
            kinozalPosts($answer['trace']), $mode . ': two failed logins end the cycle, both at the first door');
        strictAssertSame(array(), array_values(array_filter($answer['trace'], function ($request) {
            return parse_url($request[1], PHP_URL_HOST) === 'kinozal.cloudns.nz';
        })), $mode . ': the password is not sent to the other door');
        strictAssertSame(1, substr_count($answer['app_log'], 'login on kinozal.jumpingcrab.com refused (' . $reason . ')'),
            $mode . ': the failed POST is named once: ' . $answer['app_log']);
        strictAssertSame(false, strpos(json_encode($answer['logs']), 'unreachable') !== false,
            $mode . ': no door is reported unreachable: ' . json_encode($answer['logs']));
        strictAssertSame(false, strpos($answer['app_log'], 'fixture-password') !== false,
            $mode . ': no credential reaches the log');
    }
});

$suite->test('the first Cloudflare answer stops the real login and download chain for the cycle', function () {
    foreach (array('form-challenge', 'post-challenge', 'details-challenge', 'download-challenge', 'header-200') as $mode) {
        $answer = kinozalRealAccountTrace($mode);
        $trace = array(array('GET', 'https://kinozal.jumpingcrab.com/login.php'));
        if ($mode !== 'form-challenge') $trace[] = array('POST', 'https://kinozal.jumpingcrab.com/takelogin.php');
        if (in_array($mode, array('details-challenge', 'download-challenge', 'header-200'), true))
            $trace[] = array('GET', 'https://kinozal.jumpingcrab.com/get_srv_details.php?action=2&id=1');
        if ($mode === 'download-challenge') $trace[] = array('GET', kinozalDownloadUrl('1'));
        strictAssertSame($trace, $answer['trace'], $mode . ': no request follows the first challenge at the only door');
        kinozalAssertAsked($answer['verdicts'], 1, $mode . ': the challenged topic stays retryable, the rest are not asked');
        $skips = array_values(array_filter($answer['logs'], function ($line) {
            return strpos($line, 'unrepairable: kinozal: Kinozal checks skipped for the rest of this cycle') === 0;
        }));
        strictAssertSame(1, count($skips), $mode . ': the default log explains the skip exactly once: '
            . json_encode($answer['logs']));
        strictAssertTrue(strpos($skips[0], 'reason=cloudflare-challenge') !== false,
            $mode . ': the reason is classified');
        strictAssertSame(1, substr_count($answer['app_log'],
            'kinozal: kinozal.jumpingcrab.com answered a Cloudflare challenge'),
            $mode . ': the site that answered the challenge is named once: ' . $answer['app_log']);
        strictAssertTrue(strpos($answer['app_log'], 'logging in again cannot bypass the challenge') !== false,
            $mode . ': the ordinary line explains that repeating login cannot bypass Cloudflare');
        strictAssertSame(false, strpos($answer['app_log'], 'fixture-password') !== false,
            $mode . ': credentials are absent from the log');
    }
    // With a second door the challenge closes only the first one: the topic
    // is asked at the next door at once, and so is every topic after it.
    $answer = kinozalRealAccountTrace('first-door-challenge', 2, KINOZAL_TWO_DOORS);
    strictAssertSame(array(
        array('GET', 'https://kinozal.jumpingcrab.com/login.php'),
        array('POST', 'https://kinozal.jumpingcrab.com/takelogin.php'),
        array('GET', 'https://kinozal.jumpingcrab.com/get_srv_details.php?action=2&id=1'),
        array('GET', 'https://kinozal.cloudns.nz/login.php'),
        array('POST', 'https://kinozal.cloudns.nz/takelogin.php'),
        array('GET', 'https://kinozal.cloudns.nz/get_srv_details.php?action=2&id=1'),
    ), array_slice($answer['trace'], 0, 6), 'two doors: the topic the challenge interrupted is asked at the next door');
    strictAssertSame(array(), array_values(array_filter(array_slice($answer['trace'], 3), function ($request) {
        return parse_url($request[1], PHP_URL_HOST) === 'kinozal.jumpingcrab.com';
    })), 'the challenged door is not asked again');
    strictAssertTrue(in_array(array('GET', 'https://kinozal.cloudns.nz/get_srv_details.php?action=2&id=2'),
        $answer['trace'], true), 'and the next topic goes straight to the door that answered');
    strictAssertSame(1, substr_count($answer['app_log'], 'answered a Cloudflare challenge'),
        'only the challenged site is reported: ' . $answer['app_log']);
});

$suite->test('trusted absolute and query registration anchors remain guest answers on both doors', function () {
    foreach (array('/signup.php?from=details&amp;id=1', 'https://kinozal.guru/signup.php?from=details') as $href) {
        $page = str_replace('/signup.php', $href, kinozalUnauthorizedBody());
        $cases = kinozalTopics(2);
        foreach ($cases as $case) Snoopy::queue($case['details_url'], 200, $page);
        foreach (kinozalRunAll($cases) as $verdict)
            strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdict, 'details guest is retryable');
        strictAssertSame(2, strictGetPrivateStatic('KinozalCheckImpl', 'detailsGuestAnswers'),
            'both details answers establish a guest session');
        $cases = kinozalTopics(2);
        foreach ($cases as $case)
            Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('F', 40)));
        foreach ($cases as $case) {
            Snoopy::queue($case['download_url'], 200, $page);
            ruTrackerChecker::queueResult('parseMetainfo', null);
        }
        foreach (kinozalRunAll($cases) as $verdict)
            strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdict, 'download guest is retryable');
        strictAssertSame(2, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'),
            'both download answers establish a guest session');
    }
});

$suite->test('both endpoints losing the session in one cycle are explained once', function () {
    $c = kinozalTopics(4);
    $log = testCapturedAppLog(function () use ($c) {
        // Two updated topics: details answer, download meets the login wall.
        foreach (array_slice($c, 0, 2) as $case) {
            Snoopy::queue($case['details_url'], 200, kinozalDetailsBody(str_repeat('B', 40)));
            Snoopy::queue($case['download_url'], 200, kinozalLoginPage());
            ruTrackerChecker::queueResult('parseMetainfo', null);
        }
        // Then the details endpoint walls the session too.
        Snoopy::queue($c[2]['details_url'], 200, kinozalUnauthorizedBody());
        Snoopy::queue($c[3]['details_url'], 200, kinozalUnauthorizedBody());
        kinozalRunAll($c);
    });
    strictAssertSame(2, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'),
        'the download endpoint reached its guest tolerance');
    strictAssertSame(2, strictGetPrivateStatic('KinozalCheckImpl', 'detailsGuestAnswers'),
        'and so did the details endpoint');
    // One line per scope: the download latch, then the whole cycle. A later,
    // wider stop is not hidden behind the first line.
    strictAssertSame(1, substr_count($log, 'Kinozal checks skipped for the rest of this cycle: scope=download'),
        'the download latch is reported once: ' . $log);
    strictAssertSame(1, substr_count($log, 'Kinozal checks skipped for the rest of this cycle: scope=all'),
        'and the later stop of the whole cycle once: ' . $log);
    strictAssertTrue(strpos($log, 'scope=download') < strpos($log, 'scope=all'), 'in the order they happened');
    strictAssertSame(1, count(kinozalSessionHintLines($log)), 'still one explanation for the cycle: ' . $log);
});

$suite->test('a single guest blink is not explained as a lost session', function () {
    $c = kinozalTopics(2);
    $log = testCapturedAppLog(function () use ($c) {
        Snoopy::queue($c[0]['details_url'], 200, kinozalUnauthorizedBody());
        Snoopy::queue($c[1]['details_url'], 200, kinozalDetailsBody($c[1]['hash']));
        kinozalRunAll($c);
    });
    strictAssertSame(0, count(kinozalSessionHintLines($log)), 'one forgiven guest answer stays quiet: ' . $log);
});

$suite->test('signup page names inside details filenames do not latch a guest session', function () {
    $cases = kinozalTopics(3);
    foreach ($cases as $case)
        Snoopy::queue($case['details_url'], 200,
            str_replace('movie.mkv', 'source/signup.php', kinozalDetailsBody($case['hash'])));
    foreach (kinozalRunAll($cases) as $verdict)
        strictAssertSame(ruTrackerChecker::STE_UPTODATE, $verdict,
            'an authoritative details hash remains readable when a filename mentions signup.php');
    strictAssertSame(3, count(Snoopy::$requests), 'every topic is checked without a guest latch');
});

// Captured 2026-10-14 from kinozal.jumpingcrab.com on an authenticated session,
// topic 2154369: HTTP 200, text/html; charset=UTF-8, 246 bytes. The same hash
// as kinozal.me answered for that topic the same day.
function kinozalMirrorHashAnswer()
{
    $body = '<ul><li>Инфо хеш: 6486E11AEBB78DD1BFF732558C0683667497F292</li>'
        . '<li>Размер части торрента: 2 МБ</li><li><div class=\'b ing\'>'
        . 'Coyote.vs.Acme.2026.x265.WEB-DL.2160p.HDR-DV.mkv <i>18.41 ГБ (19768411034)</i></div></li></ul>';
    strictAssertSame('ddddaa161610e1eca3a4a65441d269d7', md5($body), 'the captured mirror answer is carried verbatim');
    return $body;
}

$suite->test('the live mirror hash answer is accepted verbatim', function () {
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200, kinozalMirrorHashAnswer());
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($case['topic_url'], '6486e11aebb78dd1bff732558c0683667497f292', $case['torrent']),
        'the mirror names the current hash exactly as the site does');
    strictAssertSame(array(array('fetchComplex', $case['details_url'])), Snoopy::$requests,
        'the details question goes to the mirror and nothing else is asked');
});

// get_srv_details.php for an id the site does not have (99999999), captured
// 2026-10-15 06:09 UTC from kinozal.jumpingcrab.com on the stored session
// through the checker's own client: HTTP 200, UTF-8, these 42 bytes -- the
// marker measured on kinozal.guru with a trailing period.
$suite->test('the live mirror details answer for a missing id is read as a deletion, verbatim', function () {
    $live = 'Торрент файл не найден.';
    strictAssertSame('6cd975622c0818de5dbc72f7ff6c16c1', md5($live), 'the bytes are the captured answer, untouched');
    $case = kinozalCase();
    Snoopy::queue($case['details_url'], 200, $live);
    strictAssertSame(ruTrackerChecker::STE_DELETED,
        KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']),
        'the mirror says the topic is gone in the words the site uses');
    strictAssertSame(array(array('fetchComplex', $case['details_url'])), Snoopy::$requests,
        'the authoritative details answer needs no download');
});

$suite->test('a changed hash downloads the new torrent from the same mirror', function () {
    $old = kinozalCase('old.mkv');
    $new = kinozalFixture('new.mkv');
    Snoopy::queue($old['details_url'], 200, kinozalDetailsBody($new['hash']));
    Snoopy::queue($old['download_url'], 200, $new['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', $new['torrent']);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPDATED);
    strictAssertSame(ruTrackerChecker::STE_UPDATED,
        KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']), 'the replacement runs');
    strictAssertSame(array(array('fetchComplex', $old['details_url']), array('fetchComplex', $old['download_url'])),
        Snoopy::$requests, 'the cookies the mirror set for details are the ones the download needs');
});

$suite->test('the live mirror guest redirect from download.php is a guest answer', function () {
    $old = kinozalCase('old.mkv');
    $new = kinozalFixture('new.mkv');
    Snoopy::queue($old['details_url'], 200, kinozalDetailsBody($new['hash']));
    // Captured 2026-10-14 from kinozal.jumpingcrab.com without a session: 302
    // with an absolute Location on the mirror itself (topic id substituted).
    Snoopy::queue($old['download_url'], 302, '',
        array('Location: https://kinozal.jumpingcrab.com/login.php?to=%2Fdownload.php%3Fid%3D2148020'),
        'https://kinozal.jumpingcrab.com/login.php?to=%2Fdownload.php%3Fid%3D2148020');
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER,
        KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']), 'still retryable');
    strictAssertSame(1, strictGetPrivateStatic('KinozalCheckImpl', 'downloadGuestAnswers'),
        'the mirror login wall counts toward the session latch');
    strictAssertSame(0, kinozalDoorFailures('download'), 'and not toward a door outage');
});

// ---------------------------------------------------------------------------
// Doors. Each configured host answers both questions for a topic; a door that
// does not answer hands the same topic to the next open door at once, and the
// door that answered is asked first from then on.

function kinozalDoorLog($log, $needle)
{
    return substr_count($log, $needle);
}

$suite->test('a door that does not answer hands the same topic to the next door at once', function () {
    $c = kinozalTopics(3, KINOZAL_TWO_DOORS);
    $second = function ($i) use ($c) { return kinozalDetailsUrl(1000 + $i, 'kinozal.cloudns.nz'); };
    $log = testCapturedAppLog(function () use ($c, $second, &$verdicts) {
        Snoopy::queue($c[0]['details_url'], 503, kinozalServerErrorBody());
        for ($i = 0; $i < 3; $i++) Snoopy::queue($second($i), 200, kinozalDetailsBody($c[$i]['hash']));
        $verdicts = kinozalRunAll($c);
    });
    strictAssertSame(array_fill(0, 3, ruTrackerChecker::STE_UPTODATE), $verdicts,
        'no topic is lost to the door that failed');
    strictAssertSame(array(array('fetchComplex', $c[0]['details_url']), array('fetchComplex', $second(0)),
        array('fetchComplex', $second(1)), array('fetchComplex', $second(2))), Snoopy::$requests,
        'the answering door is asked first for every later topic');
    strictAssertSame(1, kinozalDoorLog($log,
        'kinozal: kinozal.jumpingcrab.com did not answer (http-503); this cycle continues on kinozal.cloudns.nz'),
        'the operator learns which door failed and why: ' . $log);
    strictAssertSame(0, kinozalDoorLog($log, 'Kinozal checks skipped for the rest of this cycle'),
        'a working second door does not skip the cycle');
});

$suite->test('a name that does not resolve is routed around without losing the topic', function () {
    $c = kinozalTopics(1, KINOZAL_TWO_DOORS);
    // The https path stores curl's exit code (6 = the name did not resolve).
    Snoopy::queue($c[0]['details_url'], 6, '', array(), null, 'Error: cURL could not retrieve the document, error 6.');
    Snoopy::queue(kinozalDetailsUrl(1000, 'kinozal.cloudns.nz'), 200, kinozalDetailsBody($c[0]['hash']));
    $log = testCapturedAppLog(function () use ($c, &$verdicts) { $verdicts = kinozalRunAll($c); });
    strictAssertSame(array(ruTrackerChecker::STE_UPTODATE), $verdicts, 'the second door answers the topic');
    strictAssertTrue(strpos($log, 'did not answer (curl-transfer-6)') !== false,
        'the classified reason tells a dead name from a refusal: ' . $log);
});

$suite->test('a page that is not Kinozal\'s answer moves the topic to the next door', function () {
    $c = kinozalTopics(2, KINOZAL_TWO_DOORS);
    Snoopy::queue($c[0]['details_url'], 200, '<html><title>Технические работы</title></html>');
    Snoopy::queue(kinozalDetailsUrl(1000, 'kinozal.cloudns.nz'), 200, kinozalDetailsBody($c[0]['hash']));
    Snoopy::queue(kinozalDetailsUrl(1001, 'kinozal.cloudns.nz'), 200, kinozalDetailsBody($c[1]['hash']));
    $log = testCapturedAppLog(function () use ($c, &$verdicts) { $verdicts = kinozalRunAll($c); });
    strictAssertSame(array(ruTrackerChecker::STE_UPTODATE, ruTrackerChecker::STE_UPTODATE), $verdicts,
        'a maintenance page, a parked name or a gate is a door problem, not a topic verdict');
    strictAssertTrue(strpos($log, 'did not answer (unrecognised-answer)') !== false,
        'and it is visible at the shipped log level: ' . $log);
});

$suite->test('three unrecognised pages in a row close the only door and say so', function () {
    $c = kinozalTopics(4);
    for ($i = 0; $i < 3; $i++) Snoopy::queue($c[$i]['details_url'], 200, '<html>parked domain</html>');
    $log = testCapturedAppLog(function () use ($c, &$verdicts) { $verdicts = kinozalRunAll($c); });
    kinozalAssertAsked($verdicts, 3, 'nothing is concluded');
    strictAssertSame(3, count(Snoopy::$requests), 'the fourth topic spends no request on the same page');
    strictAssertSame(1, kinozalDoorLog($log, 'kinozal: kinozal.jumpingcrab.com is skipped for the rest of this cycle'
        . ' (3 failures in a row, last unrecognised-answer)'), 'the closed door is named: ' . $log);
    strictAssertSame(1, kinozalDoorLog($log, 'scope=all reason=details-unreachable'),
        'and the stop of the cycle: ' . $log);
});

$suite->test('a challenge closes only the door that showed it', function () {
    $c = kinozalTopics(3, KINOZAL_TWO_DOORS);
    Snoopy::queue($c[0]['details_url'], 403, kinozalChallengeBody());
    for ($i = 0; $i < 3; $i++)
        Snoopy::queue(kinozalDetailsUrl(1000 + $i, 'kinozal.cloudns.nz'), 200, kinozalDetailsBody($c[$i]['hash']));
    $log = testCapturedAppLog(function () use ($c, &$verdicts) { $verdicts = kinozalRunAll($c); });
    strictAssertSame(array_fill(0, 3, ruTrackerChecker::STE_UPTODATE), $verdicts, 'the mirror answers every topic');
    strictAssertSame(4, count(Snoopy::$requests), 'the challenged door is not asked again');
    strictAssertSame(true, KinozalResponse::requestsBlocked(kinozalDetailsUrl(1, 'kinozal.jumpingcrab.com')),
        'the challenged site stays blocked for the cycle');
    strictAssertSame(false, KinozalResponse::requestsBlocked(kinozalDetailsUrl(1, 'kinozal.cloudns.nz')),
        'the other site is not');
    strictAssertSame(1, kinozalDoorLog($log, 'kinozal.jumpingcrab.com answered a Cloudflare challenge'),
        'the challenged site is named: ' . $log);
    strictAssertSame(0, kinozalDoorLog($log, 'Kinozal checks skipped for the rest of this cycle'),
        'and the cycle continues: ' . $log);
});

// Captured 2026-10-14 17:30:58 UTC from https://kinozal.jumpingcrab.com/login.php
// with the plugin's User-Agent, during the IP ban that followed a burst:
// HTTP/1.1 429 Too Many Requests, Server: nginx, no Retry-After. The body is
// nginx's error page; the handler reads only the status (the verbatim answer
// is pinned in tests/php/KinozalRequestPacingTest.php).
$suite->test('the live mirror 429 answer stops every door for the cycle', function () {
    $c = kinozalTopics(3, KINOZAL_TWO_DOORS);
    Snoopy::queue($c[0]['details_url'], 429, '<html><head><title>429 Too Many Requests</title></head></html>',
        array('HTTP/1.1 429 Too Many Requests', 'Server: nginx'));
    $log = testCapturedAppLog(function () use ($c, &$verdicts) { $verdicts = kinozalRunAll($c); });
    kinozalAssertAsked($verdicts, 0, 'a 429 concerns the IP, not a topic: every verdict stands');
    strictAssertSame(1, count(Snoopy::$requests),
        'both mirrors share one limiter, so no other door is asked into the same ban');
    strictAssertSame(1, kinozalDoorLog($log, 'scope=all reason=rate-limited'),
        'the operator reads that the cycle stopped on a rate limit: ' . $log);
});

$suite->test('a pause Snoopy keeps after a 429 stops the cycle without a request', function () {
    $c = kinozalTopics(2, KINOZAL_TWO_DOORS);
    Snoopy::queueEarlyFailure($c[0]['details_url'], 'request-throttled');
    kinozalAssertAsked(kinozalRunAll($c), 0, 'a paused group is not a door failure, and no topic was asked');
    strictAssertSame(1, count(Snoopy::$requests), 'no other door and no later topic is tried');
    strictAssertSame(0, kinozalDoorFailures('details'), 'and the door is not counted as failing');
});

$suite->test('a refused credential POST is not retried at another door', function () {
    $c = kinozalTopics(3, KINOZAL_TWO_DOORS);
    $post = 'https://kinozal.jumpingcrab.com/takelogin.php';
    for ($i = 0; $i < 2; $i++)
        Snoopy::queue($c[$i]['details_url'], 403, '<html>Forbidden</html>', array(), null,
            KinozalResponse::CREDENTIAL_POST_FAILED, $post);
    $log = testCapturedAppLog(function () use ($c, &$verdicts) { $verdicts = kinozalRunAll($c); });
    kinozalAssertAsked($verdicts, 2, 'nothing is concluded');
    strictAssertSame(array(array('fetchComplex', $c[0]['details_url']), array('fetchComplex', $c[1]['details_url'])),
        Snoopy::$requests, 'no other door logs in, and no download probe sends the password again');
    strictAssertSame(0, kinozalDoorFailures('details'), 'a refused login is not a door outage');
    strictAssertSame(1, count(kinozalSessionHintLines($log)), 'the operator is asked to check the account: ' . $log);
});

$suite->test('when every door refuses, each is closed after three failures and the cycle stops', function () {
    $c = kinozalTopics(4, KINOZAL_TWO_DOORS);
    for ($i = 0; $i < 3; $i++) {
        Snoopy::queue($c[$i]['details_url'], 503, kinozalServerErrorBody());
        Snoopy::queue(kinozalDetailsUrl(1000 + $i, 'kinozal.cloudns.nz'), 503, kinozalServerErrorBody());
    }
    $log = testCapturedAppLog(function () use ($c, &$verdicts) { $verdicts = kinozalRunAll($c); });
    kinozalAssertAsked($verdicts, 3, 'nothing is concluded');
    strictAssertSame(6, count(Snoopy::$requests), 'the fourth topic is not asked once both doors are out');
    foreach (KINOZAL_TWO_DOORS as $host)
        strictAssertSame(1, kinozalDoorLog($log, 'kinozal: ' . $host . ' is skipped for the rest of this cycle'),
            $host . ' is named when it closes: ' . $log);
    strictAssertSame(1, kinozalDoorLog($log, 'scope=all reason=details-unreachable'), 'then the cycle stop: ' . $log);
});

$suite->test('a door refusing everything with 403 is probed once per topic at the last door', function () {
    $c = kinozalTopics(4, KINOZAL_TWO_DOORS);
    for ($i = 0; $i < 3; $i++) {
        Snoopy::queue($c[$i]['details_url'], 403, '<html>Forbidden</html>');
        Snoopy::queue(kinozalDetailsUrl(1000 + $i, 'kinozal.cloudns.nz'), 403, '<html>Forbidden</html>');
        Snoopy::queue(kinozalDownloadUrl(1000 + $i, 'kinozal.cloudns.nz'), 403, '<html>Forbidden</html>');
    }
    $log = testCapturedAppLog(function () use ($c, &$verdicts) { $verdicts = kinozalRunAll($c); });
    kinozalAssertAsked($verdicts, 3, 'nothing is concluded');
    strictAssertSame(9, count(Snoopy::$requests), 'two details requests and one probe per topic, then the stop');
    strictAssertSame(0, kinozalDoorLog($log, 'scope=download'),
        'a failed probe does not report a download latch that the next door would contradict: ' . $log);
    strictAssertSame(1, kinozalDoorLog($log, 'scope=all reason=details-unreachable'), 'the stop is logged: ' . $log);
});

$suite->test('a failed download at the answering door is asked at the next door', function () {
    $c = kinozalTopics(1, KINOZAL_TWO_DOORS);
    $new = kinozalFixture('new.mkv', 1000);
    Snoopy::queue($c[0]['details_url'], 200, kinozalDetailsBody($new['hash']));
    Snoopy::queue($c[0]['download_url'], 503, kinozalServerErrorBody());
    Snoopy::queue(kinozalDownloadUrl(1000, 'kinozal.cloudns.nz'), 200, $new['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', $new['torrent']);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPDATED);
    strictAssertSame(array(ruTrackerChecker::STE_UPDATED), kinozalRunAll($c), 'the replacement comes from the next door');
    strictAssertSame(array('fetchComplex', kinozalDownloadUrl(1000, 'kinozal.cloudns.nz')), end(Snoopy::$requests),
        'download.php under maintenance at one door does not cost the update');
});

$suite->test('three download refusals close download.php at the door and leave details running', function () {
    $c = kinozalTopics(4);
    $new = kinozalFixture('new.mkv', 1009);
    for ($i = 0; $i < 3; $i++) {
        Snoopy::queue($c[$i]['details_url'], 200, kinozalDetailsBody($new['hash']));
        Snoopy::queue($c[$i]['download_url'], 503, kinozalServerErrorBody());
    }
    Snoopy::queue($c[3]['details_url'], 200, kinozalDetailsBody($c[3]['hash']));
    $log = testCapturedAppLog(function () use ($c, &$verdicts) { $verdicts = kinozalRunAll($c); });
    strictAssertSame(ruTrackerChecker::STE_UPTODATE, $verdicts[3], 'details checks continue');
    strictAssertSame(7, count(Snoopy::$requests), 'three details/download pairs and the fourth details request');
    strictAssertSame(1, kinozalDoorLog($log, 'kinozal.jumpingcrab.com download.php is skipped for the rest of this cycle'),
        'the closed endpoint is named: ' . $log);
    strictAssertSame(1, kinozalDoorLog($log, 'scope=download reason=download-unreachable'), 'once: ' . $log);
});

$suite->test('guest answers do not move to another door', function () {
    // A dead session is the account's, so another door would log in for nothing.
    $c = kinozalTopics(3, KINOZAL_TWO_DOORS);
    for ($i = 0; $i < 2; $i++) Snoopy::queue($c[$i]['details_url'], 200, kinozalUnauthorizedBody());
    kinozalRunAll($c);
    strictAssertSame(2, count(Snoopy::$requests), 'the guest latch still ends the cycle');
});

$suite->test('guest answers at two doors add up: the session that died is the account\'s', function () {
    // Each guest answer spent a login with the same credentials, wherever it
    // came from; counting afresh per door let two doors failing in turn
    // spend a credential POST on every topic.
    $cases = kinozalTopics(3, KINOZAL_TWO_DOORS);
    Snoopy::queue($cases[0]['details_url'], 200, kinozalUnauthorizedBody());
    Snoopy::queue($cases[1]['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue(kinozalDetailsUrl(1001, 'kinozal.cloudns.nz'), 200, kinozalUnauthorizedBody());
    $log = testCapturedAppLog(function () use ($cases, &$verdicts) { $verdicts = kinozalRunAll($cases); });
    kinozalAssertAsked($verdicts, 2, 'nothing is concluded');
    strictAssertSame(3, count(Snoopy::$requests), 'the second guest answer, from the other door, ends the cycle');
    strictAssertSame(1, count(kinozalSessionHintLines($log)), 'the operator is asked to check the account: ' . $log);
});

$suite->test('the download latch holds at every door for the rest of the cycle', function () {
    $cases = kinozalTopics(3, KINOZAL_TWO_DOORS);
    $new = kinozalFixture('new.mkv', 1002);
    for ($i = 0; $i < 2; $i++) {
        Snoopy::queue($cases[$i]['details_url'], 200, kinozalDetailsBody($new['hash']));
        Snoopy::queue($cases[$i]['download_url'], 200, kinozalLoginPage());
        ruTrackerChecker::queueResult('parseMetainfo', null);
    }
    Snoopy::queue($cases[2]['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue(kinozalDetailsUrl(1002, 'kinozal.cloudns.nz'), 200, kinozalDetailsBody($new['hash']));
    $log = testCapturedAppLog(function () use ($cases, &$verdicts) { $verdicts = kinozalRunAll($cases); });
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdicts[2], 'the replacement waits for the next cycle');
    strictAssertSame(6, count(Snoopy::$requests), 'a door that takes over details does not reopen download.php');
    strictAssertSame(1, kinozalDoorLog($log, 'scope=download reason=download-guest-session'),
        'the latch is logged once, and holds as it says: ' . $log);
});

$suite->test('a new cycle asks the first configured door again', function () {
    $c = kinozalTopics(1, KINOZAL_TWO_DOORS);
    Snoopy::queue($c[0]['details_url'], 503, kinozalServerErrorBody());
    Snoopy::queue(kinozalDetailsUrl(1000, 'kinozal.cloudns.nz'), 200, kinozalDetailsBody($c[0]['hash']));
    kinozalRunAll($c);
    KinozalCheckImpl::resetCycle();
    Snoopy::$requests = array();
    Snoopy::queue($c[0]['details_url'], 200, kinozalDetailsBody($c[0]['hash']));
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($c[0]['topic_url'], $c[0]['hash'], $c[0]['torrent']),
        'a recovered first door is used again');
    strictAssertSame(array(array('fetchComplex', $c[0]['details_url'])), Snoopy::$requests, 'and asked first');
});

// ---------------------------------------------------------------------------
// A replacement replaces a torrent and erases payload files the new one does
// not list, and the bytes come from a name in a free-DNS zone. They must be
// this topic's torrent for this account before anything is replaced.

function kinozalReplacementCase($comment, $announce)
{
    $old = kinozalCase('old.mkv');
    $raw = strictTorrentRaw('new.mkv', $announce, $comment);
    $new = Torrent::fromRawBytes($raw);
    Snoopy::queue($old['details_url'], 200, kinozalDetailsBody((string) $new->hash_info()));
    Snoopy::queue($old['download_url'], 200, $raw);
    ruTrackerChecker::queueResult('parseMetainfo', $new);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPDATED);
    return $old;
}

$suite->test('the account\'s own replacement passes the identity check', function () {
    $old = kinozalReplacementCase(kinozalTopicUrl(KINOZAL_FIXTURE_TOPIC_ID),
        'http://tr3.torrent4me.com/ann?uk=K0I5ZrJ6If1');
    strictAssertSame(ruTrackerChecker::STE_UPDATED,
        KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']),
        'same topic, same passkey on another Kinozal tracker host: replaced');
    strictAssertSame(1, count(ruTrackerChecker::callsFor('createTorrent')), 'the replacement runs once');
});

$suite->test('a replacement that is not this topic\'s torrent for this account is refused visibly', function () {
    foreach (array(
        'topic-mismatch' => array(kinozalTopicUrl(KINOZAL_FIXTURE_TOPIC_ID + 1), 'http://tr2.torrent4me.com/ann?uk=K0I5ZrJ6If1'),
        'no topic' => array('https://evil.test/details.php?id=' . KINOZAL_FIXTURE_TOPIC_ID, 'http://tr2.torrent4me.com/ann?uk=K0I5ZrJ6If1'),
        'passkey-mismatch' => array(kinozalTopicUrl(KINOZAL_FIXTURE_TOPIC_ID), 'http://tr2.torrent4me.com/ann?uk=SomebodyElse'),
        'foreign tracker' => array(kinozalTopicUrl(KINOZAL_FIXTURE_TOPIC_ID), 'http://tracker.evil.test/ann?uk=K0I5ZrJ6If1'),
    ) as $label => $identity) {
        $old = kinozalReplacementCase($identity[0], $identity[1]);
        $log = testCapturedAppLog(function () use ($old, &$verdict) {
            $verdict = KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], $old['torrent']);
        });
        strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdict, $label . ': nothing is replaced');
        strictAssertSame(0, count(ruTrackerChecker::callsFor('createTorrent')), $label . ': createTorrent is not reached');
        strictAssertTrue(strpos($log, 'kinozal: replacement refused (') !== false, $label . ': refused visibly: ' . $log);
    }
});

$suite->test('without the old torrent\'s passkey nothing is replaced', function () {
    $old = kinozalReplacementCase(kinozalTopicUrl(KINOZAL_FIXTURE_TOPIC_ID), 'http://tr2.torrent4me.com/ann?uk=K0I5ZrJ6If1');
    $log = testCapturedAppLog(function () use ($old, &$verdict) {
        $verdict = KinozalCheckImpl::download_torrent($old['topic_url'], $old['hash'], null);
    });
    strictAssertSame(ruTrackerChecker::STE_CANT_REACH_TRACKER, $verdict, 'an unverifiable replacement is not made');
    strictAssertTrue(strpos($log, 'replacement refused (passkey-unknown)') !== false, 'and the reason is named: ' . $log);
});

$suite->test('an unchanged download needs no identity check', function () {
    $case = kinozalCase();
    kinozalReset();
    Snoopy::queue($case['details_url'], 403, '<html>Forbidden</html>');
    Snoopy::queue($case['download_url'], 200, $case['raw']);
    ruTrackerChecker::queueResult('parseMetainfo', $case['torrent']);
    ruTrackerChecker::queueResult('createTorrent', ruTrackerChecker::STE_UPTODATE);
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], null),
        'the same infohash replaces nothing, so it needs no old torrent to compare with');
});

// ---------------------------------------------------------------------------
// The setting. $kinozalHosts orders the doors for the checker and the account.

$suite->test('the configured host order is honoured', function () {
    kinozalReset(array('kinozal.cloudns.nz', 'KINOZAL.ME.'));
    $case = kinozalFixture();
    $details = kinozalDetailsUrl(KINOZAL_FIXTURE_TOPIC_ID, 'kinozal.cloudns.nz');
    Snoopy::queue($details, 200, kinozalDetailsBody($case['hash']));
    strictAssertSame(ruTrackerChecker::STE_UPTODATE,
        KinozalCheckImpl::download_torrent($case['topic_url'], $case['hash'], $case['torrent']), 'answered');
    strictAssertSame(array(array('fetchComplex', $details)), Snoopy::$requests, 'the first configured host is asked');
    strictAssertSame(array('kinozal.cloudns.nz', 'kinozal.me'), KinozalResponse::doors(),
        'a listed Kinozal host is accepted in any spelling the URL helper normalises');
    strictAssertSame(array('kinozal.tv', 'kinozal.me', 'kinozal.guru', 'kinozal.cloudns.nz'),
        KinozalResponse::accountHosts(), 'a mirror left out of the list receives no session');
});

$suite->test('a host outside the Kinozal list is refused visibly and the default is used', function () {
    foreach (array(array('evil.test'), array('kinozal.jumpingcrab.com', 'kinozal.me.evil.test'),
        array('dl.kinozal.me'), 'kinozal.me', array(), array(42)) as $configured) {
        $log = testCapturedAppLog(function () use ($configured) {
            kinozalReset($configured);
            strictAssertSame(KinozalResponse::MIRROR_HOSTS, KinozalResponse::doors(),
                'only listed Kinozal sites receive the account session');
        });
        strictAssertSame(1, substr_count($log, 'config: invalid $kinozalHosts'),
            'the refusal names the setting: ' . json_encode($configured) . ' ' . $log);
    }
});

$suite->test('without a setting the official mirrors are the doors', function () {
    kinozalReset(null);
    unset($GLOBALS['kinozalHosts']);
    KinozalCheckImpl::resetCycle();
    strictAssertSame(array('kinozal.jumpingcrab.com', 'kinozal.cloudns.nz'), KinozalResponse::doors(), 'the default');
    strictAssertSame('https://kinozal.jumpingcrab.com', KinozalResponse::refreshOrigin(),
        'the automatic refresh logs in at the first door');
});

exit($suite->run());
