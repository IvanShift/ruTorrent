<?php
// The fallback opens the download only to read its frozen path, then closes it.
// Pin the daemon command transcript so all three media consumers share it.
define('TESTLIB_HANDLER_STUBS', true);
require_once(__DIR__ . '/../plugins/rutracker_check/TestLib.php');
eval(loadClassDefinition(__DIR__ . '/../../php/rtorrent.php', 'rTorrent'));

class RtorrentFilePathTest
{
    private const HASH = '1234567890123456789012345678901234567890';

    public function testPresentPathDoesNotOpenOrCloseTheDownload()
    {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('f.get_frozen_path', true, false, array('/media/file.bin'));
        strictAssertSame('/media/file.bin', rTorrent::getFilePath(self::HASH, 3),
            'a present frozen path is used directly');
        strictAssertSame(array('f.get_frozen_path'), array_column(rXMLRPCRequest::$requests, 'key'),
            'the fast path makes one read-only RPC');
    }

    public function testEmptyPathUsesOrderedOpenReadCloseFallback()
    {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('f.get_frozen_path', true, false, array(''));
        rXMLRPCRequest::queue(array('d.open', 'f.get_frozen_path', 'd.close'),
            true, false, array(0, '/media/after-open.bin', 0));
        strictAssertSame('/media/after-open.bin', rTorrent::getFilePath(self::HASH, 3),
            'fallback reads the newly frozen path');
        strictAssertSame(array('f.get_frozen_path', 'd.open|f.get_frozen_path|d.close'),
            array_column(rXMLRPCRequest::$requests, 'key'),
            'opening, path read, and closing stay in one ordered daemon request');
        $commands = rXMLRPCRequest::$requests[1]['commands'];
        strictAssertSame(self::HASH, $commands[0]->params, 'open targets requested hash');
        strictAssertSame(array(self::HASH, 3), $commands[1]->params,
            'path read targets requested hash and integer file index');
        strictAssertSame(self::HASH, $commands[2]->params, 'close targets the same hash');
    }

    public function testFaultAndEmptyFallbackNeverReturnAUsablePath()
    {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('f.get_frozen_path', true, true);
        strictAssertSame(false, rTorrent::getFilePath(self::HASH, 3),
            'the first RPC fault does not trigger an open');
        strictAssertSame(1, count(rXMLRPCRequest::$requests), 'fault sends only the initial probe');

        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue('f.get_frozen_path', true, false, array(''));
        rXMLRPCRequest::queue(array('d.open', 'f.get_frozen_path', 'd.close'),
            true, false, array(0, '', 0));
        strictAssertSame(false, rTorrent::getFilePath(self::HASH, 3),
            'an empty fallback path does not start a media task');
    }
}

$suite = new StrictTestSuite();
$suite->addFromObject(new RtorrentFilePathTest());
exit($suite->run());
