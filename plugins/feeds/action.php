<?php
require_once( '../../php/util.php' );
require_once( '../../php/settings.php' );
require_once( '../../php/rtorrent.php' );
require_once( '../../php/torrentfilenames.php' );
eval( FileUtil::getPluginConf( 'feeds' ) );

$lang = (isset($_REQUEST['lang']) &&
	preg_match('/^[A-Za-z]{2}(\-[A-Za-z]{2}|)$/', $_REQUEST['lang']) &&
	is_file('lang/'.$_REQUEST['lang'].'.php')) ? $_REQUEST['lang'] : 'en';
$theUILang = array();
require_once( 'lang/'.$lang.'.php' );

function bytes( $bt )
{
	global $theUILang;
	$a = array($theUILang["bytes"], $theUILang['KB'], $theUILang['MB'], $theUILang['GB'], $theUILang['TB'], $theUILang['PB']);
	$ndx = 0;
	if($bt == 0)
		$ndx = 1;
	else
	{
		if($bt < 1024)
		{
			$bt = $bt / 1024;
			$ndx = 1;
		}
		else
		{
			while($bt >= 1024)
			{
       	    			$bt = $bt / 1024;
      				$ndx++;
	         	}
		}
	}
	return((floor($bt*10)/10)." ".$a[$ndx]);
}

function speed($bt)
{
	global $theUILang;
	return(($bt>0) ? bytes($bt)."/".$theUILang["s"] : "");
}

function eta($tm)
{
	global $theUILang;
	if($tm >= 2419200)
		return("∞");
	$val = $tm % (604800 * 52);
	$w = intval($val / 604800);
	$val = $val % 604800;
	$d = intval($val / 86400);
	$val = $val % 86400;
	$h = intval($val / 3600);
	$val = $val % 3600;
	$m = intval($val / 60);
	$val = intval($val % 60);
	$v = 0;
	$ret = "";
	if($w > 0)
	{
		$ret = $w.$theUILang["time_w"];
		$v++;
	}
	if($d > 0)
	{
		$ret .= $d.$theUILang["time_d"];
		$v++;
	}
	if(($h > 0) && ($v < 2))
	{
		$ret .= $h.$theUILang["time_h"];
      		$v++;
	}
	if(($m > 0) && ($v < 2))
	{
		$ret .= $m.$theUILang["time_m"];
		$v++;
	}
	if($v < 2)
		$ret .= $val.$theUILang["time_s"];
	return( substr($ret,0,-1) );
}

// A daemon value as XML 1.0 text; the description also carries it as HTML
// text inside a CDATA section. Unknown bytes become visible escapes before
// XML escaping. ENT_XML1|ENT_DISALLOWED turns a code point XML 1.0 does
// not allow -- a C0 control other than TAB, LF and CR, U+FFFE, U+FFFF --
// into U+FFFD as well, so one such byte in a tracker message or a name no
// longer makes the whole feed ill-formed. '>' is escaped, so a value cannot
// close the CDATA section either.
function feedText($value)
{
	return(htmlspecialchars(UTF::display($value),ENT_COMPAT|ENT_SUBSTITUTE|ENT_XML1|ENT_DISALLOWED,"UTF-8"));
}

function formatItemDescription($torrent)
{
	global $theUILang;
	$desc = '<font size=2><fieldset><legend>'.$theUILang["Transfer"].'</legend>'.'<strong>'.$theUILang["Size"].": </strong>".bytes($torrent["size"])."<br>";
	if($torrent["downloaded"]!=$torrent["size"])
		$desc.='<strong>'.$theUILang["Downloaded"].": </strong>".bytes($torrent["downloaded"])."<br>";
	if($torrent["eta"]>0)
		$desc.='<strong>'.$theUILang["Remaining"].": </strong>".eta($torrent["eta"])."<br>";
	if($torrent["dl"]>=1024)
		$desc.='<strong>'.$theUILang["Down_speed"].": </strong>".speed($torrent["dl"])."<br>";
	if($torrent["uploaded"]>0)
		$desc.='<strong>'.$theUILang["Uploaded"].": </strong>".bytes($torrent["uploaded"])."<br>";
	if($torrent["ul"]>=1024)
		$desc.='<strong>'.$theUILang["Ul_speed"].": </strong>".speed($torrent["ul"])."<br>";
	$desc.='<strong>'.$theUILang["Share_ratio"].": </strong>".$torrent["ratio"]."<br>";
        if($torrent["seeds"]!="0 (0)")
		$desc.='<strong>'.$theUILang["Seeds"].": </strong>".feedText($torrent["seeds"])."<br>";
        if($torrent["peers"]!="0 (0)")
		$desc.='<strong>'.$theUILang["Peers"].": </strong>".feedText($torrent["peers"])."<br>";
	$desc.="</fieldset>";
	if(!empty($torrent["message"]))
		$desc.='<fieldset><legend>'.$theUILang["Track_status"].'</legend>'.feedText($torrent["message"]).'</fieldset>';
	if(!empty($torrent["comment"]))
		$desc.='<fieldset><legend>'.$theUILang["Comment"].'</legend>'.feedText($torrent["comment"]).'</fieldset>';
	return($desc);
}

