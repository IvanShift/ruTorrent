<?php

require_once __DIR__ . '/../rutracker_check/TestLib.php';

class FileUtil
{
    public static $settings;
    public static $profile;
    public static $logs = array();
    public static function getSettingsPathEx($user = null) { return self::$settings; }
    public static function getProfilePathEx($user = null) { return self::$profile; }
    public static function toLog($message) { self::$logs[] = $message; }
}
class User
{
    public static $user = '';
    public static function getUser() { return self::$user; }
}
class DataDirMoveIntent
{
    public static function trustedDirectory($path)
    {
        return realpath($path) === $path && is_dir($path) && (fileperms($path) & 0022) === 0;
    }
}
class DataDirMoveJob
{
    public static function journalDirectory($path)
    {
        if (!file_exists($path) && !is_link($path)) mkdir($path, 0700);
        if (!is_dir($path) || is_link($path) || (fileperms($path) & 0077) !== 0)
            throw new RuntimeException('job-directory-untrusted');
        return realpath($path);
    }
}

$source = __DIR__ . '/../../../plugins/datadir/util_setdir.php';
eval(loadFunctionDefinition($source, 'rtDataDirJournalParent'));
eval(loadFunctionDefinition($source, 'rtDataDirJournalRoot'));
eval(loadFunctionDefinition($source, 'rtDataDirRecoveryProfiles'));
eval(loadFunctionDefinition($source, 'rtDataDirJournal'));

$suite = new StrictTestSuite();
$suite->test('fresh Docker writable share uses its trusted parent for private jobs', function () {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/datadir-docker-root-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root . '/share', 0775);
    mkdir($root . '/share/settings', 0775);
    chmod($root . '/share', 0775);
    chmod($root . '/share/settings', 0775);
    FileUtil::$settings = $root . '/share/settings';
    FileUtil::$profile = $root . '/share';
    User::$user = 'torrent';
    try {
        $journal = rtDataDirJournal();
        strictAssertSame($root . '/datadir-jobs/torrent', $journal,
            'journal authority is outside the group-writable profile share');
        strictAssertSame(0700, fileperms($journal) & 0777,
            'private profile journal remains owner-only');
        strictAssertSame(array(), rtDataDirRecoveryProfiles(),
            'empty protected journal does not start a recovery worker');
        file_put_contents($journal . '/' . str_repeat('A', 40) . '.json', '{}');
        chmod($root . '/share', 0755);
        chmod($root . '/share/settings', 0755);
        strictAssertSame(array('torrent'), rtDataDirRecoveryProfiles(),
            'permission repair must not hide a pending job in the existing root');
        mkdir($root . '/share/settings/datadir-jobs', 0700);
        try {
            rtDataDirJournal();
            throw new RuntimeException('expected split roots refusal');
        } catch (RuntimeException $error) {
            strictAssertSame('split-journal-roots', $error->getMessage(),
                'two possible roots hold visibly rather than selecting one silently');
        }
        rmdir($root . '/share/settings/datadir-jobs');
        chmod($root, 0775);
        try {
            rtDataDirJournal();
            throw new RuntimeException('expected untrusted parent refusal');
        } catch (RuntimeException $error) {
            strictAssertSame('journal-parent-untrusted', $error->getMessage(),
                'another UID with group write access cannot own the journal parent');
        }
    } finally {
        User::$user = '';
        chmod($root, 0700);
        @unlink($root . '/datadir-jobs/torrent/' . str_repeat('A', 40) . '.json');
        @rmdir($root . '/share/settings/datadir-jobs');
        @rmdir($root . '/datadir-jobs/torrent');
        @rmdir($root . '/datadir-jobs');
        @rmdir($root . '/share/settings');
        @rmdir($root . '/share');
        @rmdir($root);
    }
});

