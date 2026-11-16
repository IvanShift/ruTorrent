<?php

/**
 * A daemon that answers each request with the answer rtorrent gave the same
 * request about downloads it had not loaded yet.
 *
 * The fixtures were captured on 2026-11-16 from rtorrent 0.16.28 (the
 * rutorrent-native candidate cdff6007 with libtorrent ca595a62, which answers
 * RPC while it loads its session) in the middle of loading its downloads,
 * in two labs one driver built. The application's own readers sent the
 * requests ($rpcLogCalls); the capture script sent the edit read as
 * plugins/edit/action.php builds it.
 *
 * b10app (431 downloads): "filepath-*", "basepath-*" (rTorrent::getFilePath()
 * and getBasePath()) and "edit" (plugins/edit/action.php's first read without
 * the guarded erase and the throttle name, d.get_hashing and
 * d.get_hashing_failed last) on two downloads still queued for their hash
 * check: 0682D18D..., forty files in /data/downloads/t318, and 898EDEF4...,
 * the single file /data/downloads/t83. Both read d.state 1, d.is_open 0,
 * d.is_active 0, d.hashing 1, d.complete 1 and d.connection_current
 * "leech" before and after the capture, and t318 d.hashing_failed 0.
 *
 * b7app (422 downloads): the two d.multicall answers on the "started" view
 * keep seven of the 422 rows: two loaded seeds, the one incomplete download
 * (0288AEBC..., d.complete 0) and four complete ones still queued, which read
 * d.connection_current "leech". "started-base" is the scheduler's read
 * without d.complete and d.hashing, "frozen-path-call" and "is-open-call"
 * the first reads of getFilePath() and getsavepath before this change, on
 * another queued download. A system.multicall of d.stop,
 * d.close, d.open, d.start and the global rate setters gets one ACTION_CELL
 * per command, the cell every command of the same daemon's answers to the
 * scheduler's 332 d.stop/d.close pairs and its two rate setters held
 * (b5repro c3b, 2026-11-16). A request without a captured answer is a
 * fault, so a reader that asks for anything else is seen.
 */
class HalfLoadedReplies
{
    public const MULTI = '0682D18D4A841F008BB08943DC457797862F34B1';
    public const SINGLE = '898EDEF4C285DC11250BCF4AA69EBBF2E6084320';
    public const INCOMPLETE = '0288AEBC700BBF81181FA8F75844E97402096A63';

    public const ACTION_CELL = '<value><array><data><value><i8>0</i8></value></data></array></value>';
    public const ACTIONS = ['d.stop', 'd.close', 'd.open', 'd.start',
        'throttle.global_up.max_rate.set', 'throttle.global_down.max_rate.set'];

    private $answers;

    /**
     * $answers: request key (see key()) => fixture name, or a list of answers
     * given to that request in turn (the last one again after that); an
     * answer starting "<?xml" is used as it is.
     */
    public function __construct(array $answers)
    {
        $this->answers = [];
        foreach ($answers as $key => $names) {
            foreach ((array) $names as $name) {
                $this->answers[$key][] = strpos($name, '<?xml') === 0 ? $name : self::fixture($name);
            }
        }
    }

    public function __invoke($request, $methods)
    {
        $key = self::key($request, $methods);
        if (isset($this->answers[$key])) {
            $xml = count($this->answers[$key]) > 1 ? array_shift($this->answers[$key]) : $this->answers[$key][0];
        } elseif (strpos($request, '<methodName>system.multicall</methodName>') !== false
            && $methods !== [] && array_diff($methods, self::ACTIONS) === []) {
            $xml = '<?xml version="1.0"?><methodResponse><params><param><value><array><data>'
                . str_repeat(self::ACTION_CELL, count($methods)) . '</data></array></value></param></params></methodResponse>';
        } else {
            return FakeRtorrentDaemon::fault('no captured answer for ' . $key);
        }
        return FakeRtorrentDaemon::raw("Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: "
            . strlen($xml) . "\r\n\r\n" . $xml);
    }

    public static function fixture($name)
    {
        return file_get_contents(__DIR__ . '/fixtures/half-loaded-' . $name . '-rtorrent-0.16.28-cdff6007.xml');
    }

    /**
     * The method names of a request joined with "|"; for a d.multicall, or
     * the d.multicall2 it is sent as to a daemon whose settings were not
     * probed, "d.multicall:" and then its view and columns.
     */
    public static function key($request, $methods)
    {
        if ($methods === ['d.multicall'] || $methods === ['d.multicall2']) {
            preg_match_all('|<param><value><string>([^<]*)</string>|', $request, $params);
            return 'd.multicall:' . implode('|', array_slice($params[1], 1));
        }
        return implode('|', $methods);
    }
}
