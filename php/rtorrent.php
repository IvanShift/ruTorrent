<?php

require_once( 'util.php' );
require_once( 'xmlrpc.php' );
require_once( 'Torrent.php' );

class rTorrent
{
	const RTORRENT_PACKET_LIMIT = 1572864;
	const LOAD_WAIT_ATTEMPTS = 40;
	const LOAD_WAIT_DELAY_US = 50000;
	const LOAD_PROOF_KEY_PREFIX = 'ru-load-proof-';

	// An addition is appended to the load call as a trailing parameter, and
	// rtorrent reads every parameter a load command carries after the torrent
	// as a command to run against the download being created. That is how the
	// directory below is set. So an addition is an rtorrent command, and the
	// set that may be given as one is the set the shipped plugins build:
	// throttle, view membership and connection type. Anything outside it is
	// refused -- 'execute' above all, which would run a program as the daemon's
	// user, but equally a second directory command, which would land after the
	// one correctDirectory() approved and replace it.
	//
	// This is a different boundary from the one rXMLRPCCommand draws. That one
	// governs the <methodName> element; an addition travels as a parameter, is
	// HTML-escaped on its way into the payload, and is read as a command by
	// rtorrent rather than by the XMLRPC layer.
	// d.set_custom and d.set_custom3 are on the list because the shipped
	// plugins build them: the edit and retrackers plugins mark a reloaded
	// torrent with d.set_custom3, and rutracker_check writes its
	// replacement marker and check state with d.set_custom. The other
	// custom fields are deliberately absent -- d.set_custom1 and
	// d.set_custom2 hold the label and the comment, which sendTorrent()
	// already writes from its own parameters, and nothing builds
	// d.set_custom4 or d.set_custom5 as an addition.
	const ADDITION_COMMANDS = array(
		'd.set_throttle_name',
		'd.set_connection_seed',
		'view.set_visible',
		'd.views.push_back_unique',
		'd.set_custom',
		'd.set_custom3',
	);

	/**
	 * Read the argument text of an addition the way rtorrent reads it, and
	 * answer with the arguments the daemon would hand the command -- or false
	 * where the daemon would refuse the text, or read a second command out of
	 * it.
	 *
	 * An addition is not a name and a value. DownloadFactory puts every entry
	 * of a load command list through rpc::parse_command_multiple_std()
	 * (src/core/download_factory.cc), so the text after the '=' is parsed by
	 * the same parser as the name, and the only way to say what it asks for is
	 * to read it as parse_whole_list(..., parse_is_delim_command) does
	 * (src/rpc/parse.cc) and then apply parse_command()'s rule for what may
	 * follow (src/rpc/parse_commands.cc).
	 *
	 * What that parser does, which is why the answer is a list of arguments
	 * rather than a yes or a no:
	 *
	 *  - parse_object() decides on the raw first byte. '{' opens a block and
	 *    '(' a function object, and parse_command_execute() runs both. Neither
	 *    is reachable once the byte is a '"', so a quoted argument cannot be
	 *    either -- it goes to parse_string() and comes back a string.
	 *  - parse_string()'s quoted branch ends only at an unescaped '"' and never
	 *    consults the delimiter, so a ',', a ';', a newline, a NUL and a space
	 *    inside quotes are all bytes of the value. Its unquoted branch ends at
	 *    any of them (parse_is_delim_command), and '\' escapes the byte after
	 *    it in both.
	 *  - after the list, parse_command() skips spaces and tabs and then demands
	 *    the end of the text or one of ';', a newline, a NUL. Anything else is
	 *    "Junk at end of input" and the load is abandoned; one of those three
	 *    ends the command, and parse_command_multiple() parses what follows as
	 *    the next command and runs it. Neither may be allowed to leave here.
	 *
	 * The arguments come back with the quoting undone, because that is the form
	 * the one remaining danger is read in: see isValidAddition().
	 */
	static private function additionArguments( $text )
	{
		// A NUL ends a command wherever the daemon reads the text from a
		// NUL-terminated buffer, and no value a caller builds carries one, so
		// it is refused before the quoting can be argued about.
		if(strpos($text,"\0")!==false)
			return(false);
		$arguments = array();
		$i = 0;
		$n = strlen($text);
		while(true)
		{
			// parse_list(): only a space and a tab are skipped here, which is
			// why a leading space does not hide the byte parse_object() reads.
			while(($i<$n) && (($text[$i]===' ') || ($text[$i]==="\t")))
				$i++;
			// parse_object(): the evaluated forms, on the raw byte.
			if(($i<$n) && (($text[$i]==='{') || ($text[$i]==='(')))
				return(false);
			$value = '';
			if(($i<$n) && ($text[$i]==='"'))
			{
				$i++;
				$closed = false;
				while($i<$n)
				{
					if($text[$i]==='"')
					{
						$closed = true;
						$i++;
						break;
					}
					if($text[$i]==='\\')
					{
						$i++;
						// "Escape character at end of input."
						if($i>=$n)
							return(false);
					}
					$value .= $text[$i++];
				}
				// "Missing closing quote."
				if(!$closed)
					return(false);
			}
			else
			{
				while($i<$n)
				{
					$c = $text[$i];
					// parse_is_delim_command: ',', ';' and isspace().
					if(($c===',') || ($c===';') || (strpos(" \t\n\r\v\f",$c)!==false))
						break;
					if($c==='\\')
					{
						$i++;
						if($i>=$n)
							return(false);
					}
					$value .= $text[$i++];
				}
			}
			$arguments[] = $value;
			while(($i<$n) && (($text[$i]===' ') || ($text[$i]==="\t")))
				$i++;
			if(($i<$n) && ($text[$i]===','))
			{
				$i++;
				continue;
			}
			break;
		}
		while(($i<$n) && (($text[$i]===' ') || ($text[$i]==="\t")))
			$i++;
		// Anything left is either a command the daemon would run after this one
		// or the junk that makes it throw away the load.
		if($i<$n)
			return(false);
		return($arguments);
	}

