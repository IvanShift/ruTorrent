<?php

require_once('stat.php');
eval(FileUtil::getPluginConf('trafic'));

$request = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

$ret = null;
$storages = [ "global.csv" ];

if (isset($request['tracker'])) {
    if ($request['tracker'] == "none") {
        if (!isset($HTTP_RAW_POST_DATA)) {
            $HTTP_RAW_POST_DATA = file_get_contents("php://input");
        }
        $tstorages = [];
        $vars = explode('&', $HTTP_RAW_POST_DATA);
        foreach ($vars as $var) {
            $parts = explode("=", $var);
            if (($parts[0] == "hash") && rStat::isStorageName($parts[1])) {
                $tstorages[] = 'torrents/' . $parts[1] . ".csv";
            }
        }
        if (count($tstorages)) {
            $storages = $tstorages;
        }
    } elseif (($request['tracker'] != "global") && rStat::isStorageName($request['tracker'])) {
        $storages = [ "trackers/" . $request['tracker'] . ".csv" ];
    }
}

function sum($e1, $e2)
{
    return($e1 + $e2);
}

if (isset($request['mode'])) {
    $mode = $request['mode'];
    if ($mode == 'clear') {
        Requests::requirePost();
        if (!$disableClearButton) {
            foreach ($storages as $storage) {
                @unlink(FileUtil::getSettingsPath() . '/trafic/' . $storage);
            }
        }
        if ($request['tracker'] != "none") {
            $storages = [ "global.csv" ];
        }
        $mode = 'day';
    }
    $ret = [];
    foreach ($storages as $storage) {
        $st = new rStat($storage);
        if ($mode == 'day') {
            $val = $st->getDay();
        } elseif ($mode == 'month') {
            $val = $st->getMonth();
        } elseif ($mode == 'year') {
            $val = $st->getYear();
        }
        if (empty($ret)) {
            $ret = $val;
        } else {
            $ret["up"] = array_map("sum", $val["up"], $ret["up"]);
            $ret["down"] = array_map("sum", $val["down"], $ret["down"]);
            $ret["labels"] = array_map("max", $val["labels"], $ret["labels"]);
        }
    }
}

CachedEcho::send(JSON::safeEncode($ret), "application/json");
