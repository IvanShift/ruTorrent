<?php

// PHP drops the file parts of a POST beyond max_file_uploads before this
// script runs and reports that only as a startup warning. Keep it before an
// include can replace it; see addtorrent_upload_limit_row().
$addtorrentStartupError = error_get_last();

require_once( 'Snoopy.class.inc');
require_once( 'rtorrent.php' );
set_time_limit(0);

/**
 * Encode a value for use as a literal in the script this page serves.
 *
 * The result of this page is evaluated by its clients -- js/content.js, and
 * plugins/mobile/init.js, which runs each line that starts with noty( -- so
 * every reflected value has to be a complete literal that no input can end.
 * The HEX flags also keep <, >, & and both quote characters out of the bytes,
 * so the response cannot be turned into markup by asking for it directly.
 *
 * A unix filename is a string of bytes and need not be valid UTF-8, and it
 * reaches here as name[]. json_encode() answers false for bytes it cannot
 * encode, which concatenates as nothing at all and leaves the call with an
 * argument missing rather than an argument that is a literal. Callers project
 * filesystem names before this final JSON guard, which always yields a literal.
 */
function addtorrent_literal($value)
{
	$literal = json_encode(strval($value),
		JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES|
		JSON_INVALID_UTF8_SUBSTITUTE);
	return($literal===false ? '""' : $literal);
}

// The result of one confirmed load: its hash is a duplicate when it was
// loaded before this request, or by an earlier item of it. A load refused
// over its directory says so, so the user knows what to change.
function addtorrent_status($hash, &$loaded)
{
	if($hash===false)
		return(rTorrent::refusedDirectory() ? "FailedDirectory" : "Failed");
	$hash = strtoupper($hash);
	if(isset($loaded[$hash]))
		return("Duplicate");
	$loaded[$hash] = true;
	return("Success");
}

/**
 * The result of one magnet. sendMagnet() answers the hash of every magnet it
 * hands to rTorrent, and rTorrent drops some malformed links without making a
 * stub (O-1), so rTorrent::magnetShown() waits for d.hash to show the stub. A
 * stub that never shows is Pending, and its hash does not count as loaded for
 * the rest of the request.
 */
function addtorrent_magnet_status($hash, &$loaded)
{
	if($hash===false)
		return(addtorrent_status($hash, $loaded));
	if(!rTorrent::magnetShown($hash, 'addtorrent'))
		return("Pending");
	return(addtorrent_status(strtoupper($hash), $loaded));
}

/**
 * The result row for the selected files this script never received.
 *
 * PHP registers at most max_file_uploads file parts of a POST and drops the
 * rest without counting them; PHP also discards a whole body larger than
 * post_max_size. The add dialog sends the number of files it selected as
 * torrent_file_count, so the shortfall can be counted. Without a count, only
 * PHP's startup warning tells, and error_get_last() still holds it only when
 * no later startup diagnostic replaced it: PHP-FPM 8.5 with
 * register_argc_argv on raises a deprecation after it. The image's FPM pool
 * turns that directive off, so there the warning is the last error.
 */
function addtorrent_upload_limit_row($startupError, $kept, $selected)
{
	$limit = intval(ini_get('max_file_uploads'));
	if(is_string($selected) && ctype_digit($selected) && (strlen($selected) <= 9) &&
		(intval($selected) > $kept))
		$text = (intval($selected) - $kept).' of the '.intval($selected).' selected files'
			.((($limit > 0) && ($kept >= $limit)) ? ' (max_file_uploads='.$limit.')' : '');
	elseif(is_array($startupError) && (($startupError['type'] ?? null) === E_WARNING) &&
		(strpos((string)($startupError['message'] ?? ''),
			'Maximum number of allowable file uploads has been exceeded') !== false))
		$text = 'the files after the first '.$kept.' (max_file_uploads='.$limit.')';
	else
		return(null);
	FileUtil::toLog('addtorrent: upload incomplete: not processed: '.$text);
	return(array( 'name'=>'Not processed: '.$text, 'status'=>"Failed" ));
}

/**
 * Answer with the result payload of a redirected GET or of a large upload
 * POST: one noty(<literal>+theUILang[...],<kind>); call per result. Nothing
 * in this file runs after it, and the POST sends its redirect only in the
 * other branch, so neither depends on CachedEcho::send() ending the request.
 */
function addtorrent_response($results, $names, $json = false)
{
	if($json)
	{
		CachedEcho::send( '{ "result" : '.addtorrent_literal(isset($results[0]) ? $results[0] : '').' }',
			"application/json");
		return;
	}
	$js = '';
	foreach( $results as $ndx=>$result )
	{
		$status = in_array($result, array('Success', 'Duplicate', 'Pending', 'Failed',
			'FailedFile', 'FailedURL', 'FailedDirectory'), true) ? $result : 'Failed';
		$kind = $status === 'Success' ? 'success'
			: ($status === 'Duplicate' ? 'alert' : ($status === 'Pending' ? 'warning' : 'error'));
		$js.= ('noty('.addtorrent_literal(isset($names[$ndx]) ? UTF::display($names[$ndx].' - ') : '').
			'+theUILang["addTorrent"+'.addtorrent_literal($status).']'.
			','.addtorrent_literal($kind).');');
	}
	CachedEcho::send($js,"text/html");
}