$suite->test('untrusted legacy journal is held, then reused only when its parent is trusted', function () {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/datadir-legacy-root-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root . '/share', 0775);
    mkdir($root . '/share/settings', 0775);
    mkdir($root . '/share/settings/datadir-jobs', 0700);
    chmod($root . '/share', 0775);
    chmod($root . '/share/settings', 0775);
    FileUtil::$settings = $root . '/share/settings';
    FileUtil::$profile = $root . '/share';
    User::$user = 'alice';
    try {
        try {
            rtDataDirJournal();
            throw new RuntimeException('expected untrusted legacy refusal');
        } catch (RuntimeException $error) {
            strictAssertSame('legacy-journal-untrusted', $error->getMessage(),
                'group-writable legacy authority is not adopted or hidden');
        }
        strictAssertSame(false, file_exists($root . '/datadir-jobs'),
            'no competing fallback root is created while legacy jobs are held');
        chmod($root . '/share', 0755);
        chmod($root . '/share/settings', 0755);
        strictAssertSame($root . '/share/settings/datadir-jobs/alice', rtDataDirJournal(),
            'a trusted existing legacy root keeps its prior jobs discoverable');
    } finally {
        User::$user = '';
        @rmdir($root . '/share/settings/datadir-jobs/alice');
        @rmdir($root . '/share/settings/datadir-jobs');
        @rmdir($root . '/share/settings');
        @rmdir($root . '/share');
        @rmdir($root);
    }
});

$suite->test('trusted settings symlink keeps existing journal despite untrusted profile parent', function () {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/datadir-settings-link-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root . '/profile-parent', 0775);
    chmod($root . '/profile-parent', 0775);
    mkdir($root . '/profile-parent/profile', 0700);
    mkdir($root . '/secure-settings', 0700);
    symlink($root . '/secure-settings', $root . '/profile-parent/profile/settings');
    mkdir($root . '/secure-settings/datadir-jobs', 0700);
    mkdir($root . '/secure-settings/datadir-jobs/alice', 0700);
    $job = $root . '/secure-settings/datadir-jobs/alice/' . str_repeat('A', 40) . '.json';
    file_put_contents($job, '{}');
    FileUtil::$profile = $root . '/profile-parent/profile';
    FileUtil::$settings = FileUtil::$profile . '/settings';
    User::$user = 'alice';
    try {
        strictAssertSame($root . '/secure-settings/datadir-jobs/alice', rtDataDirJournal(),
            'canonical trusted settings keep their existing journal');
        strictAssertSame(array('alice'), rtDataDirRecoveryProfiles(),
            'global recovery still sees the pending job');
        strictAssertSame(false, file_exists($root . '/profile-parent/datadir-jobs'),
            'untrusted profile parent never receives a competing root');
    } finally {
        User::$user = '';
        @unlink($job);
        @rmdir($root . '/secure-settings/datadir-jobs/alice');
        @rmdir($root . '/secure-settings/datadir-jobs');
        @unlink($root . '/profile-parent/profile/settings');
        @rmdir($root . '/profile-parent/profile');
        @rmdir($root . '/secure-settings');
        @rmdir($root . '/profile-parent');
        @rmdir($root);
    }
});

$suite->test('one canonical root is not mistaken for two roots', function () {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/datadir-same-root-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root . '/profile', 0700);
    symlink($root, $root . '/profile/settings');
    FileUtil::$profile = $root . '/profile';
    FileUtil::$settings = FileUtil::$profile . '/settings';
    User::$user = 'alice';
    try {
        $journal = rtDataDirJournal();
        strictAssertSame($root . '/datadir-jobs/alice', $journal,
            'identical legacy and private paths name one authority');
        file_put_contents($journal . '/' . str_repeat('A', 40) . '.json', '{}');
        strictAssertSame(array('alice'), rtDataDirRecoveryProfiles(),
            'scanner finds the single canonical journal');
    } finally {
        User::$user = '';
        @unlink($root . '/datadir-jobs/alice/' . str_repeat('A', 40) . '.json');
        @rmdir($root . '/datadir-jobs/alice');
        @rmdir($root . '/datadir-jobs');
        @unlink($root . '/profile/settings');
        @rmdir($root . '/profile');
        @rmdir($root);
    }
});