	/**
	 * Whether one addition may be sent. Each permitted name is compared both as
	 * written and as the running daemon's alias table spells it, because that is
	 * how a caller builds one: getCmd('d.set_throttle_name=').$name.
	 *
	 * The name is the first thing the daemon reads and not the only one, so the
	 * text after it is judged as well -- a caller appends values of its own and
	 * a request's alike, and an RSS filter's throttle and ratio arrive in the
	 * POST body, so a name that matches says nothing about what follows it.
	 *
	 * additionArguments() has already refused the text if the daemon would read
	 * a second command out of it, or throw the load away. One danger survives
	 * that, and it is the one quoting cannot reach: parse_command_execute()
	 * hands an argument back to parse_command() and calls it when its first
	 * byte is a '$', and it reads that byte after parse_string() has taken the
	 * quotes off (src/rpc/parse_commands.cc). So the test is on the argument as
	 * the command will receive it, which is also why the extratio plugin's
	 * d.set_custom=x-extratio1,"$execute_capture={...}" works as it does.
	 */
	static public function isValidAddition( $addition )
	{
		if(!is_string($addition))
			return(false);
		$eq = strpos($addition,'=');
		if($eq===false)
			return(false);
		$name = substr($addition,0,$eq);
		$permittedName = false;
		foreach(self::ADDITION_COMMANDS as $permitted)
			if(($name===$permitted) ||
				($name===rTorrentSettings::get()->getCommand($permitted)))
			{
				$permittedName = true;
				break;
			}
		if(!$permittedName)
			return(false);
		$arguments = self::additionArguments(substr($addition,$eq+1));
		if($arguments===false)
			return(false);
		foreach($arguments as $value)
			if(isset($value[0]) && ($value[0]==='$'))
				return(false);
		return(true);
	}

	/**
	 * A list holding one addition that may not be sent sends none of itself: an
	 * add is one load call, so dropping the offending entry would still perform
	 * the rest of an operation that was not the one asked for.
	 *
	 * Public because a caller that erases the download before it loads it again
	 * has to ask before the erase. sendTorrent() answering false afterwards is
	 * a download that no longer exists.
	 */
	static public function areValidAdditions( $addition )
	{
		if(is_null($addition))
			return(true);
		if(!is_array($addition))
			return(false);
		foreach($addition as $prm)
			if(!self::isValidAddition($prm))
				return(false);
		return(true);
	}

