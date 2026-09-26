<?php
require_once( dirname(__FILE__)."/../../php/xmlrpc.php" );

if(!defined('RTASK_KILL_HELPER'))
	define('RTASK_KILL_HELPER', '/usr/local/bin/rutorrent-task-kill-pidfd');
if(!defined('RTASK_SUPERVISOR_HELPER'))
	define('RTASK_SUPERVISOR_HELPER', '/usr/local/bin/rutorrent-task-supervise');

class rTask
{
	const MAX_CONSOLE_SIZE = 80;
	const MAX_ARG_LENGTH = 2048;

	const FLG_WAIT		= 0x0001;
	const FLG_STRIP_LOGS	= 0x0002;
	const FLG_ONE_LOG	= 0x0004;
	const FLG_ECHO_CMD	= 0x0008;
	const FLG_DEFAULT	= 0x000A;
	const FLG_NO_ERR	= 0x0010;
	const FLG_RUN_AS_WEB	= 0x0020;
	const FLG_RUN_AS_CMD	= 0x0040;
	const FLG_STRIP_ERRS	= 0x0080;
	const FLG_NO_LOG	= 0x0100;
	const FLG_REMOVE_ASCII	= 0x0200;
	const FLG_DO_NOT_TRIM	= 0x0400;

	public $params = array();
	public $id = 0;

	public function __construct( $params, $taskNo = null )
	{
		$this->params = $params;
		$this->id = $taskNo;
		if(empty($this->id))
			$this->id = uniqid( time(), true );
	}

	// A task id is produced by uniqid(time(),true). A value of any other shape
	// names no task, and is encoded so that it addresses a single entry inside
	// the tasks directory instead of a path relative to it. The prefix keeps
	// the encoding of "." and ".." -- which are unreserved, so they survive
	// rawurlencode -- from naming the tasks directory itself or its parent.
	static public function formatId( $taskNo )
	{
		$taskNo = (string)$taskNo;
		return( preg_match('`^[0-9a-f]+\.[0-9]+$`', $taskNo) ? $taskNo : '~'.rawurlencode($taskNo) );
	}

	static public function formatPath( $taskNo )
	{
		return( FileUtil::getSettingsPath().'/tasks/'.self::formatId($taskNo) );
	}

	public function makeDirectory()
	{
		$dir = self::formatPath($this->id);
		FileUtil::makeDirectory($dir);
		return($dir);
	}

