<?php

require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/PhysicalRpcFixture.php');

/**
 * Runs PHP code in a child process that loads the real conf/config.php,
 * php/settings.php and php/xmlrpc.php against a FakeRtorrentDaemon, so every
 * string the daemon answers reaches the code under test through the real
 * parser, which hands it out in the daemon's own bytes (php/xmlrpc.php run()).
 * The child logs to its own file.
 */
final class ErasedataRealParserDriver
{
    private $base;
    private $daemon = null;
    private $logFile = null;

    public function __construct($base)
    {
        $this->base = $base;
    }

    public function daemon()
    {
        return $this->daemon;
    }

    // system.multicall wraps each command result once. The third result is
    // f.multicall's array of one-string rows, not more scalar command results.
    // Keep XML text byte-wise so quote/backslash paths exercise the string
    // parser, including '&' beside the bytes which must not be unescaped.
    public static function physicalPathReply($root, $multi, array $paths)
    {
        $string = function ($value) {
            return '<value><string>' . strtr($value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;'])
                . '</string></value>';
        };
        $rows = '';
        foreach ($paths as $path) {
            $rows .= '<value><array><data>' . $string($path) . '</data></array></value>';
        }
        $results = [$string($root), '<value><i8>' . $multi . '</i8></value>',
            '<value><array><data>' . $rows . '</data></array></value>'];
        $body = '';
        foreach ($results as $result) {
            $body .= '<value><array><data>' . $result . '</data></array></value>';
        }
        return new FakeRtorrentRawReply('<methodResponse><params><param><value><array><data>'
            . $body . '</data></array></value></param></params></methodResponse>');
    }

    // Answers $replies in order -- one scalar list or explicit wire reply per
    // request -- or through a responder such as ErasedataLoadedFleetReplies,
    // and returns what $body printed, decoded from JSON.
    public function drive($replies, $body)
    {
        $this->stop();
        $this->daemon = is_array($replies)
            ? new FakeRtorrentDaemon($replies, $this->base . '/calls-' . uniqid() . '.log')
            : new FakeRtorrentDaemon($replies, $this->base . '/calls-' . uniqid() . '.log', false, [__FILE__]);
        $this->logFile = $this->base . '/app-' . uniqid() . '.log';
        $root = realpath(__DIR__ . '/../../..');
        $driver = $this->base . '/drive-' . uniqid() . '.php';
        file_put_contents($driver, "<?php\n"
            . '$_SERVER[\'REMOTE_USER\'] = "tester"; $_ENV[\'RU_PROFILE_PATH\'] = '
                . var_export($this->base . '/profile', true) . ";\n"
            . 'require_once(' . var_export($root . '/conf/config.php', true) . ");\n"
            . 'require_once(' . var_export($root . '/php/settings.php', true) . ");\n"
            . '$r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
            . ' $s->iVersion = 0x1018; $s->aliases = array(); $s->directory = "/";'
            . ' $s->linkExist = true; $p = $r->getProperty("theSettings");'
            . ' $p->setAccessible(true); $p->setValue(null, $s);' . "\n"
            . '$scgi_host = "127.0.0.1"; $scgi_port = ' . $this->daemon->port() . ';'
                . ' $rpcTimeOut = 10; $rpcLogCalls = false;'
                . ' $log_file = ' . var_export($this->logFile, true) . ";\n"
            . 'require_once(' . var_export($root . '/php/xmlrpc.php', true) . ");\n"
            . $body);
        $out = [];
        exec(escapeshellarg(PHP_BINARY) . ' -d error_reporting=0 ' . escapeshellarg($driver) . ' 2>&1', $out);
        $printed = implode("\n", $out);
        $answer = json_decode($printed, true);
        if (!is_array($answer)) {
            throw new Exception('the driver printed: ' . $printed);
        }
        return $answer;
    }

    // What the last child logged through FileUtil::toLog(), one entry per
    // line, without the timestamp.
    public function logLines()
    {
        $content = $this->logFile === null || !is_file($this->logFile)
            ? '' : rtrim(file_get_contents($this->logFile), "\n");
        if ($content === '') {
            return [];
        }
        $lines = [];
        foreach (explode("\n", $content) as $line) {
            $lines[] = preg_replace('/^\[[^\]]*\] /', '', $line);
        }
        return $lines;
    }

    public function stop()
    {
        if ($this->daemon !== null) {
            $this->daemon->stop();
        }
        $this->daemon = null;
    }
}

/**
 * A daemon holding a fleet of loaded downloads (ErasedataLoadedFleet): it
 * answers the questions of shared.php and of the replacement cleanup from
 * the fleet, in the wire shape captured from 0.16.27 and 0.9.8 (strings
 * escaped only for '&', '<' and '>', numbers as <i8>, system.multicall
 * results wrapped once), and every other request with the next of $replies.
 * $faults maps a question kind to the reply that stands for it instead (a
 * FakeRtorrentDaemon::fault(), a paced reply, or null for no reply).
 */
class ErasedataLoadedFleetReplies
{
    private $fleet;
    private $replies;
    private $faults;

    public function __construct(array $fleet, array $replies = [], array $faults = [])
    {
        $this->fleet = $fleet;
        $this->replies = $replies;
        $this->faults = $faults;
    }

    public function __invoke($body, $methods)
    {
        $calls = self::calls($body);
        $results = [];
        foreach ($calls as $call) {
            $kind = ErasedataLoadedFleet::kind($call[0], $call[1]);
            if ($kind === null) {
                return count($this->replies) ? array_shift($this->replies) : null;
            }
            if (array_key_exists($kind, $this->faults)) {
                return $this->faults[$kind];
            }
            $result = ErasedataLoadedFleet::result($this->fleet, $call[0], $call[1]);
            if ($result === false) {
                return FakeRtorrentDaemon::fault('Could not find info-hash.', -501);
            }
            $results[] = $result;
        }
        $value = count($calls) === 1 && strpos($body, '<methodName>system.multicall</methodName>') === false
            ? self::xml($results[0]) : self::xml(array_map(function ($result) {
                return [$result];
            }, $results));
        return new FakeRtorrentRawReply('<?xml version="1.0"?><methodResponse><params><param>'
            . $value . '</param></params></methodResponse>');
    }

    // [method, params] of each call in a request body.
    private static function calls($body)
    {
        $xml = simplexml_load_string($body);
        $calls = [];
        if ((string) $xml->methodName === 'system.multicall') {
            foreach ($xml->params->param->value->array->data->value as $struct) {
                $method = '';
                $params = [];
                foreach ($struct->struct->member as $member) {
                    if ((string) $member->name === 'methodName') {
                        $method = (string) $member->value->string;
                    } else {
                        foreach ($member->value->array->data->value as $param) {
                            $params[] = trim((string) $param->children()[0]);
                        }
                    }
                }
                $calls[] = [$method, $params];
            }
            return $calls;
        }
        $params = [];
        foreach ($xml->params->param as $param) {
            $params[] = (string) $param->value->children()[0];
        }
        return [[(string) $xml->methodName, $params]];
    }

    private static function xml($value)
    {
        if (is_array($value)) {
            return '<value><array><data>' . implode('', array_map([self::class, 'xml'], $value)) . '</data></array></value>';
        }
        return is_int($value) ? '<value><i8>' . $value . '</i8></value>'
            : '<value><string>' . strtr($value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;']) . '</string></value>';
    }
}