	/**
	 * Build one addition: the command, '=', and each value quoted, the values
	 * separated by the comma rtorrent separates arguments with.
	 *
	 * A value is an argument to a command the daemon parses itself, so it is
	 * quoted exactly as the download directory beside it is. Pasted in as bare
	 * text it stops being one argument: a comma splits it in two, a space makes
	 * the daemon throw the whole load away, and a semicolon or a newline ends
	 * the command and hands the daemon whatever follows as the next one to run.
	 * Quoted, all of those are bytes of the value.
	 *
	 * Values are taken one per parameter rather than pre-joined, so nothing
	 * here has to find the boundary between two of them: a command taking
	 * several -- d.set_custom takes a key and a value -- names them separately
	 * and each is quoted on its own.
	 *
	 * The value must be in its own bytes: rXMLRPCRequest::unescapeValue() puts
	 * a value read back from the daemon into that form.
	 *
	 * Answers false for a value it will not build, and false is not a valid
	 * addition, so the list holding it is refused whole rather than sent short.
	 */
	static public function additionCommand( $command, ...$values )
	{
		if(!count($values))
			return(false);
		$quoted = array();
		foreach($values as $value)
		{
			if(is_int($value))
				$value = (string)$value;
			if(!is_string($value))
				return(false);
			// The one form quoting does not reach. rtorrent takes the quotes
			// off and then runs an argument whose first byte is '$' as a
			// command of its own (parse_command_execute,
			// src/rpc/parse_commands.cc), so it is refused instead -- as
			// XMLRPCProxy::rebuildSafeLoadParam() refuses it on the way in.
			if(isset($value[0]) && ($value[0]==='$'))
				return(false);
			// Refused here as well as in isValidAddition(), so a caller is
			// told at the point it builds one rather than at the point the
			// list is sent.
			if(strpos($value,"\0")!==false)
				return(false);
			$quoted[] = self::quoteCommandArg($value);
		}
		return(getCmd($command.'=').implode(',',$quoted));
	}

