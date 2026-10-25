<?php

require_once( 'util.php' );
require_once( 'settings.php' );
require_once( 'scgitransport.php' );
require_once( __DIR__.'/xmlrpc_response.php' );

class rXMLRPCParam
{
	public $type;
	public $value;

	// An <i8> or <i4> whose number the type cannot hold keeps a null value,
	// and rXMLRPCRequest::run() refuses a request carrying one.
	public function __construct( $aType, $aValue )
	{
		$this->type = $aType;
		if(($this->type=="i8") || ($this->type=="i4"))
			$this->value = self::integerText($this->type,$aValue);
		else if($this->type === 'string')
		{
			$aValue = (string)$aValue;
			try {
				// XML forbids these bytes and normalizes literal CR. Native RPC
				// accepts binary string parameters without changing their bytes.
				$binary = @preg_match('/[\x00-\x08\x0B-\x1F]|\xEF\xBF[\xBE\xBF]/', $aValue) !== 0;
			} catch(Throwable $error) {
				$binary = true;
			}
			if($binary || !UTF::isValid($aValue))
			{
				$this->type = 'base64';
				$this->value = null;
				try {
					$encoded = @base64_encode($aValue);
					if(@is_string($encoded) && @base64_decode($encoded, true) === $aValue)
						$this->value = $encoded;
				} catch(Throwable $error) {
					// The request refuses an unavailable value before sending any command.
				}
			}
			else $this->value = htmlspecialchars($aValue, ENT_NOQUOTES, 'UTF-8');
		}
		else
			$this->value = htmlspecialchars($aValue,ENT_NOQUOTES,"UTF-8");
	}

	// $value as the text of an XMLRPC $type, a float rounded half away from
	// zero, or null if it is not a number that type holds. INF, NAN and a
	// float beyond 64 bits have no such text: number_format() would write
	// them as "inf", "nan" and a run of digits, none of which is an integer.
	static public function integerText( $type, $value )
	{
		if(is_string($value) && is_numeric($value))
			$value = $value + 0;
		if(is_float($value))
		{
			if(!is_finite($value))
				return(null);
			$value = round($value);
			// -2^63 and 2^63 are exact as floats, and the largest float
			// below 2^63 is 2^63-1024.
			if(($value<-9223372036854775808.0) || ($value>=9223372036854775808.0))
				return(null);
			$text = number_format($value,0,'.','');
		}
		else
		if(is_int($value))
			// Not number_format(), which before PHP 8.3 takes a float and
			// turns PHP_INT_MAX into 2^63.
			$text = strval($value);
		else
			return(null);
		if(($type=="i4") && (($value<XMLRPC_MIN_I4) || ($value>XMLRPC_MAX_I4)))
			return(null);
		return($text);
	}
}

class rXMLRPCInvalidCommandName extends Exception
{
	public function __construct( $name )
	{
		parent::__construct("Not an rtorrent command name: ".$name);
	}
}

class rXMLRPCCommand
{
	// A command name is interpolated into <methodName> unescaped, so a name
	// carrying markup would close that element and add calls of its own to the
	// same request. Outbound commands use this restricted grammar. Daemon
	// inventories can also contain custom names outside it; observing those
	// names does not authorize constructing or sending them as commands.
	const NAME_PATTERN = '/^[a-z0-9_.]+\z/i';

	public $command;
	public $params;

	static public function isValidCommandName( $name )
	{
		return(is_string($name) && (preg_match(self::NAME_PATTERN,$name)===1));
	}

	public function __construct( $cmd, $args = null )
	{
		$this->command = getCmd($cmd);
		if(!self::isValidCommandName($this->command))
			throw new rXMLRPCInvalidCommandName($this->command);
		$this->params = array();
		rTorrentSettings::get()->patchDeprecatedCommand($this,$cmd);
		if($args!==null)
		{
		        if(is_array($args))
				foreach($args as $prm)
					$this->addParameter($prm);
			else
				$this->addParameter($args);
		}
	}

	public function addParameters( $args )
	{
		if($args!==null)
		{
			if(is_array($args))
				foreach($args as $prm)
					$this->addParameter($prm);
			else
				$this->addParameter($args);
		}
	}

	public function addParameter( $aValue, $aType = null )
	{
		if($aType===null)
			$aType = self::getPrmType( $aValue );
		$this->params[] = new rXMLRPCParam( $aType, $aValue );
	}

	static protected function getPrmType( $prm )
	{
		if(is_int($prm) && ($prm>=XMLRPC_MIN_I4) && ($prm<=XMLRPC_MAX_I4))
			return('i4');
		if(is_float($prm))
			return('i8');
		return('string');
	}
}