	public function start( $commands, $flags = self::FLG_DEFAULT )
	{
		if(!rTorrentSettings::get()->linkExist)
			$flags|=self::FLG_RUN_AS_WEB;
	        if(count($commands))
	        {
			$dir = $this->makeDirectory();
			if(($sh = fopen($dir."/start.sh","w"))!==false)
		        {
				fputs($sh,'#!/bin/sh'."\n");
				fputs($sh,'dir="$(dirname "$0")"'."\n");
				// The native subreaper publishes pid and identity before it starts this shell.
				file_put_contents($dir."/flags",$flags);
				@chmod($dir."/flags",0666);
				fputs($sh,'touch "${dir}"/status'."\n");
				fputs($sh,'chmod a+rw "${dir}"/status'."\n");
				fputs($sh,'touch "${dir}"/errors'."\n");
				fputs($sh,'chmod a+rw "${dir}"/errors'."\n");
				fputs($sh,'touch "${dir}"/log'."\n");
				fputs($sh,'chmod a+rw "${dir}"/log'."\n");
				fputs($sh,'last=0'."\n");
				$err = ($flags & self::FLG_ONE_LOG) ? "log" : "errors";
				foreach( $commands as $ndx=>$cmd )
				{
					if($cmd=='{')
						fputs($sh,'if [ $last -eq 0 ] ; then '."\n");
					else
					if($cmd=='}')
						fputs($sh,'fi'."\n");
					else
					if($cmd=='!{')
						fputs($sh,'if [ $last -ne 0 ] ; then '."\n");
					else
					if($cmd[0]=='>')
						fputs($sh,'echo '.escapeshellarg(substr($cmd,1)).' >> "${dir}"/log'."\n");
					else
					{
						if($flags & self::FLG_ECHO_CMD)
							fputs($sh,'echo '.escapeshellarg($cmd).' >> "${dir}"/log'."\n");
                                	        if($flags & self::FLG_NO_ERR)
							fputs($sh,$cmd.' >> "${dir}"/log'."\n");
						else
        	                                if($flags & self::FLG_NO_LOG)
							fputs($sh,$cmd.' >> "${dir}"/errors 2>> "${dir}"/errors'."\n");
						else
							fputs($sh,$cmd.' 2>> "${dir}"/'.$err.' >> "${dir}"/log'."\n");
						fputs($sh,'if [ $? -ne 0 ] ; then '."\n\t".'last=1'."\n".'fi'."\n");
					}
				}
				fputs($sh,'echo $last > "${dir}"/status'."\n");
					fclose($sh);
				@chmod($dir."/start.sh",0755);
				file_put_contents( $dir."/params", serialize($this->params) );
				if(!is_file(RTASK_SUPERVISOR_HELPER) || !is_executable(RTASK_SUPERVISOR_HELPER))
				{
					return(self::startFailure($dir, $this->id, 'supervisor unavailable; task not started'));
				}
				$launcher = $dir.'/launch.sh';
				$line = 'exec '.escapeshellarg(RTASK_SUPERVISOR_HELPER).' '.escapeshellarg($dir).' '.
					escapeshellarg(Utility::getPHP()).' '.
					escapeshellarg(dirname(__FILE__).'/notify.php').' '.
					escapeshellarg(User::getUser())."\n";
				if(file_put_contents($launcher, '#!/bin/sh'."\n".$line)===false || !@chmod($launcher, 0755))
				{
					return(self::startFailure($dir, $this->id, 'could not write supervisor launcher; task not started'));
				}
				rTorrentSettings::get()->pushEvent( 'TaskStart', $this->params );
				$launchStatus = self::run($launcher, $flags);
				if($launchStatus!==0)
				{
					if(!is_file($dir.'/pid'))
						return(self::startFailure($dir, $this->id, 'supervisor launch failed; task not started'));
					self::logRefusal($dir, 'TaskStart', 'supervisor exited without complete-tree proof');
					return(self::check($this->id, $flags));
				}
				if(!($flags & self::FLG_WAIT)) sleep(1);
				return(self::check($this->id, $flags));
			}
			self::clean($dir);
		}
		return(array
		(
			"no"=>$this->id,
			"pid"=>0,
			"status"=>255,
			"log"=>array(),
			"params"=>array(),
			"errors"=>array(count($commands) ? "Can't start operation" : "Incorrect target directory")
		));
	}

	static public function clean( $dir )
	{
		@FileUtil::deleteDirectory( $dir );
	}

	static protected function removeASCII( $subject )
	{
		$subject = preg_replace('/\x1b(\[|\(|\))[;?0-9]*[0-9A-Za-z]/', "",$subject);
		$subject = preg_replace('/\x1b(\[|\(|\))[;?0-9]*[0-9A-Za-z]/', "",$subject);
		$subject = preg_replace('/[\x03|\x1a]/', "", $subject);
		return($subject);
	}

	static public function notify( $dir, $subject )
	{
		if(is_file($dir.'/params') && is_readable($dir.'/params'))
		{
			$params = unserialize(file_get_contents($dir.'/params'), array( 'allowed_classes'=>false ));
			if( is_array($params) )
			{
				rTorrentSettings::get()->pushEvent( $subject, $params );
				return(true);
			}
		}
		return(false);
	}