	/**
	 * @return string|false|null Confirmed hash, failed request, or pending load.
	 *                          An accepted pre-dispatch callback leaves its receipt
	 *                          available even if the RPC fails; false then does not
	 *                          prove the deferred load was rejected. The receipt's
	 *                          raw flag allows source cleanup after dispatch.
	 */
	static public function sendTorrent($fname, $isStart, $isAddPath, $directory, $label, $saveTorrent, $isFast, $isNew = true, $addition = null, &$pendingReceipt = null, $beforeDispatch = null)
	{
		$pendingReceipt = null;
		if(($beforeDispatch !== null && !is_callable($beforeDispatch)) ||
			!self::areValidAdditions($addition))
			return false;
		$hash = false;
		$mustSave = is_object($fname);
		$torrent = $mustSave ? $fname : new Torrent($fname);

		if(!$torrent->errors())
		{
			if($isFast && ($resume = self::fastResume($torrent, $directory, $isAddPath)))
			{
				$torrent = $resume;
				$mustSave = true;
			}
			else
			{
				if($isNew && isset($torrent->{'libtorrent_resume'}))
				{
					unset($torrent->{'libtorrent_resume'});
					$mustSave = true;
				}
			}
			if($isNew && isset($torrent->{'rtorrent'}))
			{
				unset($torrent->{'rtorrent'});
				$mustSave = true;
			}
			$raw_value = base64_encode($torrent->__toString());
			$filename = is_object($fname) ? $torrent->getFileName() : $fname;
			$rawLoad = strlen($raw_value)<self::RTORRENT_PACKET_LIMIT;
			if($rawLoad)
			{
				$cmd = new rXMLRPCCommand( $isStart ? 'load_raw_start' : 'load_raw' );
				$cmd->addParameter($raw_value,"base64");
			}
			else
			{
				if(!User::isLocalMode())
				{
					// we can't send torrent to the other host without FS sharing
					return(false);
				}
				if(is_null($filename))
				{
					$filename = FileUtil::getTempFilename($torrent->name(), 'torrent');
					$mustSave = true;
				}
				if($mustSave)
				{
					// because torrent may be changed in memory
					$torrent->save($filename);
				}
				$cmd = new rXMLRPCCommand( $isStart ? 'load_start' : 'load' );
				$cmd->addParameter($filename);
			}
			// A fresh key cannot match an older download with the same infohash
			// or overwrite a user's custom key. It must be the first deferred
			// command: later caller additions can abort while the load survives.
			$proofKey = self::LOAD_PROOF_KEY_PREFIX . bin2hex(random_bytes(16));
			$cmd->addParameter(getCmd("d.set_custom")."=".$proofKey.",1");
			if(!is_null($filename) && (rTorrentSettings::get()->iVersion>=0x805))
				$cmd->addParameter(getCmd("d.set_custom")."=x-filename,".rawurlencode(FileUtil::getFileName($filename)));
			$req = new rXMLRPCRequest();
			$directory = self::parseDirectory($directory, $isAddPath);
			if($directory && (strlen($directory)>0))
			{
				if(!rTorrentSettings::get()->correctDirectory($directory))
					return(false);
				$req->addCommand( new rXMLRPCCommand( 'execute', array('mkdir','-p',$directory) ) );
				$cmd->addParameter( getCmd($isAddPath ? "d.set_directory=" : "d.set_directory_base=").self::quoteCommandArg($directory) );
			}
			$comment = $torrent->comment();
			if($comment)
			{
				if(UTF::isInvalidUTF8($comment))
					$comment = UTF::win2utf($comment);
				if(strlen($comment)>0)
				{
					$comment = "VRS24mrker".rawurlencode($comment);
					if(strlen($comment)<=4096)
						$cmd->addParameter(getCmd("d.set_custom2=").$comment);
				}
			}
			if($label && (strlen($label)>0))
			{
				$label = rawurlencode($label);
				if(strlen($label)<=4096)
					$cmd->addParameter(getCmd("d.set_custom1=").$label);
			}
			if(is_array($addition))
				foreach($addition as $key=>$prm)
					$cmd->addParameter($prm,'string');
			$req->addCommand( $cmd );
			$hash = $torrent->hash_info();
			$loadReceipt = array('hash' => $hash, 'key' => $proofKey, 'raw' => $rawLoad);
			// Let a caller persist the proof before a deferred load can run.
			if($beforeDispatch !== null)
			{
				if(!$beforeDispatch($loadReceipt)) return false;
				// The transport reply can be lost after the daemon accepts the load.
				$pendingReceipt = $loadReceipt;
			}
			if($req->run() && !$req->fault)
			{
				$status = self::waitForLoad($hash, $proofKey);
				if($status === 'ours')
				{
					if($rawLoad && !is_null($filename) && !$saveTorrent)
						@unlink($filename);
					return $hash;
				}
				$pendingReceipt = $loadReceipt;
				FileUtil::toLog('rtorrent: load unconfirmed: '.$hash.' status='.$status);
				return null;
			}
		}
		return false;
	}

	/** Check a receipt from a deferred load without accepting an old hash. */
	static public function pendingLoadStatus($receipt)
	{
		if(!is_array($receipt) || !isset($receipt['hash'], $receipt['key'])
			|| !is_string($receipt['hash']) || !is_string($receipt['key'])
			|| !preg_match('/^[A-F0-9]{40}$/D', $receipt['hash'])
			|| !preg_match('/^ru-load-proof-[a-f0-9]{32}$/D', $receipt['key']))
			return 'missing';
		$req = new rXMLRPCRequest(new rXMLRPCCommand(getCmd('d.get_custom'),
			array($receipt['hash'], $receipt['key'])));
		$req->important = false;
		if(!$req->run() || $req->fault)
			return 'missing';
		return isset($req->val[0]) && (string)$req->val[0] === '1' ? 'ours' : 'foreign';
	}

