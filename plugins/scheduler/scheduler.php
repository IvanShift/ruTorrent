<?php

require_once(dirname(__FILE__) . "/../../php/xmlrpc.php");
require_once(dirname(__FILE__) . "/../../php/rtorrent.php");
require_once(dirname(__FILE__) . "/../../php/cache.php");
require_once(dirname(__FILE__) . "/../../php/utility/json.php");
eval(FileUtil::getPluginConf('scheduler'));

@define('SCH_FAST', 0);
@define('SCH_STOP', 1);
@define('SCH_SEEDONLY', 2);
@define('SCH_RESTRICT1', 3);
@define('SCH_RESTRICT2', 4);
@define('SCH_RESTRICT3', 5);

class rScheduler
{
    public $hash = "scheduler.dat";
    public $modified = false;
    public $enabled = 0;
    public $UL = [];
    public $DL = [];
    public $week = [];
    public $seedStopped = [];
    public $leechStopped = [];
    // A start runs the CLI and the web plugin init at once, and each applies
    // the schedule to its own copy of this document. The later store used to
    // replace the lists the earlier one had just written, so the downloads
    // that one stopped were never started again. rCache::set() calls merge()
    // when the stored copy changed since this one was loaded; only this
    // request's own changes go into it.
    private $listChanges = ['seedStopped' => [], 'leechStopped' => []];
    private $settingsChanged = false;

    public function __sleep()
    {
        return(['hash', 'modified', 'enabled', 'UL', 'DL', 'week', 'seedStopped', 'leechStopped']);
    }
    // The changes go in in the order of the stores, not of the daemon calls.
    // When two applies change one download the other way round, say a stop
    // hour stops it after a full-speed hour started it but stores first, the
    // later store takes it off the list while it stays stopped. One lock held
    // over load, daemon calls and store would close that. The cache key's own
    // lock cannot be it: rCache::set() takes that lock itself, so it cannot
    // be held across the store; nor can rLock (php/lock.php), which neither
    // waits nor excludes. A separate lock file held with flock(), as
    // rtDataDirLock() holds datadir's, could; this is left as a known
    // limitation.
    public function merge($newer, $unused = null)
    {
        if (!$this->settingsChanged) {
            $this->enabled = $newer->enabled;
            $this->UL = $newer->UL;
            $this->DL = $newer->DL;
            $this->week = $newer->week;
        }
        foreach ($this->listChanges as $list => $changes) {
            $this->{$list} = (array) $newer->{$list};
            $this->changeStopped($list, $changes, null);
        }
        return(true);
    }

