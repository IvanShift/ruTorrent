<?php

/**
 * Unit test for KinozalTVAccount::isOK() -- loginmgr's "are we still logged
 * in?" detector for Kinozal.
 *
 * accounts.php is deliberately NOT loaded: it evals the plugin config, pulls
 * in cache.php/Snoopy.class.inc and, through them, the real rXMLRPC* classes,
 * none of which isOK() touches. The account file itself has no requires, so a
 * minimal commonAccount base is all it needs to load -- the code under test is
 * the real one, straight from plugins/loginmgr/accounts/KinozalTV.php.
 */

require_once(__DIR__ . '/../rutracker_check/TestLib.php');

abstract class commonAccount
{
    public $url = 'http://abstract.com';

    abstract protected function isOK($client);
    abstract protected function login($client, $login, $password, &$url, &$method, &$content_type, &$body, &$is_result_fetched);
}

require_once(testFindRepoRoot() . '/plugins/loginmgr/accounts/KinozalTV.php');

// isOK() and login() test double.
class KinozalFakeClient
{
    public $status = 200;
    public $results = '';
    public $referer = '';
    public $cookies = array();
    public $responses = array();

    public function __construct($results = '', $status = 200)
    {
        $this->results = $results;
        $this->status = $status;
    }

    public function fetch($url, $method = 'GET', $contentType = '', $body = '')
    {
        if (isset($this->responses[$url])) {
            $resp = $this->responses[$url];
            $this->status = $resp['status'] ?? 200;
            $this->results = $resp['results'] ?? '';
            return ($this->status >= 200 && $this->status < 400);
        }
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

$suite = new StrictTestSuite();

$suite->test('today\'s login form is recognised as a dead session', function () {
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

$suite->test('torrent bytes read as a live session', function () {
    $raw = 'd8:announce31:http://tr2.torrent4me.com/ann?uk=X4:infod6:lengthi1e'
        . '4:name9:movie.mkv12:piece lengthi16384e6:pieces20:' . str_repeat("\0", 20) . 'ee';
    strictAssertSame(true, kinozalIsOK($raw),
        'a downloaded torrent must never be mistaken for a login wall');
});

$suite->test('non-200 status or empty body is recognised as dead session', function () {
    strictAssertSame(false, kinozalIsOK('OK page', 500), 'HTTP 500 is not OK');
    strictAssertSame(false, kinozalIsOK('OK page', 302), 'HTTP 302 is not OK');
    strictAssertSame(false, kinozalIsOK('', 200), 'empty body is not OK');
});

$suite->test('login returns false when takelogin returns invalid credentials or guest page', function () {
    $client = new KinozalFakeClient();
    $client->responses = array(
        'https://kinozal.guru' => array('status' => 200, 'results' => '<html>main page</html>'),
        'https://kinozal.guru/takelogin.php' => array('status' => 200, 'results' => kinozalLoginPage()),
    );
    strictAssertSame(false, kinozalLogin($client, 'baduser', 'badpass'),
        'login must fail when takelogin returns login form');
});

$suite->test('login returns true when takelogin returns authenticated session', function () {
    $client = new KinozalFakeClient();
    $client->responses = array(
        'https://kinozal.guru' => array('status' => 200, 'results' => '<html>main page</html>'),
        'https://kinozal.guru/takelogin.php' => array('status' => 200, 'results' => '<div class="mn"><a href="/userdetails.php?id=123">user</a><a href="/logout.php">Выход</a></div>'),
    );
    strictAssertSame(true, kinozalLogin($client, 'gooduser', 'goodpass'),
        'login succeeds when takelogin returns authenticated page');
    strictAssertSame(true, isset($client->cookies['uid']), 'cookies set on login');
});

exit($suite->run());