$suite->test('empty and literal default users have distinct journal directories', function () {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/datadir-profile-map-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root . '/share', 0775);
    mkdir($root . '/share/settings', 0775);
    chmod($root . '/share', 0775);
    chmod($root . '/share/settings', 0775);
    FileUtil::$settings = $root . '/share/settings';
    FileUtil::$profile = $root . '/share';
    try {
        User::$user = '';
        $anonymous = rtDataDirJournal();
        User::$user = 'default';
        $named = rtDataDirJournal();
        strictAssertTrue($anonymous !== $named,
            'empty and literal default profiles cannot share a journal');
        strictAssertSame('default', basename($named), 'literal login keeps its name');
        strictAssertSame('~default', basename($anonymous), 'empty profile has reserved name');
    } finally {
        User::$user = '';
        @rmdir($root . '/datadir-jobs/~default');
        @rmdir($root . '/datadir-jobs/default');
        @rmdir($root . '/datadir-jobs');
        @rmdir($root . '/share/settings');
        @rmdir($root . '/share');
        @rmdir($root);
    }
});

$suite->test('global recovery selects only protected profiles that hold a real job', function () {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/datadir-profiles-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    mkdir($root . '/share', 0775);
    mkdir($root . '/share/settings', 0775);
    chmod($root . '/share', 0775);
    chmod($root . '/share/settings', 0775);
    mkdir($root . '/datadir-jobs', 0700);
    mkdir($root . '/datadir-jobs/alice', 0700);
    mkdir($root . '/datadir-jobs/~default', 0700);
    mkdir($root . '/datadir-jobs/default', 0700);
    mkdir($root . '/datadir-jobs/empty', 0700);
    mkdir($root . '/datadir-jobs/orphan', 0700);
    mkdir($root . '/datadir-jobs/unsafe', 0777);
    chmod($root . '/datadir-jobs/unsafe', 0777);
    symlink($root . '/datadir-jobs/alice', $root . '/datadir-jobs/foreign');
    file_put_contents($root . '/datadir-jobs/alice/' . str_repeat('A', 40) . '.json', '{}');
    file_put_contents($root . '/datadir-jobs/~default/' . str_repeat('D', 40) . '.json', '{}');
    file_put_contents($root . '/datadir-jobs/default/' . str_repeat('E', 40) . '.json', '{}');
    file_put_contents($root . '/datadir-jobs/unsafe/' . str_repeat('B', 40) . '.json', '{}');
    file_put_contents($root . '/datadir-jobs/orphan/' . str_repeat('C', 40) . '.json.move', '{}');
    FileUtil::$settings = $root . '/share/settings';
    FileUtil::$profile = $root . '/share';
    FileUtil::$logs = array();
    try {
        strictAssertSame(array('alice', 'default', 'orphan', '~default'), rtDataDirRecoveryProfiles(),
            'only protected profile with a job gets a child worker');
        strictAssertTrue(strpos(implode(' ', FileUtil::$logs), 'unsafe') !== false,
            'unsafe profile is visibly skipped');
        strictAssertTrue(strpos(implode(' ', FileUtil::$logs), 'foreign') !== false,
            'symlink profile is visibly skipped');
    } finally {
        @unlink($root . '/datadir-jobs/alice/' . str_repeat('A', 40) . '.json');
        @unlink($root . '/datadir-jobs/~default/' . str_repeat('D', 40) . '.json');
        @unlink($root . '/datadir-jobs/default/' . str_repeat('E', 40) . '.json');
        @unlink($root . '/datadir-jobs/unsafe/' . str_repeat('B', 40) . '.json');
        @unlink($root . '/datadir-jobs/orphan/' . str_repeat('C', 40) . '.json.move');
        @unlink($root . '/datadir-jobs/foreign');
        @rmdir($root . '/datadir-jobs/alice');
        @rmdir($root . '/datadir-jobs/~default');
        @rmdir($root . '/datadir-jobs/default');
        @rmdir($root . '/datadir-jobs/empty');
        @rmdir($root . '/datadir-jobs/orphan');
        @rmdir($root . '/datadir-jobs/unsafe');
        @rmdir($root . '/datadir-jobs');
        @rmdir($root . '/share/settings');
        @rmdir($root . '/share');
        @rmdir($root);
    }
});
exit($suite->run());
