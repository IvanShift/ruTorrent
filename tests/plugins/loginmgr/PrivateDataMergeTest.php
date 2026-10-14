<?php

// privateData's three-way merge, forget() and refresh store, through the real
// rCache: two objects loaded from one document are two requests of the same
// account, and the later store finds the earlier one's write. The client is a
// Snoopy whose storage answers are what the test says a login left behind.
require_once(__DIR__ . '/../../php/TestCase.php');
$mergeRoot = sys_get_temp_dir() . '/loginmgr-merge-' . getmypid() . '-' . bin2hex(random_bytes(4));
mkdir($mergeRoot, 0700);
$_ENV['RU_PROFILE_PATH'] = $mergeRoot . '/profile';
$_ENV['RU_LOG_FILE'] = $mergeRoot . '/application.log';
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts.php');

const PDM_OWNER = 'MergeOwner';
const PDM_A = 'kinozal.jumpingcrab.com';
const PDM_B = 'kinozal.cloudns.nz';
const PDM_A_URL = 'https://kinozal.jumpingcrab.com/details.php?id=1';

class PDMClient extends Snoopy
{
    private $flat;
    private $scoped;

    public function __construct($scoped = array(), $flat = array(), $referer = '')
    {
        parent::__construct();
        $this->scoped = $scoped;
        $this->flat = $flat;
        $this->referer = $referer;
    }

    public function cookiesForAccountStorage()
    {
        return $this->flat;
    }

    public function responseCookiesForAccountStorage()
    {
        return $this->scoped;
    }
}

// In Snoopy::responseCookiesForAccountStorage() key order.
function pdmEntry($name, $value, $domain, $hostOnly = true, $path = '/')
{
    return array('name' => $name, 'value' => $value, 'domain' => $domain, 'hostOnly' => $hostOnly,
        'path' => $path, 'secure' => true, 'expires' => null);
}

function pdmPath()
{
    return FileUtil::getSettingsPath() . '/accounts/' . PDM_OWNER . '.dat';
}

// The document as an earlier request left it. Written without rCache, so the
// seed does not depend on the code under test and takes no key lock.
function pdmWrite($scoped, $flat = array(), $referer = null)
{
    new rCache('/accounts');
    $document = new privateData(PDM_OWNER);
    $document->scopedCookies = $scoped;
    $document->cookies = $flat;
    $document->referer = $referer;
    testAssertTrue(file_put_contents(pdmPath(), serialize($document)) !== false, 'The document is seeded');
}

function pdmLoad()
{
    $data = privateData::load(PDM_OWNER, new PDMClient());
    testAssertSame(true, $data->loaded, 'A request loads the document');
    return $data;
}

// A request stores what its login left on the client.
function pdmStore($data, $scoped, $flat = array(), $referer = '', $replacedUrl = null)
{
    testAssertSame(true, $data->store(new PDMClient($scoped, $flat, $referer), $replacedUrl), 'The store succeeds');
}

function pdmStored()
{
    $data = new privateData(PDM_OWNER);
    return (new rCache('/accounts'))->get($data) ? $data : null;
}

function pdmCookies($entries)
{
    $lines = array();
    foreach ((array) $entries as $entry) {
        $lines[] = $entry['name'] . '=' . $entry['value'] . ($entry['hostOnly'] ? ' host ' : ' domain ')
            . $entry['domain'] . $entry['path'];
    }
    sort($lines);
    return $lines;
}

function pdmStoredCookies()
{
    $stored = pdmStored();
    testAssertTrue($stored !== null, 'The document is stored');
    return pdmCookies($stored->scopedCookies);
}

function pdmFlat()
{
    $stored = pdmStored();
    testAssertTrue($stored !== null, 'The document is stored');
    $cookies = (array) $stored->cookies;
    ksort($cookies);
    return $cookies;
}

