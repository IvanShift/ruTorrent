<?php

/**
 * Unit test for KinozalTVAccount::isOK() -- loginmgr's "are we still logged
 * in?" detector for Kinozal.
 *
 * accounts.php is deliberately NOT loaded: it evals the plugin config, pulls
 * in cache.php/Snoopy.class.inc and, through them, the real rXMLRPC* classes,
 * none of which isOK() touches. The account requires only its shared response
 * predicate, so a minimal commonAccount base is sufficient. The code under
 * test comes straight from plugins/loginmgr/accounts/KinozalTV.php.
 */

require_once(__DIR__ . '/../rutracker_check/TestLib.php');

abstract class commonAccount
{
    public $url = 'http://abstract.com';

    abstract protected function isOK($client);
    abstract protected function login($client, $login, $password, &$url, &$method, &$content_type, &$body, &$is_result_fetched);
}

require_once(testFindRepoRoot() . '/plugins/loginmgr/accounts/KinozalTV.php');
require_once(testFindRepoRoot() . '/php/Torrent.php');

// isOK() and login() test double.
class KinozalFakeClient
{
    public $status = 200;
    public $results = '';
    public $referer = '';
    public $cookies = array();
    public $headers = array();
    public $requests = array();
    public $responses = array();
    public $unreachable = null;

    public function __construct($results = '', $status = 200)
    {
        $this->results = $results;
        $this->status = $status;
    }

    public function fetch($url, $method = 'GET', $contentType = '', $body = '')
    {
        $this->requests[] = array($method, $url);
        if ($this->unreachable !== null && $url === $this->unreachable) {
            return false;
        }
        if (isset($this->responses[$url])) {
            $resp = $this->responses[$url];
            $this->status = $resp['status'] ?? 200;
            $this->results = $resp['results'] ?? '';
        }
        // Snoopy::fetch() returns the transport result, not a verdict on the
        // status: it answers true for a 500 as readily as for a 200. A double
        // that refused 4xx/5xx here would do the rejecting that the code under
        // test is supposed to do, and a reverted fix would still pass.
        return true;
    }

    public function setcookies()
    {
        $this->cookies['uid'] = '12345';
        $this->cookies['pass'] = 'abcde';
    }
}

function kinozalIsOK($body, $status = 200)
{
    $account = new KinozalTVAccount();
    $method = new ReflectionMethod('KinozalTVAccount', 'isOK');
    if (PHP_VERSION_ID < 80100) {
        $method->setAccessible(true);
    }
    return $method->invoke($account, new KinozalFakeClient($body, $status));
}

function kinozalLogin($client, $user = 'user', $pass = 'pass')
{
    $account = new KinozalTVAccount();
    $method = new ReflectionMethod('KinozalTVAccount', 'login');
    if (PHP_VERSION_ID < 80100) {
        $method->setAccessible(true);
    }
    $url = '';
    $httpMethod = 'GET';
    $contentType = '';
    $body = '';
    $isFetched = false;
    return $method->invokeArgs($account, array($client, $user, $pass, &$url, &$httpMethod, &$contentType, &$body, &$isFetched));
}

// Captured from https://kinozal.guru/login.php on 2026-08-07: type= carries no
// quotes and size=/id= sit between it and name=, so the old two-attribute
// probe ('type="password" name="password"') finds nothing here.
function kinozalLoginPage()
{
    return '<div class=pad0x0x5x0><ul class=lis><li class=mn><a href="/login.php">Вход</a></li>'
        . '<li><a href="/signup.php">Регистрация в Кинозал.GURU</a></li></ul></div>'
        . '<form method=post action="/takelogin.php" name=upt">'
        . '<input type=text size=35 id="username" name="username" value="">'
        . '<input type=password size=35 id="password" name="password" value="">'
        . '<input class=buttonS type=submit value=" Войти "></form>';
}

// The markup the original detector was written against.
function kinozalLegacyLoginPage()
{
    return '<form method=post action="/takelogin.php">'
        . '<input type="text" name="username" value="">'
        . '<input type="password" name="password" value=""></form>';
}

// Captured from get_srv_details.php without a session (HTTP 200, UTF-8).
function kinozalUnauthorizedAnswer()
{
    return 'Вы не зарегистрированный пользователь или не авторизированы, чтобы '
        . 'зарегистрироваться пройдите <a href=\'/signup.php\' class=\'sba\'>сюда</a>.';
}

