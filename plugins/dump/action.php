<?php
require_once( dirname(__FILE__).'/../_task/task.php' );
require_once( dirname(__FILE__).'/../../php/rtorrent.php' );
eval( FileUtil::getPluginConf( 'dump' ) );

$ret = array( "status"=>255, "errors"=>array("Can't retrieve information") );

$input = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
if(isset($input['hash'], $input['cmd']))
{
	switch($input['cmd'])
	{
		case "dumptorrent":
		{
			Requests::requirePost();
			$hash = $input['hash'];
			$identity = is_string($hash) && preg_match('/\A[0-9a-f]{40}\z/i', $hash)
				? strtoupper($hash) : 'invalid-hash';
			$fname = is_string($hash) ? rTorrent::getSourcePath($hash) : false;
			if($fname===false)
			{
				$ret['errors'] = array('Source torrent is unavailable');
				FileUtil::toLog('dump: source refused: '.$identity.' source-unavailable');
				break;
			}
			if(strcasecmp(basename($fname), $hash.'.meta') === 0)
			{
				$validatedPath = null;
				if(rTorrent::getSource($hash, $validatedPath) === false)
				{
					$reason = $validatedPath === false ? 'source-unavailable' : 'source-invalid';
					$ret['errors'] = array('Magnet metadata is missing or invalid');
					FileUtil::toLog('dump: source refused: '.$identity.' '.$reason);
					break;
				}
				$fname = $validatedPath;
			}
			$dumpArguments = $arguments;
			if(strcasecmp(basename($fname), $hash.'.meta') === 0)
			{
				// Raw BEP-9 info has no announce; dumptorrent's raw mode can inspect it.
				if(isset($rawMetadataArguments))
					$dumpArguments = $rawMetadataArguments;
				else
				{
					// Keep configured flags while replacing dumptorrent's verbose mode.
					$dumpArguments = preg_replace('/(?<!\S)-v(?!\S)/', '-d', $arguments, 1, $replaced);
					if(!$replaced)
						$dumpArguments = trim($arguments.' -d');
				}
			}
			$commands = array();
			$task = new rTask(array('requester'=>'dump', 'name'=>'dump'));
			$commands[] = Utility::getExternal("dumptorrent")." ".$dumpArguments." ".escapeshellarg($fname);
			$ret = $task->start($commands, rTask::FLG_DO_NOT_TRIM);
		}
	}
}

CachedEcho::send(JSON::safeEncode($ret),"application/json");
