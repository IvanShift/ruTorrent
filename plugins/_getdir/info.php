<?php
require_once( '../../php/util.php' );
require_once( '../../php/settings.php' );
require_once(__DIR__.'/directorylist.php');
eval(FileUtil::getPluginConf("_getdir"));

$output = array();
if(isset($_REQUEST['mode']) && is_string($_REQUEST['mode']))
{
	$modes = explode(';',$_REQUEST['mode']);
	$theSettings = rTorrentSettings::get();
	if(in_array("labels",$modes))
	{
		$req = new rXMLRPCRequest( new rXMLRPCCommand("d.multicall", array("",getCmd("d.get_custom1="))) );
		$labels = array();
		if($req->run() && !$req->fault)
		{
			for($i=0; $i<count($req->val); $i++)
			{
				$val = LabelValue::fromEncoded($req->val[$i]);
				$key = is_array($val) ? 'b:'.$val['labelKey'] : 's:'.$val;
				if($key !== 's:' && $key !== 'b:') $labels[$key] = $val;
			}
			uasort($labels, function($left, $right) {
				return strnatcasecmp(is_array($left) ? $left['name'] : $left,
					is_array($right) ? $right['name'] : $right);
			});
			$output["labels"] = array_values($labels);
		}
	}
	if(in_array("dirlist",$modes))
	{
		$dir = null;
		if(isset($_REQUEST['basedir']) && is_string($_REQUEST['basedir']))
		{
			$dir = $_REQUEST['basedir'];
			$allowedDirectory = strpos($dir, "\0") === false && $theSettings->correctDirectory($dir, true, true);
			if(!$allowedDirectory ||
				(($theSettings->uid>=0) &&
				$checkUserPermissions &&
				!Permission::doesUserHave($theSettings->uid,$theSettings->gid,$dir,0x0007)))
				$dir = null;
		}
		if($dir === null)
		{
			$dir = User::isLocalMode() ? $theSettings->directory : $topDirectory;
			if(!$theSettings->correctDirectory($dir, true, true))
				$dir = $topDirectory;
		}
		$files = array();
		$dir = FileUtil::addslash($dir);
		$entries = rDirectoryList::scan($dir);
		if($entries === null)
			$output['error'] = 'directory-unavailable';
		else
		{
			foreach($entries as $file)
			{
				$path = FileUtil::fullpath($dir . $file);
				if(($file=="..") && (rtrim($dir, '/') === rtrim($topDirectory, '/')))
					continue;
				if(is_dir($path) && is_readable($path) &&
					$theSettings->correctDirectory($path, true) &&
					( $theSettings->uid<0 || !$checkUserPermissions || Permission::doesUserHave($theSettings->uid,$theSettings->gid,$path,0x0007) )
					)
				{
					$files[] = $file;
	}
			}
		}
		usort($files, array('rDirectoryList', 'compare'));
		$output["basedir"] = FileUtil::displayPath(FileUtil::fullpath($dir));
		$output["dirlist"] = array_map(array('FileUtil', 'displayPath'), $files);
        }
	if(in_array("channels",$modes) && $theSettings->isPluginRegistered('throttle'))
	{
		require_once( '../throttle/throttle.php' );
		$trt = rThrottle::load();
		$tnames = array();
		foreach( $trt->thr as $thr )
			$tnames[] = $thr["name"];
		$output["channels"]  = $tnames;
	}
	if(in_array("ratios",$modes) && $theSettings->isPluginRegistered('ratio'))
	{
		require_once( '../ratio/ratio.php' );
		$rat = rRatio::load();
		$rnames = array();
		foreach( $rat->rat as $r )
			$rnames[] = $r["name"];
		$output["ratios"] = $rnames;
	}
}

CachedEcho::send(JSON::safeEncode($output),"application/json");
