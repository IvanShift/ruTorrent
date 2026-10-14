<?php

// Real account selection, Snoopy cookie scope, loginmgr session documents and
// rCache. Only curl's transfer is replaced (tests/php/ScriptedCurlFixture.php),
// so these synthetic exchanges make no network calls. Kinozal's pauses are a
// courtesy to the live mirrors' rate limiter, which scripted answers do not
// have; they are declared before php/kinozal.php can define its own.
define('KINOZAL_REQUEST_PAUSE', 0);
define('KINOZAL_RATE_LIMIT_PAUSE', 0);
$sessionRoot = sys_get_temp_dir() . '/kinozal-sessions-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($sessionRoot, 0700);
mkdir($sessionRoot . '/curl', 0700);
$_ENV['RU_PROFILE_PATH'] = $sessionRoot . '/profile';
$_ENV['RU_LOG_FILE'] = $sessionRoot . '/application.log';
require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../php/ScriptedCurlFixture.php');
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts.php');
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts/KinozalTV.php');
$httpBlockPrivateNetworks = false;

class KinozalSessionFixture
{
    // The default doors: A is the first, where the automatic refresh logs in.
    const A = 'https://kinozal.jumpingcrab.com';
    const B = 'https://kinozal.cloudns.nz';
    const DETAILS = '/details.php?id=1';
    // Form portion of the anonymous 2026-08-07 login.php capture.
    const FORM = '<form method=post action="/takelogin.php" name=upt">'
        . '<input type=text size=35 id="username" name="username" value="">'
        . '<input type=password size=35 id="password" name="password" value=""></form>';
    // The registration link that marks a guest page.
    const GUEST = '<a href="/signup.php">guest</a>';

    public $curl;
    private $root;

    public function __construct($root)
    {
        $this->root = $root;
        $this->curl = new ScriptedCurlFixture($root . '/curl');
        $this->writeInterleavingCurl();
    }

    public function reset()
    {
        KinozalResponse::resetCycle();
        testAssertSame(true, (new rCache('/accounts'))->remove(new privateData('KinozalTV')),
            'The session document of the previous case is removed');
        foreach (array('arm', 'fired', 'out', 'status') as $name) {
            @unlink($this->root . '/interleave.' . $name);
        }
        $this->plan(array());
    }

    public static function reply($origin, $path, $status, $body = '', $headers = array(), $method = 'GET')
    {
        return ScriptedCurlFixture::reply($method, $origin . $path, $status, $body, $headers);
    }

    public static function guest($origin)
    {
        return self::reply($origin, self::DETAILS, 200, self::GUEST);
    }

    public static function details($origin)
    {
        return self::reply($origin, self::DETAILS, 200, 'authenticated details');
    }

    // The Set-Cookie value of a session as the mirrors send it.
    public static function session($value, $domain = null)
    {
        return 'sid=' . $value . '; Path=/; Secure' . ($domain === null ? '' : '; Domain=' . $domain);
    }

    // A login that succeeds and sets $cookies (Set-Cookie values).
    public static function login($origin, $cookies)
    {
        $headers = array();
        foreach ((array) $cookies as $cookie) {
            $headers[] = 'Set-Cookie: ' . $cookie;
        }
        return array(
            self::reply($origin, '/login.php', 200, self::FORM),
            self::reply($origin, '/takelogin.php', 200, '<a href="/logout.php">signed in</a>', $headers, 'POST'),
        );
    }

    // A guest answer to the stored session, a fresh login and the answer.
    public static function relogin($origin, $cookies)
    {
        return array_merge(array(self::guest($origin)), self::login($origin, $cookies), array(self::details($origin)));
    }

    public function plan($replies)
    {
        $this->curl->plan($replies);
    }

    public function requests()
    {
        $requests = $this->curl->requests();
        testAssertSame(array(), $this->curl->remaining(),
            'Every planned offline reply was consumed; requests made: ' . json_encode(self::lines($requests)));
        return $requests;
    }

    public static function lines($requests)
    {
        return array_map(function ($request) {
            return $request['method'] . ' ' . $request['url'];
        }, $requests);
    }

