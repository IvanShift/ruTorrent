<?php
require_once( '../../php/rtorrent.php' );

if(isset($_REQUEST['result']))
	CachedEcho::send('noty(theUILang.cantFindTorrent,"error");',"text/html");
if(isset($_POST['hash']))
{
	$query = urldecode($_POST['hash']);
	$hashes = explode(" ", $query);
	if(count($hashes) == 1)
	{
		$torrent = rTorrent::getSource($_POST['hash']);
		if($torrent)
			$torrent->send();
	}
	else
	{
		if(!class_exists('ZipArchive'))
			CachedEcho::send('noty("PHP module \'zip\' is not installed.","error");',"text/html");
		foreach($hashes as $hash)
		{
			$sourcePath = null;
			$torrent = rTorrent::getSource($hash, $sourcePath);
			if($torrent)
				$files[] = array($hash, $sourcePath, $torrent);
			else
			{
				$identity = preg_match('/\A[0-9a-f]{40}\z/i', $hash)
					? strtoupper($hash) : 'invalid-hash';
				$reason = $sourcePath === false ? 'source-unavailable' : 'source-invalid';
				FileUtil::toLog('source: ZIP entry refused: '.$identity.' '.$reason);
			}
		}
		if(isset($files))
		{
			ignore_user_abort(true);
			set_time_limit(0);

			$fn = 1;
			$zippath = FileUtil::getTempFilename('source','zip');

			$zip = new ZipArchive;
			$failure = null;
			if(@$zip->open($zippath, ZipArchive::CREATE) !== true)
				$failure = 'open-failed';
			else
			{
				foreach($files as $entry)
				{
					list($hash, $filepath, $file) = $entry;
					$filename = $file->info['name']."-".$fn.".torrent";
					$isMetadata = strcasecmp(basename($filepath), $hash . '.meta') === 0;
					$added = $isMetadata
						? @$zip->addFromString($filename, (string)$file)
						: @$zip->addFile($filepath, $filename);
					if(!$added)
					{
						$failure = $isMetadata ? 'add-string-failed' : 'add-file-failed';
						break;
					}
					$fn++;
				}
				if(!@$zip->close() && $failure === null)
					$failure = 'close-failed';
			}

			if($failure === null)
			{
				// This POST creates a new ZIP; old validators and ranges cannot apply to it.
				unset($_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE'], $_SERVER['HTTP_RANGE']);
				if(!SendFile::send($zippath, "application/zip", null, false))
					$failure = 'send-failed';
			}
			if(is_file($zippath) && !@unlink($zippath))
				FileUtil::toLog('source: ZIP cleanup failed: '.($failure ?? 'completed-send'));
			if($failure !== null)
			{
				FileUtil::toLog('source: ZIP export failed: '.$failure);
				header('HTTP/1.0 500 Internal Server Error');
				CachedEcho::send('noty("Could not export source ZIP archive.","error");',"text/html");
			}

			exit();
		}
	}
}
header("HTTP/1.0 302 Moved Temporarily");
header("Location: action.php?result=0");