	static private function waitForLoad($hash, $key)
	{
		$receipt = array('hash' => $hash, 'key' => $key);
		$deadline = microtime(true) + self::LOAD_WAIT_ATTEMPTS
			* self::LOAD_WAIT_DELAY_US / 1000000;
		// The download can be visible before its deferred custom command runs.
		// A foreign value is final only after this bounded settle window.
		$status = 'missing';
		for($attempt = 0; $attempt < self::LOAD_WAIT_ATTEMPTS; $attempt++)
		{
			if($attempt) usleep(self::LOAD_WAIT_DELAY_US);
			if(microtime(true) >= $deadline) break;
			$status = self::pendingLoadStatus($receipt);
			if($status === 'ours') return $status;
		}
		return $status;
	}

	static public function sendMagnet($magnet, $isStart, $isAddPath, $directory, $label, $addition = null)
	{
		if(!self::areValidAdditions($addition))
			return false;
	        $hpos = stripos($magnet,'xt=urn:btih:');
	        if($hpos!==false)
	        {
	        	$hpos+=12;
	        	$fpos = stripos($magnet,'&',$hpos);
			if($fpos===false)
				$fpos = strlen($magnet);
			$hash = strtoupper(substr($magnet,$hpos,$fpos-$hpos));
                        if(strlen($hash)==32)
		        	$hash = Decode::base32decode($hash);
	        	if(strlen($hash)==40)
	        	{
				$req = new rXMLRPCRequest();
				$cmd = new rXMLRPCCommand( $isStart ? 'load_start' : 'load' );
				$cmd->addParameter($magnet);
				$directory = self::parseDirectory($directory, $isAddPath);
				if($directory && (strlen($directory)>0))
				{
					if(!rTorrentSettings::get()->correctDirectory($directory))
						return(false);
					$cmd->addParameter( getCmd($isAddPath ? "d.set_directory=" : "d.set_directory_base=").self::quoteCommandArg($directory) );
					$req->addCommand( new rXMLRPCCommand( 'execute', array('mkdir','-p',$directory) ) );
				}
				if($label && (strlen($label)>0))
				{
					$label = rawurlencode($label);
					if(strlen($label)<=4096)
						$cmd->addParameter(getCmd("d.set_custom1=").$label);
				}
				if(is_array($addition))
					foreach($addition as $key=>$prm)
						$cmd->addParameter($prm,'string');
				$req->addCommand( $cmd );
				if($req->success())
					return($hash);
			}
		}
		return(false);
	}

	// The info hashes rtorrent has loaded, upper case, as keys. rtorrent
	// answers a load for a hash it already has without a fault and drops
	// the new copy, so the answer to the load alone cannot tell an
	// addition from a duplicate. A failed snapshot returns false, never an
	// empty session: callers must refuse additions when the answer is unknown.
	static public function loadedHashes()
	{
		$hashes = array();
		$req = new rXMLRPCRequest( new rXMLRPCCommand("download_list") );
		if(!$req->success() || !is_array($req->val))
		{
			FileUtil::toLog('rtorrent: download_list failed; torrent addition refused');
			return(false);
		}
		foreach($req->val as $hash)
			$hashes[strtoupper($hash)] = true;
		return($hashes);
	}