// The application log that $body wrote, with the predicate's once-per-process
// failure notice re-armed, so each test observes its own first failure.
function kinozalCapturedLog(callable $body)
{
    if (property_exists('KinozalResponse', 'failureLogged'))
        strictSetPrivateStatic('KinozalResponse', 'failureLogged', false);
    $file = tempnam(sys_get_temp_dir(), 'kinozal-log');
    $saved = array_key_exists('log_file', $GLOBALS) ? $GLOBALS['log_file'] : null;
    $GLOBALS['log_file'] = $file;
    try {
        $body();
        return array_values(array_filter(explode("\n", (string) file_get_contents($file)), 'strlen'));
    } finally {
        @unlink($file);
        if ($saved === null) unset($GLOBALS['log_file']);
        else $GLOBALS['log_file'] = $saved;
    }
}

// Exhausts PCRE under pcre.backtrack_limit=1000 in either JIT mode: each
// quoted value is one iteration of the tag pattern's possessive group.
function kinozalUnparseableGuestAnswer()
{
    return str_replace('<a ', '<a ' . str_repeat('t="1" ', 2000), kinozalUnauthorizedAnswer());
}

// Openers that an unclosed quote keeps from closing, then the captured
// anchor. Reaching it means rescanning the tail once per opener.
function kinozalUnterminatedOpeners($count = 1400000)
{
    return str_repeat('<a ', $count) . '"' . kinozalUnauthorizedAnswer();
}

$suite = new StrictTestSuite();

$suite->test('the captured 2026-08-07 login form is recognised as a dead session', function () {
    strictAssertSame(false, kinozalIsOK(kinozalLoginPage()),
        'the unquoted type= / reordered attribute form must not read as logged in');
});

$suite->test('the older quoted login form is still recognised', function () {
    strictAssertSame(false, kinozalIsOK(kinozalLegacyLoginPage()),
        'the markup the original detector targeted must keep failing the check');
});

$suite->test('the not-authorized answer of get_srv_details is a dead session', function () {
    // This one never renders a login form, so the password-field marker alone
    // cannot see it -- yet it is the answer the checker actually receives, and
    // loginmgr must re-login on it rather than pass the text upstream.
    strictAssertSame(false, kinozalIsOK(kinozalUnauthorizedAnswer()),
        'the guest answer of get_srv_details.php must not read as logged in');
});