class rXMLRPCRequest
{
	protected $commands = array();
	protected $content = "";
	protected $commandOffset = 0;
	public $i8s = array();
	public $strings = array();
	public $val = array();
	// Decoded top-level response parameters, preserving array/struct boundaries.
	public $responseValues = array();
	public $responseTypes = array();
	private $responseStringArrayWidths = array();
	private $responseStringTableWidths = array();
	public $responseCommandValues = array();
	public $responseCommandTypes = array();
	private $responseCommandStringTableWidths = array();
	public $fault = false;
	public $faultString = '';
	// Keep the transport reason on this request for its HTTP endpoint.
	public $transportFailure = null;
	public $rawFaultString = null;
	public $parseByTypes = false;
	public $important = true;
	/**
	 * Deprecated: hand strings out escaped, as run() did before 2026-10.
	 *
	 * run() used to double each backslash and prefix each double quote in
	 * every string it read. Callers that quoted a value, compared it with a
	 * path or put it into JSON got bytes the daemon never had (AUD-ESC-01..17).
	 * Strings are now the daemon's own bytes. An external plugin that still
	 * pastes ->val into an unquoted command string or script literal may set
	 * this flag on its own request until it quotes the value itself
	 * (rTorrent::additionCommand(), rTorrent::quoteCommandArg(),
	 * JSON::jsValue()); no shipped code sets it.
	 *
	 * @deprecated
	 */
	public $escapeForScript = false;

	public function __construct( $cmds = null )
	{
		if($cmds)
		{
		        if(is_array($cmds))
				foreach($cmds as $cmd)
					$this->addCommand($cmd);
			else
				$this->addCommand($cmds);
		}
	}

	public static function send( $data, $trusted = true, &$failure = null, $responseMode = rSCGITransport::RESPONSE_RAW )
	{
		global $rpcLogCalls;
		if($rpcLogCalls)
			FileUtil::toLog($data);
		$result = rSCGITransport::sendConfigured($data, $trusted, array('responseMode' => $responseMode,
			'temporaryDirectory' => self::temporaryDirectory()), $failure);
		if($result === null)
		{
			// A stopped daemon would add a line on every WebUI poll, which
			// already answers "Could not reach rTorrent"; log the other failures.
			if($failure !== 'connect-failed')
				FileUtil::toLog('rXMLRPCRequest: '.rSCGITransport::describeFailure($failure));
			$result = false;
		}
		if($rpcLogCalls)
			FileUtil::toLog($result);
		return($result);
	}

	/** Spill is optional even when functions used by ruTorrent's directory owner are disabled. */
	private static function temporaryDirectory()
	{
		foreach(array('ini_get', 'is_dir', 'file_exists', 'is_readable', 'is_writable', 'umask', 'mkdir') as $function)
			if(!function_exists($function)) return false;
		return FileUtil::getTempDirectory();
	}

	/**
	 * Undo the escaping of a string read with $escapeForScript set.
	 *
	 * Deprecated, for external plugins only: run() hands out the daemon's own
	 * bytes unless $escapeForScript is set, and unescaping such a value again
	 * would drop a backslash the daemon reported. No shipped file outside this
	 * one calls it (tests/php/RpcStringConsumersTest.php).
	 *
	 * @deprecated
	 */
	static public function unescapeValue( $value )
	{
		if(!is_string($value) || (strpos($value,'\\')===false))
			return($value);
		$out = '';
		$len = strlen($value);
		for($i = 0; $i<$len; $i++)
		{
			if(($value[$i]==='\\') && (($i+1)<$len) &&
				(($value[$i+1]==='\\') || ($value[$i+1]==='"')))
				$i++;
			$out .= $value[$i];
		}
		return($out);
	}

	public function setParseByTypes( $enable = true )
	{
		$this->parseByTypes = $enable;
	}

	public function getCommandsCount()
	{
		return(count($this->commands));
	}

	protected function makeNextCall()
	{
		$this->fault = false;
		$this->content = "";
		$cnt = count($this->commands) - $this->commandOffset;
		if($cnt>0)
		{
			$this->content = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>';
			if($cnt==1)
			{
				$cmd = $this->commands[$this->commandOffset++];
	        		$this->content .= "{$cmd->command}</methodName><params>\r\n";
	        		foreach($cmd->params as &$prm)
	        			$this->content .= "<param><value><{$prm->type}>{$prm->value}</{$prm->type}></value></param>\r\n";
		        }
			else
			{
				$maxContentSize = rTorrentSettings::get()->maxContentSize();
				$this->content .= "system.multicall</methodName><params><param><value><array><data>";
				for(; $this->commandOffset < count($this->commands); $this->commandOffset++)
				{
					$cmd = $this->commands[$this->commandOffset];
					$cmdStr = "\r\n<value><struct><member><name>methodName</name><value><string>".
						"{$cmd->command}</string></value></member><member><name>params</name><value><array><data>";
					foreach($cmd->params as &$prm)
						$cmdStr .= "\r\n<value><{$prm->type}>{$prm->value}</{$prm->type}></value>";
					$cmdStr .= "\r\n</data></array></value></member></struct></value>";
					if($this->commandOffset > count($this->commands) - $cnt and
						strlen($this->content) + strlen($cmdStr) + 35 + 22 > $maxContentSize)
						break;
					$this->content .= $cmdStr;
				}
				$this->content .= "\r\n</data></array></value></param>";
			}
			$this->content .= "</params></methodCall>";
		}
		return($cnt>0);
	}

