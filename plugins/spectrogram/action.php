<?php

require_once( dirname(__FILE__).'/../_task/task.php' );
eval( FileUtil::getPluginConf( 'spectrogram' ) );

$ret = array();
$input = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
if(isset($input['cmd']))
{
	switch($input['cmd'])
	{
		case "sox":
		{
			Requests::requirePost();
			if(isset($input['hash']) &&
				isset($input['no']))
			{
				$req = new rXMLRPCRequest( new rXMLRPCCommand( "f.get_frozen_path", array($input['hash'],intval($input['no']))) );
				if($req->success())
				{
					$filename = $req->val[0];
					if($filename=='')
					{
						$req = new rXMLRPCRequest( array(
							new rXMLRPCCommand( "d.open", $input['hash'] ),
							new rXMLRPCCommand( "f.get_frozen_path", array($input['hash'],intval($input['no'])) ),
							new rXMLRPCCommand( "d.close", $input['hash'] ) ) );
						if($req->success())
							$filename = $req->val[1];
					}
					if($filename!=='')
					{
						$mediafile = basename($filename);
						$commands = array();
						$name = '"${dir}"/frame.png';
						$commands[] = Utility::getExternal("sox").
								" ".escapeshellarg($filename)." ".
								implode( " ", array_map( "escapeshellarg", explode(" ",$arguments) ) ).
								' -t '.escapeshellarg($mediafile).' -o '.
								$name;
						$commands[] = '{';
						$commands[] = '>-=*=-';
						$commands[] = '}';
						$commands[] = 'chmod a+r "${dir}"/frame.png';
						$task = new rTask( array
						(
						        'arg' => FileUtil::getFileName($filename),
							'requester'=>'spectrogram',
							'name'=>'sox',
							'hash'=>$input['hash'],
							'no'=>$input['no']
						) );
						$ret = $task->start($commands, rTask::FLG_ONE_LOG | rTask::FLG_STRIP_LOGS);
					}
				}
			}
			break;
		}
		case "soxgetimage":
		{
			$dir = rTask::formatPath( $input['no'] );
			$filename = $dir.'/frame.png';
			SendFile::send($filename, 'image/png', $input['file'].".png");
			exit();
		}
	}
}

CachedEcho::send(JSON::safeEncode($ret),"application/json");
