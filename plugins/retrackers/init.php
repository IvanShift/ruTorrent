<?php

// update.php reaches rTorrent::quoteCommandArg() while it builds the insert
// grammar, i.e. at plugin-init time, and nothing on the getplugins.php path has
// loaded that class by then. ruTorrent's autoloader (php/util.php) looks for a
// class under php/utility/<lowercase>.php, and rTorrent lives in php/rtorrent.php,
// so it can never resolve this one: the include fails and the whole page 500s.
// getplugins.php is the only thing that re-creates plugin schedules, so that
// turns one plugin's init into a silent, fleet-wide stop.
require_once( dirname(__FILE__)."/../../php/rtorrent.php");

define('RETRACKERS_IMPORT_ONLY', true);
require_once( dirname(__FILE__)."/update.php");
require_once( dirname(__FILE__)."/retryreset.php");
require_once( dirname(__FILE__)."/applyexisting.php");
require_once( dirname(__FILE__)."/retrackers.php");

$script = $rootPath.'/plugins/retrackers/run.sh';
$php = Utility::getPHP();
$user = User::getUser();

$failure = null;
$retrackersAdapter = new RetrackersLifecycleRpcAdapter();
$ok = retrackersRunLifecycleInit($user, $script, $php, $jResult, $failure, $retrackersAdapter);
// A failed init may retain a daemon hook; getplugins can also disable us
// after successful init if its deferred daemon PHP check fails. Preserve
// the guarded done path in either case.
$theSettings->registerPlugin($plugin["name"],$pInfo["perms"]);
$jResult .= "plugin.shutdownWhileDisabled = true;";
// Jobs in flight only postpone a lifecycle change and leave the installed
// hooks untouched, so this session keeps the plugin after the visible notice.
if($ok || $failure === 'recovery-in-flight')
{
	$trks = rRetrackers::load();
	$jResult.=$trks->get();
	// Exhausted queue jobs are never retried on their own; init.js tells the
	// user once per session (one rows read, on the native queue only).
	$jResult.=RetrackersRetryReset::sessionNotice($retrackersAdapter, $user);
	$jResult.=RetrackersApplyExisting::sessionNotice($retrackersAdapter, $user);
}
else
	$jResult .= "plugin.disable(); noty('retrackers: '+theUILang.pluginCantStart,'error');";
