<?php

// The path of a closed download's file is read without opening it: d.open
// and the d.close after it would cancel a hash check the daemon has queued.
// The frozen path counts only while the download is open; a closed one may
// have moved since. Pin the daemon command transcript so all the media
// consumers share it.
define('TESTLIB_HANDLER_STUBS', true);
require_once(__DIR__ . '/../plugins/rutracker_check/TestLib.php');
eval(loadClassDefinition(__DIR__ . '/../../php/rtorrent.php', 'rTorrent'));

class RtorrentFilePathTest
{
    private const HASH = '1234567890123456789012345678901234567890';
    private const READ = 'd.is_open|f.get_frozen_path|d.get_directory|f.get_path';
    private const BASE = 'd.is_open|d.get_base_path|d.get_directory|f.get_path|d.is_multi_file';

    public function testAnOpenDownloadsFileIsItsFrozenPath()
    {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(self::READ, true, false, ['1', '/media/file.bin', '/elsewhere', 'other.bin']);
        strictAssertSame(
            '/media/file.bin',
            rTorrent::getFilePath(self::HASH, 3),
            'an open download\'s frozen path is the path it uses',
        );
        strictAssertSame([self::READ], array_column(rXMLRPCRequest::$requests, 'key'), 'one read-only RPC');
        $commands = rXMLRPCRequest::$requests[0]['commands'];
        strictAssertSame(self::HASH, $commands[0]->params, 'the open state is read of the same hash');
        strictAssertSame([self::HASH, 3], $commands[1]->params, 'frozen path read targets the hash and integer file index');
        strictAssertSame(self::HASH, $commands[2]->params, 'directory read targets the same hash');
        strictAssertSame([self::HASH, 3], $commands[3]->params, 'path read targets the same file');
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(self::READ, true, false, ['1', '', '/media/dir', 'sub/pad']);
        strictAssertSame(
            false,
            rTorrent::getFilePath(self::HASH, 3),
            'an open download\'s file without a frozen path (a padding file) has none',
        );
    }

    public function testAClosedDownloadsFileIsTheDirectoryAndTheFilesPath()
    {
        foreach (['' => 'never opened', '/old/dir/sub/file.bin' => 'moved since it was open'] as $frozen => $what) {
            rXMLRPCRequest::reset();
            rXMLRPCRequest::queue(self::READ, true, false, ['0', $frozen, '/media/dir', 'sub/file.bin']);
            strictAssertSame(
                '/media/dir/sub/file.bin',
                rTorrent::getFilePath(self::HASH, 3),
                'a closed download\'s file is at d.directory "/" f.path: ' . $what,
            );
            strictAssertSame(
                [self::READ],
                array_column(rXMLRPCRequest::$requests, 'key'),
                'the closed download is neither opened nor closed: ' . $what,
            );
        }
    }

    public function testFaultAndEmptyAnswersNeverReturnAUsablePath()
    {
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(self::READ, true, true);
        strictAssertSame(false, rTorrent::getFilePath(self::HASH, 3), 'a fault is no path');
        strictAssertSame(1, count(rXMLRPCRequest::$requests), 'and asks nothing more');
        foreach ([['0', '/old/file.bin', '', 'file.bin'], ['0', '/old/file.bin', '/media', ''],
            ['0', '', '/media'], [null, '', '/media', 'file.bin'], ['1', '', '/media', 'file.bin']] as $answer) {
            rXMLRPCRequest::reset();
            rXMLRPCRequest::queue(self::READ, true, false, $answer);
            strictAssertSame(
                false,
                rTorrent::getFilePath(self::HASH, 3),
                'an empty or partial answer does not start a media task: ' . json_encode($answer),
            );
        }
    }

    public function testBasePathOfAnOpenDownloadIsItsOwn()
    {
        foreach (['1', '0'] as $multi) {
            rXMLRPCRequest::reset();
            rXMLRPCRequest::queue(self::BASE, true, false, ['1', '/media/open', '/media/elsewhere', 'other', $multi]);
            strictAssertSame('/media/open', rTorrent::getBasePath(self::HASH), 'an open download\'s base path is d.base_path');
            strictAssertSame([self::BASE], array_column(rXMLRPCRequest::$requests, 'key'), 'one read-only RPC');
        }
        $commands = rXMLRPCRequest::$requests[0]['commands'];
        strictAssertSame([self::HASH, 0], $commands[3]->params, 'the file path read is of the first file');
    }

    public function testBasePathOfAClosedDownloadIsWhereItIsNow()
    {
        foreach (['' => 'never opened', '/old/dir' => 'moved since it was open'] as $frozen => $what) {
            rXMLRPCRequest::reset();
            rXMLRPCRequest::queue(self::BASE, true, false, ['0', $frozen, '/media/dir', 'sub/a.bin', '1']);
            strictAssertSame(
                '/media/dir',
                rTorrent::getBasePath(self::HASH),
                'a closed multi-file download is its directory: ' . $what,
            );
            rXMLRPCRequest::reset();
            rXMLRPCRequest::queue(self::BASE, true, false, ['0', $frozen, '/media', 'file.bin', '0']);
            strictAssertSame(
                '/media/file.bin',
                rTorrent::getBasePath(self::HASH),
                'a closed single-file download is its file: ' . $what,
            );
            strictAssertSame([self::BASE], array_column(rXMLRPCRequest::$requests, 'key'), 'neither is opened nor closed: ' . $what);
        }
        foreach ([['0', '', '', 'a.bin', '1'], ['0', '', '/media', '', '0'], ['0', '', '/media', 'a.bin'],
            [null, '', '/media/dir', 'a.bin', '1'], ['1', '', '/media/dir', 'a.bin', '1']] as $answer) {
            rXMLRPCRequest::reset();
            rXMLRPCRequest::queue(self::BASE, true, false, $answer);
            strictAssertSame(false, rTorrent::getBasePath(self::HASH), 'no path: ' . json_encode($answer));
        }
        rXMLRPCRequest::reset();
        rXMLRPCRequest::queue(self::BASE, true, true);
        strictAssertSame(false, rTorrent::getBasePath(self::HASH), 'a fault is no path');
    }
}

$suite = new StrictTestSuite();
$suite->addFromObject(new RtorrentFilePathTest());
exit($suite->run());
