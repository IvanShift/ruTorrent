<?php
// Relevant lines from the configuration persisted before the 2026-09 fixes.
$profileMask = $_ENV['RU_PROFILE_MASK'] ?? 0777;
$profilePath = $_ENV['RU_PROFILE_PATH'] ?? '../../share';
$forbidUserSettings = false;
$enableCSRFCheck = false;
$al_diagnostic = true;
$locale = 'UTF8';
$localhosts = array('::1', '127.0.0.1', 'localhost');
getenv('RU_LOCALHOSTS') && $localhosts[] = $_ENV['RU_LOCALHOSTS'];
$configLocalPath = __DIR__.'/config.local.php';
if(is_file($configLocalPath) && is_readable($configLocalPath))
    require($configLocalPath);
