<?php
require_once( '../../php/util.php' );
require_once( '../../php/settings.php' );
require_once(__DIR__.'/directorylist.php');
eval(FileUtil::getPluginConf("_getdir"));

$theSettings = rTorrentSettings::get();
$requestedDir = $_REQUEST['dir'] ?? null;

if(is_string($requestedDir) && strlen($requestedDir))
{
	$dir = $requestedDir;
	if(
			strpos($dir, "\0") !== false || !$theSettings->correctReportedDirectory($dir, true, true) ||
			(($theSettings->uid>=0) && $checkUserPermissions && !Permission::doesUserHave($theSettings->uid,$theSettings->gid,$dir,0x0007))
		)
	{
		$dir = $topDirectory;
	}
}
else
{
	$dir = User::isLocalMode() ? $theSettings->directory : $topDirectory;
	if (!$theSettings->correctReportedDirectory($dir, true, true))
		$dir = $topDirectory;
}

$dir = FileUtil::addslash($dir);
$items = rDirectoryList::scan($dir);
$error = $items === null;
$items = array_diff($items ?? array(), ((rtrim($dir, '/') === rtrim($topDirectory, '/')) ? ["..", "."] : ["."]));
$directories = array_filter($items, function ($item) {
	global $dir, $theSettings, $checkUserPermissions;
	$path = $dir.$item;
	return is_dir($path) && is_readable($path) && $theSettings->correctReportedDirectory($path, true) &&
		($theSettings->uid<0 || !$checkUserPermissions || Permission::doesUserHave($theSettings->uid,$theSettings->gid,$path,0x0007));
});
$files = (isset($_REQUEST['withfiles']) && $_REQUEST['withfiles'] === '1') ? array_filter($items, function ($item) {
	global $dir, $theSettings;
	$path = $dir.$item;
	return is_file($path) && is_readable($path) && $theSettings->correctReportedDirectory($path, true);
}) : array();

usort($directories, array('rDirectoryList', 'compare'));
usort($files, array('rDirectoryList', 'compare'));

header("Content-Type: application/json");
$output = array(
	"path" => FileUtil::displayPath($dir),
	"directories" => array_map(array('FileUtil', 'displayPath'), $directories),
	"files" => array_map(array('FileUtil', 'displayPath'), $files),
);
if($error)
	$output['error'] = 'directory-unavailable';
echo JSON::safeEncode($output);
?>