    public function fetch($url)
    {
        $client = new Snoopy();
        return array($client->fetchComplex($url), $client);
    }

    // Each origin logs in through a real public fetch, in order: the first
    // into an empty profile, each later one after its guest answer.
    public function seed($sessions)
    {
        foreach ($sessions as $origin => $cookies) {
            $this->plan($this->documentExists() ? self::relogin($origin, $cookies)
                : array_merge(self::login($origin, $cookies), array(self::details($origin))));
            list($ok) = $this->fetch($origin . self::DETAILS);
            testAssertSame(true, $ok, 'A real public fetch saves the ' . parse_url($origin, PHP_URL_HOST) . ' session');
            $this->requests();
        }
    }

    public function documentPath()
    {
        return FileUtil::getSettingsPath() . '/accounts/KinozalTV.dat';
    }

    public function documentExists()
    {
        clearstatcache(true, $this->documentPath());
        return is_file($this->documentPath());
    }

    public function cached()
    {
        $data = new privateData('KinozalTV');
        testAssertSame(true, (new rCache('/accounts'))->get($data), 'The account session document survives');
        return $data;
    }

    // The values the document holds for $name, scoped to $origin's host.
    public function storedValues($origin, $name = 'sid')
    {
        $values = array();
        foreach ((array) $this->cached()->scopedCookies as $entry) {
            if ($entry['domain'] === parse_url($origin, PHP_URL_HOST) && $entry['name'] === $name) {
                $values[] = $entry['value'];
            }
        }
        return $values;
    }

    // Lets a stored session expire while it sits in the document.
    public function age($origin, $name, $expires)
    {
        $cache = new rCache('/accounts');
        $data = new privateData('KinozalTV');
        testAssertSame(true, $cache->get($data), 'The document to age loads');
        $aged = 0;
        foreach ($data->scopedCookies as &$entry) {
            if ($entry['domain'] === parse_url($origin, PHP_URL_HOST) && $entry['name'] === $name) {
                $entry['expires'] = $expires;
                $aged++;
            }
        }
        unset($entry);
        testAssertSame(1, $aged, 'Exactly one stored entry is aged');
        testAssertSame(true, $cache->set($data), 'The aged document is stored');
    }

    public function assertWarmFetch($origin, $cookie)
    {
        $this->plan(array(self::details($origin)));
        list($ok) = $this->fetch($origin . self::DETAILS);
        testAssertSame(true, $ok, 'A fresh client uses the stored session without logging in');
        $requests = $this->requests();
        testAssertSame(1, count($requests), 'Only the requested details URL reaches the wire');
        testAssertSame($cookie, $requests[0]['cookie'],
            'Only the session stored for ' . parse_url($origin, PHP_URL_HOST) . ' reaches the wire');
    }

    // A later guest answer on $origin renews its session; returns the wire.
    public function renewAfterGuest($origin, $value)
    {
        $this->plan(self::relogin($origin, array(self::session($value))));
        list($ok) = $this->fetch($origin . self::DETAILS);
        testAssertSame(true, $ok, 'A later guest answer renews the session');
        $requests = $this->requests();
        testAssertSame(array('GET', 'GET', 'POST', 'GET'), array_column($requests, 'method'),
            'The renewal is one login exchange between two details requests');
        testAssertSame('Cookie: sid=' . $value, $requests[3]['cookie'], 'The final details request uses the renewed session');
        return $requests;
    }

    // Runs $request with another request armed, in its own PHP process: a
    // real public fetch of the URL $action, or Settings -> Accounts -> Save
    // for 'save-accounts'. The first curl transfer to start runs it to
    // completion before that transfer is answered. By then $request has
    // loaded the session document; it stores or forgets it only afterwards.
    // $outcome is what the other request reports: 'done', or 'refused' for a
    // fetch that fails.
    public function interleave($action, $outcome, callable $request)
    {
        global $pathToExternals;
        testAssertTrue(file_put_contents($this->root . '/interleave.arm', $action) !== false, 'The other request is armed');
        $previous = $pathToExternals['curl'];
        $pathToExternals['curl'] = $this->root . '/curl-interleaved';
        try {
            $result = $request();
        } finally {
            $pathToExternals['curl'] = $previous;
        }
        clearstatcache();
        testAssertSame(false, is_file($this->root . '/interleave.arm'), 'The other request ran');
        testAssertSame($outcome, (string) @file_get_contents($this->root . '/interleave.out'),
            'The other process completed without diagnostics');
        testAssertSame("0\n", (string) @file_get_contents($this->root . '/interleave.status'),
            'The other process exited cleanly');
        return $result;
    }

