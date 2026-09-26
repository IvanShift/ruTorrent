<?php

define('RETRACKERS_IMPORT_ONLY', true);
require_once( dirname(__FILE__)."/update.php");

$user = User::getUser();
$failure = null;
$pluginDone = retrackersRunLifecycleDone($user, $jResult, $failure);
