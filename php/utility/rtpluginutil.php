<?php

// Shared live utility functions for AutoTools and DataDir. The plugins keep
// their different transaction and directory-removal contracts locally.

function rtDbg( $prefix, $str )
{
	if( !$str )
		FileUtil::toLog( "" );
	elseif( $prefix && strlen( $prefix ) > 0 )
		FileUtil::toLog( $prefix.": ".$str );
	else
		FileUtil::toLog( $str );
}

function rtAddTailSlash( $str )
{
	$len = strlen( $str );
	if( $len > 0 && $str[$len-1] == '/' )
		return $str;
	return $str.'/';
}

function rtMkDir( $dir, $mode = 0777 )
{
	if( !is_dir( $dir ) )
	{
		mkdir( $dir, $mode, true );
		if( !is_dir( $dir ) )
			return false;
	}
	return true;
}

function rtExec( $cmds, $hash, $dbg )
{
	$req = new rXMLRPCRequest();
	if( !is_array( $cmds ) )
	{
		$req->addCommand( new rXMLRPCCommand( $cmds, $hash ) );
		if( $dbg ) rtDbg( __FUNCTION__, $cmds );
	}
	else {
		$s = '';
		foreach( $cmds as $cmd )
		{
			$s.= $cmd.", ";
			$req->addCommand( new rXMLRPCCommand( $cmd, $hash ) );
		}
		if( $dbg ) rtDbg( __FUNCTION__, substr( $s, 0, -2 ) );
	}
	if( !$req->run() )
	{
		if( $dbg ) rtDbg( __FUNCTION__, "rXMLRPCRequest() run fail" );
		return null;
	}
	elseif( $req->fault )
	{
		if( $dbg ) rtDbg( __FUNCTION__, "rXMLRPCRequest() fault" );
		return null;
	}
	else return $req;
}
