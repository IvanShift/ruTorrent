<?php

require_once( dirname(__FILE__).'/../_task/task.php' );
require_once( dirname(__FILE__).'/../../php/rtorrent.php' );
eval( FileUtil::getPluginConf( 'mediainfo' ) );

class mediainfoSettings
{
	public $hash = "mediainfo.dat";
	public $modified = false;
	public $data = array();
	static public function load()
	{
		$cache = new rCache();
		$rt = new mediainfoSettings();
		return( $cache->get($rt) ? $rt : null );
	}
}

$ret = array( "status"=>255, "errors"=>array("Can't retrieve information") );
$input = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;

if(isset($input['hash']) &&
	isset($input['no']) &&
	isset($input['cmd']))
{
	switch($input['cmd'])
	{
		case "mediainfo":
		{
			Requests::requirePost();
			$filename = rTorrent::getFilePath($input['hash'], $input['no']);
			if($filename !== false)
			{
				$commands = array();
				$flags = '';
				$st = mediainfoSettings::load();
				$task = new rTask( array
				(
					'arg' => FileUtil::getFileName($filename),
					'requester'=>'mediainfo',
					'name'=>'mediainfo',
					'hash'=>$input['hash'],
					'no'=>$input['no']
				) );
				if($st && !empty($st->data["mediainfousetemplate"]))
				{
					$randName = $task->makeDirectory()."/opts";
					file_put_contents( $randName, $st->data["mediainfotemplate"] );
					$flags = "--Inform=file://".escapeshellarg($randName);
				}
				$commands[] = Utility::getExternal("mediainfo")." ".$flags." ".escapeshellarg($filename);
				$ret = $task->start($commands, 0);
			}
			break;
		}
	}
}

CachedEcho::send(JSON::safeEncode($ret),"application/json");