$suite->test('a guest-page parser failure leaves authentication unproven', function () {
    $limit = ini_get('pcre.backtrack_limit');
    $lines = kinozalCapturedLog(function () use ($limit) {
        try {
            ini_set('pcre.backtrack_limit', '1');
            strictAssertSame(null, kinozalIsOK(kinozalUnauthorizedAnswer()),
                'a failed marker parser cannot certify a live session or demand a login');
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
    });
    // The log write runs PCRE itself, so its classified line, not
    // preg_last_error() afterwards, is the evidence of the failure.
    strictAssertSame(1, count($lines), 'the fixture really exhausts PCRE: ' . implode(' | ', $lines));
    strictAssertTrue(strpos($lines[0], 'kinozal: guest-page check failed (pcre-') !== false,
        'the refusal is classified: ' . $lines[0]);
});

$suite->test('an unverified guest check is logged once with its classified PCRE reason', function () {
    $limit = ini_get('pcre.backtrack_limit');
    $lines = kinozalCapturedLog(function () use ($limit) {
        try {
            ini_set('pcre.backtrack_limit', '1000');
            for ($i = 0; $i < 3; $i++)
                strictAssertSame(null, kinozalIsOK(kinozalUnparseableGuestAnswer()),
                    'every failed check stays unverified, not only the logged one');
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
        strictAssertSame(false, kinozalIsOK(kinozalUnparseableGuestAnswer()),
            'the same page is a guest page at the shipped limit');
    });
    strictAssertSame(1, count($lines), 'one line per process, not one per topic: ' . implode(' | ', $lines));
    strictAssertSame(1, preg_match('~^\[[^\]]+\] kinozal: guest-page check failed \(pcre-backtrack-limit\);'
        . ' the answer is treated as unverified, not as a live session or a guest page$~', $lines[0]),
        'the line names the classified PCRE reason and its consequence: ' . $lines[0]);
    strictAssertSame(false, strpos($lines[0], 'signup') !== false, 'no page text reaches the log');
});

$suite->test('long attribute runs keep the guest markers readable with and without PCRE JIT', function () {
    // Synthetic metamorphic variants of the captured anonymous answers. The
    // backtracking parser answered null for every case here (both controls
    // included) except quoted values without JIT: the JIT stack gave out at
    // ~8 KB of unquoted attribute text in one tag, the recursion limit at
    // ~50 KB. Without JIT its attribute pattern's leading \s+ alone took
    // ~8.7 s on the 256 KiB space run.
    $long = str_repeat('x', 65536);
    $expected = array(
        'unquoted value' => array(true,
            str_replace('<a href=', '<a data-x=' . $long . ' href=', kinozalUnauthorizedAnswer())),
        'bare attributes' => array(true,
            str_replace('<a ', '<a ' . str_repeat('x ', 32768), kinozalUnauthorizedAnswer())),
        'whitespace before equals' => array(true,
            str_replace('<a ', '<a' . str_repeat(' ', 262144) . '=y ', kinozalUnauthorizedAnswer())),
        'unterminated tag before the anchor' => array(true, '<a ' . $long . kinozalUnauthorizedAnswer()),
        'password input' => array(true, str_replace('<input type="password"',
            '<input ' . $long . ' type="password"', kinozalLegacyLoginPage())),
        'quoted values' => array(true,
            str_replace('<a ', '<a ' . str_repeat('t="1" ', 4000), kinozalUnauthorizedAnswer())),
        'long authorized tag' => array(false, '<ul><li>Инфо хеш: ' . str_repeat('A', 40) . '</li></ul>'
            . '<a data-x=' . $long . ' href="/userdetails.php?id=1">me</a><input ' . $long . ' name=s>'),
        // One stray unterminated opener is retried from the next opener and
        // stays well inside RESCAN_LIMIT; a limit near zero made both null.
        'stray unterminated opener before a guest answer' => array(true, '<a "' . kinozalUnauthorizedAnswer()),
        'authorized page ending in an unterminated input' => array(false, '<ul><li>Инфо хеш: ' . str_repeat('A', 40)
            . '</li></ul><a href="/userdetails.php?id=1">me</a><input '),
        // HTML keeps the first of repeated attributes, and so does the scan.
        'repeated attribute keeps its first value' => array(false, '<ul><li>Инфо хеш: ' . str_repeat('A', 40)
            . '</li></ul><a href=/userdetails.php?id=1 href=/signup.php>me</a>'),
        // An unclosed quote ends every opener's attempt, so each would rescan
        // the tail before the anchor is reached: bounded, then unverified.
        'unterminated openers' => array(null, kinozalUnterminatedOpeners()),
        // An unmatched quote scans its entire tail before the tag match
        // falls back to its short prefix. That failed work needs a budget too.
        'unterminated quoted tail' => array(null, str_repeat('<a ', 6000) . '"'
            . str_repeat('x', 4 * 1024 * 1024 - 18001)),
    );
    $dir = sys_get_temp_dir() . '/kinozal-pcre-' . bin2hex(random_bytes(4));
    strictAssertTrue(mkdir($dir, 0700), 'private child directory');
    try {
        file_put_contents($dir . '/cases.json', json_encode(array_map(function ($case) {
            return $case[1];
        }, $expected)));
        file_put_contents($dir . '/child.php', <<<'PHP'
<?php
// A quadratic regression dies here rather than stalling the suite.
set_time_limit(60);
require $argv[1] . '/php/kinozal.php';
$answers = array();
foreach (json_decode(file_get_contents($argv[2]), true) as $name => $body) {
    $started = microtime(true);
    $answer = KinozalResponse::isGuestPage($body);
    $answers[$name] = array($answer, (microtime(true) - $started) * 1000);
}
echo json_encode(array('jit' => ini_get('pcre.jit'), 'answers' => $answers)), "\n";
PHP
        );
        foreach (array('0', '1') as $jit) {
            $output = array();
            exec(escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg(__DIR__ . '/../../php-test.ini')
                . ' -d pcre.jit=' . $jit . ' -d memory_limit=256M ' . escapeshellarg($dir . '/child.php')
                . ' ' . escapeshellarg(testFindRepoRoot()) . ' ' . escapeshellarg($dir . '/cases.json') . ' 2>&1',
                $output, $code);
            strictAssertSame(0, $code, 'jit=' . $jit . ': the child completed: ' . implode(' | ', $output));
            $result = json_decode(implode("\n", $output), true);
            strictAssertSame($jit, (string) $result['jit'], 'the child ran in the requested JIT mode');
            foreach ($expected as $name => $case) {
                strictAssertSame($case[0], $result['answers'][$name][0], 'jit=' . $jit . ': ' . $name);
                // Linear scans finish in milliseconds; the old constructs took seconds.
                strictAssertTrue($result['answers'][$name][1] < 2000,
                    'jit=' . $jit . ': ' . $name . ' is linear: ' . round($result['answers'][$name][1]) . ' ms');
            }
        }
    } finally {
        strictRemoveTree($dir);
    }
});

$suite->test('a page of unterminated openers is refused as unverified with a classified reason', function () {
    // 30 KB: exact rescans would cost ~150 MB of scanning, past the bound.
    $lines = kinozalCapturedLog(function () {
        strictAssertSame(null, kinozalIsOK(kinozalUnterminatedOpeners(10000)),
            'bounded rescans leave the page unverified rather than reading a verdict');
    });
    strictAssertSame(1, count($lines), 'the refusal is visible: ' . implode(' | ', $lines));
    strictAssertTrue(strpos($lines[0], 'kinozal: guest-page check failed (rescan-limit);') !== false,
        'the reason is the bound, not a PCRE error: ' . $lines[0]);
});

$suite->test('registration links retain query and trusted absolute URL compatibility', function () {
    // Metamorphic variants of the captured anonymous registration anchor,
    // not claims that these href spellings were observed on the live site.
    foreach (array('/signup.php?from=details&amp;id=1', 'signup.php?from=details',
        'https://kinozal.guru/signup.php', 'https://KINOZAL.ME./signup.php?from=details',
        '//kinozal.tv/signup.php#form', 'HTTPS://kinozal.guru:443/signup.php',
        '&#47;signup.php?from=details') as $href) {
        $page = str_replace('/signup.php', $href, kinozalUnauthorizedAnswer());
        strictAssertSame(false, kinozalIsOK($page), 'the registration anchor is a guest signal: ' . $href);
    }
});

$suite->test('foreign and unrelated hrefs do not masquerade as Kinozal registration', function () {
    foreach (array('https://evil.test/signup.php', 'https://kinozal.guru@evil.test/signup.php',
        'https://kinozal.guru.evil.test/signup.php', '/download.php?next=/signup.php',
        '/source/signup.php', 'javascript:/signup.php', 'ftp://kinozal.guru/signup.php',
        '/signup.php.bak') as $href) {
        $page = str_replace('/signup.php', $href, kinozalUnauthorizedAnswer());
        strictAssertSame(true, kinozalIsOK($page), 'only a Kinozal registration link is a guest signal: ' . $href);
    }
});

$suite->test('guest-looking text inside HTML containers does not invalidate the session', function () {
    // Synthetic contexts around the captured markers, not new live fixtures.
    $contexts = array('<div data-note="<input name=password>">authorized</div>',
        '<!-- ' . kinozalUnauthorizedAnswer() . ' -->',
        '<script>const example = "<input name=password>";</script>',
        '<style>p:after {content: "<input name=password>"}</style>',
        '<textarea><input name=password></textarea>',
        '<title><a href=/signup.php>Example</a></title>');
    foreach ($contexts as $page) {
        strictAssertSame(true, kinozalIsOK($page), 'text outside a live element is not a guest marker');
        strictAssertSame(false, kinozalIsOK($page . kinozalUnauthorizedAnswer()),
            'a captured live anchor after the container remains a guest marker');
    }
    foreach (array('<div data-note="' . kinozalUnauthorizedAnswer(),
        '<!-- ' . kinozalUnauthorizedAnswer(), '<script>' . kinozalUnauthorizedAnswer()) as $page)
        strictAssertSame(null, kinozalIsOK($page),
            'an unfinished container cannot establish whether its marker is live');
});

$suite->test('a logged-in page reads as a live session', function () {
    $page = '<div class="mn"><a href="/userdetails.php?id=20244841">fessfess</a>'
        . '<a href="/logout.php">Выход</a></div>'
        . '<form action="/browse.php" method="get" id="srchform">'
        . '<input type="text" class="inp" id="s" name="s" size="15" value=""></form>';
    strictAssertSame(true, kinozalIsOK($page), 'a page behind the login wall is fine');
});

$suite->test('authorized tracker answers read as a live session', function () {
    strictAssertSame(
        true,
        kinozalIsOK('<ul><li>Инфо хеш: ' . str_repeat('A', 40) . '</li><li>Размер части торрента: 2 МБ</li></ul>'),
        'the details answer must not be mistaken for a login wall'
    );
    strictAssertSame(true, kinozalIsOK('Торрент файл не найден.'),
        'a removed topic is an answer from the tracker, not a dead session');
});

$suite->test('a signup filename inside an authorized details answer is not a guest link', function () {
    $page = '<ul><li>Инфо хеш: ' . str_repeat('A', 40) . '</li>'
        . '<li><div class="b ing">source/signup.php</div></li></ul>';
    strictAssertSame(true, kinozalIsOK($page),
        'a filename does not invalidate the session or spend another credential POST');
});

$suite->test('torrent bytes read as a live session', function () {
    $raw = 'd8:announce34:http://tr2.torrent4me.com/ann?uk=X4:infod6:lengthi1e'
        . '4:name9:movie.mkv12:piece lengthi16384e6:pieces20:' . str_repeat("\0", 20) . 'ee';
    strictAssertSame(false, Torrent::fromRawBytes($raw)->errors(), 'the fixture is valid bencode');
    strictAssertSame(true, kinozalIsOK($raw),
        'a downloaded torrent must never be mistaken for a login wall');
});

$suite->test('a torrent whose comment names the signup page is still a torrent', function () {
    // The registration link is a marker of a guest PAGE. A torrent is payload
    // and may carry any bytes at all, so reading the marker out of one would
    // cost a re-login, and the cached session, on every download of it.
    $comment = 'see /signup.php to register <title>Just a moment...</title>'
        . ' /cdn-cgi/challenge-platform/h/g/orchestrate/chl_page/v1';
    $torrent = 'd8:announce31:http://tr.kinozal.guru/ann?uk=X7:comment' . strlen($comment) . ':' . $comment
        . '4:infod6:lengthi1e4:name9:movie.mkv12:piece lengthi16384e6:pieces20:'
        . str_repeat("\0", 20) . 'ee';
    strictAssertSame(false, Torrent::fromRawBytes($torrent)->errors(), 'the fixture is valid bencode');
    strictAssertSame(true, kinozalIsOK($torrent),
        'a torrent must not be read as a login wall because of its comment');
});

$suite->test('a captured login form permits the exchange even when credentials are refused', function () {
    // The form admits the credential request; commonAccount still decides
    // whether the answer to that request accepted the credentials.
    strictAssertSame(true, KinozalResponse::hasLoginForm(kinozalLoginPage()), 'the captured form admits login');
    $client = new KinozalFakeClient();
    $client->responses = array(
        'https://kinozal.guru/login.php' => array('status' => 200, 'results' => kinozalLoginPage()),
        'https://kinozal.guru/takelogin.php' => array('status' => 200, 'results' => kinozalLoginPage()),
    );
    strictAssertSame(true, kinozalLogin($client, 'baduser', 'badpass'),
        'the captured form permits the POST and its refusal is left for commonAccount');
    strictAssertSame(false, kinozalIsOK($client->results),
        'and the guest form it came back with is what the caller then refuses');
    strictAssertSame(true, isset($client->cookies['uid']), 'cookies are captured either way');
});

$suite->test('login fails when the tracker cannot be reached at all', function () {
    $client = new KinozalFakeClient();
    $client->responses = array('https://kinozal.guru/login.php' => array('status' => 200, 'results' => kinozalLoginPage()));
    $client->unreachable = 'https://kinozal.guru/takelogin.php';
    strictAssertSame(false, kinozalLogin($client, 'user', 'pass'),
        'a request that did not go through is a failed login');
});

$suite->test('a refused or unrelated login form cannot spend a credential POST', function () {
    KinozalResponse::resetCycle();
    $lines = kinozalCapturedLog(function () {
    foreach (array(
        'HTTP refusal' => array(503, kinozalLoginPage()),
        'no form' => array(200, '<html>maintenance</html>'),
        'password outside form' => array(200, '<input name=username><input name=password>'),
        'foreign action' => array(200, str_replace('/takelogin.php', 'https://evil.test/takelogin.php', kinozalLoginPage())),
        'other action' => array(200, str_replace('/takelogin.php', '/other.php', kinozalLoginPage())),
        'get form' => array(200, str_replace('method=post', 'method=get', kinozalLoginPage())),
        'quoted fake form' => array(200, "<div data-note='" . kinozalLoginPage() . "'>text</div>"),
    ) as $label => $answer) {
        $client = new KinozalFakeClient();
        $client->responses['https://kinozal.guru/login.php'] = array('status' => $answer[0], 'results' => $answer[1]);
        strictAssertSame(false, kinozalLogin($client), $label . ': form admission refuses the login');
        strictAssertSame(array(array('GET', 'https://kinozal.guru/login.php')), $client->requests,
            $label . ': no credential request follows the GET');
    }
    });
    strictAssertSame(1, count($lines), 'repeated form refusals log once per cycle');
    strictAssertTrue(strpos($lines[0], 'login refused (login-form-http-refusal)') !== false,
        'the refusal uses a classification without the page or credentials');
});

exit($suite->run());
