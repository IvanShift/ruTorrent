<?php
require_once( 'retrackers.php' );
Requests::requirePost();

$trks = new rRetrackers();
$trks->set();
require_once(__DIR__.'/../../php/rtorrent.php');
define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__.'/update.php');
$jResult = '';
$failure = null;
// Eligibility is part of the installed functional action. Refresh it when
// settings change, so an empty list or an excluded private torrent gets no claim.
retrackersRunLifecycleInit(User::getUser(), $rootPath.'/plugins/retrackers/run.sh',
    Utility::getPHP(), $jResult, $failure, null, $trks);
CachedEcho::send($trks->get().$jResult,"application/javascript");