	static protected function tail($filename, $lines = 128, $buffer = 16384)
	{
		$sz = filesize($filename);
		if( $sz < 0xFFFF )
			return( file($filename) );
		else
		{
			$f = fopen($filename, "rb");
			fseek($f, -1, SEEK_END);
			if(fread($f, 1) != "\n") $lines -= 1;

			$output = '';
			$chunk = '';

			$currentLines = 0;

			while($currentLines < $lines && ftell($f) > 0)
			{
				$seek = min(ftell($f), $buffer);
				fseek($f, -$seek, SEEK_CUR);

				$startPosition = ftell($f);
				$checkPosition = $startPosition;
				$offset = 0;
				while ($checkPosition > 0) {
					fseek($f, $checkPosition, SEEK_SET);
					$byte = fread($f, 1);
					$byteValue = ord($byte);
					if (($byteValue & 0xC0) === 0x80) {
						$checkPosition++;
					} else {
						$offset = $checkPosition - $startPosition;
						$startPosition = $checkPosition;
						break;
					}
				}
				fseek($f, $startPosition, SEEK_SET);
				$chunk = fread($f, $seek - $offset);
				$currentLines += substr_count($chunk, "\n");
				$output = $chunk . $output;
				fseek($f, $startPosition, SEEK_SET);

			}
			fclose($f);

			$linesArray = explode("\n", $output);
			if(count($linesArray) > $lines) {
				$linesArray = array_slice($linesArray, -$lines);
			}
			return $linesArray;
		}
	}

	static protected function processLog( $dir, $logName, &$ret, $stripConsole, $removeASCII, $doNotTrim )
	{
		if(is_file($dir.'/'.$logName) && is_readable($dir.'/'.$logName))
		{
			if($doNotTrim) $lines = file($dir.'/'.$logName);
			else $lines = self::tail($dir.'/'.$logName);
			foreach( $lines as $line )
			{
//				if($stripConsole)
				{
					$pos = strrpos($line,"\r");
					if($pos!==false)
					{
						$line = rtrim(substr($line,$pos+1));
						if(strlen($line)==0)
							continue;
					}
					if(strrpos($line,chr(8))!==false)
					{
						$len = strlen($line);
						$res = array();
						for($i=0; $i<$len; $i++)
						{
							if($line[$i]==chr(8))
								array_pop($res);
							else
								$res[] = $line[$i];
						}
						$line = implode('',$res);
					}
				}
				if($removeASCII)
					$line = self::removeASCII( $line );
				$ret[$logName][] = rtrim($line);
			}
			if($stripConsole && (count($ret[$logName])>self::MAX_CONSOLE_SIZE))
				array_splice($ret[$logName],0,count($ret[$logName])-self::MAX_CONSOLE_SIZE);
		}
	}

	static public function supervisorMarker($dir, $name)
	{
		return(@file_get_contents($dir.'/'.$name)==="1\n");
	}

	static public function supervisorOutcome($dir)
	{
		$outcome = @file_get_contents($dir.'/supervisor.outcome');
		return(($outcome==="normal\n" || $outcome==="cancelled\n") ? trim($outcome) : null);
	}

