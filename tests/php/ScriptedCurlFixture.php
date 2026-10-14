<?php

/**
 * Replaces the curl binary Snoopy runs for HTTPS with a scripted stand-in, so
 * a test drives the real Snoopy, loginmgr and handler code and only the wire
 * answers are invented. Every invocation is recorded -- method, URL, Cookie
 * header, body and start time -- before it is matched against the plan, so a
 * request nobody planned still shows in requests() instead of vanishing. An
 * unplanned or mismatched request is answered with curl's exit code 1 and the
 * line "Unexpected offline request: METHOD URL" on stderr. A reply may name an
 * 'exit' code instead of an answer, e.g. 6 for a name that does not resolve.
 *
 * The plan and the record are files under one lock, so separate processes can
 * share a fixture. Long-lived processes are not involved: each request is one
 * short PHP run, started through /bin/sh with exec so no shell lingers.
 */
class ScriptedCurlFixture
{
    private $root;
    private $previousCurl = null;
    private $previousRoot = false;

    public function __construct($root)
    {
        $this->root = $root;
        file_put_contents($root . '/curl.php', <<<'PHP'
<?php
$root = getenv('SCRIPTED_CURL_ROOT');
$request = array('method' => 'GET', 'url' => end($argv), 'cookie' => '', 'body' => '');
$headerFile = $bodyFile = null;
for ($i = 1; $i < $argc - 1; $i++) {
    if ($argv[$i] === '-D') $headerFile = $argv[++$i];
    elseif ($argv[$i] === '-o') $bodyFile = $argv[++$i];
    elseif ($argv[$i] === '-X') $request['method'] = $argv[++$i];
    elseif ($argv[$i] === '-d') $request['body'] = $argv[++$i];
    elseif ($argv[$i] === '-H' && strncasecmp($argv[$i + 1], 'Cookie:', 7) === 0) $request['cookie'] = $argv[++$i];
}
$clock = hrtime();
$request['time'] = $clock[0] + $clock[1] / 1000000000.0;
$lock = fopen($root . '/lock', 'c');
flock($lock, LOCK_EX);
$requests = unserialize(file_get_contents($root . '/requests'), array('allowed_classes' => false));
$requests[] = $request;
file_put_contents($root . '/requests', serialize($requests));
$plan = unserialize(file_get_contents($root . '/plan'), array('allowed_classes' => false));
$reply = array_shift($plan);
file_put_contents($root . '/plan', serialize($plan));
flock($lock, LOCK_UN);
if (!is_array($reply) || $reply['url'] !== $request['url'] || $reply['method'] !== $request['method']) {
    fwrite(STDERR, 'Unexpected offline request: ' . $request['method'] . ' ' . $request['url'] . "\n");
    exit(1);
}
if (isset($reply['exit'])) exit($reply['exit']);
file_put_contents($headerFile, 'HTTP/1.1 ' . $reply['status'] . " Fixture\r\n"
    . implode("\r\n", $reply['headers']) . "\r\n\r\n");
file_put_contents($bodyFile, $reply['body']);
PHP
        );
        file_put_contents($root . '/curl', "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' -c '
            . escapeshellarg(__DIR__ . '/../php-test.ini') . ' ' . escapeshellarg($root . '/curl.php') . ' "$@"' . "\n");
        chmod($root . '/curl', 0700);
        $this->plan(array());
    }

    /** Points Snoopy at the stand-in; restore() puts the previous curl back. */
    public function install()
    {
        global $pathToExternals;
        $this->previousCurl = isset($pathToExternals['curl']) ? $pathToExternals['curl'] : null;
        $this->previousRoot = getenv('SCRIPTED_CURL_ROOT');
        $pathToExternals['curl'] = $this->root . '/curl';
        putenv('SCRIPTED_CURL_ROOT=' . $this->root);
    }

    public function restore()
    {
        global $pathToExternals;
        if ($this->previousCurl === null) unset($pathToExternals['curl']);
        else $pathToExternals['curl'] = $this->previousCurl;
        if ($this->previousRoot === false) putenv('SCRIPTED_CURL_ROOT');
        else putenv('SCRIPTED_CURL_ROOT=' . $this->previousRoot);
    }

    public static function reply($method, $url, $status, $body = '', $headers = array())
    {
        return array('method' => $method, 'url' => $url, 'status' => $status, 'body' => $body, 'headers' => $headers);
    }

    /** A request the transport never completes: curl's own exit code. */
    public static function failure($method, $url, $exit)
    {
        return array('method' => $method, 'url' => $url, 'exit' => $exit);
    }

    /** Replaces the plan and clears the record. */
    public function plan($replies)
    {
        file_put_contents($this->root . '/plan', serialize(array_values($replies)));
        file_put_contents($this->root . '/requests', serialize(array()));
    }

    /** Every request made since plan(), in order: method, url, cookie, body, time. */
    public function requests()
    {
        return unserialize(file_get_contents($this->root . '/requests'), array('allowed_classes' => false));
    }

    /** The replies nobody asked for yet. */
    public function remaining()
    {
        return unserialize(file_get_contents($this->root . '/plan'), array('allowed_classes' => false));
    }
}
