<?php
require_once( dirname(__FILE__).'/../_task/task.php' );
require_once( dirname(__FILE__).'/../../php/rtorrent.php' );
eval( FileUtil::getPluginConf( 'dump' ) );

// An error the interface can translate: the entry names a key in theUILang
// and carries the values to substitute into it, and plugins/dump/init.js
// renders the pair. The strings are in plugins/dump/lang/.
function dumpError( $reason, $file = '', $user = '' )
{
	return( array( "status"=>255, "errors"=>array(
		array( "reason"=>$reason, "file"=>UTF::display($file), "user"=>$user ) ) ) );
}

// The account a reader of the torrent file would have to be. The numeric id
// stands in when the name cannot be resolved -- a container with no passwd
// entry still answers with the id the file is checked against. The owner of
// this script is not the process, so get_current_user() is a last guess, and
// an empty answer is reported as no answer rather than as a blank name.
function dumpWebUser()
{
	if( function_exists('posix_geteuid') )
	{
		$euid = posix_geteuid();
		$info = function_exists('posix_getpwuid') ? posix_getpwuid($euid) : false;
		if( is_array($info) && isset($info['name']) && strlen($info['name']) )
			return( $info['name'] );
		if( $euid !== false )
			return( (string)$euid );
	}
	$name = (string)getenv('USER');
	return( strlen($name) ? $name : (string)get_current_user() );
}

$input = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? $_POST : $_GET;
$hash = (isset($input['hash']) && is_string($input['hash'])) ? $input['hash'] : '';
$cmd = (isset($input['cmd']) && is_string($input['cmd'])) ? $input['cmd'] : '';

$ret = dumpError( ($hash==='') ? "dumpErrorNoHash" : "dumpErrorNoCommand" );

if(($hash!=='') && ($cmd!==''))
{
	switch($cmd)
	{
		case "dumptorrent":
		{
			Requests::requirePost();
			$identity = preg_match('/\A[0-9a-f]{40}\z/i', $hash) ? strtoupper($hash) : 'invalid-hash';
			// Empty until a file is found. The test below is !== '', so a
			// request that fails must leave a name here that fails it --
			// an unset variable passes it and dumps nothing.
			$fname = '';
			// The same request and order as rTorrent::getSourcePath(), which
			// answers only a path; the reasons below need the answers. A reply
			// without two strings is no usable answer either.
			$req = new rXMLRPCRequest( array(
				new rXMLRPCCommand("get_session"),
				new rXMLRPCCommand("d.get_tied_to_file",$hash)) );
			if(!$req->run() || $req->fault || !isset($req->val[0], $req->val[1])
				|| !is_string($req->val[0]) || !is_string($req->val[1]))
				$ret = dumpError("dumpErrorNoAnswer");
			else
			{
				// The session copy, then the file the download is tied to:
				// the two places the torrent can be, preferred in that order.
				$candidates = array();
				if(!empty($req->val[0]))
					$candidates[] = $req->val[0].$hash.".torrent";
				if(strlen($req->val[1]))
					$candidates[] = $req->val[1];
				$reason = count($candidates) ? "dumpErrorNoFile" : "dumpErrorNoSessionDirectory";
				$file = count($candidates) ? $candidates[0] : '';
				foreach($candidates as $candidate)
				{
					if(is_readable($candidate))
					{
						$fname = $candidate;
						break;
					}
					// A file that is there but closed to this process sends
					// its reader somewhere else entirely from one that is not
					// there, so it is reported in preference to one that is
					// merely missing.
					if(($reason!=="dumpErrorUnreadable") && file_exists($candidate))
					{
						$reason = "dumpErrorUnreadable";
						$file = $candidate;
					}
				}
				if($fname==='')
				{
					$user = ($reason==="dumpErrorUnreadable") ? dumpWebUser() : '';
					if(($reason==="dumpErrorUnreadable") && ($user===''))
						$reason = "dumpErrorUnreadableUnknownUser";
					$ret = dumpError($reason, $file, $user);
				}
			}
			if($fname==='')
			{
				FileUtil::toLog('dump: source refused: '.$identity.' source-unavailable');
				break;
			}
			if(rTorrent::isMetadataStubName(basename($fname), $hash))
			{
				$validatedPath = null;
				if(rTorrent::getSource($hash, null, $validatedPath) === false)
				{
					$reason = $validatedPath === false ? 'source-unavailable' : 'source-invalid';
					$ret = array( "status"=>255, "errors"=>array('Magnet metadata is missing or invalid') );
					FileUtil::toLog('dump: source refused: '.$identity.' '.$reason);
					break;
				}
				$fname = $validatedPath;
			}
			$dumpArguments = $arguments;
			if(rTorrent::isMetadataStubName(basename($fname), $hash))
			{
				// Raw BEP-9 info has no announce; dumptorrent's raw mode can inspect it.
				if(isset($rawMetadataArguments))
					$dumpArguments = $rawMetadataArguments;
				else
				{
					// Keep configured flags while replacing dumptorrent's verbose mode.
					$dumpArguments = preg_replace('/(?<!\S)-v(?!\S)/', '-d', $arguments, 1, $replaced);
					if(!$replaced)
						$dumpArguments = trim($arguments.' -d');
				}
			}
			$commands = array();
			$task = new rTask(array('requester'=>'dump', 'name'=>'dump'));
			$commands[] = Utility::getExternal("dumptorrent")." ".$dumpArguments." ".Utility::escapeShellArg($fname);
			$ret = $task->start($commands, rTask::FLG_DO_NOT_TRIM);
		}
	}
}

CachedEcho::send(JSON::safeEncode($ret),"application/json");