	static public function check( $taskNo, $flags = null )
	{
		$dir = self::formatPath($taskNo);
		$ret = array
		(
			"no"=>$taskNo,
			"pid"=>0,
			"status"=>-1,
			"log"=>array(),
			"errors"=>array(),
			"params"=>array(),
			"start"=>@filemtime($dir.'/pid'),
			"finish"=>0
		);
		if(is_file($dir.'/pid') && is_readable($dir.'/pid'))
		{
			if(is_null($flags))
				$flags = intval(@file_get_contents($dir.'/flags'));
			$ret["pid"] = intval(trim(@file_get_contents($dir.'/pid')));
			if(is_file($dir.'/status') && is_readable($dir.'/status'))
			{
				$status = trim(file_get_contents($dir.'/status'));
				if(strlen($status))
				{
					if((!is_file($dir.'/supervisor.version') && !is_file($dir.'/supervisor.complete')) ||
						(self::supervisorMarker($dir, 'supervisor.version') &&
						 self::supervisorMarker($dir, 'supervisor.complete') &&
						 self::supervisorOutcome($dir)!==null))
					{
						$ret["status"] = intval($status);
						$ret["finish"] = filemtime($dir.'/status');
					}
				}
			}
			if(is_file($dir.'/params') && is_readable($dir.'/params'))
				$ret["params"] = unserialize(file_get_contents($dir.'/params'), array( 'allowed_classes'=>false ));
			self::processLog($dir, 'log', $ret, ($flags & self::FLG_STRIP_LOGS), ($flags & self::FLG_REMOVE_ASCII), ($flags & self::FLG_DO_NOT_TRIM) );
			self::processLog($dir, 'errors', $ret, ($flags & self::FLG_STRIP_ERRS), ($flags & self::FLG_REMOVE_ASCII), false);
			if(!is_file($dir.'/supervisor.version'))
				$ret['errors'][] = 'rtask: legacy task lacks supervisor; complete-tree state cannot be proved';
			if(is_file($dir.'/supervisor.notify-pending'))
				$ret['errors'][] = 'rtask: normal completion notification is still pending';
			if(is_file($dir.'/supervisor.notify-failed'))
				$ret['errors'][] = 'rtask: normal completion notification failed: '.
					trim((string)@file_get_contents($dir.'/supervisor.notify-failed')).'; task retained';
			if(is_file($dir.'/supervisor.version') && !self::supervisorMarker($dir, 'supervisor.version'))
			{
				$ret['status'] = -2;
				$ret['errors'][] = 'rtask: invalid supervisor.version; task retained';
			}
			else if(is_file($dir.'/supervisor.complete') && !self::supervisorMarker($dir, 'supervisor.complete'))
			{
				$ret['status'] = -2;
				$ret['errors'][] = 'rtask: invalid supervisor.complete; task retained';
			}
			else if(is_file($dir.'/supervisor.complete') && self::supervisorOutcome($dir)===null)
			{
				$ret['status'] = -2;
				$ret['errors'][] = 'rtask: invalid supervisor.outcome; task retained';
			}
			else if(is_file($dir.'/supervisor.version') && !self::supervisorMarker($dir, 'supervisor.complete')
				&& !self::supervisorAlive($dir, $ret['pid']))
			{
				$ret['status'] = -2;
				$ret['errors'][] = 'rtask: supervisor exited before proving all descendants stopped; task retained';
			}
		}
		else if(is_file($dir.'/errors') && filesize($dir.'/errors')>0)
		{
			$ret['status'] = 255;
			self::processLog($dir, 'errors', $ret, false, false, false);
		}
		return($ret);
	}

	static protected function supervisorAlive($dir, $pid)
	{
		if($pid<=1 || !is_file($dir.'/pid.identity')) return(false);
		$record = @file_get_contents($dir.'/pid.identity');
		$current = @file_get_contents('/proc/'.$pid.'/stat');
		$boot = @file_get_contents('/proc/sys/kernel/random/boot_id');
		if($record===false || $current===false || $boot===false || strpos($record, $boot)!==0)
			return(false);
		$expected = @stat(RTASK_SUPERVISOR_HELPER);
		$executable = @stat('/proc/'.$pid.'/exe');
		if($expected===false || $executable===false ||
			$expected['dev']!==$executable['dev'] || $expected['ino']!==$executable['ino'])
			return(false);
		$recordedStat = substr($record, strlen($boot));
		$recordedTick = self::statStartTick($recordedStat, $pid);
		return($recordedTick!==null && $recordedTick===self::statStartTick($current, $pid));
	}

	static protected function statStartTick($stat, $pid)
	{
		$end = strrpos($stat, ') ');
		if($end===false || substr($stat, 0, strpos($stat, ' '))!==(string)$pid) return(null);
		$fields = preg_split('/\s+/', trim(substr($stat, $end+2)));
		return(isset($fields[19]) && ctype_digit($fields[19])) ? $fields[19] : null;
	}

