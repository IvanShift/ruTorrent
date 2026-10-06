<?php
require_once( '../../php/util.php' );
require_once( '../../php/settings.php' );
eval(FileUtil::getPluginConf("_getdir"));

function compareEntries( $a, $b )
{
	if($a=='..')
		return( -1 );
	if($b=='..')
		return( 1 );
	return( strcmp(mb_strtolower($a), mb_strtolower($b)) );
}

$theSettings = rTorrentSettings::get();
// Compare physical paths on both sides when the configured root is an alias.
$resolvedTop = @realpath($topDirectory);
if($resolvedTop !== false)
	$topDirectory = $resolvedTop;

$requestedDir = $_REQUEST['dir'] ?? null;

if(is_string($requestedDir) && strlen($requestedDir))
{
	$dir = rawurldecode($requestedDir);
	if(
			strpos($dir, "\0") !== false || !$theSettings->correctDirectory($dir, true) ||
			(($theSettings->uid>=0) && $checkUserPermissions && !Permission::doesUserHave($theSettings->uid,$theSettings->gid,$dir,0x0007))
		)
	{
		$dir = $topDirectory;
	}
}
else
{
	$dir = User::isLocalMode() ? $theSettings->directory : $topDirectory;
	if (!$theSettings->correctDirectory($dir, true))
		$dir = $topDirectory;
}

$dir = FileUtil::addslash($dir);
$items = array_diff(scandir($dir, SCANDIR_SORT_NONE), ((rtrim($dir, '/') === rtrim($topDirectory, '/')) ? ["..", "."] : ["."]));
$directories = array_filter($items, function ($item) {
	global $dir, $theSettings, $checkUserPermissions;
	$path = $dir.$item;
	return is_dir($path) && is_readable($path) && $theSettings->correctDirectory($path, true) &&
		($theSettings->uid<0 || !$checkUserPermissions || Permission::doesUserHave($theSettings->uid,$theSettings->gid,$path,0x0007));
});
$files = (isset($_REQUEST['withfiles']) && $_REQUEST['withfiles'] === '1') ? array_filter($items, function ($item) {
	global $dir, $theSettings;
	$path = $dir.$item;
	return is_file($path) && is_readable($path) && $theSettings->correctDirectory($path, true);
}) : array();

usort($directories, "compareEntries");
usort($files, "compareEntries");

header("Content-Type: application/json");
echo JSON::safeEncode(array(
	"path" => $dir,
	"directories" => array_values($directories),
	"files" => array_values($files),
));
?>
