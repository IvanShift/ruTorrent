<?php

define('RETRACKERS_IMPORT_ONLY', true);
require_once( dirname(__FILE__)."/update.php");

$user = User::getUser();
$failure = null;
retrackersRunLifecycleDone($user, $jResult, $failure);
