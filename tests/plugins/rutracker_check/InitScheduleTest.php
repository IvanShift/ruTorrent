<?php

require_once(__DIR__ . '/TestLib.php');
eval(loadClassDefinition(testFindRepoRoot() . '/php/rtorrent.php', 'rTorrent'));

class FileUtil
{
    public static $log = array();
    static public function getPluginConf($plugin) { return $plugin === 'rutracker_check' ? '$updateInterval = 30;' : ''; }
    static public function addslash($path) { return rtrim($path, '/') . '/'; }
    static public function toLog($message) { self::$log[] = $message; }
}
require_once(testFindRepoRoot().'/php/utility/utility.php');
$pathToExternals = array('php' => "/opt/o'neil/php");
class User { static public function getUser() { return "alice';touch forbidden;'"; } }
class InitScheduleSettings
{
    public $registered = array();
    public function getScheduleCommand($name, $interval, $command, &$startAt = null, $now = null, $daemonClock = false) {
        strictAssertSame(true, $daemonClock, 'the real init protects its aligned slot from stale daemon registration time');
        return new rXMLRPCCommand('schedule', array($name, $interval, $command));
    }
    public function registerPlugin($name, $perms) { $this->registered[] = $name; }
}
function rpcMethodCapability($required)
{
    strictAssertSame(array('execute.raw.bg'), $required, 'init requests the exact background capability');
    $GLOBALS['initCapabilityCalls']++;
    return $GLOBALS['initCapability'];
}

$tree = sys_get_temp_dir() . '/checker-init-' . uniqid();
mkdir($tree . '/plugins/rutracker_check', 0700, true);
mkdir($tree . '/plugins/erasedata', 0700, true);
mkdir($tree . '/php', 0700);
mkdir($tree . '/php/utility', 0700);
file_put_contents($tree . '/php/utility/utility.php', '<?php require_once '
    . var_export(testFindRepoRoot() . '/php/utility/utility.php', true) . ';');
foreach (array('init.php', 'launcher.php') as $file)
    copy(testFindRepoRoot() . '/plugins/rutracker_check/' . $file, $tree . '/plugins/rutracker_check/' . $file);
foreach (array('xmlrpc.php', 'rtorrent.php') as $file) file_put_contents($tree . '/php/' . $file, '<?php');
file_put_contents($tree . '/php/commandargs.php', '<?php require_once ' . var_export(testFindRepoRoot() . '/php/commandargs.php', true) . ';');
file_put_contents($tree . '/plugins/erasedata/removewithdata.php', '<?php
function erasedataInitializationQueueReady() { return true; }
function erasedataCollectorScheduleCommand($settings, $interval) { return new rXMLRPCCommand("collector.schedule"); }
function erasedataRearmDrainSchedule() { $GLOBALS["initDrainRearms"]++; }
');
rTorrentSettings::get()->session = $tree;

function initScheduleRun($tree, $capability)
{
    rTorrentSettings::get()->session = $tree;
    $GLOBALS['initCapability'] = $capability;
    $GLOBALS['initCapabilityCalls'] = 0;
    $GLOBALS['initDrainRearms'] = 0;
    FileUtil::$log = array();
    rXMLRPCRequest::reset();
    $keys = $capability === 'unconfirmed' ? 'collector.schedule' : array('collector.schedule', 'schedule');
    rXMLRPCRequest::queue($keys, true, false, array(0));
    $theSettings = new InitScheduleSettings();
    $plugin = array('name' => 'rutracker_check');
    $pInfo = array('perms' => true);
    $jResult = '';
    include($tree . '/plugins/rutracker_check/init.php');
    return array($theSettings, $jResult);
}

$suite = new StrictTestSuite();
$suite->test('unconfirmed checker launch remains visible and preserves the independent collector', function () use ($tree) {
    list($settings, $ui) = initScheduleRun($tree, 'unconfirmed');
    strictAssertSame(array(), $settings->registered, 'the checker is not registered as ready');
    strictAssertTrue(strpos($ui, 'plugin.disable()') !== false, 'the failed admission is visible');
    strictAssertSame(array('rutracker_check: checker schedule refused reason=rpc-unknown'), FileUtil::$log,
        'the log contains a classified reason and no path or account bytes');
    strictAssertSame(1, $GLOBALS['initCapabilityCalls'], 'capability is sampled only once');
    strictAssertSame(1, $GLOBALS['initDrainRearms'], 'the independent drain is rearmed');
    strictAssertSame(array('collector.schedule'), array_column(rXMLRPCRequest::$requests, 'key'),
        'checker refusal still submits the independent collector schedule');
});
$suite->test('confirmed capability selects a detached command and registers after schedule success', function () use ($tree) {
    foreach (array('available', 'unsupported') as $capability) {
        list($settings, $ui) = initScheduleRun($tree, $capability);
        strictAssertSame(array('rutracker_check'), $settings->registered, 'a successful detached schedule registers');
        strictAssertSame('', $ui, 'successful init has no refusal');
        strictAssertSame(array(), FileUtil::$log, 'successful init does not log account bytes');
        strictAssertSame(1, $GLOBALS['initCapabilityCalls'], 'the capability probe is not replayed');
        $command = rXMLRPCRequest::$requests[0]['commands'][1]->params[2];
        strictAssertSame(RuTrackerDetachedPhp::scheduleCommand(Utility::getPHP(), $tree . '/plugins/rutracker_check/update.php',
            array(User::getUser()), $capability), $command, 'the real init passes the launcher command unchanged');
    }
});
try { $status = $suite->run(); }
finally { strictRemoveTree($tree); }
exit($status);