	public function addCommand( $cmd )
	{
		$this->commands[] = $cmd;
	}

	/**
	 * Save each of these downloads after the commands already in this request.
	 *
	 * rTorrent keeps a download's label and other custom fields, priority,
	 * state, file priorities, tracker switches, views and throttle in its
	 * session files and writes them only when something saves the download.
	 * After a change from the WebUI only the periodic session.save did that,
	 * so a crash or `docker kill` before it lost the change (ACC-OWN-A-02,
	 * R4-NF-2). session.save runs d.save_resume for every download on 0.9.8
	 * and 0.16; this runs it for the downloads the request changed, now.
	 */
	public function addSessionSaves( $hashes )
	{
		$saved = array();
		foreach($hashes as $hash)
		{
			$hash = strtoupper($hash);
			if(isset($saved[$hash]))
				continue;
			$saved[$hash] = true;
			$this->addCommand( new rXMLRPCCommand( "d.save_resume", $hash ) );
		}
		return($this);
	}

	// No answer read yet: no column for a caller to read.
	protected function clearValues()
	{
		$this->i8s = array();
		$this->strings = array();
		$this->val = array();
		$this->responseValues = array();
		$this->responseTypes = array();
		$this->responseStringArrayWidths = array();
		$this->responseStringTableWidths = array();
		$this->responseCommandValues = array();
		$this->responseCommandTypes = array();
		$this->responseCommandStringTableWidths = array();
	}

	public function run($trusted = true)
	{
	        $ret = false;
		$this->clearValues();
		$this->transportFailure = null;
		$this->fault = false;
		$this->faultString = '';
		$this->rawFaultString = null;
		// Every name is checked before the first payload is built, so a batch
		// holding one unusable name sends no part of itself.
		foreach($this->commands as $cmd)
			if(!rXMLRPCCommand::isValidCommandName($cmd->command))
			{
				$this->fault = true;
				$this->faultString = 'Refused: not an rtorrent command name.';
				$this->commands = array();
				return(false);
			}
		// So is every number, for the same reason.
		foreach($this->commands as $cmd)
			foreach($cmd->params as $prm)
				if((($prm->type=="i8") || ($prm->type=="i4")) && ($prm->value===null))
				{
					$this->fault = true;
					$this->faultString = 'Refused: not a number an XMLRPC integer can hold.';
					$this->commands = array();
					return(false);
				}
		foreach($this->commands as $cmd)
			foreach($cmd->params as $prm)
				if($prm->value === null)
				{
					$this->fault = true;
					$this->faultString = 'Refused: could not encode an XMLRPC parameter.';
					$this->commands = array();
					return(false);
				}
		rTorrentSettings::get()->patchDeprecatedRequest($this->commands);
		$this->commandOffset = 0;
		$proofOffset = 0;
		$commandProofValid = true;
		while($this->makeNextCall())
		{
			$sentCommands = $this->commandOffset - $proofOffset;
			$opaqueMulticall = count($this->commands) - $proofOffset === 1
				&& $this->commands[$proofOffset]->command === 'system.multicall';
			$proofOffset = $this->commandOffset;
			// An earlier chunk cannot make a failed later chunk successful.
			$ret = false;
			$answer = self::send($this->content,$trusted,$this->transportFailure);
			if($answer === false || $answer === '')
			{
				$this->fault = true;
				$this->faultString = 'rpc-response-unavailable';
				$this->clearValues();
				break;
			}
			try
			{
				$response = (new rXMLRPCResponse($answer, strpos($this->content, '<methodName>system.multicall</methodName>') !== false))->read();
			}
			catch(UnexpectedValueException $error)
			{
				$this->clearValues();
				$this->fault = true;
				$this->faultString = 'rpc-'.$error->getMessage();
				FileUtil::toLog('rXMLRPCRequest: '.$this->faultString);
				break;
			}
			$this->responseValues = array_merge($this->responseValues, $response->values);
			$this->responseTypes = array_merge($this->responseTypes, $response->valueTypes);
			$this->responseStringArrayWidths = array_merge($this->responseStringArrayWidths, $response->stringArrayWidths);
			$this->responseStringTableWidths = array_merge($this->responseStringTableWidths, $response->stringTableWidths);
			// Counts must match this call, not just the sum of several chunks:
			// a missing result and a later extra result must never shift indexes.
			if($opaqueMulticall || count($response->commandValues) !== $sentCommands) $commandProofValid = false;
			if($commandProofValid)
			{
				$this->responseCommandValues = array_merge($this->responseCommandValues, $response->commandValues);
				$this->responseCommandTypes = array_merge($this->responseCommandTypes, $response->commandTypes);
				$this->responseCommandStringTableWidths = array_merge($this->responseCommandStringTableWidths, $response->commandStringTableWidths);
			}
			else
				$this->responseCommandValues = $this->responseCommandTypes = $this->responseCommandStringTableWidths = array();
			foreach($response->scalars as $scalar)
			{
				list($type, $value) = $scalar;
				if($this->escapeForScript && is_string($value))
					$value = str_replace(array("\\", "\""), array("\\\\", "\\\""), $value);
				if(!$this->parseByTypes) $this->val[] = $value;
				else if(in_array($type, array('i4', 'i8', 'int', 'boolean', 'double'), true))
					$this->i8s[] = $value;
				else $this->strings[] = $value;
			}
			$ret = true;
			if($response->fault)
			{
				$this->fault = true;
				$this->responseCommandValues = $this->responseCommandTypes = $this->responseCommandStringTableWidths = array();
				$this->rawFaultString = $response->faultString;
				$this->faultString = trim($this->rawFaultString);
				global $rpcLogFaults;
				if($rpcLogFaults && $this->important)
				{
					FileUtil::toLog($this->content);
					FileUtil::toLog($answer);
				}
				break;
			}
		}
		$this->content = "";
		$this->commands = array();
		return($ret);
	}