	static public function run( $cmd, $flags = 0 )
	{
		$ret = -1;
		$params = " >/dev/null 2>&1";
		if(!($flags & self::FLG_WAIT))
			$params.=" &";
		if($flags & self::FLG_RUN_AS_WEB)
		{
			$cmd = ($flags & self::FLG_RUN_AS_CMD) ? '-c '.escapeshellarg($cmd) : escapeshellarg($cmd);
			exec('sh '.$cmd.$params, $output, $ret);
		}
		else
		{
			$req = new rXMLRPCRequest(
				((rTorrentSettings::get()->iVersion>=0x900) && !($flags & self::FLG_WAIT)) ?
					new rXMLRPCCommand( "execute.nothrow", array("","sh","-c",'"$0" "$1" </dev/null >/dev/null 2>&1 &',"sh",$cmd) ) :
					new rXMLRPCCommand( "execute_nothrow", array("sh","-c",$cmd.$params) )
				);
			if($req->success() && count($req->val))
				$ret = intval($req->val[0]);
		}
		return($ret);
	}

	static protected function logRefusal($dir, $operation, $reason)
	{
		$message = 'rtask: '.$operation.' failed for '.$dir.' ('.$reason.'); task directory kept for diagnosis';
		@file_put_contents($dir.'/errors', $message."\n", FILE_APPEND | LOCK_EX);
		error_log($message);
	}

	static protected function startFailure($dir, $id, $reason)
	{
		self::logRefusal($dir, 'TaskStart', $reason);
		return(array('no'=>$id, 'pid'=>0, 'status'=>255, 'log'=>array(),
			'params'=>array(), 'errors'=>array($reason)));
	}

	static protected function failKill( $dir, $reason )
	{
		self::logRefusal($dir, 'TaskKill', $reason);
		return(false);
	}

	static public function kill( $taskNo, $flags = null )
	{
		$dir = self::formatPath($taskNo);
		if(is_file($dir.'/pid') && is_readable($dir.'/pid'))
		{
			if(!is_file($dir.'/supervisor.version'))
				return(self::failKill($dir, 'legacy task has no supervisor; complete-tree cancellation cannot be proved; no signal sent'));
			if(!self::supervisorMarker($dir, 'supervisor.version'))
				return(self::failKill($dir, 'invalid supervisor.version; no signal sent'));
			if(is_file($dir.'/supervisor.complete') && !self::supervisorMarker($dir, 'supervisor.complete'))
				return(self::failKill($dir, 'invalid supervisor.complete; task retained'));
			if(is_file($dir.'/supervisor.complete') && self::supervisorOutcome($dir)===null)
				return(self::failKill($dir, 'invalid supervisor.outcome; task retained'));
			if(is_file($dir.'/supervisor.notify-pending'))
				return(self::failKill($dir, 'normal completion notification pending; task retained'));
			if(!self::supervisorMarker($dir, 'supervisor.complete'))
			{
				if(is_null($flags))
					$flags = intval(file_get_contents($dir.'/flags'));
				$pidText = trim(file_get_contents($dir.'/pid'));
				$pid = filter_var($pidText, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>2)));
				if($pid===false)
					return(self::failKill($dir, 'pid identity: invalid pid, including pid 1; no signal sent'));
				// A missing native helper cannot safely fall back to numeric kill.
				if(!is_file(RTASK_KILL_HELPER) || !is_executable(RTASK_KILL_HELPER))
					return(self::failKill($dir, 'pidfd helper unavailable; no signal sent'));
				$cmd = escapeshellarg(RTASK_KILL_HELPER).' '.$pid.' '.
					escapeshellarg($dir.'/pid.identity').' --supervisor-cancel '.escapeshellarg(RTASK_SUPERVISOR_HELPER);
				$result = self::run($cmd, ($flags & self::FLG_RUN_AS_WEB) | self::FLG_WAIT | self::FLG_RUN_AS_CMD);
				if($result===3 && self::supervisorMarker($dir, 'supervisor.complete'))
					return(self::kill($taskNo, $flags));
				if($result===3)
					return(self::failKill($dir, 'pid identity: supervisor absent or mismatched pid.identity; no signal sent'));
				if($result!==0)
					return(self::failKill($dir, 'verified supervisor cancellation failed; signal state uncertain'));
				for($attempt=0; $attempt<300; $attempt++)
				{
					clearstatcache(true, $dir.'/supervisor.complete');
					if(self::supervisorMarker($dir, 'supervisor.complete')) break;
					usleep(10000);
				}
				if(!self::supervisorMarker($dir, 'supervisor.complete'))
					return(self::failKill($dir, 'supervisor cancellation pending or uncertain; task retained'));
				$outcome = self::supervisorOutcome($dir);
				if($outcome===null)
					return(self::failKill($dir, 'invalid supervisor.outcome; task retained'));
				if($outcome==='normal')
				{
					if(is_file($dir.'/supervisor.notify-pending'))
						return(self::failKill($dir, 'normal completion notification pending; task retained'));
				}
				else
					self::notify($dir,"TaskKill");
			}
			self::clean($dir);
		}
		return(true);
	}
}

