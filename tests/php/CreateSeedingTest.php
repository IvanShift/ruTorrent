<?php

require_once(__DIR__ . '/TestCase.php');

class FileUtil
{
    public static $path;
    public static $logs = [];
    public static function getUniqueUploadedFilename($name)
    {
        return(self::$path);
    }
    public static function addslash($path)
    {
        return(rtrim($path, '/') . '/');
    }
    public static function toLog($message)
    {
        self::$logs[] = $message;
    }
}
class JSON
{
    public static function safeEncode($value)
    {
        return(json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
class User
{
    public static function isLocalMode()
    {
        return(true);
    }
}
class rTorrentSettings
{
    public static function get()
    {
        return(new self());
    }
    public function correctReportedDirectory(&$path)
    {
        $path = rtrim($path, '/');
        return(true);
    }
}
class rTorrent
{
    public static $status;
    public static $calls = [];
    public static $inside = true;
    public static function isInsideTopDirectory($path)
    {
        return(self::$inside);
    }
    public static function fastResume($torrent, $path)
    {
        self::$calls[] = ['resume', $path];
        return(false);
    }
    public static function sendTorrent(
        $torrent,
        $start,
        $new,
        $path,
        $label,
        $resume,
        $local,
        $isNew = true,
        $addition = null,
        &$pendingReceipt = null,
        $beforeDispatch = null,
        $directoryIsReported = false
    ) {
        self::$calls[] = ['send', $start, $new, $path, $label, $resume, $local, $directoryIsReported];
        return(self::$status);
    }
}
class CreateSeedingFixtureTorrent
{
    public $info = ['name' => 'example'];
    public $saved = [];
    public function save($path)
    {
        $this->saved[] = $path;
        return(true);
    }
}

class CreateSeedingTest extends TestCase
{
    public function testBothCliPathsPublishOnceAndClassifyAllThreeStatuses()
    {
        $helper = __DIR__ . '/../../plugins/create/seeding.php';
        $this->assertTrue(is_file($helper), 'create CLI paths need one seeding publisher');
        if (!is_file($helper)) {
            return;
        }
        require_once($helper);
        $dir = sys_get_temp_dir() . '/rutorrent-create-seed-' . getmypid();
        mkdir($dir, 0700);
        $GLOBALS['topDirectory'] = '/';
        try {
            foreach (['correct', 'create'] as $origin) {
                foreach ([true, null, false] as $status) {
                    FileUtil::$path = $dir . '/uploaded.torrent';
                    FileUtil::$logs = [];
                    rTorrent::$status = $status;
                    rTorrent::$calls = [];
                    $torrent = new CreateSeedingFixtureTorrent();
                    CreateSeeding::publish(
                        $torrent,
                        ['path_edit' => $dir],
                        0666,
                        $origin,
                    );
                    $this->assertSame([FileUtil::$path], $torrent->saved);
                    $this->assertSame('send', rTorrent::$calls[1][0]);
                    $this->assertSame(true, rTorrent::$calls[1][1]);
                    $this->assertSame(true, rTorrent::$calls[1][2]);
                    $this->assertSame(dirname($dir), rTorrent::$calls[1][3]);
                    $this->assertSame(true, rTorrent::$calls[1][7], 'the source directory is reported, never reinterpreted as typed input');
                    $expected = $status === null
                        ? [$origin . ': seeding load pending confirmation']
                        : ($status === false
                            ? [$origin . ': seeding load dispatch failed'] : []);
                    $this->assertSame($expected, FileUtil::$logs);
                }
            }
            foreach (['correct.php', 'createtorrent.php'] as $script) {
                $source = file_get_contents(__DIR__ . '/../../plugins/create/' . $script);
                $this->assertTrue(
                    strpos($source, 'CreateSeeding::publish(') !== false,
                    $script . ' uses the shared publisher',
                );
            }
        } finally {
            @unlink($dir . '/uploaded.torrent');
            @rmdir($dir);
            unset($GLOBALS['topDirectory']);
        }
    }

    public function testAPathTheBoundaryRefusesIsNeitherSavedNorSentAndSaysWhy()
    {
        require_once(__DIR__ . '/../../plugins/create/seeding.php');
        $GLOBALS['topDirectory'] = '/nonexistent/';
        foreach (['correct', 'create'] as $origin) {
            FileUtil::$path = '/nonexistent/uploaded.torrent';
            FileUtil::$logs = [];
            rTorrent::$calls = [];
            rTorrent::$inside = false;
            $torrent = new CreateSeedingFixtureTorrent();
            ob_start();
            try {
                CreateSeeding::publish($torrent, ['path_edit' => '/nonexistent/payload'], 0666, $origin);
            } finally {
                $printed = ob_get_clean();
                rTorrent::$inside = true;
            }
            $this->assertSame([], $torrent->saved, 'no copy of the torrent is kept for seeding');
            $this->assertSame([], rTorrent::$calls, 'and rtorrent is given nothing to serve');
            $this->assertSame(
                [$origin . ': seeding refused reason=outside-top-directory entry="\\/payload"'],
                FileUtil::$logs,
                'the log names the classified reason and the entry',
            );
            $this->assertSame(
                "Seeding refused (outside-top-directory): \"\\/payload\"\n",
                $printed,
                'and so does the task log, encoded like the log',
            );
        }
        unset($GLOBALS['topDirectory']);
    }
}