	static public function getSource($hash)
	{
		$req = new rXMLRPCRequest( array(
			new rXMLRPCCommand("get_session"),
			new rXMLRPCCommand("d.get_tied_to_file",$hash)) );
		if($req->run() && !$req->fault)
		{
			$fname = $req->val[0].$hash.".torrent";
			if(empty($req->val[0]) || !is_readable($fname))
			{
				if(strlen($req->val[1]) && is_readable($req->val[1]))
					$fname = $req->val[1];
				else
					$fname = null;
			}
			if($fname)
			{
				// rTorrent 0.16 stores BEP-9 magnet metadata as the raw
				// bencoded info dictionary in <hash>.meta. Passing that path to
				// Torrent builds a new torrent *of the .meta file*, producing a
				// stable but unrelated hash. Accept only the daemon's canonical
				// metadata filename, prove the raw bytes against the requested
				// info hash, then restore the missing metainfo envelope.
				if(strcasecmp(basename($fname), $hash . '.meta') === 0)
				{
					$raw = @file_get_contents($fname);
					if($raw === false || $raw === ''
						|| !preg_match('/^[0-9a-f]{40}$/i', $hash)
						|| !hash_equals(strtoupper($hash), strtoupper(sha1($raw))))
						return(false);
					$torrent = new Torrent('d4:info' . $raw . 'e');
					// Torrent re-encodes info when calculating hash_info(). This
					// second equality therefore also rejects non-canonical input
					// that could not be sent back without changing its hash.
					if($torrent->errors() || $torrent->hash_info() !== strtoupper($hash))
						return(false);
				}
				else
					$torrent = new Torrent( $fname );
				if( !$torrent->errors() )
				{
					if(isset($torrent->{'libtorrent_resume'}))
						unset($torrent->{'libtorrent_resume'});
					if(isset($torrent->{'rtorrent'}))
						unset($torrent->{'rtorrent'});
					return($torrent);
				}
			}
		}
		return(false);
	}

	static public function fastResume($torrent, $base, $add_path = true)
	{
	        $files = array();
	        $info = $torrent->info;
	        $psize = intval($info['piece length']);
		$base = trim($base);
	        if($base=='')
	        {
	        	$base = rTorrentSettings::get()->directory;
		}
	        if($psize && rTorrentSettings::get()->correctDirectory($base))
	        {
		        $base = FileUtil::addslash($base);
	                $tsize = 0.0;
			if(isset($info['files']))
			{
				foreach($info['files'] as $key=>$file)
				{
				        $tsize+=floatval($file['length']);
					$files[] = ($add_path ? $info['name']."/".implode('/',$file['path']) : implode('/',$file['path']));
				}
			}
			else
			{
				$tsize = floatval($info['length']);
				$files[] = $info['name'];
			}
			$chunks = intval(($tsize + $psize - 1) / $psize);
			$torrent->{'libtorrent_resume'}['bitfield'] = intval($chunks);
			if(!isset($torrent->{'libtorrent_resume'}['files']))
				$torrent->{'libtorrent_resume'}['files'] = array();
			foreach($files as $key=>$file)
			{
				$ss = LFS::stat($base.$file);
				if($ss===false)
					return(false);
				if(count($torrent->{'libtorrent_resume'}['files'])<$key)
					$torrent->{'libtorrent_resume'}['files'][$key]['mtime'] = $ss["mtime"];
				else
					$torrent->{'libtorrent_resume'}['files'][$key] = array( "priority" => 2, "mtime" => $ss["mtime"] );
			}
			return($torrent);
		}
		return(false);
	}

	/**
	 * Quote a value for use inside an rtorrent command string.
	 *
	 * The commands handed to load/load_raw are not typed parameters: rtorrent
	 * parses them itself (src/rpc/parse.cc), ending a quoted argument at the
	 * first unescaped '"' and reading '\' as an escape. A value wrapped in
	 * quotes without escaping therefore ends the argument early -- a download
	 * directory holding a quote silently loses everything from that quote on,
	 * and the command around it stops parsing.
	 *
	 * Mirrors the escaping XMLRPCProxy::rebuildSafeLoadParam() applies to the
	 * command strings it rebuilds.
	 */
	static public function quoteCommandArg($value)
	{
		// The order of the pairs is what makes this correct, and it must not be
		// changed: str_replace() works through the arrays left to right and can
		// revisit a value an earlier pair inserted. Escaping the quote first
		// would double the backslash that escaping just added, leaving a live
		// quote behind it -- so the backslash pair has to come first.
		return('"'.str_replace(array('\\', '"'), array('\\\\', '\\"'), $value).'"');
	}

	static protected function parseDirectory($directory, $isAddPath)
	{
		if(!$isAddPath && (!$directory || (strlen($directory)==0)))
		{
			$directory = rTorrentSettings::get()->directory;
		}
		return($directory);
	}
}