class rTaskManager
{
	const MAX_TASK_COUNT = 100;

	static public function obtain()
	{
		$tasks = array();
		$dir = FileUtil::getSettingsPath().'/tasks/';
		if( $handle = @opendir($dir) )
		{
			while(false !== ($file = readdir($handle)))
			{
				if($file != "." && $file != ".." && is_dir($dir.$file))
				{
					$tasks[$file] = rTask::check( $file );
					if( isset($tasks[$file]["params"]["name"]) )
					{
						$tasks[$file]["name"] = $tasks[$file]["params"]["name"];
						unset($tasks[$file]["params"]["name"]);
					}
					else
					{
						$tasks[$file]["name"] = 'Unknown';
					}
					if( isset($tasks[$file]["params"]["requester"]) )
					{
						$tasks[$file]["requester"] = $tasks[$file]["params"]["requester"];
        					unset($tasks[$file]["params"]["requester"]);
					}
					else
					{
						$tasks[$file]["requester"] = 'Unknown';
					}
				}
			}
			closedir($handle);
	        }
	        uasort($tasks,array(self::class, 'sortByStarted'));
	        return($tasks);
	}

	static public function sortByStarted($a,$b)
	{
		return( $a['start'] > $b['start'] ? -1 : ($a['start'] < $b['start'] ? 1 : 0) );
	}

	static public function isPIDExists( $pid )
	{
		return( function_exists( 'posix_getpgid' ) ? (posix_getpgid($pid)!==false) : file_exists( '/proc/'.$pid ) );
	}

	static public function cleanup()
	{
		$counter = 0;
		$tasks = self::obtain();
		foreach( $tasks as $id=>$task )
		{
			$finished_with_error = ($task["status"]>0);
			$dir = rTask::formatPath($id);
			$unproved_supervisor = is_file($dir.'/supervisor.version') &&
				(!rTask::supervisorMarker($dir, 'supervisor.version') ||
				 !rTask::supervisorMarker($dir, 'supervisor.complete') ||
				 rTask::supervisorOutcome($dir)===null ||
				 is_file($dir.'/supervisor.notify-pending'));
			$unproved_legacy = !is_file($dir.'/supervisor.version') && is_file($dir.'/pid');
			$in_progress = $unproved_supervisor || $unproved_legacy ||
				(!$finished_with_error && $task["pid"] && self::isPIDExists($task["pid"]));
			if( !$finished_with_error && !$in_progress && ($counter>=self::MAX_TASK_COUNT) )
				rTask::clean(rTask::formatPath($id));
			else
				$counter++;
		}
	}

	static public function remove( $list )
	{
		$tasks = array();
		$dir = FileUtil::getSettingsPath().'/tasks/';
		if( $handle = @opendir($dir) )
		{
			while(false !== ($file = readdir($handle)))
			{
				if($file != "." && $file != ".." && is_dir($dir.$file) && in_array($file,$list))
					$tasks[] = $file;
			}
			closedir($handle);
			foreach( $tasks as $id )
				rTask::kill( $id );
			$tasks = self::obtain();
	        }
	        return($tasks);
	}
}
