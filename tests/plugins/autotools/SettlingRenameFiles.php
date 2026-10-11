<?php

// A rename that reports success while the next lookups of its new name still
// answer ENOENT. drvfs over 9p with cache=0x5 (the owner's Docker Desktop
// volumes) did exactly that once on 2026-10-11: lstat of the new name failed
// right after a successful rename, and the name was there a moment later.
//
// The pattern of ErasedataSettlingRenameFixture
// (tests/plugins/erasedata/CollectorFixture.php), without that file's collector
// stubs, for the AutoTools and datadir suites. A renameNoReplace() destination
// matching $pattern is renamed to a hidden sibling instead; the entryIdentity()
// lookup number $revealAt of the real name moves that same inode into place,
// or with $foreign a different inode holding the same bytes. hide() does the
// same for a name production renamed with plain rename(). Every lookup of such
// a name is counted, and with $countFile the counts reach a parent process.
//
// With self::$helper set, the no-replace rename runs the real shared runner
// through that scripted helper (an AutoTools worker child); without it, it is
// the plain lstat-then-rename the datadir fixtures use.
require_once dirname(__DIR__, 3) . '/plugins/erasedata/filesystem.php';

class SettlingRenameFiles extends ErasedataFilesystemOps
{
    public static $helper = null;
    public $lookups = array();
    private $pattern;
    private $revealAt;
    private $foreign;
    private $countFile;
    private $pending = array();

    public function __construct($pattern, $revealAt, $foreign = false, $countFile = null)
    {
        $this->pattern = $pattern;
        $this->revealAt = $revealAt;
        $this->foreign = $foreign;
        $this->countFile = $countFile;
    }

    public static function renameHelperPath()
    {
        return self::$helper !== null ? self::$helper : parent::renameHelperPath();
    }

    public static function hiddenName($path)
    {
        return dirname($path) . '/.settling-' . basename($path);
    }

    private function flush()
    {
        if ($this->countFile !== null)
            file_put_contents($this->countFile, json_encode($this->lookups));
    }

    private function track($path)
    {
        $this->pending[$path] = true;
        $this->lookups[$path] = 0;
        $this->flush();
        return true;
    }

    // Hide a name that production has just renamed itself.
    public function hide($path)
    {
        return @rename($path, self::hiddenName($path)) && $this->track($path);
    }

    private function noReplace($from, $to)
    {
        if (self::$helper !== null)
            return parent::renameNoReplace($from, $to);
        return @lstat($to) === false && @rename($from, $to);
    }

    public function renameNoReplace($from, $to)
    {
        if (!preg_match($this->pattern, $to))
            return $this->noReplace($from, $to);
        if (erasedataPathExists($to))
            return false;
        return $this->noReplace($from, self::hiddenName($to)) && $this->track($to);
    }

    // Settle by hand, as a later lookup on the real mount would.
    public function settle($path)
    {
        if (!isset($this->pending[$path]))
            return false;
        unset($this->pending[$path]);
        $hidden = self::hiddenName($path);
        if (!$this->foreign)
            return @rename($hidden, $path);
        $stat = lstat($hidden);
        return file_put_contents($path, file_get_contents($hidden)) !== false
            && chmod($path, $stat['mode'] & 07777);
    }

    public function entryIdentity($path)
    {
        if (isset($this->lookups[$path])) {
            $this->lookups[$path]++;
            $this->flush();
        }
        if (isset($this->pending[$path]) && $this->lookups[$path] >= $this->revealAt)
            $this->settle($path);
        return parent::entryIdentity($path);
    }
}