    private function writeInterleavingCurl()
    {
        $writer = <<<'PHP'
<?php
// Another request on the same account, in its own process.
define('KINOZAL_REQUEST_PAUSE', 0);
define('KINOZAL_RATE_LIMIT_PAUSE', 0);
list(, $root, $action, $source) = $argv;
$_ENV['RU_PROFILE_PATH'] = $root . '/profile';
$_ENV['RU_LOG_FILE'] = $root . '/application.log';
require_once($source . '/plugins/loginmgr/accounts.php');
require_once($source . '/plugins/loginmgr/accounts/KinozalTV.php');
$httpBlockPrivateNetworks = false;
$pathToExternals['curl'] = $root . '/curl/curl';
rTorrentSettings::get()->registerPlugin('loginmgr');
if ($action === 'save-accounts') {
    // Settings -> Accounts -> Save, with no field changed: the account list
    // is stored again and every account's session is forgotten.
    $manager = accountManager::load();
    echo $manager !== false && $manager->set() ? 'done' : 'failed';
} else {
    echo (new Snoopy())->fetchComplex($action) ? 'done' : 'refused';
}
PHP;
        testAssertTrue(file_put_contents($this->root . '/interleave-writer.php', $writer) !== false,
            'The other request was written');
        $file = function ($name) {
            return escapeshellarg($this->root . '/interleave.' . $name);
        };
        // The rename claims the armed request, so it runs once, and the other
        // request's own transfers go straight to the scripted curl.
        $script = "#!/bin/sh\n"
            . 'if mv -f ' . $file('arm') . ' ' . $file('fired') . " 2>/dev/null; then\n"
            . '    ' . escapeshellarg(PHP_BINARY) . ' -c ' . escapeshellarg(realpath(__DIR__ . '/../../php-test.ini'))
            . ' ' . escapeshellarg($this->root . '/interleave-writer.php') . ' ' . escapeshellarg($this->root)
            . ' "$(cat ' . $file('fired') . ')" ' . escapeshellarg(realpath(__DIR__ . '/../../..'))
            . ' >' . $file('out') . " 2>&1\n"
            . '    echo "$?" >' . $file('status') . "\n"
            . "fi\n"
            . 'exec ' . escapeshellarg($this->root . '/curl/curl') . ' "$@"' . "\n";
        testAssertTrue(file_put_contents($this->root . '/curl-interleaved', $script) !== false,
            'The interleaving curl was written');
        testAssertTrue(chmod($this->root . '/curl-interleaved', 0700), 'The interleaving curl is executable');
    }
}

