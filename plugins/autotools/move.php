<?php

if( !chdir( dirname( __FILE__) ) )
	exit();

if( count( $argv ) > 7 )
	$_SERVER['REMOTE_USER'] = $argv[7];

require_once( "./util_rt.php" );
require_once( "./autotools.php" );
eval( FileUtil::getPluginConf( 'autotools' ) );

//------------------------------------------------------------------------------
function Debug( $str )
{
	global $autodebug_enabled;
	if( $autodebug_enabled ) rtDbg( "AutoMove", $str );
}


//------------------------------------------------------------------------------
function skip_move($files)
{
	global $at;
	$filter = $at->skip_move_for_files;
	if(strlen($filter)>0)
    	{
		Debug("using filter:".$filter);
		foreach($files as $file)
		{
			if ( preg_match($filter.'u',$file)==1)
			{
				return true;
			}
	    	}
		Debug("filter: " . $filter . " did not match any files in" . implode(" | ",$files) .". end");
	}
	return(false);
}

//------------------------------------------------------------------------------
function operationOnTorrentFiles($torrent,&$base_path,$base_file,$is_multy_file,$dest_path,$fileop_type,$hash,$token,$sourceBase,$finishedRoot)
{
	global $autodebug_enabled;

	$ret = false;
	if( $is_multy_file )
		$sub_dir = rtAddTailSlash( $base_file );	// $base_file - is a directory
	else
		$sub_dir = '';					// $base_file - is really a file

	$noticeDestination = $dest_path;
	$base_path.=$sub_dir;
	$dest_path.=$sub_dir;

	Debug( "Operation ".$fileop_type );
	Debug( "from ".$base_path );
	Debug( "to   ".$dest_path );

        $files = array();
        $info = $torrent->info;
	if(isset($info['files']))
		foreach($info['files'] as $key=>$file)
			$files[] = implode('/',$file['path']);
	else
		$files[] = $info['name'];

    if (skip_move($files)){
        $ret = false;
        return ($ret);
    }

	if( $base_path != $dest_path && is_dir( $base_path ) )
	{
		$context = array('hash'=>$hash, 'token'=>$token, 'sourceBase'=>$sourceBase,
			'destination'=>$dest_path);
		if($fileop_type !== 'Move')
			$context['notice'] = array('noticeDestination'=>$noticeDestination,
				'finishedRoot'=>$finishedRoot, 'torrentName'=>$torrent->name());
		if( rtOpFiles( $files, $base_path, $dest_path, $fileop_type,
			$autodebug_enabled, $context ) )
		{
			$ret = true;
		}
	}
	$base_path = $dest_path;
	return($ret);
}

//------------------------------------------------------------------------------
Debug( "" );
Debug( "--- begin ---" );

$hash      = $argv[1];
$base_path = $argv[2];
$base_name = $argv[3];
$is_multi  = $argv[4];
$label	   = rawurldecode($argv[5]);
$name	   = $argv[6];
$at = rAutoTools::load();

$sourceBase = $base_path;
$jobToken = isset($argv[8]) ? $argv[8] : '';

if( $at->enable_move && (@preg_match($at->automove_filter.'u',$label)==1) )
{
	$path_to_finished = trim( $at->path_to_finished );
	$fileop_type = $at->fileop_type;
	$session  = rTorrentSettings::get()->session;
	if( ($path_to_finished != '') && !empty($session) )
	{
		$path_to_finished = rtAddTailSlash( $path_to_finished );
		$fname = rtAddTailSlash($session).$hash.".torrent";
		$directory    = rTorrentSettings::get()->directory;
		if(is_readable($fname) && !empty($directory))
		{
			$torrent = new Torrent( $fname );
			if( !$torrent->errors() )
			{
				$directory = rtAddTailSlash( $directory );
				$base_path = rtRemoveTailSlash( $base_path );
				$base_path = rtRemoveLastToken( $base_path, '/' );	// filename or dirname
				$base_path = rtAddTailSlash( $base_path );
				$rel_path  = rtGetRelativePath( $directory, $base_path );
				//------------------------------------------------------------------------------
				// !! this is a feature !!
				// ($rel_path == '') means, that $base_path is NOT a SUBDIR of $directory at all
				// so, we have to skip all automove actions
				// for example, if we don't want torrent to be automoved - we save it out of $directory subtree
				//------------------------------------------------------------------------------
				if( $rel_path != '' )
				{
					if( $rel_path == './' ) $rel_path = '';
					$dest_path = rtAddTailSlash( $path_to_finished.$rel_path );
					// last condition avoids appending duplicate path from combining folder and label (eg autowatch and autolabel)
					if($at->addLabel && ($label!='') && ($label!=trim($rel_path,'/')))
		        			$dest_path.=FileUtil::addslash($label);
			        	if($at->addName && ($name!=''))
						$dest_path.=FileUtil::addslash($name);
					if(!rtMkDir($path_to_finished))
						FileUtil::toLog('autotools: file operation refused: finished root unavailable');
					else if(!rAutoTools::destinationWithinRoot($path_to_finished, $dest_path))
						FileUtil::toLog('autotools: file operation refused: destination outside finished root');
					else if(operationOnTorrentFiles($torrent,$base_path,$base_name,$is_multi,$dest_path,$fileop_type,$hash,$jobToken,$sourceBase,$path_to_finished))
					{
							echo $base_path;
						if($fileop_type === 'Move')
							rAutoTools::notifyCompletedFileTransfer($dest_path, $path_to_finished, $torrent->name());
					}
				}
			}
		}
	}
}

Debug( "--- end ---" );
