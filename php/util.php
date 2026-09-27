<?php

// Older persisted conf/config.php checks getenv() but reads $_ENV. Make the
// setting available from the same source before that configuration runs.
if(!isset($_ENV['RU_LOCALHOSTS']))
{
    $localhostsEnv = getenv('RU_LOCALHOSTS');
    if(($localhostsEnv !== false) && ($localhostsEnv !== ''))
        $_ENV['RU_LOCALHOSTS'] = $localhostsEnv;
}

// Include our base configuration file
$rootPath = realpath(dirname(__FILE__)."/..");
// Avoid reusing $rootPath here becuase it calls realpath
// dirname is a more stable option becuase it's not file system aware
require_once( dirname(__FILE__).'/../conf/config.php' );

// Persisted configs may still pass an octal-looking environment string to
// mkdir/chmod as decimal. Only normalize values matching the raw environment setting.
function rutorrent_normalize_profile_mask($value)
{
    if(($value === null) || ($value === '')) return 0777;
    if(!is_string($value) || !isset($_ENV['RU_PROFILE_MASK']) ||
        ($value !== $_ENV['RU_PROFILE_MASK'])) return $value;
    if(preg_match('/^0?[0-7]{3}$/D', $value)) return intval($value, 8);
    error_log('RU_PROFILE_MASK/profileMask is invalid; using the 0777 default.');
    return 0777;
}
$profileMask = rutorrent_normalize_profile_mask($profileMask ?? null);

// Automatically include only the used utility classes
spl_autoload_register(function ($class)
{
	// Remove namespaces from the classname string
	// Important for compatibility with 3rd party plugins
	$arr = explode('\\',$class);
	$class = end($arr);

	// Suppress include warnings if the user disables al_diagnostic
	// For compatibility with 3rd party plugins which use autoloaders
	global $al_diagnostic;
	if($al_diagnostic)
		include_once 'utility/'. strtolower($class). '.php';
	else
		@include_once 'utility/'. strtolower($class). '.php';
});

// Only allow "POST" or "GET" request methods
// Exit script and send 405 if anther method is tried
Requests::disableUnsupportedMethods();

// For "Cross-Site Request Forgery" checks if enabled
Requests::makeCSRFCheck();

@ini_set('precision',16);
@define('XMLRPC_MAX_I4', 2147483647);
@define('XMLRPC_MIN_I4', ~XMLRPC_MAX_I4);
@define('XMLRPC_MIN_I8', -9.999999999999999E+15);
@define('XMLRPC_MAX_I8', 9.999999999999999E+15);

if(function_exists('ini_set'))
{
	ini_set('display_errors',false);
	// A caller with a classified fatal logger owns its engine log setting.
	// Keep that setting through profile loading as well as base config loading.
	if(!defined('RUTORRENT_FATAL_LOG_IS_MANAGED'))
		ini_set('log_errors',true);
}

if(!isset($_SERVER['REMOTE_USER']))
{
	if(isset($_SERVER['PHP_AUTH_USER']))
		$_SERVER['REMOTE_USER'] = $_SERVER['PHP_AUTH_USER'];
	else
	if(isset($_SERVER['REDIRECT_REMOTE_USER']))
		$_SERVER['REMOTE_USER'] = $_SERVER['REDIRECT_REMOTE_USER'];
}

FileUtil::getProfilePath();	// for creation profile, if it is absent
$conf = FileUtil::getConfFile('config.php');
if($conf)
	require_once($conf);

$profileMask = rutorrent_normalize_profile_mask($profileMask ?? null);
if(!isset($locale))
	$locale = "UTF8";
setlocale(LC_CTYPE, $locale, "UTF-8", "en_US.UTF-8", "en_US.UTF8");
setlocale(LC_COLLATE, $locale, "UTF-8", "en_US.UTF-8", "en_US.UTF8");
