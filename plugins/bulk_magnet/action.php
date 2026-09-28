<?php

require_once( dirname(__FILE__).'/../../php/Snoopy.class.inc');
require_once( dirname(__FILE__).'/../../php/rtorrent.php');
require_once( dirname(__FILE__).'/../../php/torrentfetch.php');

function getTorrent( $url )
{
	global $profileMask;
	$cli = new Snoopy();
	$fetched = $cli->fetchComplex($url);
	if($fetched && Snoopy::isTorrentResponse($cli))
	{
		$name = $cli->get_filename();
		if($name===false)
			$name = md5($url).".torrent";
		$name = FileUtil::getUniqueUploadedFilename($name);
		$f = @fopen($name,"w");
		if($f===false)
		{
			$name = FileUtil::getUniqueUploadedFilename(md5($url).".torrent");
			$f = @fopen($name,"w");
		}
		if($f!==false)
		{
			@fwrite($f,$cli->results,strlen($cli->results));
			fclose($f);
			@chmod($name,$profileMask & 0666);
			return($name);
		}
		FileUtil::toLog("bulk_magnet: torrent save refused: local-write-failed");
	}
	else
		FileUtil::toLog("bulk_magnet: torrent fetch refused: "
			.TorrentFetch::rejectionReason($cli, $fetched));
	return(false);
}

function parseValue( $value, &$pendingReceipt = null )
{
	global $saveUploadedTorrents;
        $ret = false;
	if(( strpos($value,'http://') === 0 ) || ( strpos($value,'https://') === 0 ) )
	{
		$fname = getTorrent( $value );
		if($fname)
		{
			$ret = rTorrent::sendTorrent($fname, true, true, '', '',
				$saveUploadedTorrents, false, true, null, $pendingReceipt);
			if($ret === false || ($ret === null && !$saveUploadedTorrents
				&& !empty($pendingReceipt['raw'])))
				@unlink($fname);
		}
	}
	else
	{
		$len = strlen($value);
		if( (strpos($value,'magnet:') === false) && (($len==40) || ($len==32)) )
		{
			$value = "magnet:?xt=urn:btih:".$value;
		}
		if( strpos($value,'magnet:') === 0 )
		{
			$ret = rTorrent::sendMagnet($value, true, true, '', '');
		}
	}
	return($ret);
}

ignore_user_abort( true );
set_time_limit( 0 );

$result = array
(
	'error' => 0,
	'success' => 0,
	'pending' => 0,
	'duplicate' => 0,
);

if(!isset($HTTP_RAW_POST_DATA))
	$HTTP_RAW_POST_DATA = file_get_contents("php://input");
if(isset($HTTP_RAW_POST_DATA))
{
	$vars = explode('&', $HTTP_RAW_POST_DATA);
	$torrents = array();
	$loaded = rTorrent::loadedHashes();
	if($loaded===false)
		$result['error']++;
	else
	{
		foreach($vars as $var)
		{
			$parts = explode("=",$var);
			if( count($parts)>1 )
			{
				$value = trim(rawurldecode($parts[1]));
				if(strlen($value))
				{
					$receipt = null;
					$hash = parseValue($value, $receipt);
					if($hash === false)
						$result['error']++;
					elseif($hash === null)
					{
						if(isset($receipt['hash']) && isset($loaded[strtoupper($receipt['hash'])]))
							$result['duplicate']++;
						else
							$result['pending']++;
					}
					elseif(isset($loaded[strtoupper($hash)]))
						$result['duplicate']++;
					else
					{
						$loaded[strtoupper($hash)] = true;
						$result['success']++;
					}
				}
			}
		}
	}
}

CachedEcho::send(JSON::safeEncode($result),"application/json",true);
