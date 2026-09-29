<?php

require_once( dirname(__FILE__).'/../_task/task.php' );
require_once( dirname(__FILE__).'/../../php/rtorrent.php' );
require_once( 'ffmpeg.php' );
eval( FileUtil::getPluginConf( 'screenshots' ) );

$ret = array();
$input = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
if(isset($input['cmd']))
{
	$st = ffmpegSettings::load();
	switch($input['cmd'])
	{
		case "ffmpeg":
		{
			Requests::requirePost();
			if(isset($input['hash']) &&
				isset($input['no']))
			{
				$filename = rTorrent::getFilePath($input['hash'], $input['no']);
				if($filename !== false)
				{
					$commands = array();
					$offs = $st->data['exfrmoffs'];
					$useWidth = $st->data['exusewidth'];
					for($i=0; $i<$st->data['exfrmcount']; $i++)
					{
						$name = '"${dir}"/frame'.$i.($st->data['exformat'] ? '.png' : '.jpg');
						$commands[] = Utility::getExternal("ffmpeg").
							' -ss '.$offs.
							' -i '.escapeshellarg($filename).
							' -y'.
							' -vframes 1'.
							' -an'.
							' -sn'.
							' -vf "scale=\'max(sar,1)*iw\':\'max(1/sar,1)*ih\''.($useWidth ? ',scale='.$st->data['exfrmwidth'].':-1' : '').'" '.
							$name;
						$commands[] = '{';
						$commands[] = '>'.$i;
						$commands[] = '}';
						$offs += $st->data['exfrminterval'];
					}
					$commands[] = 'chmod a+r "${dir}"/frame*.*';
					$task = new rTask( array
					(
						'arg' => FileUtil::getFileName($filename),
						'requester'=>'screenshots',
						'name'=>'ffmpeg',
						'hash'=>$input['hash'],
						'no'=>$input['no']
					));
					$ret = $task->start($commands, rTask::FLG_NO_ERR);
				}
			}
			break;
		}
		case "ffmpeggetall":
		{
			$dir = rTask::formatPath( $input['no'] );
			if(@chdir( $dir ))
			{
				$randName = FileUtil::getTempFilename('screenshots-detail');
				exec(escapeshellarg(Utility::getExternal('tar'))." -cf ".$randName." *.".($st->data['exformat'] ? 'png' : 'jpg'),$results,$return);
				if(is_file($randName))
				{
					SendFile::send( $randName, "application/x-tar",  $input['file'].'.tar', false );
					unlink($randName);
					exit();
				}
			}
			header('HTTP/1.0 404 Not Found');
			exit();
		}
		case "ffmpeggetimage":
		{
			$dir = rTask::formatPath( $input['no'] );
			$ext = ($st->data['exformat'] ? '.png' : '.jpg');
			$filename = ffmpegSettings::frameName( $dir, $input['fno'], $st->data['exformat'] );
			SendFile::send($filename, $st->data['exformat'] ? 'image/png' : 'image/jpeg', $input['file']."-".str_pad($input['fno']+1, 3, "0", STR_PAD_LEFT).$ext);
			exit();
		}
		case "ffmpegset":
		{
			Requests::requirePost();
			$ret = $st->set();
			break;
		}
	}
}

CachedEcho::send(JSON::safeEncode($ret),"application/json");
