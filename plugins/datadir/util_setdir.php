<?php

require_once( "../../php/xmlrpc.php" );
require_once( './util_rt.php' );
require_once( dirname( __FILE__ ).'/../rutracker_check/runstate.php' );

//------------------------------------------------------------------------------
// Move torrent data of $hash torrent to new location at $dest_path
//------------------------------------------------------------------------------
function rtSetDataDir( $hash, $dest_path, $add_path, $move_files, $fast_resume, $dbg = false )
{
	if( $dbg ) rtDbg( __FUNCTION__, "hash        : ".$hash );
	if( $dbg ) rtDbg( __FUNCTION__, "dest_path   : ".$dest_path );
	if( $dbg ) rtDbg( __FUNCTION__, "add path    : ".($add_path ? "1" : "0") );
	if( $dbg ) rtDbg( __FUNCTION__, "move files  : ".($move_files ? "1" : "0") );
	if( $dbg ) rtDbg( __FUNCTION__, "fast resume : ".($fast_resume ? "1" : "0") );

	$is_open       = false;
	$is_active     = false;
	$is_multy_file = false;
	$base_name     = '';
	$base_path     = '';
	$base_file     = '';

	$is_ok = true;
	if( $dest_path == '' )
	{
		$is_ok = false;
	}
	else {
		$dest_path = rtAddTailSlash( $dest_path );
	}

	if( $is_ok )
	{
		$keys = RuTrackerAtomicOwnership::ownershipKeys();
		$commands = array(
			new rXMLRPCCommand( getCmd( "d.get_custom1" ), $hash ),
		);
		foreach( $keys as $key )
			$commands[] = new rXMLRPCCommand( getCmd( "d.get_custom" ), array( $hash, $key ) );
		$owner = new rXMLRPCRequest( $commands );
		$owner->important = false;
		if( !$owner->success() || $owner->fault || !is_array( $owner->val )
			|| count( $owner->val ) !== count( $commands )
			|| count( array_filter( $owner->val, 'is_string' ) ) !== count( $commands ) )
		{
			FileUtil::toLog( 'datadir: '.$hash.' change refused: unreadable checker ownership' );
			return false;
		}
		$markers = array_combine( $keys, array_slice( $owner->val, 1 ) );
		unset( $markers['chk-revived'] );
		if( rawurldecode( $owner->val[0] ) === '.chk-meta'
			|| count( array_filter( $markers, 'strlen' ) ) !== 0 )
		{
			FileUtil::toLog( 'datadir: '.$hash.' change refused: active checker transaction or service label' );
			return false;
		}
		if( $fast_resume )
			FileUtil::toLog( 'datadir: '.$hash.' fast resume disabled: changing directory in place' );
	}

	// Check if torrent is open or active
	if( $is_ok )
	{
		$req = rtExec( array( "d.is_open", "d.is_active" ), $hash, $dbg );
		if( !$req )
			$is_ok = false;
		else {
			$is_open   = ( $req->val[0] != 0 );
			$is_active = ( $req->val[1] != 0 );
			if( $dbg ) rtDbg( __FUNCTION__, "is_open=".$req->val[0].", is_active=".$req->val[1] );
		}
	}

	// Open closed torrent to get d.get_base_path, d.get_base_filename
	if( $is_ok && $move_files )
	{
		if( !$is_open && !rtExec( "d.open", $hash, $dbg ) )
		{
			$is_ok = false;
		}
	}

	// Ask info from rTorrent
	if( $is_ok && $move_files )
	{
		$req = rtExec(
			array( 	"d.get_name",
				"d.get_base_path",
				"d.get_base_filename",
				"d.is_multi_file" ),
			$hash, $dbg );
		if( !$req )
			$is_ok = false;
		else {
			$base_name     = trim( $req->val[0] );
			$base_path     = trim( $req->val[1] );
			$base_file     = trim( $req->val[2] );
			$is_multy_file = ( $req->val[3] != 0 );
			if( $dbg ) rtDbg( __FUNCTION__, "d.get_name          : ".$base_name );
			if( $dbg ) rtDbg( __FUNCTION__, "d.get_base_path     : ".$base_path );
			if( $dbg ) rtDbg( __FUNCTION__, "d.get_base_filename : ".$base_file );
			if( $dbg ) rtDbg( __FUNCTION__, "d.is_multy_file     : ".$req->val[3] );
		}
	}

	// Check if paths are valid
	if( $is_ok && $move_files )
	{
		if( $base_path == '' || $base_file == '' )
		{
			if( $dbg ) rtDbg( __FUNCTION__, "base paths are empty" );
			$is_ok = false;
		}
		else {
			// Make $base_path a really BASE path for downloading data
			// (not including single file or subdir for multiple files).
			// Add trailing slash, if none.
			$base_path = rtRemoveTailSlash( $base_path );
			$base_path = rtRemoveLastToken( $base_path, '/' );	// filename or dirname
			$base_path = rtAddTailSlash( $base_path );
		}
	}

	// Get list of torrent data files
	$torrent_files = array();
	if( $is_ok && $move_files )
	{
		$req = rtExec( "f.multicall", array( $hash, "", getCmd("f.get_path=") ), $dbg );
		if( !$req )
			$is_ok = false;
		else {
			$torrent_files = $req->val;
			if( $dbg ) rtDbg( __FUNCTION__, "files in torrent    : ".count( $torrent_files ) );
		}
	}

	// 1. Stop torrent if active (if not, then rTorrent can crash)
	// 2. Close torrent anyway
	if( $is_ok )
	{
		$cmds = array();
		if( $is_active ) $cmds[] = "d.stop";
		if( $is_open || $move_files ) $cmds[] = "d.close";
		if( count( $cmds ) > 0 && !rtExec( $cmds, $hash, $dbg ) )
			$is_ok = false;
	}

	// Move torrent data files to new location
	if( $is_ok && $move_files )
	{
		$full_base_path = $base_path;
		$full_dest_path = $dest_path;
		// Don't use "count( $torrent_files ) > 1" check (there can be one file in a subdir)
		if( $is_multy_file )
		{
			// torrent is a directory
			$full_base_path .= rtAddTailSlash( $base_file );
			$full_dest_path .= $add_path ? rtAddTailSlash( $base_name ) : "";
		}
		else {
			// torrent is a single file
		}

		if( $dbg ) rtDbg( __FUNCTION__, "from ".$full_base_path );
		if( $dbg ) rtDbg( __FUNCTION__, "to   ".$full_dest_path );

		if( $full_base_path != $full_dest_path && is_dir( $full_base_path ) )
		{
			if( !rtOpFiles( $torrent_files, $full_base_path, $full_dest_path, "Move", $dbg ) )
				$is_ok = false;
			else {
				// Recursively remove source dirs without files
				if( $dbg ) rtDbg( __FUNCTION__, "clean ".$full_base_path );
				if( $is_multy_file )
				{
					rtRemoveDirectory( $full_base_path, false );
					if( $dbg && is_dir( $full_base_path ) )
						rtDbg( __FUNCTION__, "some files were not deleted" );
				}
			}
		}
	}

	if( $is_ok )
	{
		// Keep this daemon object and its checker customs while changing the directory.
		$is_ok = $add_path ?
			rtExec( "d.set_directory",      array( $hash, $dest_path ), $dbg ) :
			rtExec( "d.set_directory_base", array( $hash, $dest_path ), $dbg );

		if( $is_ok )
		{
			if( $is_active )
				$is_ok = rtExec( array( "d.open", "d.start" ), $hash, $dbg );
			elseif( $is_open )
				$is_ok = rtExec( "d.open", $hash, $dbg );
			else
				$is_ok = rtExec( array( "d.open", "d.close" ), $hash, $dbg );
		}
	}

	if( $dbg ) rtDbg( __FUNCTION__, "finished" );
	return $is_ok;
}
