<?php

/**
 * What rutracker_check does with a value whose bytes include '"' and '\'.
 *
 * Every case here runs the shipped checker in a child process against the
 * real php/xmlrpc.php, php/rtorrent.php and php/Torrent.php, talking SCGI to a
 * MiniRtorrent that parses load commands and branch bodies the way rtorrent
 * does (RealStackDriver.php, MiniRtorrent.php). The queued double the other
 * suites use hands the checker whatever string a test wrote; it cannot show
 * what the transport made of the daemon's answer, and that transformation is
 * the subject here (AUD-ESC-02, -13, -14, -15, -17).
 *
 * The name that carries the hazard is the round-4 probe value
 * aud "q" \b 'a' &c: a double quote, a backslash, an apostrophe and an
 * ampersand -- each byte a different layer has escaped at some point.
 */

require_once(__DIR__ . '/TestLib.php');
require_once(__DIR__ . '/RealStackDriver.php');

const DB_SPECIAL = 'aud "q" \\b \'a\' &c';

function dbBencode($value)
{
    if (is_int($value)) return 'i' . $value . 'e';
    if (is_string($value)) return strlen($value) . ':' . $value;
    if (count($value) && array_keys($value) === range(0, count($value) - 1)) {
        $out = 'l';
        foreach ($value as $item) $out .= dbBencode($item);
        return $out . 'e';
    }
    ksort($value, SORT_STRING);
    $out = 'd';
    foreach ($value as $key => $item) $out .= dbBencode((string) $key) . dbBencode($item);
    return $out . 'e';
}

// A multi-file metainfo: a directory named $name holding one-byte $files.
function dbMultiTorrent($name, $files)
{
    $list = array();
    foreach ($files as $file) $list[] = array('length' => 1, 'path' => array($file));
    $info = array('files' => $list, 'name' => $name, 'piece length' => 16384,
        'pieces' => str_repeat("\0", 20));
    return array('bytes' => dbBencode(array('announce' => 'https://bt.t-ru.org/ann', 'info' => $info)),
        'hash' => strtoupper(sha1(dbBencode($info))));
}

/** One temporary tree, one daemon, one child driver. */
final class DaemonBytesScene
{
    public $base;
    public $downloads;
    public $root;
    public $session;
    public $mini;
    public $driver;

    public function __construct($sessionName = 'session', $legacy = false)
    {
        $this->base = sys_get_temp_dir() . '/rut-bytes-' . bin2hex(random_bytes(4));
        if (!mkdir($this->base, 0700, true))
            throw new RuntimeException('cannot create ' . $this->base);
        $this->downloads = $this->base . '/dl ' . DB_SPECIAL;
        $this->root = $this->downloads . '/' . DB_SPECIAL;
        $this->session = $this->base . '/' . $sessionName . '/';
        mkdir($this->root, 0777, true);
        mkdir($this->session, 0777, true);
        $this->mini = new MiniRtorrent($this->base . '/daemon-state.json', $this->base,
            $this->session, $this->downloads, $legacy);
        $this->driver = new RutrackerRealStackDriver($this->base);
    }

    /** The predecessor: a started, open multi-file download in $this->root. */
    public function seedPredecessor($torrent, $fields = array())
    {
        file_put_contents($this->session . $torrent['hash'] . '.torrent', $torrent['bytes']);
        $this->mini->addDownload($torrent['hash'], array_merge(array(
            'name' => DB_SPECIAL,
            'directory_base' => $this->root,
            'directory' => $this->root,
            'custom1' => rawurlencode('movies'),
            'state' => 1,
            'open' => 1,
            'customs' => array('chk-topic' => '6879823', 'chk-forum' => '1106'),
        ), $fields));
    }

    public function start()
    {
        $this->driver->startDaemon($this->mini);
    }