function sortByPubDate( $a, $b )
{
	return( (intval($a["pubDate"]) > intval($b["pubDate"])) ? -1 : ((intval($a["pubDate"]) < intval($b["pubDate"])) ? 1 : strcmp($a["title"],$b["title"])) );
}

$mode = $_REQUEST['mode'] ?? 'all';
if(!is_string($mode) || !in_array($mode, array('all', 'completed', 'downloading', 'active', 'inactive', 'error'), true))
	$mode = 'all';
$url = Requests::publicBaseURL('/plugins/feeds/action.php');
if($url === false)
{
	header('HTTP/1.0 500 Internal Server Error', true, 500);
	die('Unable to determine the public feed URL. Configure RU_PUBLIC_URL or publicURL.');
}
$xmlURL = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
// session.name and the versions are the daemon's own bytes, like every
// item value below, and are escaped for XML the same way.
$server = explode(':',rTorrentSettings::get()->server);
$xmlServer = feedText($server[0]);
$ret = '<?xml version="1.0" encoding="UTF-8"?>'.
	'<rss version="2.0"><channel><description>*** rTorrent '.
	feedText(rTorrentSettings::get()->version.'/'.rTorrentSettings::get()->libVersion)." - ".$xmlServer.' ***</description><link>'.
	$xmlURL.'</link><title>'.$xmlServer.': '.$theUILang[$mode].'</title>'.
	'<image><url>'.$xmlURL.'images/logo.png</url><link>'.$xmlURL.'</link><title>ruTorrent</title></image>';

$view = '';	// all, inactive
switch($mode)
{
	case 'downloading':
	{
		$view = 'incomplete';
		break;
	}
	case 'completed':
	{
		$view = 'complete';
		break;
	}
	case 'error':
	case 'active':
	{
		$view = 'active';
		break;
	}
}

$prm = array($view,
	getCmd("d.get_hash="),
	getCmd("d.get_name="),
	getCmd("d.get_message="),
	getCmd("d.get_up_rate="),
	getCmd("d.get_down_rate="),
	getCmd("d.get_custom1="),
	(rTorrentSettings::get()->iVersion >= 0x806) ? getCmd('d.get_custom').'=seedingtime' : getCmd('cat='));
if($showItemDescription)
	$prm = array_merge($prm, array(
		getCmd("d.get_custom2="),
		getCmd("d.get_completed_chunks="),
		getCmd("d.get_size_chunks="),
		getCmd("d.get_size_bytes="),
		getCmd("d.get_bytes_done="),
		getCmd("d.get_up_total="),
		getCmd("d.get_ratio="),
		getCmd("d.get_up_rate="),
		getCmd("d.get_down_rate="),
		getCmd("d.get_chunk_size="),
		getCmd("d.get_peers_not_connected="),
		getCmd("d.get_peers_connected="),
		getCmd("d.get_peers_accounted="),
		getCmd("d.get_peers_complete=")));
$paths = new rXMLRPCCommand('d.multicall', array($view, getCmd('d.get_hash='), getCmd('d.get_tied_to_file=')));
$req = new rXMLRPCRequest(array(new rXMLRPCCommand('d.multicall', $prm), $paths,
	new rXMLRPCCommand('get_session')));