	/** One exact XML array of string/base64 values; unknown or wrong shape is null. */
	public function stringArray()
	{
		if($this->fault || $this->responseTypes !== array('array') || count($this->responseValues) !== 1 ||
			count($this->responseStringArrayWidths) !== 1 || $this->responseStringArrayWidths[0] === null) return null;
		return $this->responseValues[0];
	}

	/**
	 * One exact XML array of equally wide XML array rows, with string/base64
	 * cells only. Empty inventory is []; unknown, fault or wrong shape is null.
	 * An explicit index selects one command result, including native multicall
	 * wrappers. The default still requires one sole top-level table. Provenance
	 * comes from the streaming grammar, not PHP array key coercion.
	 */
	public function stringTable($columns, $resultIndex = null)
	{
		if(!is_int($columns) || $columns < 1 || $this->fault) return null;
		if($resultIndex !== null)
		{
			if(!is_int($resultIndex) || $resultIndex < 0 ||
				!isset($this->responseCommandValues[$resultIndex], $this->responseCommandTypes[$resultIndex],
					$this->responseCommandStringTableWidths[$resultIndex]) ||
				$this->responseCommandTypes[$resultIndex] !== 'array') return null;
			$width = $this->responseCommandStringTableWidths[$resultIndex];
			return ($width === -1 || $width === $columns) ? $this->responseCommandValues[$resultIndex] : null;
		}
		if($this->responseTypes !== array('array') || count($this->responseValues) !== 1 ||
			count($this->responseStringTableWidths) !== 1) return null;
		$width = $this->responseStringTableWidths[0];
		return ($width === -1 || $width === $columns) ? $this->responseValues[0] : null;
	}

	/** Compatibility probe; only response fault positions are interpreted. */
	static public function isFaultAnswer($answer)
	{
		if(!is_string($answer)) return false;
		try { return (new rXMLRPCResponse($answer, true))->read()->fault; }
		catch(UnexpectedValueException $error) { return false; }
	}

	public function success($trusted = true)
	{
		return($this->run($trusted) && !$this->fault);
	}
}

function getCmd($cmd)
{
	return(rTorrentSettings::get()->getCommand($cmd));
}

/**
 * Read one current method snapshot. Only a fully validated known reply is
 * published through $available, including a known partial ABI; an unknown
 * reply leaves null so callers cannot mistake it for an absent capability.
 */
function rpcMethodCapability($required, &$available = null)
{
	$available = null;
	$request = new rXMLRPCRequest(new rXMLRPCCommand('system.listMethods'));
	$request->important = false;
	$methods = $request->success() ? $request->stringArray() : null;
	if ($methods === null) return 'unconfirmed';
	$seen = array();
	foreach ($methods as $method)
	{
		// Custom daemon method names are opaque inventory data, not commands.
		if (!is_string($method) || $method === '') return 'unconfirmed';
		$seen[$method] = true;
	}
	if (!isset($seen['system.listMethods'])) return 'unconfirmed';
	$available = array_map('strval', array_keys($seen));
	foreach ($required as $method)
		if (!isset($seen[$method])) return 'unsupported';
	return 'available';
}