$fixture = null;
try {
    $fixture = new KinozalSessionFixture($sessionRoot);
    $fixture->curl->install();
    rTorrentSettings::get()->registerPlugin('loginmgr');
    $manager = new accountManager();
    $manager->accounts['KinozalTV'] = array('enabled' => 1, 'auto' => 0,
        'path' => realpath(__DIR__ . '/../../../plugins/loginmgr/accounts/KinozalTV.php'),
        'object' => 'KinozalTVAccount', 'login' => 'fixture-user', 'password' => 'fixture-password');
    testAssertSame(true, $manager->store(), 'Account selection fixture was saved');
    $A = KinozalSessionFixture::A;
    $B = KinozalSessionFixture::B;
    $details = KinozalSessionFixture::DETAILS;
    $dl = 'https://dl.kinozal.cloudns.nz';

    $tests = array(
        'the automatic refresh logs in without the stored session, renews A, keeps B and purges a cookie retired from A'
        => function () use ($fixture, $A, $B) {
            $fixture->seed(array(
                $A => array(KinozalSessionFixture::session('session-0'), 'old=retired; Path=/; Secure'),
                $B => array(KinozalSessionFixture::session('session-1')),
            ));
            testAssertSame(array('retired'), $fixture->storedValues($A, 'old'), 'A stored a second cookie');
            $fixture->plan(KinozalSessionFixture::login($A, array(KinozalSessionFixture::session('renewed-A'))));
            (new KinozalTVAccount())->check(new Snoopy(), 'fixture-user', 'fixture-password', 0);
            $requests = $fixture->requests();
            testAssertSame(array('GET ' . $A . '/login.php', 'POST ' . $A . '/takelogin.php'),
                KinozalSessionFixture::lines($requests), 'The refresh is one login exchange on the first door');
            testAssertSame(array('', ''), array_column($requests, 'cookie'),
                'Neither the login form GET nor the credential POST carries a stored cookie');
            testAssertSame('username=fixture-user&password=fixture-password', $requests[1]['body'],
                'The refresh posts the account credentials');
            testAssertSame(array('renewed-A'), $fixture->storedValues($A), 'The refresh renews A');
            testAssertSame(array(), $fixture->storedValues($A, 'old'),
                'A cookie the fresh login no longer sets leaves with the session it belonged to');
            testAssertSame(array('session-1'), $fixture->storedValues($B), 'B keeps its own session');
            $fixture->assertWarmFetch($A, 'Cookie: sid=renewed-A');
            $fixture->assertWarmFetch($B, 'Cookie: sid=session-1');
        },
        'a guest answer on B with an unavailable login form drops B and keeps A' => function () use ($fixture, $A, $B, $details) {
            $fixture->seed(array(
                $A => array(KinozalSessionFixture::session('session-0')),
                $B => array(KinozalSessionFixture::session('session-1')),
            ));
            $fixture->plan(array(
                KinozalSessionFixture::guest($B),
                KinozalSessionFixture::reply($B, '/login.php', 503, 'login unavailable'),
            ));
            list($ok) = $fixture->fetch($B . $details);
            testAssertSame(false, $ok, 'The failed B login is not authenticated');
            $requests = $fixture->requests();
            testAssertSame(array('GET', 'GET'), array_column($requests, 'method'),
                'An unavailable login form never receives credentials');
            testAssertSame('Cookie: sid=session-1', $requests[0]['cookie'], 'The guest answer came to the stored B session');
            testAssertSame(array(), $fixture->storedValues($B), 'The session proven dead on B is dropped');
            testAssertSame(array('session-0'), $fixture->storedValues($A), 'A keeps its session');
            $fixture->assertWarmFetch($A, 'Cookie: sid=session-0');
            $requests = $fixture->renewAfterGuest($B, 'renewed-B');
            testAssertSame('', $requests[0]['cookie'], 'The next request to B carries no cookie');
            testAssertSame(array('renewed-B'), $fixture->storedValues($B), 'B stores its renewed session');
            testAssertSame(array('session-0'), $fixture->storedValues($A), 'Renewing B leaves A alone');
        },
        'a refused credential POST on B keeps A, drops B and leaves its status on the client'
        => function () use ($fixture, $A, $B, $details) {
            foreach (array(403, 500) as $status) {
                $fixture->reset();
                $fixture->seed(array(
                    $A => array(KinozalSessionFixture::session('session-0')),
                    $B => array(KinozalSessionFixture::session('session-1')),
                ));
                $fixture->plan(array(
                    KinozalSessionFixture::guest($B),
                    KinozalSessionFixture::reply($B, '/login.php', 200, KinozalSessionFixture::FORM),
                    KinozalSessionFixture::reply($B, '/takelogin.php', $status, 'login refused', array(), 'POST'),
                ));
                list($ok, $client) = $fixture->fetch($B . $details);
                testAssertSame(false, $ok, 'HTTP ' . $status . ' credential POST is refused');
                testAssertSame($status, (int) $client->status, 'The actual credential POST status remains visible');
                testAssertSame(array('GET', 'GET', 'POST'), array_column($fixture->requests(), 'method'),
                    'A refused credential POST is the final request');
                testAssertSame(array(), $fixture->storedValues($B), 'HTTP ' . $status . ': the dead B session is dropped');
                testAssertSame(array('session-0'), $fixture->storedValues($A), 'HTTP ' . $status . ': A keeps its session');
                $fixture->assertWarmFetch($A, 'Cookie: sid=session-0');
            }
        },
        'a single mirror\'s failed login removes the whole document' => function () use ($fixture, $B, $details) {
            $fixture->seed(array($B => array(KinozalSessionFixture::session('session-0'))));
            $fixture->plan(array(
                KinozalSessionFixture::guest($B),
                KinozalSessionFixture::reply($B, '/login.php', 503, 'login unavailable'),
            ));
            list($ok) = $fixture->fetch($B . $details);
            testAssertSame(false, $ok, 'The single mirror login fails');
            testAssertSame('Cookie: sid=session-0', $fixture->requests()[0]['cookie'], 'The dead session was sent');
            $data = new privateData('KinozalTV');
            testAssertSame(false, (new rCache('/accounts'))->get($data), 'Nothing is left, so the document is removed');
        },
        'a Domain cookie covering the requested subdomain is dropped by that failure'
        => function () use ($fixture, $A, $B, $details, $dl) {
            $failure = array(
                KinozalSessionFixture::reply($dl, $details, 200, KinozalSessionFixture::GUEST),
                KinozalSessionFixture::reply($B, '/login.php', 503, 'login unavailable'),
            );
            $fixture->seed(array($B => array(KinozalSessionFixture::session('session-0', 'kinozal.cloudns.nz'))));
            $fixture->plan($failure);
            list($ok) = $fixture->fetch($dl . $details);
            testAssertSame(false, $ok, 'The failed subdomain login is refused');
            testAssertSame('Cookie: sid=session-0', $fixture->requests()[0]['cookie'],
                'The Domain session really covered the requested subdomain');
            testAssertSame(false, $fixture->documentExists(), 'The Domain session was all the document held');

            // Next to a host-only session of another mirror, only the Domain one goes.
            $fixture->reset();
            $fixture->seed(array(
                $A => array(KinozalSessionFixture::session('session-0')),
                $B => array(KinozalSessionFixture::session('session-1', 'kinozal.cloudns.nz')),
            ));
            $fixture->plan($failure);
            list($ok) = $fixture->fetch($dl . $details);
            testAssertSame(false, $ok, 'The failed subdomain login is refused again');
            testAssertSame('Cookie: sid=session-1', $fixture->requests()[0]['cookie'],
                'Only the Domain session went to the subdomain');
            testAssertSame(array(), $fixture->storedValues($B), 'The Domain session proven dead is dropped');
            testAssertSame(array('session-0'), $fixture->storedValues($A), 'The other mirror keeps its session');
        },
        'an expired cookie of another host no longer keeps a session proven stale on A'
        => function () use ($fixture, $A, $B, $details) {
            $fixture->seed(array(
                $A => array(KinozalSessionFixture::session('session-0')),
                $B => array(KinozalSessionFixture::session('session-1') . '; Max-Age=3600'),
            ));
            $fixture->age($B, 'sid', time() - 60);
            $fixture->plan(array(
                KinozalSessionFixture::guest($A),
                KinozalSessionFixture::reply($A, '/login.php', 503, 'login unavailable'),
            ));
            list($ok) = $fixture->fetch($A . $details);
            testAssertSame(false, $ok, 'The failed A login is not authenticated');
            testAssertSame('Cookie: sid=session-0', $fixture->requests()[0]['cookie'],
                'The guest answer came to the stored A session');
            $requests = $fixture->renewAfterGuest($A, 'renewed-A');
            testAssertSame('', $requests[0]['cookie'], 'The session proven dead on A is not sent again');
        },
        'in one process: B stored by another request while this one renews A survives the renewal'
        => function () use ($fixture, $A, $B, $details) {
            $fixture->seed(array($A => array(KinozalSessionFixture::session('session-0'))));
            $client = new Snoopy();
            $data = $client->withAccountCookieScope('KinozalTV', function () use ($client) {
                return privateData::load('KinozalTV', $client);
            });
            testAssertSame(true, $data->loaded, 'This request loaded the document holding A');
            $fixture->plan(KinozalSessionFixture::relogin($B, array(KinozalSessionFixture::session('session-1'))));
            list($ok) = $fixture->fetch($B . $details);
            testAssertSame(true, $ok, 'Another request logs in on B meanwhile');
            $fixture->requests();
            testAssertSame(array('session-1'), $fixture->storedValues($B), 'The other request stored B');
            // This request's own login on A answers with a renewed session.
            $fixture->plan(array(KinozalSessionFixture::reply($A, '/takelogin.php', 200, 'signed in',
                array('Set-Cookie: ' . KinozalSessionFixture::session('renewed-A')), 'POST')));
            $stored = $client->withAccountCookieScope('KinozalTV', function () use ($client, $data, $A) {
                $client->fetch($A . '/takelogin.php', 'POST', 'application/x-www-form-urlencoded',
                    'username=fixture-user&password=fixture-password');
                return $data->store($client);
            });
            testAssertSame('Cookie: sid=session-0', $fixture->requests()[0]['cookie'],
                'This request still held the A session it loaded');
            testAssertSame(true, $stored, 'This request stores its renewed session');
            testAssertSame(array('renewed-A'), $fixture->storedValues($A), 'A is renewed');
            testAssertSame(array('session-1'), $fixture->storedValues($B), 'B, stored after this request loaded, survives');
        },
        'in one process: B stored by another request while this one forgets A survives the failure'
        => function () use ($fixture, $A, $B, $details) {
            $fixture->seed(array($A => array(KinozalSessionFixture::session('session-0'))));
            $client = new Snoopy();
            $data = $client->withAccountCookieScope('KinozalTV', function () use ($client) {
                return privateData::load('KinozalTV', $client);
            });
            $fixture->plan(KinozalSessionFixture::relogin($B, array(KinozalSessionFixture::session('session-1'))));
            list($ok) = $fixture->fetch($B . $details);
            testAssertSame(true, $ok, 'Another request logs in on B meanwhile');
            $fixture->requests();
            testAssertSame(true, $data->forget($A . $details), 'This request forgets the A session its login failed to renew');
            testAssertSame(array(), $fixture->storedValues($A), 'The session this request proved dead is dropped');
            testAssertSame(array('session-1'), $fixture->storedValues($B), 'B, stored after this request loaded, survives');
        },
        'a session another process stores for B while a login on A renews A survives that login'
        => function () use ($fixture, $A, $B, $details) {
            $fixture->seed(array($A => array(KinozalSessionFixture::session('session-0'))));
            $fixture->plan(array_merge(
                KinozalSessionFixture::relogin($B, array(KinozalSessionFixture::session('session-1'))),
                KinozalSessionFixture::relogin($A, array(KinozalSessionFixture::session('renewed-A')))));
            list($ok) = $fixture->interleave($B . $details, 'done', function () use ($fixture, $A, $details) {
                return $fixture->fetch($A . $details);
            });
            testAssertSame(true, $ok, 'This request renews A');
            $requests = $fixture->requests();
            testAssertSame(array(
                'GET ' . $B . $details, 'GET ' . $B . '/login.php', 'POST ' . $B . '/takelogin.php', 'GET ' . $B . $details,
                'GET ' . $A . $details, 'GET ' . $A . '/login.php', 'POST ' . $A . '/takelogin.php', 'GET ' . $A . $details,
            ), KinozalSessionFixture::lines($requests), 'The other process ran before this request read its first answer');
            testAssertSame('Cookie: sid=session-0', $requests[4]['cookie'], 'This request sent the A session it had loaded');
            testAssertSame(array('renewed-A'), $fixture->storedValues($A), 'A is renewed');
            testAssertSame(array('session-1'), $fixture->storedValues($B), 'B, stored by the other process, survives');
            $fixture->assertWarmFetch($B, 'Cookie: sid=session-1');
        },
        'a session another process stores for B while a login on A fails survives that failure'
        => function () use ($fixture, $A, $B, $details) {
            $fixture->seed(array($A => array(KinozalSessionFixture::session('session-0'))));
            $fixture->plan(array_merge(
                KinozalSessionFixture::relogin($B, array(KinozalSessionFixture::session('session-1'))),
                array(
                    KinozalSessionFixture::guest($A),
                    KinozalSessionFixture::reply($A, '/login.php', 503, 'login unavailable'),
                )));
            list($ok) = $fixture->interleave($B . $details, 'done', function () use ($fixture, $A, $details) {
                return $fixture->fetch($A . $details);
            });
            testAssertSame(false, $ok, 'The A login fails');
            testAssertSame(array(
                'GET ' . $B . $details, 'GET ' . $B . '/login.php', 'POST ' . $B . '/takelogin.php', 'GET ' . $B . $details,
                'GET ' . $A . $details, 'GET ' . $A . '/login.php',
            ), KinozalSessionFixture::lines($fixture->requests()), 'The other process ran before this request read its first answer');
            testAssertSame(array(), $fixture->storedValues($A), 'The session this request proved dead is dropped');
            testAssertSame(array('session-1'), $fixture->storedValues($B), 'B, stored by the other process, survives');
            $fixture->assertWarmFetch($B, 'Cookie: sid=session-1');
        },
        'a session another process forgets, removing the document, does not come back with a login on A'
        => function () use ($fixture, $A, $B, $details) {
            $fixture->seed(array($B => array(KinozalSessionFixture::session('session-1'))));
            $fixture->plan(array_merge(
                array(
                    KinozalSessionFixture::guest($B),
                    KinozalSessionFixture::reply($B, '/login.php', 503, 'login unavailable'),
                ),
                KinozalSessionFixture::relogin($A, array(KinozalSessionFixture::session('renewed-A')))));
            list($ok) = $fixture->interleave($B . $details, 'refused', function () use ($fixture, $A, $details) {
                return $fixture->fetch($A . $details);
            });
            testAssertSame(true, $ok, 'This request logs in on A');
            $requests = $fixture->requests();
            testAssertSame(array(
                'GET ' . $B . $details, 'GET ' . $B . '/login.php',
                'GET ' . $A . $details, 'GET ' . $A . '/login.php', 'POST ' . $A . '/takelogin.php', 'GET ' . $A . $details,
            ), KinozalSessionFixture::lines($requests), 'The other process ran before this request read its first answer');
            testAssertSame('Cookie: sid=session-1', $requests[0]['cookie'], 'The other process found B\'s session dead');
            testAssertSame(array('renewed-A'), $fixture->storedValues($A), 'A is stored');
            testAssertSame(array(), $fixture->storedValues($B),
                'The B session this request only read stays forgotten');
            $requests = $fixture->renewAfterGuest($B, 'renewed-B');
            testAssertSame('', $requests[0]['cookie'], 'The next request to B carries no cookie');
        },
        'a session Settings -> Save forgets while a request holds it is not stored back by that request'
        => function () use ($fixture, $A, $details) {
            $fixture->seed(array($A => array(KinozalSessionFixture::session('session-0'))));
            // The live answer sets one more cookie, so this request stores.
            $fixture->plan(array(KinozalSessionFixture::reply($A, $details, 200, 'authenticated details',
                array('Set-Cookie: lang=ru; Path=/; Secure'))));
            list($ok) = $fixture->interleave('save-accounts', 'done', function () use ($fixture, $A, $details) {
                return $fixture->fetch($A . $details);
            });
            testAssertSame(true, $ok, 'The live answer reaches the caller');
            testAssertSame('Cookie: sid=session-0', $fixture->requests()[0]['cookie'],
                'This request had loaded the session before it was forgotten');
            testAssertSame(array(), $fixture->storedValues($A), 'The forgotten session is not stored back');
            testAssertSame(array('ru'), $fixture->storedValues($A, 'lang'), 'Only what this request changed is stored');
        },
    );
    $status = testRunCases($tests, function () use ($fixture) { $fixture->reset(); });
} finally {
    if ($fixture !== null) {
        $fixture->curl->restore();
    }
    FileUtil::deleteDirectory($sessionRoot);
}
exit($status);