if(isset($_REQUEST['result']))
{
	$results = is_array($_REQUEST['result']) ? array_values($_REQUEST['result']) : array($_REQUEST['result']);
	$names = (isset($_REQUEST['name']) && is_array($_REQUEST['name']))
		? array_values($_REQUEST['name']) : array();
	addtorrent_response($results, $names, isset($_REQUEST['json']));
}
else
{
	require_once( __DIR__ . '/torrentfetch.php' );
	if(($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['url']))
	{
		if(!is_string($_GET['url']) || trim($_GET['url']) === '')
		{
			error_log('addtorrent: refused GET confirmation: invalid url');
			header('HTTP/1.0 400 Bad Request', true, 400);
			die('Invalid URL');
		}
		header('Content-Type: text/html; charset=UTF-8');
		header('Cache-Control: no-store');
		header('Referrer-Policy: same-origin');
		header('X-Frame-Options: DENY');
		header("Content-Security-Policy: default-src 'none'; form-action 'self'; frame-ancestors 'none'");
		$url = htmlspecialchars($_GET['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
		echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="referrer" content="same-origin"><title>Add torrent</title><body>';
		echo '<p>Add this torrent to ruTorrent?</p><p><code>'.$url.'</code></p>';
		echo '<form action="addtorrent.php" method="post"><input type="hidden" name="url" value="'.$url.'">';
		echo '<button type="submit">Add torrent</button></form></body></html>';
		exit;
	}
	Requests::requirePost();
	$uploaded_files = array();
	$label = null;
	if(isset($_REQUEST['label']))
		$label = trim($_REQUEST['label']);
	$dir_edit = null;
	if(isset($_REQUEST['dir_edit']))
	{
		$dir_edit = trim($_REQUEST['dir_edit']);
		if((strlen($dir_edit)>0) && (!rTorrentSettings::get()->correctDirectory($dir_edit) ||
			!rTorrent::isInsideTopDirectory($dir_edit)))
			$uploaded_files = array( array( 'status' => "FailedDirectory" ) );
	}
	// No addition is taken from the request. An addition is an rtorrent command
	// appended to the load call, so accepting one here would let a request name
	// the commands the daemon runs. The parameter stays on rTorrent::sendTorrent()
	// and rTorrent::sendMagnet() for the plugins that build one in php.
	$addition = null;
	$loaded = rTorrent::loadedHashes();
	if($loaded===false && empty($uploaded_files))
		$uploaded_files = array( array( 'status' => "Failed" ) );
	if(empty($uploaded_files))
	{
		$files = array();
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
			if(isset($_POST['url']))
			{
				$urls = preg_split('/[\r\n]+/', trim($_POST['url']));
				foreach($urls as $url)
				{
					$url = trim($url);
					if(empty($url)) continue;

					$uploaded_url = array( 'name'=>$url, 'status'=>"Failed" );
					if(strpos($url,"magnet:")===0)
					{
						$uploaded_url['status'] = addtorrent_magnet_status(rTorrent::sendMagnet($url,
							!isset($_REQUEST['torrents_start_stopped']),
							!isset($_REQUEST['not_add_path']),
							$dir_edit,$label,$addition), $loaded);
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
		$limitRow = addtorrent_upload_limit_row($addtorrentStartupError, count($files),
			isset($_REQUEST['torrent_file_count']) ? $_REQUEST['torrent_file_count'] : null);
		if($limitRow!==null)
			$uploaded_files[] = $limitRow;
	}
	// The browser knows the public origin and prefix even when a proxy rewrites them.
	$location = "Location: ./addtorrent.php?";
	if(empty($uploaded_files))
		$uploaded_files = array( array( 'status' => "Failed" ) );
	$results = array();
	$names = array();
	foreach($uploaded_files as $ndx=>&$file)
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
				// rtorrent drops a load whose hash it already holds before the
				// load's deferred proof can run, so sending one could only end
				// Pending after the whole settle window, 2 s per duplicate.
				// $loaded -- the download_list snapshot and the loads confirmed
				// since -- only makes a duplicate likely: the snapshot is read
				// when the request starts, so one d.hash read confirms that the
				// torrent is still there before its load is skipped.
				$hash = $torrent->hash_info();
				$duplicate = isset($loaded[$hash]) && (HashFault::presence($hash) === HashFault::PRESENT);
				// Otherwise the entry was stale, or the read proved nothing: the
				// load and its proof decide, and a confirmed load is not a duplicate.
				if(!$duplicate)
					unset($loaded[$hash]);
				if($duplicate)
				{
					$file['status'] = "Duplicate";
					@unlink($file['file']);
				}
				else
				{
					$pendingReceipt = null;
					$load = rTorrent::sendTorrent($torrent,
						!isset($_REQUEST['torrents_start_stopped']),
						!isset($_REQUEST['not_add_path']),
						$dir_edit,$label,$saveUploadedTorrents,isset($_REQUEST['fast_resume']),true,$addition,$pendingReceipt);
					if($load===null)
					{
						$file['status'] = "Pending";
						if(!$saveUploadedTorrents && !empty($pendingReceipt['raw']))
							@unlink($file['file']);
					}
					else
					{
						$file['status'] = addtorrent_status($load, $loaded);
						if($file['status']!='Success')
							@unlink($file['file']);
					}
				}
			}
		}
		$results[] = $file['status'];
		$location.=('result[]='.$file['status'].'&');
		if( isset($file['name']) )
		{
			$names[$ndx] = $file['name'];
			$location.=('name[]='.rawurlencode($file['name']).'&');
		}
	}
	unset($file);
	if(isset($_REQUEST['json']))
		$location.='json=1';
	// Percent-encoded UTF-8 batch names can overflow the usual 4 KiB FastCGI
	// header buffer. Bound the complete Location field to 2 KiB and let the
	// existing iframe/JSON consumer read the same result from this POST body.
	if(strlen($location)>2048)
		addtorrent_response($results, $names, isset($_REQUEST['json']));
	else
	{
		header("HTTP/1.0 302 Moved Temporarily");
		header($location);
	}
}
