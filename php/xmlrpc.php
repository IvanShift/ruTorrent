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
	// same request. An rtorrent method name is letters, digits, '_' and '.':
	// of the 1001 names system.listMethods answers with on 0.9.8, not one
	// carries another character. Anything else is refused rather than escaped,
	// because escaping it would only send a name no daemon answers to.
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
	private $responseStringTableWidths = array();
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

	public static function send( $data, $trusted = true, &$failure = null )
	{
		global $rpcLogCalls;
		if($rpcLogCalls)
			FileUtil::toLog($data);
		global $rpcTimeOut;
		global $rpcTransferTimeOut;
		global $rpcMaxResponseBytes;
		global $scgi_host;
		global $scgi_port;
		$failure = null;
		$result = rSCGITransport::send($scgi_host, $scgi_port, $data, $trusted,
			isset($rpcTimeOut) ? $rpcTimeOut : 30, $failure,
			isset($rpcTransferTimeOut) ? $rpcTransferTimeOut : null,
			isset($rpcMaxResponseBytes) ? $rpcMaxResponseBytes : null,
			rSCGITransport::RESPONSE_RAW);
		if($result === null)
		{
			FileUtil::toLog('rXMLRPCRequest: '.$failure);
			$result = false;
		}
		if($rpcLogCalls)
			FileUtil::toLog($result);
		return($result);
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
		$this->responseStringTableWidths = array();
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
		rTorrentSettings::get()->patchDeprecatedRequest($this->commands);
		$this->commandOffset = 0;
		while($this->makeNextCall())
		{
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
			$this->responseStringTableWidths = array_merge($this->responseStringTableWidths, $response->stringTableWidths);
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

	/**
	 * One exact XML array of equally wide XML array rows, with string/base64
	 * cells only. Empty inventory is []; unknown, fault or wrong shape is null.
	 * Provenance comes from the streaming grammar, not PHP array key coercion.
	 */
	public function stringTable($columns)
	{
		if(!is_int($columns) || $columns < 1 || $this->fault ||
			$this->responseTypes !== array('array') || count($this->responseValues) !== 1 ||
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
	if (!$request->success() || $request->fault || $request->responseTypes !== array('array') || !is_array($request->responseValues) ||
		count($request->responseValues) !== 1 || !is_array($request->responseValues[0]))
		return 'unconfirmed';
	$seen = array();
	foreach ($request->responseValues[0] as $method)
	{
		if (!rXMLRPCCommand::isValidCommandName($method)) return 'unconfirmed';
		$seen[$method] = true;
	}
	if (!isset($seen['system.listMethods'])) return 'unconfirmed';
	$available = array_map('strval', array_keys($seen));
	foreach ($required as $method)
		if (!isset($seen[$method])) return 'unsupported';
	return 'available';
}