if($req->run(true) && $req->responseTypes === array('array') && count($req->responseValues) === 1)
{
	$replies = $req->responseValues[0];
	$rows = isset($replies[0]) && is_array($replies[0]) && array_keys($replies[0]) === array(0)
		&& is_array($replies[0][0]) ? $replies[0][0] : array();
	$sourcePaths = array();
	// Optional source failures must not discard the live list or trigger a
	// separate daemon request for every name that needs display metadata.
	if(isset($replies[1], $replies[2]) && is_array($replies[1]) && is_array($replies[2])
		&& array_keys($replies[1]) === array(0) && array_keys($replies[2]) === array(0)
		&& is_array($replies[1][0]) && is_string($replies[2][0]))
		foreach($replies[1][0] as $row)
			if(is_array($row) && array_keys($row) === array(0,1) && is_string($row[0]) && is_string($row[1]))
				$sourcePaths[$row[0]] = array($replies[2][0], $row[1]);
	$values = array();
	$fields = count($prm)-1;
	foreach($rows as $row)
	{
		if(!is_array($row) || array_keys($row) !== range(0,$fields-1)) continue;
		foreach($row as $value) if(!is_string($value)) continue 2;
		if(TorrentFileNames::needsTitleMetadata($row[1]))
		{
			$source = false;
			try
			{
				$sourcePath = null;
				$source = rTorrent::getSource($row[0], $sourcePath, $sourcePaths[$row[0]] ?? false);
			}
			catch(Throwable $error)
			{
				// Unreadable optional metadata leaves the daemon name visible.
			}
			$title = TorrentFileNames::title($row[1], $row[0], $source);
			$row[1] = $title['name'];
		}
		foreach($row as $value) $values[] = $value;
	}
	$items = array();
	for($i = 0; $i<count($values); $i+=$fields)
	{
		$item = array(
			"guid"=>$values[$i],
			"title"=>$values[$i+1],
			"category"=>rawurldecode($values[$i+5]),
			"pubDate"=>floatval($values[$i+6]) );
		if(rTorrentSettings::get()->isPluginRegistered('data'))
			$item["link"] = $url.'plugins/data/action.php?hash='.$values[$i].'&no=0&readable=1';
		if($mode=='error')
		{
                        if(empty($values[$i+2]))
				continue;
		}
		else
		{
			if($mode=='active')
			{
				if( floatval($values[$i+3])<1024 && floatval($values[$i+4])<1024 )
					continue;
			}
			else
			if($mode=='inactive')
			{
				if( floatval($values[$i+3])>=1024 || floatval($values[$i+4])>=1024 )
					continue;
			}
		}

		if($showItemDescription)
		{
			$get_completed_chunks = floatval($values[$i+8]);
			$get_size_chunks = floatval($values[$i+9]);
			$get_chunk_size = floatval($values[$i+16]);
			$get_peers_not_connected = floatval($values[$i+17]);
			$get_peers_connected = floatval($values[$i+18]);
			$get_peers_all = $get_peers_not_connected+$get_peers_connected;

			$torrent = array(
				"downloaded" => floatval($values[$i+11]),
				"uploaded" => floatval($values[$i+12]),
				"size" => floatval($values[$i+10]),
				"ratio" => floatval($values[$i+13])/1000,
				"ul" => floatval($values[$i+14]),
				"dl" =>  floatval($values[$i+15]),
				"comment" => rawurldecode(substr($values[$i+7],10)),
				"hash" => $values[$i],
				"peers" => $values[$i+19]." (".$get_peers_all.")",
				"seeds" => $values[$i+20]." (".$get_peers_all.")",
				"message" => $values[$i+2],
				);
			$torrent["eta"] = ($torrent["dl"]>0) ? floor(($get_size_chunks-$get_completed_chunks)*$get_chunk_size/$torrent["dl"]) : -1;
			$item["description"] = formatItemDescription( $torrent );
		}
		$items[] = $item;
	}
	usort( $items, "sortByPubDate");
	foreach( $items as $item )
	{
		$ret.="\r\n<item>";
		foreach( $item as $key=>$val )
		{
			if($key=="guid")
				$ret.="\r\n\t<guid isPermaLink=\"false\">".feedText($val).'</guid>';
			else
			if($key=="pubDate")
			{
				if(empty($val))
					continue;
				$ret.="\r\n\t<pubDate>".TimeFormat::gmstrftime('%a, %d %b %Y %T %Z',$val).'</pubDate>';
			}
			else
			if($key=="description")
				$ret.="\r\n\t<description><![CDATA[".$val.']]></description>';
			else
				$ret.="\r\n\t<".$key.'>'.feedText($val).'</'.$key.'>';
		}
		$ret.="\r\n</item>";
	}
}

ob_clean();
CachedEcho::send($ret."</channel></rss>","application/rss+xml",true);