    public function replace($newTorrent, $oldHash)
    {
        return $this->driver->run('$t = ruTrackerChecker::parseMetainfo(base64_decode('
            . var_export(base64_encode($newTorrent['bytes']), true) . '));'
            . ' rsDone(array("result" => ruTrackerChecker::createTorrent($t, '
            . var_export($oldHash, true) . ')));');
    }

    public function state()
    {
        return $this->mini->state();
    }

    public function close()
    {
        $this->driver->stop();
        strictRemoveTree($this->base);
    }
}

function dbWithScene($callback, $sessionName = 'session')
{
    $scene = new DaemonBytesScene($sessionName);
    try {
        return $callback($scene);
    } finally {
        $scene->close();
    }
}

function dbLoadFor($state, $hash)
{
    foreach ($state['loads'] as $load)
        if ($load['hash'] === $hash) return $load;
    throw new RuntimeException('no load of ' . $hash);
}

/**
 * The string literals of a JavaScript fragment and the code around them,
 * the way a JavaScript parser reads them: a quote opens a literal, a
 * backslash escape is decoded or -- for an invalid \x or \u escape -- is the
 * SyntaxError that discards the whole getplugins script. Returns
 * array('code' => code with each literal replaced by S, 'strings' => decoded
 * literals), or a string naming the syntax error.
 */
