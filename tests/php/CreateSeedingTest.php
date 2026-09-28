<?php

require_once(__DIR__ . '/TestCase.php');

class FileUtil
{
    public static $path;
    public static $logs = array();
    public static function getUniqueUploadedFilename($name) { return(self::$path); }
    public static function addslash($path) { return(rtrim($path, '/') . '/'); }
    public static function toLog($message) { self::$logs[] = $message; }
}
class User
{
    public static function isLocalMode() { return(true); }
}
class rTorrentSettings
{
    public static function get() { return(new self()); }
    public function correctDirectory(&$path) { $path = rtrim($path, '/'); return(true); }
}
class rTorrent
{
    public static $status;
    public static $calls = array();
    public static function fastResume($torrent, $path) {
        self::$calls[] = array('resume', $path);
        return(false);
    }
    public static function sendTorrent($torrent, $start, $new, $path, $label,
        $resume, $local) {
        self::$calls[] = array('send', $start, $new, $path, $label, $resume, $local);
        return(self::$status);
    }
}
class CreateSeedingFixtureTorrent
{
    public $info = array('name' => 'example');
    public $saved = array();
    public function save($path) { $this->saved[] = $path; return(true); }
}

class CreateSeedingTest extends TestCase
{
    public function testBothCliPathsPublishOnceAndClassifyAllThreeStatuses()
    {
        $helper = __DIR__ . '/../../plugins/create/seeding.php';
        $this->assertTrue(is_file($helper), 'create CLI paths need one seeding publisher');
        if (!is_file($helper)) return;
        require_once($helper);
        $dir = sys_get_temp_dir() . '/rutorrent-create-seed-' . getmypid();
        mkdir($dir, 0700);
        try {
            foreach (array('correct', 'create') as $origin) {
                foreach (array(true, null, false) as $status) {
                    FileUtil::$path = $dir . '/uploaded.torrent';
                    FileUtil::$logs = array();
                    rTorrent::$status = $status;
                    rTorrent::$calls = array();
                    $torrent = new CreateSeedingFixtureTorrent();
                    CreateSeeding::publish($torrent, array('path_edit' => $dir),
                        0666, $origin);
                    $this->assertSame(array(FileUtil::$path), $torrent->saved);
                    $this->assertSame('send', rTorrent::$calls[1][0]);
                    $this->assertSame(true, rTorrent::$calls[1][1]);
                    $this->assertSame(true, rTorrent::$calls[1][2]);
                    $this->assertSame(dirname($dir), rTorrent::$calls[1][3]);
                    $expected = $status === null ?
                        array($origin . ': seeding load pending confirmation') :
                        ($status === false ?
                            array($origin . ': seeding load dispatch failed') : array());
                    $this->assertSame($expected, FileUtil::$logs);
                }
            }
            foreach (array('correct.php', 'createtorrent.php') as $script) {
                $source = file_get_contents(__DIR__ . '/../../plugins/create/' . $script);
                $this->assertTrue(strpos($source, 'CreateSeeding::publish(') !== false,
                    $script . ' uses the shared publisher');
            }
        } finally {
            @unlink($dir . '/uploaded.torrent');
            @rmdir($dir);
        }
    }
}
