<?php

require_once( 'util.php' );
require_once( 'xmlrpc.php' );
require_once( 'Torrent.php' );
require_once( __DIR__.'/commandargs.php' );

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
	// replacement marker and check state with d.set_custom.
	// d.set_custom1 also preserves edit's exact stored label without a
	// decode/encode round trip. d.set_custom2 (comment) is written by
	// sendTorrent(); no shipped caller builds custom4 or custom5 additions.
	const ADDITION_COMMANDS = array(
		'd.set_throttle_name',
		'd.set_connection_seed',
		'view.set_visible',
		'd.views.push_back_unique',
		'd.set_custom',
		'd.set_custom1',
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
	 * The value must be in its own bytes, which is how rXMLRPCRequest hands
	 * out a value read back from the daemon. One escaped beforehand would be
	 * escaped again here, and rtorrent would be given a name that has grown a
	 * backslash (AUD-ESC-02).
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
			// Quoting does not prevent evaluation of a leading '$', and a
			// NUL terminates the daemon parser's input buffer.
			if(!RtorrentCommandArguments::isLiteral($value))
				return(false);
			$quoted[] = self::quoteCommandArg($value);
		}
		return(getCmd($command.'=').implode(',',$quoted));
	}

	/** Validate and prepare a load before callers erase an existing download. */
	static public function preflightTorrentLoad($fname, $isAddPath, $directory, $isFast,
		$isNew = true, $addition = null, &$failure = null, $beforeDispatch = null, $directoryIsReported = false)
	{
		self::$directoryRefused = false;
		$failure = 'invalid-load';
		if(($beforeDispatch !== null && !is_callable($beforeDispatch)) ||
			!self::areValidAdditions($addition))
			return false;
		$mustSave = is_object($fname);
		$torrent = $mustSave ? clone $fname : new Torrent($fname);
		if($torrent->errors())
			return false;
		// New metainfo may reuse a daemon path; provenance is independent of $isNew.
		$directoryIsReported = $directoryIsReported || !$isNew || (!$isAddPath && !$directory);
		$directory = self::parseDirectory($directory, $isAddPath);
		if($directory && strlen($directory)>0 &&
			!self::validateLoadDirectory($directory, $directoryIsReported))
		{
			$failure = 'invalid-directory';
			return false;
		}
		if($isFast && ($resume = self::fastResume($torrent, $directory, $isAddPath)))
		{
			$torrent = $resume;
			$mustSave = true;
		}
		elseif($isNew && isset($torrent->{'libtorrent_resume'}))
		{
			unset($torrent->{'libtorrent_resume'});
			$mustSave = true;
		}
		if($isNew && isset($torrent->{'rtorrent'}))
		{
			unset($torrent->{'rtorrent'});
			$mustSave = true;
		}
		$rawValue = base64_encode($torrent->__toString());
		$rawLoad = strlen($rawValue)<self::RTORRENT_PACKET_LIMIT;
		if(!$rawLoad && !User::isLocalMode())
		{
			$failure = 'remote-filesystem';
			return false;
		}
		$failure = null;
		return array('torrent'=>$torrent, 'directory'=>$directory,
			'filename'=>is_object($fname) ? $torrent->getFileName() : $fname,
			'must_save'=>$mustSave, 'raw_value'=>$rawValue, 'raw_load'=>$rawLoad,
			'directory_reported'=>$directoryIsReported);
	}

	/**
	 * @return string|false|null Confirmed hash, failed request, or pending load.
	 *                          An accepted pre-dispatch callback leaves its receipt
	 *                          available even if the RPC fails; false then does not
	 *                          prove the deferred load was rejected. The receipt's
	 *                          raw flag allows source cleanup after dispatch.
	 */
	static public function sendTorrent($fname, $isStart, $isAddPath, $directory, $label, $saveTorrent, $isFast, $isNew = true, $addition = null, &$pendingReceipt = null, $beforeDispatch = null, $directoryIsReported = false)
	{
		$pendingReceipt = null;
		$prepared = self::preflightTorrentLoad($fname, $isAddPath, $directory, $isFast,
			$isNew, $addition, $failure, $beforeDispatch, $directoryIsReported);
		if($prepared === false)
			return false;
		$hash = false;
		$torrent = $prepared['torrent'];
		$directory = $prepared['directory'];
		$filename = $prepared['filename'];
		$mustSave = $prepared['must_save'];
		$raw_value = $prepared['raw_value'];
		$rawLoad = $prepared['raw_load'];
		if($rawLoad)
		{
			$cmd = new rXMLRPCCommand( $isStart ? 'load_raw_start' : 'load_raw' );
			$cmd->addParameter($raw_value,"base64");
		}
		else
		{
			if(is_null($filename))
			{
				$filename = FileUtil::getTempFilename($torrent->name(), 'torrent');
				$mustSave = true;
			}
			if($mustSave)
			{
				// because torrent may be changed in memory
				if($torrent->save($filename) !== strlen($torrent->__toString()))
					return false;
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
		if($directory && (strlen($directory)>0))
		{
			if(!self::prepareDirectory($directory, $isNew, $req, $prepared['directory_reported']))
				return false;
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
		if(!$req->run())
		{
			self::logUnknownLoadStatus($req->transportFailure ?? 'unusable-response');
			return 'unknown';
		}
		if($req->fault)
		{
			// Only the daemon's own missing-hash answer (HashFault) discards a
			// receipt; a transport error or another fault cannot.
			if(HashFault::isMissing($req->val[0] ?? null, $req->rawFaultString))
				return 'missing';
			self::logUnknownLoadStatus('rpc-fault');
			return 'unknown';
		}
		if(!isset($req->val[0]))
		{
			self::logUnknownLoadStatus('unusable-response');
			return 'unknown';
		}
		return (string)$req->val[0] === '1' ? 'ours' : 'foreign';
	}

	static private function logUnknownLoadStatus($reason)
	{
		// Polling can repeat the same failure many times in one PHP request.
		static $logged = array();
		if(isset($logged[$reason])) return;
		$logged[$reason] = true;
		FileUtil::toLog('rtorrent: pending load status unknown: '.$reason);
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

	/** The last btih wins; libtorrent ignores other exact topics, including hybrid btmh. */
	static public function magnetHash($magnet)
	{
		if(!is_string($magnet) || strncmp($magnet, 'magnet:?', 8) !== 0) return false;
		$hash = false;
		foreach(explode('&', substr($magnet, 8)) as $part)
		{
			$pair = explode('=', $part, 2);
			if(count($pair) !== 2 || $pair[0] !== 'xt') continue;
			// Even ignored exact topics must survive libtorrent's strict URL decoding.
			if(preg_match('/%(?![0-9a-f]{2})/i', $pair[1])) return false;
			if(strncmp($pair[1], 'urn:btih:', 9) !== 0) continue;
			$text = substr($pair[1], 9);
			if(preg_match('/^[A-Z2-7]{32}\z/i', $text)) $hash = Decode::base32decode($text);
			else
			{
				$text = rawurldecode($text);
				if(strlen($text) === 20) $hash = strtoupper(bin2hex($text));
				else if(preg_match('/^[0-9a-f]{40}\z/i', $text)) $hash = strtoupper($text);
				else return false;
			}
		}
		// The daemon refuses the decoded zero sentinel after selecting its final btih.
		return $hash === str_repeat('0', 40) ? false : $hash;
	}

	static public function sendMagnet($magnet, $isStart, $isAddPath, $directory, $label, $addition = null, $directoryIsReported = false)
	{
		self::$directoryRefused = false;
		if(!self::areValidAdditions($addition))
			return false;
		$hash = self::magnetHash($magnet);
		if($hash !== false)
		{
			$req = new rXMLRPCRequest();
			$cmd = new rXMLRPCCommand( $isStart ? 'load_start' : 'load' );
			$cmd->addParameter($magnet);
			$directoryIsReported = $directoryIsReported || (!$isAddPath && !$directory);
			$directory = self::parseDirectory($directory, $isAddPath);
			if($directory && (strlen($directory)>0))
			{
				if(!self::prepareDirectory($directory, true, $req, $directoryIsReported))
					return(false);
				$cmd->addParameter( getCmd($isAddPath ? "d.set_directory=" : "d.set_directory_base=").self::quoteCommandArg($directory) );
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
		return(false);
	}

	/**
	 * Whether a magnet sendMagnet() handed to rTorrent became a download.
	 *
	 * sendMagnet() answers the hash of every magnet it sends, and rTorrent
	 * drops some malformed links without making a stub (O-1). So a caller
	 * waits here, within the settle window of a .torrent load, for d.hash to
	 * show the stub before it reports the magnet as added. A stub that never
	 * shows is logged as "<source>: magnet unconfirmed: <hash>", and the
	 * caller reports the magnet as pending: addtorrent.php, bulk_magnet,
	 * extsearch and rss all use this one check.
	 */
	static public function magnetShown($hash, $source)
	{
		$hash = strtoupper($hash);
		$deadline = microtime(true) + self::LOAD_WAIT_ATTEMPTS
			* self::LOAD_WAIT_DELAY_US / 1000000;
		for($attempt = 0; $attempt < self::LOAD_WAIT_ATTEMPTS; $attempt++)
		{
			if($attempt) usleep(self::LOAD_WAIT_DELAY_US);
			if(microtime(true) >= $deadline) break;
			if(HashFault::presence($hash) === HashFault::PRESENT)
				return(true);
		}
		FileUtil::toLog($source.': magnet unconfirmed: '.$hash);
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

	// The same session/tied-file fallback serves source download and dump.
	// Return a path only; getSource() owns parsing and .meta validation.
	static public function getSourcePath($hash, $knownPaths = null)
	{
		// A caller that already read these paths in its batch must not start
		// another daemon request; false records an unavailable optional result.
		if($knownPaths === null)
		{
			$req = new rXMLRPCRequest( array(
				new rXMLRPCCommand("get_session"),
				new rXMLRPCCommand("d.get_tied_to_file",$hash)) );
			if(!$req->run() || $req->fault) return(false);
			$knownPaths = $req->val;
		}
		if(!is_array($knownPaths) || !isset($knownPaths[0], $knownPaths[1])
			|| !is_string($knownPaths[0]) || !is_string($knownPaths[1]))
			return(false);
		$fname = $knownPaths[0].$hash.".torrent";
		if(empty($knownPaths[0]) || !is_readable($fname))
			$fname = strlen($knownPaths[1]) && is_readable($knownPaths[1])
				? $knownPaths[1] : false;
		return($fname);
	}

	// Closed downloads may not have a frozen file path until a temporary open.
	// Keep the open/read/close commands together so media plugins share one result.
	static public function getFilePath($hash, $index, &$displayName = null)
	{
		try { $withName = @func_num_args() > 2; }
		catch(Throwable $error) { $withName = false; }
		$displayName = null;
		$index = intval($index);
		$req = new rXMLRPCRequest(new rXMLRPCCommand("f.get_frozen_path", array($hash, $index)));
		$values = self::filePathValues($req, $hash, $index, $withName, 1);
		if($values === false || !isset($values[0]) || !is_string($values[0]))
			return(false);
		$path = $values[0];
		$metadataOffset = 1;
		if($path === '')
		{
			$req = new rXMLRPCRequest(array(
				new rXMLRPCCommand("d.open", $hash),
				new rXMLRPCCommand("f.get_frozen_path", array($hash, $index)),
				new rXMLRPCCommand("d.close", $hash)));
			$values = self::filePathValues($req, $hash, $index, $withName, 3);
			if($values === false || !isset($values[1]) || !is_string($values[1]))
				return(false);
			$path = $values[1];
			$metadataOffset = 3;
		}
		if($withName && $path !== '')
		{
			$leaf = FileUtil::getFileName($path);
			$displayName = UTF::display($leaf);
			if(!UTF::isValid($leaf))
			{
				try
				{
					$meta = @array_slice($values, $metadataOffset);
					if(UTF::isString($hash) && @preg_match('/\A[0-9A-Fa-f]{40}\z/', $hash) === 1
						&& @count($meta) === 5 && UTF::isString($meta[0]) && UTF::isString($meta[1]) && UTF::isString($meta[2])
						&& UTF::isString($meta[3]) && UTF::isString($meta[4]) && FileUtil::getFileName($meta[0]) === $leaf)
					{
						$sourcePath = null;
						$source = @self::getSource(@strtoupper($hash), $sourcePath, array($meta[3], $meta[4]));
						require_once(__DIR__.'/torrentfilenames.php');
						$displayName = @TorrentFileNames::fileName($meta[0], $index, $meta[1], $meta[2], $hash, $source);
					}
				}
				catch(Throwable $error)
				{
					// The readable payload remains available without optional name evidence.
				}
			}
		}
		return($path !== '' ? $path : false);
	}

	/** Read optional name evidence in the existing path request, preserving fault slots. */
	private static function filePathValues($req, $hash, $index, $withName, $required)
	{
		if(!$withName) return $req->success() ? $req->val : false;
		$req->addCommand(new rXMLRPCCommand('f.get_path', array($hash, $index)));
		$req->addCommand(new rXMLRPCCommand('f.get_size_bytes', array($hash, $index)));
		$req->addCommand(new rXMLRPCCommand('d.get_size_files', $hash));
		$req->addCommand(new rXMLRPCCommand('get_session'));
		$req->addCommand(new rXMLRPCCommand('d.get_tied_to_file', $hash));
		if(isset($req->parseStructured)) $req->parseStructured = true;
		if(!$req->run() || $req->responseTypes !== array('array') || count($req->responseValues) !== 1
			|| !is_array($req->responseValues[0])) return false;
		$values = array();
		foreach($req->responseValues[0] as $position=>$reply)
		{
			$valid = is_array($reply) && array_keys($reply) === array(0);
			if($position < $required && !$valid) return false;
			$values[] = $valid ? $reply[0] : null;
		}
		if(count($values) < $required) return false;
		return $values;
	}

	// rTorrent names a BEP-9 magnet download <HASH>.meta, in uppercase hex,
	// until its metadata arrives. Captured on 0.16.25 and 0.9.8 (L3 lab,
	// 2026-10-08) for magnets given in lowercase hex, uppercase hex and base32:
	// the same spelling in d.name, in the session copy's info name and in the
	// stub file's basename. The hash argument is canonicalized; the name is
	// compared exactly, because the daemon writes no other spelling and a real
	// torrent may carry any name. d.is_meta remains the RPC-level check.
	static public function isMetadataStubName($name, $hash)
	{
		return(is_string($name) && is_string($hash) && preg_match('/^[0-9A-Fa-f]{40}$/D', $hash) === 1
			&& $name === strtoupper($hash).'.meta');
	}

	static public function getSource($hash, &$sourcePath = null, $knownPaths = null)
	{
		$sourcePath = self::getSourcePath($hash, $knownPaths);
		if($sourcePath === false)
			return(false);
		$fname = $sourcePath;
		// rTorrent 0.16 stores BEP-9 magnet metadata as the raw
		// bencoded info dictionary in <hash>.meta. Passing that path to
		// Torrent builds a new torrent *of the .meta file*, producing a
		// stable but unrelated hash. Accept only the daemon's canonical
		// metadata filename, prove the raw bytes against the requested
		// info hash, then restore the missing metainfo envelope.
		if(self::isMetadataStubName(basename($fname), $hash))
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
		return(false);
	}

	static public function fastResume($torrent, $base, $add_path = true)
	{
	        $files = array();
	        $info = $torrent->info;
		// Before load only the daemon can choose a differing legacy UTF-8
		// alias. Let it hash the selected files rather than certify another name.
		if(isset($info['name.utf-8']) && (!isset($info['files']) || $add_path)
			&& $info['name.utf-8'] !== $info['name'])
			return(false);
	        $psize = intval($info['piece length']);
	        if($base=='')
	        {
	        	$base = rTorrentSettings::get()->directory;
		}
	        if($psize && rTorrentSettings::get()->correctReportedDirectory($base))
	        {
		        $base = FileUtil::addslash($base);
	                $tsize = 0.0;
			if(isset($info['files']))
			{
				foreach($info['files'] as $key=>$file)
				{
					if(isset($file['path.utf-8']) && $file['path.utf-8'] !== $file['path'])
						return(false);
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
		return(RtorrentCommandArguments::quote($value));
	}

	private static $directoryRefused = false;

	/**
	 * Whether the last sendTorrent() or sendMagnet() that answered false did
	 * so because of the download's directory: refused, or rtorrent could not
	 * make it. addtorrent.php tells the user so rather than that the add
	 * failed.
	 */
	static public function refusedDirectory()
	{
		return(self::$directoryRefused);
	}

	/**
	 * Resolve, in place, the directory a download is loaded into, and see
	 * that it exists. False when it is refused or cannot be made.
	 *
	 * A new user-supplied directory is resolved by correctDirectory(), which
	 * places it under the top directory; isInsideTopDirectory() follows
	 * its links. It is made before the load is sent, in a request of its own,
	 * so a directory that cannot be made leaves nothing loaded.
	 *
	 * A reload sends back the directory rtorrent reported. It is judged as
	 * one, and its mkdir is added to $req to go with the load: the caller has
	 * already erased the download, so loading it where it cannot be written
	 * keeps it, and refusing would lose it.
	 */
	static protected function prepareDirectory(&$directory, $isNew, $req, $directoryIsReported = false)
	{
		if(!self::validateLoadDirectory($directory, $directoryIsReported || !$isNew))
			return false;
		if($isNew)
		{
			$mkdir = new rXMLRPCRequest( new rXMLRPCCommand( 'execute', array('mkdir','-p',$directory) ) );
			return($mkdir->success() ? true : self::directoryFailed());
		}
		$req->addCommand( new rXMLRPCCommand( 'execute', array('mkdir','-p',$directory) ) );
		return true;
	}

	/** Share directory admission with the preflight that runs before an erase. */
	static private function validateLoadDirectory(&$directory, $directoryIsReported)
	{
		$settings = rTorrentSettings::get();
		$valid = $directoryIsReported
			? $settings->correctReportedDirectory($directory)
			: $settings->correctDirectory($directory) && self::isInsideTopDirectory($directory);
		return $valid ? true : self::directoryFailed();
	}

	static private function directoryFailed()
	{
		self::$directoryRefused = true;
		return(false);
	}

	/**
	 * Whether $dir, a directory correctDirectory() accepted, is still inside
	 * $topDirectory once the symbolic links along it are followed.
	 *
	 * correctDirectory() compares the path as written; rtorrent's "mkdir -p"
	 * and its file writes follow links. So the deepest part of $dir that
	 * exists is resolved and the rest, which mkdir will create, re-attached.
	 * A part that exists but does not resolve -- a dangling link, or a link
	 * into a directory this user cannot enter -- is refused rather than read
	 * as a plain name.
	 *
	 * Only a local rtorrent shares this filesystem, so for a remote one the
	 * text check is all there is. A top directory that does not exist is
	 * compared as written.
	 */
	static public function isInsideTopDirectory($dir)
	{
		global $topDirectory;
		$top = FileUtil::addslash($topDirectory);
		if(($top === '/') || !User::isLocalMode())
			return(true);
		foreach(array('realpath', 'file_exists', 'is_link', 'dirname') as $function)
			if(!function_exists($function)) return false;
		$realTop = @realpath($top);
		$realTop = FileUtil::addslash(is_string($realTop) ? $realTop : $top);
		if(!is_string($dir) || ($dir === '') || ($dir[0] !== '/') || strpos($dir, "\0") !== false)
			return(false);
		$path = ($dir === '/') ? '/' : rtrim($dir, '/');
		$rest = '';
		while(!@file_exists($path) && !@is_link($path))
		{
			$parent = @dirname($path);
			$name = FileUtil::getFileName($path);
			// An inaccessible root is not a missing directory we can keep climbing.
			if(!is_string($parent) || $parent === '' || $parent === $path)
				return false;
			$rest = '/'.$name.$rest;
			$path = $parent;
		}
		$real = @realpath($path);
		if(!is_string($real))
			return(false);
		return(strpos(FileUtil::addslash(rtrim($real, '/').$rest), $realTop) === 0);
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