$tests = array(
    'a value this request renewed wins over the value another request stored for it' => function () {
        pdmWrite(array(pdmEntry('sid', 'base', PDM_A)));
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array(pdmEntry('sid', 'theirs', PDM_A)));
        pdmStore($ours, array(pdmEntry('sid', 'ours', PDM_A)));
        testAssertSame(array('sid=ours host kinozal.jumpingcrab.com/'), pdmStoredCookies(),
            'This request\'s own change wins the conflict');
    },
    'a value this request left alone takes the value another request stored' => function () {
        pdmWrite(array(pdmEntry('sid', 'base', PDM_A), pdmEntry('sid', 'base', PDM_B)));
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array(pdmEntry('sid', 'base', PDM_A), pdmEntry('sid', 'theirs', PDM_B)));
        pdmStore($ours, array(pdmEntry('sid', 'ours', PDM_A), pdmEntry('sid', 'base', PDM_B)));
        testAssertSame(array('sid=ours host kinozal.jumpingcrab.com/', 'sid=theirs host kinozal.cloudns.nz/'),
            pdmStoredCookies(), 'Each request keeps what it changed, and an unchanged value is not written back');
    },
    'a value this request dropped stays dropped unless another request renewed it' => function () {
        pdmWrite(array(pdmEntry('sid', 'base', PDM_A), pdmEntry('sid', 'base', PDM_B)));
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array(pdmEntry('sid', 'base', PDM_A), pdmEntry('sid', 'theirs', PDM_B)));
        pdmStore($ours, array(pdmEntry('sid', 'base', PDM_B)));
        testAssertSame(array('sid=theirs host kinozal.cloudns.nz/'), pdmStoredCookies(),
            'A value this request dropped is not brought back by another request that left it alone');

        pdmWrite(array(pdmEntry('sid', 'base', PDM_A), pdmEntry('sid', 'base', PDM_B)));
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array(pdmEntry('sid', 'renewed', PDM_A), pdmEntry('sid', 'base', PDM_B)));
        pdmStore($ours, array(pdmEntry('sid', 'base', PDM_B)));
        testAssertSame(array('sid=base host kinozal.cloudns.nz/', 'sid=renewed host kinozal.jumpingcrab.com/'),
            pdmStoredCookies(), 'A value another request renewed is not dropped with the old one');
    },
    'entries another request added are kept beside this request\'s own additions' => function () {
        pdmWrite(array(pdmEntry('sid', 'base', PDM_A)));
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array(pdmEntry('sid', 'base', PDM_A), pdmEntry('sid', 'theirs', PDM_B)));
        pdmStore($ours, array(pdmEntry('sid', 'base', PDM_A), pdmEntry('lang', 'ours', PDM_A)));
        testAssertSame(array('lang=ours host kinozal.jumpingcrab.com/', 'sid=base host kinozal.jumpingcrab.com/',
            'sid=theirs host kinozal.cloudns.nz/'), pdmStoredCookies(), 'Both additions are kept');
    },
    'one domain in two spellings is one entry, and another path is another entry' => function () {
        pdmWrite(array(pdmEntry('sid', 'base', PDM_A)));
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array(pdmEntry('sid', 'theirs', 'KINOZAL.JumpingCrab.com.'),
            pdmEntry('sid', 'forum', PDM_A, true, '/forum')));
        pdmStore($ours, array(pdmEntry('sid', 'ours', PDM_A)));
        testAssertSame(array('sid=forum host kinozal.jumpingcrab.com/forum', 'sid=ours host kinozal.jumpingcrab.com/'),
            pdmStoredCookies(), 'The entry is named by name, normalized domain and path');
    },
    'a host-only and a domain cookie of one name, domain and path are two entries' => function () {
        // Snoopy's jar keys on the host-only flag too, so a merge must not fold them.
        $pair = array(pdmEntry('uid', 'H', PDM_A, true), pdmEntry('uid', 'D', PDM_A, false));
        pdmWrite($pair);
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array_merge($pair, array(pdmEntry('sid', 'other', PDM_B))));
        pdmStore($ours, $pair);
        testAssertSame(array('sid=other host kinozal.cloudns.nz/', 'uid=D domain kinozal.jumpingcrab.com/',
            'uid=H host kinozal.jumpingcrab.com/'), pdmStoredCookies(), 'Both cookies survive the merge');
    },
    'flat cookies merge by name under the same rules' => function () {
        pdmWrite(array(), array('kept' => 'base', 'mine' => 'base', 'clash' => 'base', 'gone' => 'base',
            'renewed' => 'base'));
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array(), array('kept' => 'theirs', 'mine' => 'base', 'clash' => 'theirs', 'gone' => 'base',
            'renewed' => 'theirs', 'added' => 'theirs'));
        pdmStore($ours, array(), array('kept' => 'base', 'mine' => 'ours', 'clash' => 'ours'));
        testAssertSame(array('added' => 'theirs', 'clash' => 'ours', 'kept' => 'theirs', 'mine' => 'ours',
            'renewed' => 'theirs'), pdmFlat(), 'Flat cookies follow the three-way rule by name');
    },
    'the referer follows this request only when this request changed it' => function () {
        pdmWrite(array(pdmEntry('sid', 'base', PDM_A)), array(), 'https://base.example');
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array(pdmEntry('sid', 'base', PDM_A)), array(), 'https://theirs.example');
        pdmStore($ours, array(pdmEntry('sid', 'ours', PDM_A)), array(), 'https://base.example');
        testAssertSame('https://theirs.example', pdmStored()->referer, 'An unchanged referer takes the newer one');

        pdmWrite(array(pdmEntry('sid', 'base', PDM_A)), array(), 'https://base.example');
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array(pdmEntry('sid', 'base', PDM_A)), array(), 'https://theirs.example');
        pdmStore($ours, array(pdmEntry('sid', 'ours', PDM_A)), array(), 'https://ours.example');
        testAssertSame('https://ours.example', pdmStored()->referer, 'A referer this request changed wins');
    },
    'a refresh replaces whatever is sent to its host and keeps what other hosts hold' => function () {
        pdmWrite(array(
            pdmEntry('sid', 'stale', PDM_A),
            pdmEntry('old', 'retired', PDM_A),
            pdmEntry('wide', 'stale', PDM_A, false),
            pdmEntry('zone', 'stale', 'jumpingcrab.com', false),
            pdmEntry('sid', 'parent', 'jumpingcrab.com'),
            pdmEntry('sid', 'download', 'dl.' . PDM_A),
            pdmEntry('wide', 'download', 'dl.' . PDM_A, false),
            pdmEntry('sid', 'other', PDM_B),
        ), array('uid' => 'stale', 'pass' => 'stale'), 'https://' . PDM_B);
        // The automatic refresh reads nothing: its login sends no stored cookie.
        $refresh = privateData::load(PDM_OWNER);
        pdmStore($refresh, array(pdmEntry('sid', 'fresh', PDM_A)), array('uid' => 'fresh'), 'https://' . PDM_A,
            'https://' . PDM_A);
        testAssertSame(array(
            'sid=download host dl.kinozal.jumpingcrab.com/',
            'sid=fresh host kinozal.jumpingcrab.com/',
            'sid=other host kinozal.cloudns.nz/',
            'sid=parent host jumpingcrab.com/',
            'wide=download domain dl.kinozal.jumpingcrab.com/',
        ), pdmStoredCookies(), 'Host-only and Domain entries sent to the refreshed host give way to the fresh login');
        testAssertSame(array('uid' => 'fresh'), pdmFlat(), 'The flat cookies are the fresh login\'s alone');
        testAssertSame('https://' . PDM_A, pdmStored()->referer, 'The refresh sets the referer');
    },
    'a refresh into an absent document stores the fresh login as it is' => function () {
        $refresh = privateData::load(PDM_OWNER);
        pdmStore($refresh, array(pdmEntry('sid', 'fresh', PDM_A)), array('uid' => 'fresh'), 'https://' . PDM_A,
            'https://' . PDM_A);
        testAssertSame(array('sid=fresh host kinozal.jumpingcrab.com/'), pdmStoredCookies(), 'The fresh session is stored');
        testAssertSame(array('uid' => 'fresh'), pdmFlat(), 'The fresh flat cookies are stored');
    },
    'forget drops what was sent to the host, with the flat cookies, and keeps what other hosts hold' => function () {
        pdmWrite(array(
            pdmEntry('sid', 'dead', PDM_A),
            pdmEntry('zone', 'dead', 'jumpingcrab.com', false),
            pdmEntry('sid', 'parent', 'jumpingcrab.com'),
            pdmEntry('wide', 'download', 'dl.' . PDM_A, false),
            pdmEntry('sid', 'other', PDM_B),
        ), array('uid' => 'sent-with-it'), 'https://' . PDM_B);
        testAssertSame(true, pdmLoad()->forget(PDM_A_URL), 'forget succeeds');
        testAssertSame(array(
            'sid=other host kinozal.cloudns.nz/',
            'sid=parent host jumpingcrab.com/',
            'wide=download domain dl.kinozal.jumpingcrab.com/',
        ), pdmStoredCookies(), 'Only the entries sent to the failed host are dropped');
        testAssertSame(array(), pdmFlat(), 'The flat cookies sent with the dead session are dropped');
        testAssertSame('https://' . PDM_B, pdmStored()->referer, 'The referer stays while sessions remain');
    },
    'forget with nothing to drop writes nothing at all' => function () {
        pdmWrite(array(pdmEntry('sid', 'other', PDM_B), pdmEntry('sid', 'parent', 'jumpingcrab.com')));
        clearstatcache();
        $before = stat(pdmPath());
        $bytes = file_get_contents(pdmPath());
        testAssertSame(true, pdmLoad()->forget(PDM_A_URL), 'forget succeeds');
        clearstatcache();
        $after = stat(pdmPath());
        testAssertSame(array($before['ino'], $before['mtime'], $before['size']),
            array($after['ino'], $after['mtime'], $after['size']), 'The document keeps its inode and timestamp');
        testAssertSame($bytes, file_get_contents(pdmPath()), 'The document keeps its bytes');
        testAssertSame(false, file_exists(pdmPath() . '.lock'), 'No store was attempted under the key lock');
    },
    'forget drops the flat cookies even when no scoped entry was sent to the host' => function () {
        pdmWrite(array(pdmEntry('sid', 'other', PDM_B)), array('uid' => 'flat'));
        testAssertSame(true, pdmLoad()->forget(PDM_A_URL), 'forget succeeds');
        testAssertSame(array('sid=other host kinozal.cloudns.nz/'), pdmStoredCookies(), 'The other host keeps its session');
        testAssertSame(array(), pdmFlat(), 'The flat cookies, sent to every host, are dropped');
    },
    'forget that leaves nothing removes the document' => function () {
        pdmWrite(array(pdmEntry('sid', 'dead', PDM_A)), array('uid' => 'flat'), 'https://' . PDM_A);
        testAssertSame(true, pdmLoad()->forget(PDM_A_URL), 'forget succeeds');
        clearstatcache();
        testAssertSame(false, file_exists(pdmPath()), 'The document is removed, not stored empty');
        testAssertSame(null, pdmStored(), 'rCache finds no document');
        testAssertSame(true, file_exists(pdmPath() . '.lock'), 'The removal ran under the key lock');
    },
    'forget keeps what another request stored meanwhile, a renewal of the forgotten session included' => function () {
        pdmWrite(array(pdmEntry('sid', 'dead', PDM_A)));
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array(pdmEntry('sid', 'dead', PDM_A), pdmEntry('sid', 'other', PDM_B)));
        testAssertSame(true, $ours->forget(PDM_A_URL), 'forget succeeds');
        testAssertSame(array('sid=other host kinozal.cloudns.nz/'), pdmStoredCookies(),
            'A session stored after this request read the document is kept');

        pdmWrite(array(pdmEntry('sid', 'dead', PDM_A)));
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array(pdmEntry('sid', 'renewed', PDM_A)));
        testAssertSame(true, $ours->forget(PDM_A_URL), 'forget succeeds');
        testAssertSame(array('sid=renewed host kinozal.jumpingcrab.com/'), pdmStoredCookies(),
            'A renewal stored after this request read the document was never shown stale');
    },
    'forget keeps a referer another request stored meanwhile' => function () {
        pdmWrite(array(pdmEntry('sid', 'dead', PDM_A)), array(), 'https://old.example');
        $ours = pdmLoad();
        $theirs = pdmLoad();
        pdmStore($theirs, array(pdmEntry('sid', 'other', PDM_B)), array(), 'https://theirs.example');
        testAssertSame(true, $ours->forget(PDM_A_URL), 'forget succeeds');
        testAssertSame(array('sid=other host kinozal.cloudns.nz/'), pdmStoredCookies(), 'The other session is kept');
        testAssertSame('https://theirs.example', pdmStored()->referer, 'and so is the referer stored with it');
    },
    'a session another request forgot is not stored back by a request that only read it' => function () {
        pdmWrite(array(pdmEntry('sid', 'dead', PDM_B)));
        $ours = pdmLoad();
        $theirs = pdmLoad();
        testAssertSame(true, $theirs->forget('https://' . PDM_B . '/details.php?id=1'), 'The other request forgets B');
        clearstatcache();
        testAssertSame(false, file_exists(pdmPath()), 'Its forget left nothing, so the document was removed');
        pdmStore($ours, array(pdmEntry('sid', 'dead', PDM_B), pdmEntry('sid', 'fresh', PDM_A)));
        testAssertSame(array('sid=fresh host kinozal.jumpingcrab.com/'), pdmStoredCookies(),
            'Only what this request changed is stored; the forgotten session stays forgotten');

        pdmWrite(array(pdmEntry('sid', 'dead', PDM_B)));
        $ours = pdmLoad();
        $theirs = pdmLoad();
        testAssertSame(true, $theirs->forget('https://' . PDM_B . '/details.php?id=1'), 'The other request forgets B again');
        pdmStore($ours, array(pdmEntry('sid', 'dead', PDM_B)));
        clearstatcache();
        testAssertSame(false, file_exists(pdmPath()), 'A store that changed nothing does not recreate the document');
    },
    'forget without a loaded document changes nothing' => function () {
        $missed = privateData::load(PDM_OWNER, new PDMClient());
        testAssertSame(false, $missed->loaded, 'There is no document to load');
        testAssertSame(true, (new privateData(PDM_OWNER))->forget(PDM_A_URL), 'A new object has nothing to forget');
        testAssertSame(false, file_exists(pdmPath()), 'No document is created');
        pdmWrite(array(pdmEntry('sid', 'live', PDM_A)));
        testAssertSame(true, $missed->forget(PDM_A_URL), 'A request that read nothing has nothing to forget');
        testAssertSame(true, privateData::load(PDM_OWNER)->forget(PDM_A_URL),
            'A request that loaded without a client read nothing either');
        testAssertSame(array('sid=live host kinozal.jumpingcrab.com/'), pdmStoredCookies(),
            'A session no request read is never shown stale');
    },
    // fetch() forgets after any failure, its own store's included: what the
    // object holds then is the fresh login, which no request ever read.
    'forget after a failed store writes nothing for a request that read nothing' => function () {
        $data = privateData::load(PDM_OWNER, new PDMClient());
        testAssertSame(false, $data->loaded, 'There is no document to load');
        new rCache('/accounts');
        $lock = fopen(pdmPath() . '.lock', 'c');
        testAssertTrue(flock($lock, LOCK_EX), 'Another writer holds the key lock');
        try {
            testAssertSame(false, $data->store(new PDMClient(array(pdmEntry('sid', 'fresh', PDM_A),
                pdmEntry('sid', 'fresh', PDM_B)))), 'The fresh login cannot be stored while the lock is held');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        testAssertSame(true, $data->forget(PDM_A_URL), 'forget succeeds');
        clearstatcache();
        testAssertSame(false, file_exists(pdmPath()), 'Nothing is written for a session this request never read');
    },
    'forget removes a legacy document unless another request replaced it' => function () {
        // A document saved before scoped cookies existed: flat cookies only.
        $legacy = new privateData(PDM_OWNER);
        $legacy->cookies = array('uid' => 'legacy');
        $legacy->referer = 'https://' . PDM_A;
        unset($legacy->scopedCookies);
        new rCache('/accounts');
        testAssertTrue(file_put_contents(pdmPath(), serialize($legacy)) !== false, 'The legacy document is seeded');
        $data = privateData::load(PDM_OWNER, new PDMClient());
        testAssertSame(false, $data->loaded, 'A legacy document is refused, not loaded');
        testAssertSame(true, $data->forget(PDM_A_URL), 'forget succeeds');
        clearstatcache();
        testAssertSame(false, file_exists(pdmPath()), 'A failed login removes the legacy document');

        testAssertTrue(file_put_contents(pdmPath(), serialize($legacy)) !== false, 'The legacy document is seeded again');
        $data = privateData::load(PDM_OWNER, new PDMClient());
        $theirs = privateData::load(PDM_OWNER, new PDMClient());
        pdmStore($theirs, array(pdmEntry('sid', 'other', PDM_B)), array('uid' => 'legacy', 'lang' => 'ru'));
        testAssertSame(true, $data->forget(PDM_A_URL), 'forget succeeds');
        testAssertSame(array('sid=other host kinozal.cloudns.nz/'), pdmStoredCookies(),
            'The document another request stored in place of the legacy one is kept');
        testAssertSame(array('lang' => 'ru'), pdmFlat(),
            'Of its flat cookies, the legacy one carried over unchanged is dropped and the new one is kept');
    },
    'forget removes a legacy document that holds no flat cookie' => function () {
        new rCache('/accounts');
        foreach (array('empty' => array(), 'missing' => null) as $kind => $cookies) {
            $legacy = new privateData(PDM_OWNER);
            $legacy->cookies = $cookies;
            unset($legacy->scopedCookies);
            testAssertTrue(file_put_contents(pdmPath(), serialize($legacy)) !== false, 'The legacy document is seeded');
            $data = privateData::load(PDM_OWNER, new PDMClient());
            testAssertSame(false, $data->loaded, 'A legacy document is refused, not loaded');
            testAssertSame(true, $data->forget(PDM_A_URL), 'forget succeeds');
            clearstatcache();
            testAssertSame(false, file_exists(pdmPath()),
                'A failed login removes a legacy document whose flat cookies are ' . $kind);
        }

        $legacy = new privateData(PDM_OWNER);
        $legacy->cookies = array();
        unset($legacy->scopedCookies);
        testAssertTrue(file_put_contents(pdmPath(), serialize($legacy)) !== false, 'The legacy document is seeded again');
        $data = privateData::load(PDM_OWNER, new PDMClient());
        $theirs = privateData::load(PDM_OWNER, new PDMClient());
        pdmStore($theirs, array(pdmEntry('sid', 'other', PDM_B)));
        testAssertSame(true, $data->forget(PDM_A_URL), 'forget succeeds');
        testAssertSame(array('sid=other host kinozal.cloudns.nz/'), pdmStoredCookies(),
            'The document another request stored in place of the legacy one is kept');
    },
    'isEmpty asks for no cookie of either kind and no referer' => function () {
        $data = new privateData(PDM_OWNER);
        testAssertSame(true, $data->isEmpty(), 'A new document is empty');
        $data->referer = '';
        testAssertSame(true, $data->isEmpty(), 'An empty referer is no content');
        $data->referer = 'https://' . PDM_A;
        testAssertSame(false, $data->isEmpty(), 'A referer is content');
        $data->referer = null;
        $data->cookies = array('uid' => 'flat');
        testAssertSame(false, $data->isEmpty(), 'A flat cookie is content');
        $data->cookies = array();
        $data->scopedCookies = array(pdmEntry('sid', 'scoped', PDM_A));
        testAssertSame(false, $data->isEmpty(), 'A scoped cookie is content');
    },
    'the stored document keeps its six public properties' => function () {
        pdmWrite(array(pdmEntry('sid', 'stored', PDM_A)));
        $data = pdmLoad();
        pdmStore($data, array(pdmEntry('sid', 'renewed', PDM_A)), array(), '', 'https://' . PDM_A);
        foreach (array('in memory' => serialize($data), 'on disk' => file_get_contents(pdmPath())) as $where => $bytes) {
            testAssertSame(1, preg_match('/^O:11:"privateData":6:\{/', $bytes), 'Six properties are serialized ' . $where);
            testAssertSame(false, strpos($bytes, "\0privateData\0") !== false,
                'No private bookkeeping is serialized ' . $where);
        }
        testAssertSame(array('sid=renewed host kinozal.jumpingcrab.com/'), pdmStoredCookies(),
            'The stored document reads back');
    },
);

try {
    $status = testRunCases($tests, function () {
        foreach (glob(FileUtil::getSettingsPath() . '/accounts/*') ?: array() as $file) {
            unlink($file);
        }
    });
} finally {
    FileUtil::deleteDirectory($mergeRoot);
}
exit($status);