function dbJsLiterals($fragment)
{
    $code = '';
    $strings = array();
    $simple = array('n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", 'v' => "\x0B", '0' => "\0");
    for ($i = 0, $n = strlen($fragment); $i < $n; $i++) {
        $c = $fragment[$i];
        if ($c !== '"' && $c !== "'") {
            $code .= $c;
            continue;
        }
        $quote = $c;
        $value = '';
        for ($i++; ; $i++) {
            if ($i >= $n || $fragment[$i] === "\n" || $fragment[$i] === "\r")
                return 'unterminated string literal';
            $c = $fragment[$i];
            if ($c === $quote) break;
            if ($c !== '\\') {
                $value .= $c;
                continue;
            }
            $e = $fragment[++$i] ?? '';
            if ($e === 'x') {
                if (!preg_match('/\G[0-9A-Fa-f]{2}/', $fragment, $m, 0, $i + 1)) return 'invalid hexadecimal escape';
                $value .= json_decode('"\\u00' . $m[0] . '"');
                $i += 2;
            } elseif ($e === 'u') {
                if (!preg_match('/\G[0-9A-Fa-f]{4}/', $fragment, $m, 0, $i + 1)) return 'invalid Unicode escape';
                $value .= json_decode('"\\u' . $m[0] . '"');
                $i += 4;
            } elseif (isset($simple[$e])) {
                $value .= $simple[$e];
            } else {
                $value .= $e;
            }
        }
        $code .= 'S';
        $strings[] = $value;
    }
    return array('code' => $code, 'strings' => $strings);
}

// A predecessor, its successor and a cross-seed of the predecessor in one
// directory. $shared is a file only the predecessor and the cross-seed carry:
// obsolete for the replacement, still needed by the cross-seed.
function dbCrossSeedReplacement($shared)
{
    dbWithScene(function ($scene) use ($shared) {
        $old = dbMultiTorrent(DB_SPECIAL, array('a.mkv', $shared, 'old.nfo'));
        $new = dbMultiTorrent(DB_SPECIAL, array('a.mkv', 'gen1.nfo'));
        $cross = dbMultiTorrent(DB_SPECIAL, array('a.mkv', $shared));
        foreach (array('a.mkv', $shared, 'old.nfo') as $file)
            file_put_contents($scene->root . '/' . $file, $file);
        $scene->seedPredecessor($old);
        file_put_contents($scene->session . $cross['hash'] . '.torrent', $cross['bytes']);
        $scene->mini->addDownload($cross['hash'], array('name' => DB_SPECIAL,
            'directory_base' => $scene->root, 'directory' => $scene->root,
            'files' => array('a.mkv', $shared), 'multi' => 1, 'state' => 1, 'open' => 1));
        $scene->start();

        strictAssertSame(array('result' => null), $scene->replace($new, $old['hash']), 'the replacement commits');
        $prepared = array_values(array_filter($scene->driver->erasedataCalls(), function ($call) {
            return $call['name'] === 'erasedataPrepareObsoleteCleanup';
        }));
        strictAssertSame(1, count($prepared), 'one cleanup job is prepared');
        strictAssertSame(array(realpath($scene->root) . '/old.nfo'),
            array_map(function ($entry) { return $entry['path']; }, $prepared[0]['arguments'][5]),
            'the cross-seed\'s file ' . $shared . ' is claimed and stays out of the job');
    });
}

$suite = new StrictTestSuite();

// AUD-ESC-02. The successor is loaded with d.set_directory_base taken from
// the predecessor's d.directory_base. While php/xmlrpc.php escaped every
// string it read, quoteCommandArg() escaped that escaping once more and the
// daemon created a NEW directory -- 'aud \"q\" \\b ...' -- next to the
// payload: a full re-download, with the old data left without an owner.
$suite->test('aud "q" \\b \'a\' &c: the successor is loaded into the predecessor\'s own directory', function () {
    dbWithScene(function ($scene) {
        $old = dbMultiTorrent(DB_SPECIAL, array('a.mkv', 'old.nfo'));
        $new = dbMultiTorrent(DB_SPECIAL, array('a.mkv', 'gen1.nfo'));
        file_put_contents($scene->root . '/a.mkv', 'a');
        file_put_contents($scene->root . '/old.nfo', 'o');
        $scene->seedPredecessor($old);
        $scene->start();

        $answer = $scene->replace($new, $old['hash']);
        strictAssertSame(array('result' => null), $answer, 'the replacement commits');
        $state = $scene->state();
        strictAssertTrue(!isset($state['downloads'][$old['hash']]), 'the predecessor is erased');
        strictAssertSame($scene->root, $state['downloads'][$new['hash']]['directory_base'],
            'the daemon stores the predecessor\'s own directory bytes for the successor');
        strictAssertSame(array(array('mkdir', '-p', $scene->root)), $state['executed'],
            'mkdir -p is handed the same directory, not an escaped spelling of it');
        strictAssertSame(null, dbLoadFor($state, $new['hash'])['aborted'],
            'no load command was refused');
        strictAssertSame(array(DB_SPECIAL), array_values(array_diff(scandir($scene->downloads), array('.', '..'))),
            'no second directory was created beside the payload');
    });
});

// The same value on the cleanup side: realpath() of the snapshot directory
// is the base the obsolete-file job is built against (check.php
// buildObsoleteCleanupFiles() and erasedataPrepareObsoleteCleanup()). An
// escaped spelling names no directory, so every replacement with an obsolete
// file was refused and rolled back, cycle after cycle.
$suite->test('aud "q" \\b \'a\' &c: the obsolete-file job is built in the predecessor\'s real directory', function () {
    dbWithScene(function ($scene) {
        $old = dbMultiTorrent(DB_SPECIAL, array('a.mkv', 'old.nfo'));
        $new = dbMultiTorrent(DB_SPECIAL, array('a.mkv', 'gen1.nfo'));
        file_put_contents($scene->root . '/a.mkv', 'a');
        file_put_contents($scene->root . '/old.nfo', 'o');
        $scene->seedPredecessor($old);
        $scene->start();

        strictAssertSame(array('result' => null), $scene->replace($new, $old['hash']), 'the replacement commits');
        $prepared = array_values(array_filter($scene->driver->erasedataCalls(), function ($call) {
            return $call['name'] === 'erasedataPrepareObsoleteCleanup';
        }));
        strictAssertSame(1, count($prepared), 'one cleanup job is prepared');
        strictAssertSame(realpath($scene->root), $prepared[0]['arguments'][4],
            'the job base is the real directory the payload is in');
        strictAssertSame(array(realpath($scene->root) . '/old.nfo'),
            array_map(function ($entry) { return $entry['path']; }, $prepared[0]['arguments'][5]),
            'exactly the obsolete file is owned, by its real path');
        strictAssertTrue(is_file($scene->root . '/a.mkv'), 'the shared file is untouched');
    });
});

// Each generation reads back what the previous one wrote. With escaping on
// the read side every replacement doubled the backslashes again (1, 3, 7,
// 15 ...) and moved the torrent once more.
$suite->test('aud "q" \\b \'a\' &c: three successive replacements keep one directory', function () {
    dbWithScene(function ($scene) {
        $generations = array(dbMultiTorrent(DB_SPECIAL, array('a.mkv', 'gen0.nfo')));
        for ($i = 1; $i <= 3; $i++)
            $generations[] = dbMultiTorrent(DB_SPECIAL, array('a.mkv', 'gen' . $i . '.nfo'));
        file_put_contents($scene->root . '/a.mkv', 'a');
        file_put_contents($scene->root . '/gen0.nfo', '0');
        $scene->seedPredecessor($generations[0]);
        $scene->start();

        for ($i = 1; $i <= 3; $i++) {
            file_put_contents($scene->root . '/gen' . $i . '.nfo', (string) $i);
            strictAssertSame(array('result' => null),
                $scene->replace($generations[$i], $generations[$i - 1]['hash']),
                'generation ' . $i . ' commits');
            $state = $scene->state();
            strictAssertSame(array($generations[$i]['hash']), array_keys($state['downloads']),
                'only generation ' . $i . ' is left in the session');
            strictAssertSame($scene->root, $state['downloads'][$generations[$i]['hash']]['directory_base'],
                'generation ' . $i . ' keeps the original directory bytes');
        }
        $mkdirs = array_unique(array_map(function ($call) { return $call[2]; }, $scene->state()['executed']));
        strictAssertSame(array($scene->root), array_values($mkdirs), 'every generation named one directory');
        strictAssertSame(array(DB_SPECIAL), array_values(array_diff(scandir($scene->downloads), array('.', '..'))),
            'no generation created a directory of its own');
    });
});

// Round-4 S4: a file name may hold a backslash. The cleanup build takes each
// metainfo path component as one name; refusing the byte rolled back every
// replacement of such a torrent.
$suite->test('aud "q" \\b \'a\' &c: a torrent whose files hold a backslash is replaced and its obsolete file owned', function () {
    dbWithScene(function ($scene) {
        $obsolete = 'old ' . DB_SPECIAL . '.nfo';
        $old = dbMultiTorrent(DB_SPECIAL, array('a.mkv', $obsolete));
        $new = dbMultiTorrent(DB_SPECIAL, array('a.mkv', 'new \\ part.nfo'));
        file_put_contents($scene->root . '/a.mkv', 'a');
        file_put_contents($scene->root . '/' . $obsolete, 'o');
        $scene->seedPredecessor($old);
        $scene->start();

        strictAssertSame(array('result' => null), $scene->replace($new, $old['hash']), 'the replacement commits');
        $prepared = array_values(array_filter($scene->driver->erasedataCalls(), function ($call) {
            return $call['name'] === 'erasedataPrepareObsoleteCleanup';
        }));
        strictAssertSame(1, count($prepared), 'one cleanup job is prepared');
        strictAssertSame(array(realpath($scene->root) . '/' . $obsolete),
            array_map(function ($entry) { return $entry['path']; }, $prepared[0]['arguments'][5]),
            'the obsolete file is owned under its own name');
    });
});

// The obsolete-file job leaves out every file another torrent still names.
// That claim scan (erasedataCleanupOtherOwnerSnapshot()) reads the other
// torrent's paths from the daemon, and the job's candidates come from the
// snapshot directory: both sides must be the same bytes, or a cross-seed's
// file is scheduled for deletion (round-4 A3a, AUD-ESC-09).
$suite->test('aud "q" \\b \'a\' &c: a cross-seed\'s file in the predecessor\'s directory is never in the obsolete-file job', function () {
    dbCrossSeedReplacement('shared ' . DB_SPECIAL . '.nfo');
});

// Integration variant, applied after the erasedata lane is merged: a shared
// name holding the byte pairs \" and \\ tells a claim scan that still
// unescapes the daemon's bytes (erasedataDaemonString() at r5/esc-base) from
// one that does not. On the rutracker lane alone it fails; it must pass on
// the integrated tree.
$suite->test('x \\"q\\" \\\\b: a cross-seed\'s file whose name holds \\" and \\\\ is never in the obsolete-file job', function () {
    dbCrossSeedReplacement('shared x \\"q\\" \\\\b.nfo');
});

// AUD-ESC-15 and AUD-ESC-14. The throttle name goes through
// rTorrent::additionCommand(), the label through rawurlencode(); both must
// start from the daemon's bytes. A label a third-party client wrote without
// percent-encoding is carried in ruTorrent's encoding of the same bytes.
$suite->test('aud "q" \\b \'a\' &c: throttle name and a third-party label reach the successor unchanged', function () {
    dbWithScene(function ($scene) {
        $old = dbMultiTorrent(DB_SPECIAL, array('a.mkv'));
        $new = dbMultiTorrent(DB_SPECIAL, array('a.mkv', 'b.mkv'));
        file_put_contents($scene->root . '/a.mkv', 'a');
        $scene->seedPredecessor($old, array('throttle_name' => 'slow ' . DB_SPECIAL,
            'custom1' => 'label ' . DB_SPECIAL));
        $scene->start();

        strictAssertSame(array('result' => null), $scene->replace($new, $old['hash']), 'the replacement commits');
        $successor = $scene->state()['downloads'][$new['hash']];
        strictAssertSame('slow ' . DB_SPECIAL, $successor['throttle_name'],
            'd.set_throttle_name receives the predecessor\'s throttle bytes, quoted once');
        strictAssertSame(rawurlencode('label ' . DB_SPECIAL), $successor['custom1'],
            'the successor label is ruTorrent\'s encoding of the predecessor\'s label bytes');
        strictAssertSame(array('chk-topic' => '6879823', 'chk-forum' => '1106'),
            array_intersect_key($successor['customs'], array('chk-topic' => 1, 'chk-forum' => 1)),
            'the topic and forum travel with it');
    });
});

// AUD-ESC-14, read side: getState() hands the live d.custom1 to
// isIgnoredLabel(). A raw label from another client matched nothing while
// the transport escaped it, so an ignored torrent was checked and replaced.
$suite->test('aud "q" \\b \'a\' &c: a third-party label in $ignoreLabels is honoured on the live read', function () {
    dbWithScene(function ($scene) {
        $old = dbMultiTorrent(DB_SPECIAL, array('a.mkv'));
        $scene->seedPredecessor($old, array('custom1' => DB_SPECIAL));
        $scene->start();

        $answer = $scene->driver->run('$ignoreLabels = array(' . var_export(DB_SPECIAL, true) . ');'
            . ' $m = new ReflectionMethod("ruTrackerChecker", "getState");'
            . ' if(PHP_VERSION_ID < 80100) $m->setAccessible(true);'
            . ' $args = array(' . var_export($old['hash'], true) . ', null, null, null, null, null);'
            . ' $refs = array(&$args[0], &$args[1], &$args[2], &$args[3], &$args[4], &$args[5]);'
            . ' $read = $m->invokeArgs(null, $refs);'
            . ' rsDone(array("read" => $read, "label" => $args[3],'
            . ' "ignored" => ruTrackerChecker::isIgnoredLabel($args[3])));');
        strictAssertSame(array('read' => true, 'label' => DB_SPECIAL, 'ignored' => true), $answer,
            'the label arrives in its own bytes and the ignore list matches it');
    });
});

// AUD-ESC-13. rTorrent::getSource() finds the session copy under
// get_session's path. With that path escaped, is_readable() failed and every
// check of every torrent had no source; the replacement could not start.
$suite->test('aud "q" \\b \'a\' &c: a session directory with these bytes still yields the session copy', function () {
    dbWithScene(function ($scene) {
        $old = dbMultiTorrent(DB_SPECIAL, array('a.mkv', 'old.nfo'));
        $new = dbMultiTorrent(DB_SPECIAL, array('a.mkv', 'gen1.nfo'));
        file_put_contents($scene->root . '/a.mkv', 'a');
        file_put_contents($scene->root . '/old.nfo', 'o');
        $scene->seedPredecessor($old);
        $scene->start();

        $answer = $scene->driver->run('$path = null;'
            . ' $t = rTorrent::getSource(' . var_export($old['hash'], true) . ', $path);'
            . ' rsDone(array("path" => $path, "hash" => is_object($t) ? $t->hash_info() : null));');
        strictAssertSame(array('path' => $scene->session . $old['hash'] . '.torrent', 'hash' => $old['hash']),
            $answer, 'getSource() reads the session copy by its real path');
        strictAssertSame(array('result' => null), $scene->replace($new, $old['hash']),
            'the replacement, which reads the predecessor through getSource(), commits');
    }, 'session ' . DB_SPECIAL);
});

$suite->test('aud "q" \\b \'a\' &c: the tied .torrent fallback is opened by its real path', function () {
    dbWithScene(function ($scene) {
        $old = dbMultiTorrent(DB_SPECIAL, array('a.mkv'));
        $tied = $scene->base . '/watch ' . DB_SPECIAL . '.torrent';
        file_put_contents($tied, $old['bytes']);
        $scene->mini->addDownload($old['hash'], array('name' => DB_SPECIAL, 'tied_to_file' => $tied));
        $scene->start();

        $answer = $scene->driver->run('$path = null;'
            . ' $t = rTorrent::getSource(' . var_export($old['hash'], true) . ', $path);'
            . ' rsDone(array("path" => $path, "hash" => is_object($t) ? $t->hash_info() : null));');
        strictAssertSame(array('path' => $tied, 'hash' => $old['hash']), $answer,
            'without a session copy the tied file is found by its own bytes');
    });
});

// AUD-ESC-17. Layer 1 hands the RuTracker row's URL on to the announce probe
// and to the metadata fetch's magnet. It is the daemon's t.url, byte for byte.
$suite->test('aud "q" \\b \'a\' &c: layer 1 reports the tracker URL in its own bytes', function () {
    dbWithScene(function ($scene) {
        $old = dbMultiTorrent(DB_SPECIAL, array('a.mkv'));
        $url = 'https://bt.t-ru.org/ann?pk=0&x=' . DB_SPECIAL;
        $scene->seedPredecessor($old, array('trackers' => array(
            array('url' => $url, 'enabled' => 1, 'failed' => 0, 'success' => 5))));
        $scene->start();

        $answer = $scene->driver->run('$m = new ReflectionMethod("RuTrackerCheckImpl", "layer1Verdict");'
            . ' if(PHP_VERSION_ID < 80100) $m->setAccessible(true);'
            . ' $args = array(' . var_export($old['hash'], true) . ', null);'
            . ' $verdict = $m->invokeArgs(null, array(&$args[0], &$args[1]));'
            . ' rsDone(array("verdict" => $verdict, "url" => $args[1]));');
        strictAssertSame(array('verdict' => 'alive', 'url' => $url), $answer,
            'the row URL is the daemon\'s t.url');
    });
});

// runstate.php: a fast projection whose branch reply is lost is proved by
// reading the fields back. The expected values are the logical strings; the
// readback has to be the daemon's bytes for the comparison to mean anything.
$suite->test('aud "q" \\b \'a\' &c: a projection whose reply was lost is proved by its readback', function () {
    dbWithScene(function ($scene) {
        $old = dbMultiTorrent(DB_SPECIAL, array('a.mkv'));
        $scene->seedPredecessor($old);
        $scene->mini->loseReplies = array('branch' => 1);
        $scene->start();

        $answer = $scene->driver->run('$hash = ' . var_export($old['hash'], true) . ';'
            . ' $id = new rXMLRPCRequest(new rXMLRPCCommand("d.get_local_id", $hash));'
            . ' if(!$id->success()) rsDone("no local id");'
            . ' $command = new rXMLRPCCommand(getCmd("d.set_custom"), array($hash, "chk-msg", '
            . var_export('topic-status|' . DB_SPECIAL, true) . '));'
            . ' rsDone(array("proved" => RuTrackerCustomProjection::write($hash, array($command),'
            . ' "test", $id->val[0])));');
        strictAssertSame(array('proved' => true), $answer, 'the readback proves the write');
        strictAssertSame('topic-status|' . DB_SPECIAL,
            $scene->state()['downloads'][$old['hash']]['customs']['chk-msg'],
            'the daemon holds the message bytes the checker meant');
    });
});

// init.php reports an unreadable session directory inside the getplugins
// script, as a JavaScript string. The path is the daemon's session.path in
// its own bytes: an apostrophe ended the literal, and a backslash became an
// escape -- '\u' that is not a Unicode escape is a SyntaxError that stops the
// whole getplugins script, not only this plugin.
$suite->test('aud "q" \\b \'a\' &c: an unreadable session path is shown as text in the getplugins script', function () {
    dbWithScene(function ($scene) {
        $scene->start();
        $session = $scene->base . '/missing ' . DB_SPECIAL . ' \\users\\';
        $fragment = $scene->driver->run('$plugin = array("name" => "rutracker_check"); $pInfo = array("perms" => 0);'
            . ' $jResult = ""; $theSettings = rTorrentSettings::get();'
            . ' require(' . var_export(testFindRepoRoot() . '/plugins/rutracker_check/init.php', true) . ');'
            . ' rsDone($jResult);', array('session' => $session));
        $parsed = dbJsLiterals($fragment);
        strictAssertTrue(is_array($parsed), 'the fragment is valid JavaScript: ' . var_export($parsed, true)
            . ' in ' . $fragment);
        strictAssertSame('plugin.disable(); noty(S+theUILang.webBadSessionError+S,S);', $parsed['code'],
            'the path never leaves its string literal');
        strictAssertSame(array('rutracker_check: ', ' (' . $session . ').', 'error'), $parsed['strings'],
            'the message shows the session path byte for byte');
    });
});

// Diagnostics quote the daemon's own words. finishStrandedReplacement() names
// the d.message that keeps a stranded successor stopped (updatepass.php); an
// escaped copy doubled every backslash and named a message nobody sent.
$suite->test('aud "q" \\b \'a\' &c: the sweep quotes a stranded successor\'s message in its own bytes', function () {
    dbWithScene(function ($scene) {
        $old = str_repeat('A', 40);
        $new = str_repeat('B', 40);
        $message = 'Tracker: [' . DB_SPECIAL . ']';
        $scene->mini->addDownload($new, array('name' => DB_SPECIAL, 'message' => $message,
            'customs' => array('chk-replacement' => str_repeat('c', 32),
                'chk-replaces' => $old . '-started-' . (time() - 7200))));
        $scene->start();

        $scene->driver->run('require_once("updatepass.php");'
            . ' RuTrackerUpdatePass::sweepReplacements(time()); rsDone(true);');
        $lines = array_values(array_filter($scene->driver->appLogLines(), function ($line) {
            return strpos($line, 'will not be started blind') !== false;
        }));
        strictAssertSame(array('rutracker_check: sweepReplacements: ' . $new
            . ' will not be started blind, the daemon already reports: ' . $message), $lines,
            'the log line carries the message bytes the daemon holds');
        strictAssertSame(0, $scene->state()['downloads'][$new]['state'], 'the successor is left stopped');
    });
});

exit($suite->run());
