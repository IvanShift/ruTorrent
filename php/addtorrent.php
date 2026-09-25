<?php

require_once( 'Snoopy.class.inc');
require_once( 'rtorrent.php' );
require_once( __DIR__ . '/torrentfetch.php' );
set_time_limit(0);

if(isset($_REQUEST['result']))
{
	if(isset($_REQUEST['json']))
		CachedEcho::send( '{ "result" : "'.$_REQUEST['result'][0].'" }',"application/json");
	else
	{
		$js = '';
		foreach( $_REQUEST['result'] as $ndx=>$result )
		{
			$status = in_array($result, array('Success', 'Pending', 'Failed',
				'FailedFile', 'FailedURL', 'FailedDirectory'), true) ? $result : 'Failed';
			$message = $status === 'Pending'
				? '(theUILang.addTorrentPending || "Sent to rTorrent; confirmation pending.")'
				: 'theUILang.addTorrent'.$status;
			$kind = $status === 'Success' ? 'success' : ($status === 'Pending' ? 'warning' : 'error');
			$js.= ('noty("'.(isset($_REQUEST['name'][$ndx]) ? addslashes(rawurldecode(htmlspecialchars($_REQUEST['name'][$ndx]))).' - ' : '').
				'"+'.$message.',"'.$kind.'");');
		}
		CachedEcho::send($js,"text/html");
	}
}
else
{
	$uploaded_files = array();
	$label = null;
	if(isset($_REQUEST['label']))
		$label = trim($_REQUEST['label']);
	$dir_edit = null;
	if(isset($_REQUEST['dir_edit']))
	{
		$dir_edit = trim($_REQUEST['dir_edit']);
		if((strlen($dir_edit)>0) && !rTorrentSettings::get()->correctDirectory($dir_edit))
			$uploaded_files = array( array( 'status' => "FailedDirectory" ) );
	}
	$addition = null;
	if(isset($_REQUEST['addition']) && is_array($_REQUEST['addition']))
		$addition = $_REQUEST['addition'];
	if(empty($uploaded_files))
	{
		if(isset($_FILES['torrent_file']))
		{
			if( is_array($_FILES['torrent_file']['name']) )
			{
				for ($i = 0; $i<count($_FILES['torrent_file']['name']); ++$i)
				{
		                        $files[] = array
        		                (
                		            'name' => $_FILES['torrent_file']['name'][$i],
                        		    'tmp_name' => $_FILES['torrent_file']['tmp_name'][$i],
		                        );
        	        	}
			}
			else
				$files[] = $_FILES['torrent_file'];
			foreach( $files as $file )
			{
				$ufile = $file['name'];
				if(pathinfo($ufile,PATHINFO_EXTENSION)!="torrent")
					$ufile.=".torrent";
				$ufile = FileUtil::getUniqueUploadedFilename($ufile);
				$ok = move_uploaded_file($file['tmp_name'],$ufile);
				$uploaded_files[] = array( 'name'=>$file['name'], 'file'=>$ufile, 'status'=>($ok ? "Success" : "Failed") );
			}
		}
		else
		{
			if(isset($_REQUEST['url']))
			{
				$urls = preg_split('/[\r\n]+/', trim($_REQUEST['url']));
				foreach($urls as $url)
				{
					$url = trim($url);
					if(empty($url)) continue;

					$uploaded_url = array( 'name'=>$url, 'status'=>"Failed" );
					if(strpos($url,"magnet:")===0)
					{
						$uploaded_url['status'] = (rTorrent::sendMagnet($url,
							!isset($_REQUEST['torrents_start_stopped']),
							!isset($_REQUEST['not_add_path']),
							$dir_edit,$label,$addition) ? "Success" : "Failed" );
					}
					else
					{
						$cli = new Snoopy();
						$fetched = @$cli->fetchComplex($url);
						if($fetched && Snoopy::isTorrentResponse($cli))
						{
							$name = $cli->get_filename();
							if($name===false)
								$name = md5($url).".torrent";
							$name = FileUtil::getUniqueUploadedFilename($name);
							$f = @fopen($name,"w");
							if($f!==false)
							{
								@fwrite($f,$cli->results,strlen($cli->results));
								fclose($f);
								$uploaded_url['file'] = $name;
								$uploaded_url['status'] = "Success";
							}
						}
						else
						{
							$uploaded_url['status'] = ($fetched && Snoopy::isSuccessfulResponse($cli))
								? "FailedFile" : "FailedURL";
							FileUtil::toLog("addtorrent: torrent fetch refused: "
								.TorrentFetch::rejectionReason($cli, $fetched));
						}
					}
					$uploaded_files[] = $uploaded_url;
				}
			}
		}
	}
	if (!empty($_SERVER['PHP_SELF']) && !empty($_SERVER['HTTP_HOST']))
		$location = "Location: //".$_SERVER['HTTP_HOST'].dirname($_SERVER['PHP_SELF'])."/addtorrent.php?";
	else
		$location = "Location: ./addtorrent.php?";
	if(empty($uploaded_files))
		$uploaded_files = array( array( 'status' => "Failed" ) );
	foreach($uploaded_files as &$file)
	{
		if( ($file['status']=='Success') && isset($file['file']) )
		{
			$file['file'] = realpath($file['file']);
			@chmod($file['file'],$profileMask & 0666);
			$torrent = new Torrent($file['file']);
			if($torrent->errors())
			{
				@unlink($file['file']);
				$file['status'] = "FailedFile";
			}
			else
			{
				if(isset($_REQUEST['randomize_hash']))
					$torrent->info['unique'] = uniqid("rutorrent-",true);
				$pendingReceipt = null;
				$load = rTorrent::sendTorrent($torrent,
					!isset($_REQUEST['torrents_start_stopped']),
					!isset($_REQUEST['not_add_path']),
					$dir_edit,$label,$saveUploadedTorrents,isset($_REQUEST['fast_resume']),true,$addition,$pendingReceipt);
				if($load===false)
				{
					@unlink($file['file']);
					$file['status'] = "Failed";
				}
				elseif($load===null)
				{
					$file['status'] = "Pending";
					if(!$saveUploadedTorrents && !empty($pendingReceipt['raw']))
						@unlink($file['file']);
				}
			}
		}
		$location.=('result[]='.$file['status'].'&');
		if( isset($file['name']) )
			$location.=('name[]='.rawurlencode($file['name']).'&');
	}
	header("HTTP/1.0 302 Moved Temporarily");
	if(isset($_REQUEST['json']))
		$location.='json=1';
	header($location);
}