    public static function load()
    {
        $cache = new rCache();
        $rt = new rScheduler();
        if (!$cache->get($rt)) {
            $rt->fillWeek();
        }
        return($rt);
    }
    public function fillWeek()
    {
        $this->week = [];
        for ($i = 0; $i < 7; $i++) {
            $this->week[] = array_fill(0, 24, SCH_FAST);
        }
        $this->enabled = 0;
        $this->UL = array_fill(0, 3, 1);
        $this->DL = array_fill(0, 3, 1);
    }
    public function store()
    {
        $cache = new rCache();
        return($cache->set($this));
    }
    public function get()
    {
        $table = [
            "UL" => self::numbers($this->UL),
            "DL" => self::numbers($this->DL),
            "enabled" => intval($this->enabled),
            "week" => [] ];
        foreach ((array) $this->week as $item) {
            $table["week"][] = self::numbers($item);
        }
        return("theWebUI.scheduleTable = " . JSON::jsValue($table) . ";\n");
    }
    public function set()
    {
        $this->fillWeek();
        for ($i = 0; $i < 7; $i++) {
            for ($j = 0; $j < 24; $j++) {
                if (isset($_POST['day_' . $i . "_" . $j])) {
                    $this->week[$i][$j] = intval($_POST['day_' . $i . "_" . $j]);
                }
            }
        }
        if (isset($_POST['UL0'])) {
            $this->UL[0] = intval($_POST['UL0']);
        }
        if (isset($_POST['DL0'])) {
            $this->DL[0] = intval($_POST['DL0']);
        }
        if (isset($_POST['UL1'])) {
            $this->UL[1] = intval($_POST['UL1']);
        }
        if (isset($_POST['DL1'])) {
            $this->DL[1] = intval($_POST['DL1']);
        }
        if (isset($_POST['UL2'])) {
            $this->UL[2] = intval($_POST['UL2']);
        }
        if (isset($_POST['DL2'])) {
            $this->DL[2] = intval($_POST['DL2']);
        }
        if (isset($_POST['enabled'])) {
            $this->enabled = intval($_POST['enabled']);
        }
        $this->settingsChanged = true;
        $this->apply();
    }
    public static function setSpeed($ul, $dl)
    {
        $req = new rXMLRPCRequest(
            [
                new rXMLRPCCommand("set_upload_rate", intval($ul) * 1024),
                new rXMLRPCCommand("set_download_rate", intval($dl) * 1024) ],
        );
        return($req->run() && !$req->fault);
    }
    public static function processArray($hashes, $cmd1, $cmd2)
    {
        $req = new rXMLRPCRequest();
        foreach ($hashes as $hash => $val) {
            $req->addCommand(new rXMLRPCCommand($cmd1, $hash));
            $req->addCommand(new rXMLRPCCommand($cmd2, $hash));
        }
        return($req->run() && !$req->fault);
    }
    // The started downloads, by whether they leech. A started download the
    // daemon has not resumed yet reads "leech" whatever it will do, so its
    // saved d.complete decides (rTorrent::isLeeching()).
    public static function getActiveTorrents()
    {
        $columns = [getCmd("d.get_hash="), getCmd("d.get_connection_current="),
            getCmd("d.get_complete="), getCmd("d.get_hashing=")];
        if (rTorrentSettings::get()->iVersion >= 0x805) {
            $columns[] = getCmd("d.get_custom=") . "sch_ignore";
        }
        $delta = count($columns);
        $req = new rXMLRPCRequest(new rXMLRPCCommand("d.multicall", array_merge(["started"], $columns)));
        $seeds = [];
        $leeches = [];
        if ($req->run() && !$req->fault) {
            for ($i = 0; $i < count($req->val); $i += $delta) {
                $hash = $req->val[$i];
                if (($delta == 4) || empty($req->val[$i + 4])) {
                    if (rTorrent::isLeeching($req->val[$i + 1], $req->val[$i + 2], $req->val[$i + 3])) {
                        $leeches[$hash] = true;
                    } else {
                        $seeds[$hash] = true;
                    }
                }
            }
        }
        return(["seeds" => $seeds,"leeches" => $leeches]);
    }
    public function apply()
    {
        if ($this->enabled) {
            $di = getdate();
            $wDay = $di["wday"] - 1;
            if ($wDay < 0) {
                $wDay = 6;
            }
            $no = $this->week[$wDay][$di["hours"]];
            switch ($no) {
                case SCH_FAST:
                    {
                        self::processArray(array_merge($this->seedStopped, $this->leechStopped), "d.open", "d.start");
                        $this->changeStopped('seedStopped', $this->seedStopped, false);
                        $this->changeStopped('leechStopped', $this->leechStopped, false);
                        self::setSpeed(SCH_DEF_UL, SCH_DEF_DL);
                        break;
                    }
                case SCH_RESTRICT1:
                case SCH_RESTRICT2:
                case SCH_RESTRICT3:
                    {
                        self::processArray(array_merge($this->seedStopped, $this->leechStopped), "d.open", "d.start");
                        $this->changeStopped('seedStopped', $this->seedStopped, false);
                        $this->changeStopped('leechStopped', $this->leechStopped, false);
                        self::setSpeed($this->UL[$no - SCH_RESTRICT1], $this->DL[$no - SCH_RESTRICT1]);
                        break;
                    }
                case SCH_STOP:
                    {
                        $active = self::getActiveTorrents();
                        if (self::processArray(array_merge($active["seeds"], $active["leeches"]), "d.stop", "d.close")) {
                            $this->changeStopped('leechStopped', $active["leeches"], true);
                            $this->changeStopped('seedStopped', $active["seeds"], true);
                        }
                        self::setSpeed(1, 1);
                        break;
                    }
                case SCH_SEEDONLY:
                    {
                        $active = self::getActiveTorrents();
                        if (self::processArray($active["leeches"], "d.stop", "d.close")) {
                            $this->changeStopped('leechStopped', $active["leeches"], true);
                        }
                        self::processArray($this->seedStopped, "d.open", "d.start");
                        $this->changeStopped('seedStopped', $this->seedStopped, false);
                        self::setSpeed(SCH_DEF_UL, 1);
                        break;
                    }
            }
        }
        $this->store();
    }
    // array_values keeps a restored row encoding as a JSON array rather than
    // as an object, whatever keys it came back with.
    protected static function numbers($list)
    {
        return(array_map("intval", array_values((array) $list)));
    }
    // Adds the keys of $hashes to a stopped list, or removes them when $kept
    // is false; null replays the changes $hashes holds.
    private function changeStopped($list, $hashes, $kept)
    {
        foreach ($hashes as $hash => $value) {
            $value = ($kept === null) ? $value : $kept;
            $this->listChanges[$list][$hash] = $value;
            if ($value) {
                $this->{$list}[$hash] = true;
            } else {
                unset($this->{$list}[$hash]);
            }
        }
    }
}
