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
    if (is_int($value)) {
        return 'i' . $value . 'e';
    }
    if (is_string($value)) {
        return strlen($value) . ':' . $value;
    }
    if (count($value) && array_keys($value) === range(0, count($value) - 1)) {
        $out = 'l';
        foreach ($value as $item) {
            $out .= dbBencode($item);
        }
        return $out . 'e';
    }
    ksort($value, SORT_STRING);
    $out = 'd';
    foreach ($value as $key => $item) {
        $out .= dbBencode((string) $key) . dbBencode($item);
    }
    return $out . 'e';
}

// A multi-file metainfo: a directory named $name holding one-byte $files.
function dbMultiTorrent($name, $files)
{
    $list = [];
    foreach ($files as $file) {
        $list[] = ['length' => 1, 'path' => [$file]];
    }
    $info = ['files' => $list, 'name' => $name, 'piece length' => 16384,
        'pieces' => str_repeat("\0", 20)];
    return ['bytes' => dbBencode(['announce' => 'https://bt.t-ru.org/ann', 'info' => $info]),
        'hash' => strtoupper(sha1(dbBencode($info)))];
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
        if (!mkdir($this->base, 0700, true)) {
            throw new RuntimeException('cannot create ' . $this->base);
        }
        $this->downloads = $this->base . '/dl ' . DB_SPECIAL;
        $this->root = $this->downloads . '/' . DB_SPECIAL;
        $this->session = $this->base . '/' . $sessionName . '/';
        mkdir($this->root, 0777, true);
        mkdir($this->session, 0777, true);
        $this->mini = new MiniRtorrent(
            $this->base . '/daemon-state.json',
            $this->base,
            $this->session,
            $this->downloads,
            $legacy,
        );
        $this->driver = new RutrackerRealStackDriver($this->base);
    }

    /** The predecessor: a started, open multi-file download in $this->root. */
    public function seedPredecessor($torrent, $fields = [])
    {
        file_put_contents($this->session . $torrent['hash'] . '.torrent', $torrent['bytes']);
        $metainfo = MiniBencode::decode($torrent['bytes']);
        $info = $metainfo['value']['info'];
        $paths = isset($info['files']) ? array_map(function ($file) {
            return implode('/', $file['path']);
        }, $info['files']) : [$info['name']];
        $this->mini->addDownload($torrent['hash'], array_merge([
            'name' => DB_SPECIAL,
            'directory_base' => $this->root,
            'directory' => $this->root,
            'custom1' => rawurlencode('movies'),
            'state' => 1,
            'open' => 1,
            'files' => $paths,
            'multi' => isset($info['files']) ? 1 : 0,
            'customs' => ['chk-topic' => '6879823', 'chk-forum' => '1106'],
        ], $fields));
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
    foreach ($state['loads'] as $load) {
        if ($load['hash'] === $hash) {
            return $load;
        }
    }
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
    $strings = [];
    $simple = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\f", 'v' => "\x0B", '0' => "\0"];
    for ($i = 0, $n = strlen($fragment); $i < $n; $i++) {
        $c = $fragment[$i];
        if ($c !== '"' && $c !== "'") {
            $code .= $c;
            continue;
        }
        $quote = $c;
        $value = '';
        for ($i++; ; $i++) {
            if ($i >= $n || $fragment[$i] === "\n" || $fragment[$i] === "\r") {
                return 'unterminated string literal';
            }
            $c = $fragment[$i];
            if ($c === $quote) {
                break;
            }
            if ($c !== '\\') {
                $value .= $c;
                continue;
            }
            $e = $fragment[++$i] ?? '';
            if ($e === 'x') {
                if (!preg_match('/\G[0-9A-Fa-f]{2}/', $fragment, $m, 0, $i + 1)) {
                    return 'invalid hexadecimal escape';
                }
                $value .= json_decode('"\\u00' . $m[0] . '"');
                $i += 2;
            } elseif ($e === 'u') {
                if (!preg_match('/\G[0-9A-Fa-f]{4}/', $fragment, $m, 0, $i + 1)) {
                    return 'invalid Unicode escape';
                }
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
    return ['code' => $code, 'strings' => $strings];
}
