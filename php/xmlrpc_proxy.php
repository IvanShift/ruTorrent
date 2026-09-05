<?php
/**
 * XMLRPC Proxy — handles raw XMLRPC pass-through with configurable trust.
 *
 * Modes:
 *   "off"                — reject all raw XMLRPC
 *   "passthrough_unsafe" — send all raw XMLRPC as trusted (dangerous)
 *   "sanitize"           — parse and sanitize known methods, send safe
 *                          payload as trusted; pass unknown methods as
 *                          untrusted (rtorrent whitelist decides)
 *
 * process() applies the policy and sends the result; decide() applies it and
 * returns the result, for a caller that owns its own connection to rtorrent.
 *
 * Dependencies of process() (loaded by the production caller before non-"off"
 * modes):
 *   php/util.php    — FileUtil::toLog
 *   php/xmlrpc.php  — rXMLRPCRequest::send
 * (Both are required by plugins/httprpc/action.php, the production caller.)
 * decide() has none.
 */

class XMLRPCProxy
{
	// Methods that need trusted connections but can carry command
	// parameters. We rebuild these from scratch, keeping only safe params.
	//
	// Any unclassifiable, denied, or boundary-refused expression makes the
	// outer request locally terminal with no transport call.
	private static $sanitizeMethods = array(
		'load.normal', 'load.start', 'load.verbose', 'load.start_verbose',
		'load.raw', 'load.raw_start', 'load.raw_verbose', 'load.raw_start_verbose',
	);

	private static $uriLoadMethods = array(
		'load.normal', 'load.start', 'load.verbose', 'load.start_verbose',
	);

	private static $rawLoadMethods = array(
		'load.raw', 'load.raw_start', 'load.raw_verbose', 'load.raw_start_verbose',
	);

	private static $evaluatorDenies = array(
		'catch', 'branch', 'try', 'and', 'or', 'less', 'greater', 'equal', 'match',
	);

	private static $viewCarrierDenies = array(
		'view.filter', 'view.filter.temp', 'view.sort_new',
		'view.sort_current', 'view.event_added', 'view.event_removed',
	);

	// Exactly the URIs rtorrent does not treat as a local path:
	// is_network_uri() and is_magnet_uri() in core/download_factory.cc, which
	// use strncmp and are therefore case-sensitive. This has to agree with
	// them character for character — accepting a form rtorrent reads as a path
	// would be the hole this closes.
	private static $networkUri = '#^(?:http://|https://|ftp://|magnet:\?)#';

	// Multicalls carry commands in trailing positions, and the same rebuilding
	// applies. For multicalls all command slots, including filtered filters,
	// must be parsed and rebuilt; any unknown, denied, or unrebuildable command
	// causes terminal rejection of the entire outer call.
	//
	// Refused outright in sanitize mode, whatever rtorrent would have made of
	// them. Matched as name prefixes, because these are families that differ
	// between versions — 0.9.8 has execute2 and schedule_remove2, 0.16.x has
	// execute.raw.bg and schedule.remove — and an exact list goes stale
	// silently, which for a refusal list is the wrong way to fail.
	//
	// This does not exist because rtorrent would allow them. It exists because
	// rtorrent only refuses them from 0.16.10, where UNTRUSTED_CONNECTION is
	// honoured; below that the header is read and ignored, so "forward it
	// untrusted" is a plain forward and this list is the only refusal there is.
	private static $denyPrefixes = array(
		'execute',                // and execute2, execute.capture, execute.raw.bg, ...
		'method.',                // insert / set / set_key / erase / redirect
		'import', 'try_import',   // read a file of commands
		'schedule',               // and schedule2, schedule.remove, scheduler.*
		'log.',                   // log.execute, log.open_file, log.xmlrpc
		'network.scgi',           // re-open the listener somewhere else
		'session.path.set',
		'directory.default.set',
		'system.env',
		'system.shutdown',        // and .normal / .quick -- answered by the xmlrpc-c
		                          // registry, not rtorrent's command map, so rtorrent's
		                          // own untrusted gate never sees it
	);

	// Methods rtorrent refuses to an untrusted caller that a remote client
	// still needs, with the shape each argument has to have. A call that
	// matches is re-emitted from the parsed parts and sent trusted; anything
	// else is left untrusted, where rtorrent refuses it.
	//
	// The claim being made is per call, not per command: not "d.custom1.set is
	// safe" but "this call, naming one download by hash, with a value rtorrent
	// stores rather than parses, is within what the owner of this instance may
	// do". Measured, not assumed: a $-prefixed value arriving as an XMLRPC
	// parameter of these methods is stored verbatim and never executed.
	private static $elevate = array(
		'd.open'                        => array('hash'),
		'd.start'                       => array('hash'),
		'd.stop'                        => array('hash'),
		'd.custom1.set'                 => array('hash', 'text'),
		'd.custom2.set'                 => array('hash', 'text'),
		'd.custom3.set'                 => array('hash', 'text'),
		'd.custom4.set'                 => array('hash', 'text'),
		'd.custom5.set'                 => array('hash', 'text'),
		'd.custom.set'                  => array('hash', 'text', 'text'),
		'd.priority.set'                => array('hash', 'int'),
		'd.delete_tied'                 => array('hash'),
		'network.xmlrpc.size_limit.set' => array('empty', 'size'),
	);

	// Ceiling for network.xmlrpc.size_limit.set. A client raises it to add a
	// large torrent by file; without a bound it is also how a caller makes
	// rtorrent buffer as much as it likes.
	private static $sizeLimitMax = 16777216;

	// Commands whose argument is a path rtorrent will write a download into.
	// apply_d_directory() (command_download.cc:146) makes it the download's root
	// directory: for a single-file torrent the data lands at <dir>/<info.name>,
	// and the caller wrote the torrent, so it names the file too. Unconfined,
	// that is an arbitrary file write as the user rtorrent runs as — which lands
	// in a PHP-executing docroot if one is reachable and writable.
	//
	// ruTorrent already confines these everywhere else: correctDirectory() holds
	// a directory inside $topDirectory for the panel, for addtorrent.php and for
	// httprpc's own settings branch. This path skipped it.
	private static $directoryCommands = array(
		'd.directory.set', 'd.directory_base.set',
	);

	private static $multiArgCommands = array(
		'd.custom.set' => 2,
	);

	private static $multicallMethods = array(
		'd.multicall', 'd.multicall2', 'd.multicall.filtered',
		't.multicall', 'f.multicall', 'p.multicall',
	);

	private static $log = true;

	private static function log($msg)
	{
		if(self::$log)
			FileUtil::toLog("xmlrpc-proxy: ".$msg);
	}

	private static $validTypes = array(
		'string', 'int', 'i4', 'i8', 'boolean', 'double',
		'dateTime.iso8601', 'base64', 'array', 'struct'
	);

	public static function normalizeMethodName($name)
	{
		if($name === null || $name === '')
			return null;
		$name = (string)$name;
		$clean = preg_replace('/[^A-Za-z0-9_.:-]/', '?', $name);
		if(strlen($clean) > 96)
			$clean = substr($clean, 0, 96);
		return $clean;
	}

	public static function formatLogMessage($msg)
	{
		$clean = preg_replace('/[\x00-\x1f\x7f]/', ' ', (string)$msg);
		if(strlen($clean) > 512)
			$clean = substr($clean, 0, 512);
		return $clean;
	}

	private static function isDeniedCommand($name, $deny)
	{
		if(in_array($name, self::$evaluatorDenies, true))
			return true;
		if($name === 'p.call_target')
			return true;
		if(in_array($name, self::$viewCarrierDenies, true))
			return true;
		if(strncmp($name, 'directory.watch.', 16) === 0)
			return true;
		foreach($deny as $prefix)
		{
			if(strncmp($name, $prefix, strlen($prefix)) === 0)
				return true;
		}
		return false;
	}

	private static function isDirectDenied($name, $deny)
	{
		if(in_array($name, self::$directoryCommands, true))
			return true;
		return self::isDeniedCommand($name, $deny);
	}

	private static function isValidXmlUtf8String($str)
	{
		if(!is_string($str))
			return false;
		if(@preg_match('//u', $str) !== 1)
			return false;
		// Base64 URI text bypasses the input XML parser's character checks.
		// Valid UTF-8 alone does not exclude XML 1.0's U+FFFE and U+FFFF.
		if(preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x{fffe}\x{ffff}]/u', $str))
			return false;
		$escaped = htmlspecialchars($str, ENT_NOQUOTES, 'UTF-8');
		if($escaped === '' && $str !== '')
			return false;
		if(htmlspecialchars_decode($escaped, ENT_NOQUOTES) !== $str)
			return false;
		return true;
	}

	private static function hasNsOrAttrs($node, $xpath = null)
	{
		if($node->hasAttributes())
			return true;
		if($node->namespaceURI !== null && $node->namespaceURI !== '')
			return true;
		if($node->prefix !== null && $node->prefix !== '')
			return true;
		if($xpath !== null)
		{
			$nsList = $xpath->query('namespace::*[local-name() != "xml"]', $node);
			if($nsList->length > 0)
				return true;
		}
		return false;
	}

	private static function decodeCall($rawData)
	{
		if(!is_string($rawData) || $rawData === '')
			return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);

		$prev = null;
		if(PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader'))
			$prev = libxml_disable_entity_loader(true);
		$doc = new DOMDocument();
		$ok = @$doc->loadXML($rawData, LIBXML_NONET);
		if($prev !== null)
			libxml_disable_entity_loader($prev);

		if(!$ok)
			return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);

		if($doc->doctype !== null)
			return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);

		foreach($doc->childNodes as $docChild)
		{
			if($docChild->nodeType !== XML_ELEMENT_NODE)
				return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);
		}

		$xpath = new DOMXPath($doc);
		$root = $doc->documentElement;
		if(!$root || $root->nodeName !== 'methodCall')
			return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);

		if(self::hasNsOrAttrs($root, $xpath))
			return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);

		$methodNode = null;
		$paramsNode = null;
		$elementCount = 0;

		foreach($root->childNodes as $child)
		{
			if($child->nodeType === XML_TEXT_NODE)
			{
				if(trim($child->nodeValue, " \t\r\n") !== '')
					return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);
			}
			elseif($child->nodeType === XML_ELEMENT_NODE)
			{
				if(self::hasNsOrAttrs($child, $xpath))
					return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);

				$elementCount++;
				if($elementCount === 1)
				{
					if($child->nodeName !== 'methodName')
						return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);
					$methodNode = $child;
				}
				elseif($elementCount === 2)
				{
					if($child->nodeName !== 'params')
						return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);
					$paramsNode = $child;
				}
				else
				{
					return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);
				}
			}
			else
			{
				return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);
			}
		}

		if($methodNode === null)
			return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);

		if($methodNode->childNodes->length !== 1)
			return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);
		$mnChild = $methodNode->childNodes->item(0);
		if($mnChild->nodeType !== XML_TEXT_NODE)
			return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);
		$methodName = $mnChild->nodeValue;
		if($methodName === '' || trim($methodName, " \t\r\n") !== $methodName)
			return array('ok' => false, 'error' => 'rejected (invalid XML)', 'method' => null);

		$decodedParams = array();
		if($paramsNode !== null)
		{
			foreach($paramsNode->childNodes as $pChild)
			{
				if($pChild->nodeType === XML_TEXT_NODE || $pChild->nodeType === XML_CDATA_SECTION_NODE)
				{
					if(trim($pChild->nodeValue, " \t\r\n") !== '')
						return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
				}
				elseif($pChild->nodeType === XML_ELEMENT_NODE)
				{
					if(self::hasNsOrAttrs($pChild, $xpath) || $pChild->nodeName !== 'param')
						return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

					$paramRes = self::decodeParam($pChild, $methodName, $xpath);
					if(!$paramRes['ok'])
						return $paramRes;

					$decodedParams[] = $paramRes['param'];
				}
				else
				{
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
				}
			}
		}

		return array(
			'ok' => true,
			'method' => $methodName,
			'params' => $decodedParams,
		);
	}

	private static function decodeParam($paramNode, $methodName, $xpath)
	{
		$valueNode = null;
		$valueCount = 0;
		foreach($paramNode->childNodes as $child)
		{
			if($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE)
			{
				if(trim($child->nodeValue, " \t\r\n") !== '')
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
			elseif($child->nodeType === XML_ELEMENT_NODE)
			{
				if(self::hasNsOrAttrs($child, $xpath) || $child->nodeName !== 'value')
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

				$valueCount++;
				if($valueCount > 1)
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

				$valueNode = $child;
			}
			else
			{
				return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
		}

		if($valueCount !== 1 || $valueNode === null)
			return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

		return self::decodeValue($valueNode, $methodName, $xpath);
	}

	private static function decodeValue($valueNode, $methodName, $xpath)
	{
		if(self::hasNsOrAttrs($valueNode, $xpath))
			return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

		$elementChildren = array();
		$hasNonWhitespaceText = false;
		$rawText = '';

		foreach($valueNode->childNodes as $child)
		{
			if($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE)
			{
				$rawText .= $child->nodeValue;
				if(trim($child->nodeValue, " \t\r\n") !== '')
					$hasNonWhitespaceText = true;
			}
			elseif($child->nodeType === XML_ELEMENT_NODE)
			{
				$elementChildren[] = $child;
			}
			else
			{
				return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
		}

		if(count($elementChildren) > 1)
			return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

		if(count($elementChildren) === 1)
		{
			if($hasNonWhitespaceText)
				return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

			$typeNode = $elementChildren[0];
			if(self::hasNsOrAttrs($typeNode, $xpath))
				return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

			$tag = $typeNode->nodeName;
			if(in_array($tag, self::$validTypes, true))
			{
				if($tag === 'array')
				{
					$res = self::decodeArray($typeNode, $methodName, $xpath);
					if(!$res['ok'])
						return $res;
					return array('ok' => true, 'param' => array(
						'type' => 'array',
						'typeTag' => 'array',
						'value' => $res['data'],
					));
				}
				elseif($tag === 'struct')
				{
					$res = self::decodeStruct($typeNode, $methodName, $xpath);
					if(!$res['ok'])
						return $res;
					return array('ok' => true, 'param' => array(
						'type' => 'struct',
						'typeTag' => 'struct',
						'value' => $res['data'],
					));
				}
				else
				{
					$val = '';
					foreach($typeNode->childNodes as $scChild)
					{
						if($scChild->nodeType === XML_TEXT_NODE || $scChild->nodeType === XML_CDATA_SECTION_NODE)
						{
							$val .= $scChild->nodeValue;
						}
						else
						{
							return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
						}
					}

					$type = ($tag === 'i4' || $tag === 'i8') ? 'int' : $tag;
					return array('ok' => true, 'param' => array(
						'type' => $type,
						'typeTag' => $tag,
						'value' => $val,
					));
				}
			}
			else
			{
				return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
		}

		return array('ok' => true, 'param' => array(
			'type' => 'string',
			'typeTag' => 'string',
			'value' => $rawText,
		));
	}

	private static function decodeArray($arrayNode, $methodName, $xpath)
	{
		$dataNode = null;
		$dataCount = 0;
		foreach($arrayNode->childNodes as $child)
		{
			if($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE)
			{
				if(trim($child->nodeValue, " \t\r\n") !== '')
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
			elseif($child->nodeType === XML_ELEMENT_NODE)
			{
				if(self::hasNsOrAttrs($child, $xpath) || $child->nodeName !== 'data')
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

				$dataCount++;
				if($dataCount > 1)
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

				$dataNode = $child;
			}
			else
			{
				return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
		}

		if($dataCount !== 1 || $dataNode === null)
			return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

		$items = array();
		foreach($dataNode->childNodes as $child)
		{
			if($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE)
			{
				if(trim($child->nodeValue, " \t\r\n") !== '')
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
			elseif($child->nodeType === XML_ELEMENT_NODE)
			{
				if(self::hasNsOrAttrs($child, $xpath) || $child->nodeName !== 'value')
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

				$valRes = self::decodeValue($child, $methodName, $xpath);
				if(!$valRes['ok'])
					return $valRes;

				$items[] = $valRes['param'];
			}
			else
			{
				return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
		}

		return array('ok' => true, 'data' => $items);
	}

	private static function decodeStruct($structNode, $methodName, $xpath)
	{
		$members = array();
		foreach($structNode->childNodes as $child)
		{
			if($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE)
			{
				if(trim($child->nodeValue, " \t\r\n") !== '')
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
			elseif($child->nodeType === XML_ELEMENT_NODE)
			{
				if(self::hasNsOrAttrs($child, $xpath) || $child->nodeName !== 'member')
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

				$memRes = self::decodeMember($child, $methodName, $xpath);
				if(!$memRes['ok'])
					return $memRes;

				$members[] = $memRes['data'];
			}
			else
			{
				return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
		}

		return array('ok' => true, 'data' => $members);
	}

	private static function decodeMember($memberNode, $methodName, $xpath)
	{
		$nameNode = null;
		$valNode = null;
		$elementCount = 0;

		foreach($memberNode->childNodes as $child)
		{
			if($child->nodeType === XML_TEXT_NODE)
			{
				if(trim($child->nodeValue, " \t\r\n") !== '')
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
			elseif($child->nodeType === XML_ELEMENT_NODE)
			{
				if(self::hasNsOrAttrs($child, $xpath))
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

				$elementCount++;
				if($elementCount === 1)
				{
					if($child->nodeName !== 'name')
						return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
					$nameNode = $child;
				}
				elseif($elementCount === 2)
				{
					if($child->nodeName !== 'value')
						return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
					$valNode = $child;
				}
				else
				{
					return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
				}
			}
			else
			{
				return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
		}

		if($nameNode === null || $valNode === null)
			return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);

		$nameText = '';
		foreach($nameNode->childNodes as $nc)
		{
			if($nc->nodeType === XML_TEXT_NODE || $nc->nodeType === XML_CDATA_SECTION_NODE)
			{
				$nameText .= $nc->nodeValue;
			}
			else
			{
				return array('ok' => false, 'error' => 'rejected (malformed XML envelope or structure)', 'method' => $methodName);
			}
		}

		$valRes = self::decodeValue($valNode, $methodName, $xpath);
		if(!$valRes['ok'])
			return $valRes;

		return array('ok' => true, 'data' => array(
			'name' => $nameText,
			'value' => $valRes['param'],
		));
	}

	private static function emitArgumentFromDecoded($shape, $param, $sizeLimitMax)
	{
		if($param['type'] === 'array' || $param['type'] === 'struct')
			return null;

		$val = $param['value'];

		switch($shape)
		{
			case 'hash':
				if($param['type'] !== 'string' || !preg_match('/^[0-9a-fA-F]{40}$/', $val))
					return null;
				return '<param><value><string>' . strtoupper($val) . '</string></value></param>';

			case 'empty':
				if($param['type'] !== 'string' || $val !== '')
					return null;
				return '<param><value><string></string></value></param>';

			case 'int':
				if($param['type'] !== 'int' && $param['type'] !== 'string')
					return null;
				if(!preg_match('/^(?:0|-?[1-9][0-9]{0,17})$/', $val))
					return null;
				return '<param><value><i8>' . $val . '</i8></value></param>';

			case 'size':
				if($param['type'] !== 'int' && $param['type'] !== 'string')
					return null;
				if(!preg_match('/^[1-9][0-9]{0,17}$/', $val))
					return null;
				if(strlen($val) > 8 || (int)$val > $sizeLimitMax)
					$size = $sizeLimitMax;
				else
					$size = (int)$val;
				return '<param><value><i8>' . $size . '</i8></value></param>';

			case 'text':
				if($param['type'] !== 'string')
					return null;
				return '<param><value><string>'
					. htmlspecialchars($val, ENT_NOQUOTES, 'UTF-8')
					. '</string></value></param>';

			default:
				return null;
		}
	}

	/**
	 * Parse untrusted XMLRPC XML with entity loading disabled.
	 *
	 * PHP 8+ libxml2 defaults external-entity loading off; PHP 7.x does
	 * not, and ruTorrent still supports PHP 7. We disable it explicitly to
	 * prevent XXE on client-supplied XML.
	 */
	private static function parseXml($rawData)
	{
		$prev = null;
		if(PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader'))
			$prev = libxml_disable_entity_loader(true);
		$xml = @simplexml_load_string($rawData, 'SimpleXMLElement', LIBXML_NONET);
		if($prev !== null)
			libxml_disable_entity_loader($prev);
		return $xml;
	}

	/**
	 * Process a raw XMLRPC payload according to the configured mode.
	 *
	 * @param string $rawData     Raw XMLRPC XML from the client
	 * @param string $mode        "off", "passthrough_unsafe", or "sanitize"
	 * @param bool   $enableLog   Enable/disable logging
	 * @param array  $safeParams  Command names allowed as load.* params, matched exactly
	 * @param bool   $allowLocalPaths  Let a caller name a path on rtorrent's own
	 *                                 filesystem in load.start / load.normal
	 * @return string|null        SCGI response, or null on rejection
	 */
	public static function process($rawData, $mode = 'sanitize', $enableLog = true, $safeParams = array(), $allowLocalPaths = false, $options = array())
	{
		self::$log = $enableLog;

		$decision = self::decide($rawData, $mode, $safeParams, $allowLocalPaths, $options);

		foreach($decision['log'] as $line)
			self::log($line);

		if($decision['action'] !== 'send')
			return null;

		return rXMLRPCRequest::send($decision['payload'], $decision['trusted']);
	}

	/**
	 * Decide what to do with a raw XMLRPC payload, without acting on it.
	 *
	 * Same policy as process(), separated from the sending and the logging so
	 * that a caller holding its own connection to rtorrent can apply it —
	 * ruTorrent's SCGI plumbing needs the settings bootstrap, and an endpoint
	 * whose whole job is to filter one request should not have to carry that.
	 *
	 * @param string $rawData     Raw XMLRPC XML from the client
	 * @param string $mode        "off", "passthrough_unsafe", or "sanitize"
	 * @param array  $safeParams  Command names allowed as load.* params, matched exactly
	 * @param bool   $allowLocalPaths  Let a caller name a path on rtorrent's own
	 *                                 filesystem in load.start / load.normal.
	 *                                 Off by default: a remote client has no way
	 *                                 to know what is on that filesystem, and the
	 *                                 path it names becomes the download's tied
	 *                                 file, which d.delete_tied then unlinks.
	 * @return array  'action'  => "send" or "reject"
	 *                'payload' => the bytes to send, empty when rejecting
	 *                'trusted' => whether the connection carrying them may be trusted
	 *                'method'  => the command a refusal refused, null when forwarding
	 *                             or when the refusal names no single command
	 *                'log'     => what happened, in the order it happened
	 */
	public static function decide($rawData, $mode = 'sanitize', $safeParams = array(), $allowLocalPaths = false, $options = array())
	{
		$deny = isset($options['deny']) ? $options['deny'] : self::$denyPrefixes;
		$elevate = isset($options['elevate']) ? $options['elevate'] : self::$elevate;
		$sizeLimitMax = isset($options['sizeLimitMax']) ? $options['sizeLimitMax'] : self::$sizeLimitMax;
		$directory = isset($options['directory']) ? $options['directory'] : null;

		if($mode === 'off' || ($mode !== 'passthrough_unsafe' && $mode !== 'sanitize'))
			return self::reject("rejected (proxy disabled)");

		if($mode === 'passthrough_unsafe')
			return self::forward($rawData, true, "passthrough (UNSAFE mode)");

		$decoded = self::decodeCall($rawData);
		if(!$decoded['ok'])
		{
			$method = isset($decoded['method']) ? $decoded['method'] : null;
			$logMsg = $decoded['error'];
			if($method !== null)
				$logMsg .= ': ' . self::normalizeMethodName($method);
			return self::reject($logMsg, $method, $decoded['error']);
		}

		$methodName = $decoded['method'];
		$params = $decoded['params'];

		if(self::isDirectDenied($methodName, $deny))
			return self::reject("rejected (not allowed on this connection): ".
				self::normalizeMethodName($methodName), $methodName);

		if($methodName === 'system.multicall')
			return self::reject("rejected (not allowed on this connection): ".
				self::normalizeMethodName($methodName), $methodName);

		if(in_array($methodName, self::$sanitizeMethods, true))
		{
			if(count($params) < 2)
				return self::reject("rejected (malformed load call): ".
					self::normalizeMethodName($methodName), $methodName);

			if($params[0]['type'] !== 'string')
				return self::reject("rejected (malformed load call): ".
					self::normalizeMethodName($methodName), $methodName);

			$targetVal = $params[0]['value'];
			$isRaw = in_array($methodName, self::$rawLoadMethods, true);
			$dataParam = $params[1];
			$localPath = null;

			if($isRaw)
			{
				if($dataParam['typeTag'] !== 'base64')
					return self::reject("rejected (malformed load call): ".
						self::normalizeMethodName($methodName), $methodName);

				$rawBytes = base64_decode($dataParam['value'], true);
				if($rawBytes === false)
					return self::reject("rejected (malformed load call): ".
						self::normalizeMethodName($methodName), $methodName);

				$canonicalDataXml = '<param><value><base64>'.base64_encode($rawBytes).'</base64></value></param>';
			}
			else
			{
				if($dataParam['type'] !== 'string' && $dataParam['typeTag'] !== 'base64')
					return self::reject("rejected (malformed load call): ".
						self::normalizeMethodName($methodName), $methodName);

				$uri = $dataParam['value'];
				if($dataParam['typeTag'] === 'base64')
				{
					$decodedUri = base64_decode($uri, true);
					if($decodedUri === false)
						return self::reject("rejected (malformed load call): ".
							self::normalizeMethodName($methodName), $methodName);
					$uri = $decodedUri;
				}

				if(!self::isValidXmlUtf8String($uri))
					return self::reject("rejected (malformed load call): ".
						self::normalizeMethodName($methodName), $methodName);

				if(!preg_match(self::$networkUri, $uri))
				{
					if(!$allowLocalPaths)
						return self::reject("rejected (load from a local path): ".
							self::normalizeMethodName($methodName), $methodName);
					$localPath = $uri;
				}

				$canonicalDataXml = '<param><value><string>'.htmlspecialchars($uri, ENT_NOQUOTES, 'UTF-8').'</string></value></param>';
			}

			$rebuiltCommands = array();

			for($i = 2; $i < count($params); $i++)
			{
				$cmdParam = $params[$i];
				if($cmdParam['type'] !== 'string')
					return self::reject("rejected (malformed load call): ".
						self::normalizeMethodName($methodName), $methodName);

				$cmdVal = $cmdParam['value'];
				$rebuilt = self::rebuildSafeLoadParam($cmdVal, $safeParams, $directory, $deny);
				if($rebuilt === null || $rebuilt === false)
				{
					return self::reject("rejected (not allowed on this connection): ".
						self::normalizeMethodName($methodName), $methodName);
				}

				$rebuiltCommands[] = $rebuilt;
			}

			$canonicalXml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
				. '<methodCall><methodName>'.htmlspecialchars($methodName, ENT_NOQUOTES, 'UTF-8').'</methodName><params>'
				. '<param><value><string>'.htmlspecialchars($targetVal, ENT_NOQUOTES, 'UTF-8').'</string></value></param>'
				. $canonicalDataXml;
			foreach($rebuiltCommands as $rc)
			{
				$canonicalXml .= '<param><value><string>'.htmlspecialchars($rc, ENT_NOQUOTES, 'UTF-8').'</string></value></param>';
			}
			$canonicalXml .= '</params></methodCall>';

			$totalKept = 2 + count($rebuiltCommands);
			$normMethod = self::normalizeMethodName($methodName);
			$paramDesc = "kept ".$totalKept." params";

			if($localPath !== null)
			{
				$logMsg = "WARNING: operator-enabled local path forwarded: ".$normMethod.
					"; trusted: ".$normMethod." (".$paramDesc.")";
			}
			else
			{
				$logMsg = "trusted: ".$normMethod." (".$paramDesc.")";
			}
			return self::forward($canonicalXml, true, $logMsg);
		}

		if(in_array($methodName, self::$multicallMethods, true))
		{
			$isFiltered = ($methodName === 'd.multicall.filtered');
			$minParams = $isFiltered ? 4 : 3;
			if(count($params) < $minParams)
			{
				return self::reject("rejected (not allowed on this connection): " . self::normalizeMethodName($methodName), $methodName);
			}

			if($params[0]['type'] !== 'string' || $params[1]['type'] !== 'string')
				return self::reject("rejected (not allowed on this connection): " . self::normalizeMethodName($methodName), $methodName);

			$targetVal = $params[0]['value'];
			$viewVal = $params[1]['value'];

			$filterVal = null;
			$resultStartIndex = 2;
			if($isFiltered)
			{
				if($params[2]['type'] !== 'string')
					return self::reject("rejected (not allowed on this connection): " . self::normalizeMethodName($methodName), $methodName);
				$rawFilter = $params[2]['value'];
				$rebuiltFilter = self::rebuildSafeLoadParam($rawFilter, $safeParams, $directory, $deny);
				if($rebuiltFilter === null || $rebuiltFilter === false)
				{
					return self::reject("rejected (not allowed on this connection): " . self::normalizeMethodName($methodName), $methodName);
				}
				$filterVal = $rebuiltFilter;
				$resultStartIndex = 3;
			}

			$rebuiltResults = array();
			for($i = $resultStartIndex; $i < count($params); $i++)
			{
				if($params[$i]['type'] !== 'string')
					return self::reject("rejected (not allowed on this connection): " . self::normalizeMethodName($methodName), $methodName);

				$cmd = $params[$i]['value'];
				$separator = strpos($cmd, '=');
				$cmdName = ($separator !== false) ? trim(substr($cmd, 0, $separator)) : trim($cmd);
				if($cmdName !== '' && self::isDirectDenied($cmdName, $deny))
					return self::reject("rejected (not allowed on this connection): " . self::normalizeMethodName($methodName), $methodName);

				$rebuilt = self::rebuildSafeLoadParam($cmd, $safeParams, $directory, $deny);
				if($rebuilt === null || $rebuilt === false)
				{
					return self::reject("rejected (not allowed on this connection): " . self::normalizeMethodName($methodName), $methodName);
				}
				$rebuiltResults[] = $rebuilt;
			}

			$canonicalXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. '<methodCall><methodName>' . htmlspecialchars($methodName, ENT_NOQUOTES, 'UTF-8') . '</methodName><params>'
				. '<param><value><string>' . htmlspecialchars($targetVal, ENT_NOQUOTES, 'UTF-8') . '</string></value></param>'
				. '<param><value><string>' . htmlspecialchars($viewVal, ENT_NOQUOTES, 'UTF-8') . '</string></value></param>';
			if($isFiltered)
			{
				$canonicalXml .= '<param><value><string>' . htmlspecialchars($filterVal, ENT_NOQUOTES, 'UTF-8') . '</string></value></param>';
			}
			foreach($rebuiltResults as $rr)
			{
				$canonicalXml .= '<param><value><string>' . htmlspecialchars($rr, ENT_NOQUOTES, 'UTF-8') . '</string></value></param>';
			}
			$canonicalXml .= '</params></methodCall>';

			$totalParams = count($params);
			return self::forward($canonicalXml, true, "trusted: " . self::normalizeMethodName($methodName) . " (" . $totalParams . " params)");
		}

		if(isset($elevate[$methodName]))
		{
			$shapes = $elevate[$methodName];
			if(count($params) !== count($shapes))
				return self::reject("rejected (arguments did not match allowed shape): ".
					self::normalizeMethodName($methodName), $methodName);

			$canonicalParams = array();
			for($i = 0; $i < count($shapes); $i++)
			{
				$shape = $shapes[$i];
				$param = $params[$i];
				$emitted = self::emitArgumentFromDecoded($shape, $param, $sizeLimitMax);
				if($emitted === null)
					return self::reject("rejected (arguments did not match allowed shape): ".
						self::normalizeMethodName($methodName), $methodName);
				$canonicalParams[] = $emitted;
			}

			$canonicalXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. '<methodCall><methodName>' . htmlspecialchars($methodName, ENT_NOQUOTES, 'UTF-8') . '</methodName><params>'
				. implode('', $canonicalParams)
				. '</params></methodCall>';

			return self::forward($canonicalXml, true, "trusted: " . self::normalizeMethodName($methodName) . " (elevated)");
		}

		// Unknown method — pass through as untrusted.
		return self::forward($rawData, false, "untrusted: " . self::normalizeMethodName($methodName));
	}

	/**
	 * May a download be written into this path?
	 *
	 * $directory is the policy: array('root' => <absolute path>, 'resolve' =>
	 * <callable|null>). Without one, no — a caller naming a write target has to
	 * be answered from a stated boundary, and "none was stated" is not a boundary.
	 *
	 * The value normally does not exist yet, so realpath() on it returns false
	 * and cannot be the check. A lexical check alone is not enough either: one
	 * symlink inside the tree, which the customer can create, points anywhere.
	 * So the lexical check runs first and the resolver is then asked about the
	 * deepest part that does exist.
	 */
	private static function directoryIsAllowed($path, $directory)
	{
		if(!is_array($directory) || !isset($directory['root']))
			return false;
		$root = self::normalisePath($directory['root']);
		if(($root === null) || ($root === ''))
			return false;

		$path = self::normalisePath($path);
		if($path === null)
			return false;
		if(!self::isInside($path, $root))
			return false;

		if(isset($directory['resolve']) && is_callable($directory['resolve']))
		{
			$real = call_user_func($directory['resolve'], $path);
			$realRoot = call_user_func($directory['resolve'], $root);
			// A resolver that cannot answer for either side leaves the question
			// open, and an open question about a write target is a no.
			if(!is_string($real) || !is_string($realRoot) || ($real === '') || ($realRoot === ''))
				return false;
			if(!self::isInside($real, $realRoot))
				return false;
		}

		return true;
	}

	/**
	 * Collapse '.', '..' and repeated separators without touching the
	 * filesystem. Returns null for anything that is not an absolute path,
	 * including one that climbs above '/'.
	 */
	private static function normalisePath($path)
	{
		$path = trim((string)$path);
		if(($path === '') || ($path[0] !== '/'))
			return null;
		$out = array();
		foreach(explode('/', $path) as $part)
		{
			if(($part === '') || ($part === '.'))
				continue;
			if($part === '..')
			{
				if(count($out) === 0)
					return null;
				array_pop($out);
				continue;
			}
			$out[] = $part;
		}
		return '/'.implode('/', $out);
	}

	/**
	 * Is $path the root itself or something under it? Compared with the
	 * separator attached, so /torrents1x is not inside /torrents1.
	 */
	private static function isInside($path, $root)
	{
		if($root === '/')
			return true;
		return ($path === $root) || (strpos($path, rtrim($root, '/').'/') === 0);
	}

	private static function forward($payload, $trusted, $line)
	{
		return array('action' => 'send', 'payload' => $payload,
			'trusted' => $trusted, 'method' => null, 'log' => array(self::formatLogMessage($line)));
	}

	/**
	 * A refusal. $method names the command that was refused, when the refusal
	 * has one to name — a door renders it back to the caller so the client is
	 * told what it may not do, instead of being left to guess.
	 */
	private static function reject($line, $method = null, $error = null)
	{
		return array(
			'action' => 'reject',
			'payload' => '',
			'trusted' => false,
			'method' => self::normalizeMethodName($method),
			'log' => array(self::formatLogMessage($line)),
			'error' => ($error !== null) ? $error : $line,
		);
	}

	/**
	 * The sentence both doors show when this filter refuses a call. It names the
	 * command so a refusal reads the same at either door, and it says this
	 * server refused the call rather than blaming rtorrent for an outage that
	 * did not happen -- rtorrent never saw it.
	 */
	public static function rejectionMessage($method)
	{
		$cleanMethod = self::normalizeMethodName($method);
		return (($cleanMethod !== null) && ($cleanMethod !== ''))
			? "The command '".$cleanMethod."' was rejected by this server."
			: "This XMLRPC call was rejected by this server.";
	}

	/**
	 * The same sentence wrapped in the XMLRPC fault a door returns: faultCode
	 * -501, matching what rpc2.php answers for the same refusals.
	 */
	public static function rejectionFault($method)
	{
		$faultString = self::rejectionMessage($method);
		return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
			.'<methodResponse><fault><value><struct>'
			.'<member><name>faultCode</name><value><i4>-501</i4></value></member>'
			.'<member><name>faultString</name><value><string>'
			.htmlspecialchars($faultString, ENT_NOQUOTES, 'UTF-8')
			.'</string></value></member>'
			.'</struct></value></fault></methodResponse>';
	}

	/**
	 * Split command arguments up to the command's known arity. Before the final
	 * argument, commas separate values the way rtorrent expects; the final one
	 * keeps raw commas so existing labels and paths remain one value. A
	 * double-quoted string is one argument even when it contains commas.
	 * Unquoted arguments are trimmed; quoted ones are not. An unclosed quote,
	 * or text after a quoted argument that is not a comma, is malformed and
	 * returns null. The production policy treats that result as a terminal
	 * rejection of the complete outer request without a transport call.
	 *
	 * Clients such as cross-seed quote every value (d.custom1.set="cross-seed").
	 * Splitting on ',' first would cut inside those quotes; dropping them left
	 * torrents unlabeled and in the default directory. Unquoting here, then
	 * re-quoting in rebuildSafeLoadParam, keeps the value as one argument.
	 */
	private static function splitLoadArguments($value, $maxArguments)
	{
		if(!is_int($maxArguments) || $maxArguments < 1)
			return null;

		$arguments = array();
		$len = strlen($value);
		$i = 0;
		while(true)
		{
			while($i < $len && ($value[$i] === ' ' || $value[$i] === "\t"))
				$i++;
			$isLastArgument = (count($arguments) + 1 >= $maxArguments);

			if($i < $len && $value[$i] === '"')
			{
				$i++;
				$argument = '';
				$closed = false;
				while($i < $len)
				{
					$c = $value[$i];
					if($c === '\\' && $i + 1 < $len)
					{
						$argument .= $value[$i + 1];
						$i += 2;
						continue;
					}
					if($c === '"')
					{
						$closed = true;
						$i++;
						break;
					}
					$argument .= $c;
					$i++;
				}
				if(!$closed)
					return null;
				$arguments[] = $argument;
			}
			else
			{
				// Only one escape changes where an argument ends: rtorrent reads
				// '\\,' as a comma inside the value rather than a separator
				// (parse_string, src/rpc/parse.cc), so splitting on every comma
				// cut such a value in two and delivered a second argument the
				// client never sent.
				//
				// Its other escapes are deliberately left alone. rtorrent would
				// read '\\' as an escape everywhere, which turns C:\\downloads
				// into C:downloads -- but this side re-quotes the value, so the
				// backslash reaches rtorrent intact, and clients have been
				// sending paths and labels through here on that basis. Matching
				// rtorrent exactly would eat those backslashes.
				$argument = '';
				$keep = 0;
				while($i < $len)
				{
					$c = $value[$i];
					if($c === ',' && !$isLastArgument)
						break;
					if(($c === '\\') && ($i + 1 < $len) && ($value[$i + 1] === ','))
					{
						$argument .= ',';
						$i += 2;
						$keep = strlen($argument);
						continue;
					}
					$argument .= $c;
					$i++;
					// Trailing whitespace is trimmed as rtorrent trims it.
					if(($c !== ' ') && ($c !== "\t"))
						$keep = strlen($argument);
				}
				$arguments[] = substr($argument, 0, $keep);
			}

			while($i < $len && ($value[$i] === ' ' || $value[$i] === "\t"))
				$i++;
			if($i < $len)
			{
				if($value[$i] !== ',')
					return null;
				if(count($arguments) >= $maxArguments)
					return null;
				$i++;
			}
			else
				break;
		}
		return $arguments;
	}

	/**
	 * Rebuild one command parameter. A null or false result makes the production
	 * policy reject the complete outer request without a transport call.
	 *
	 * A parameter is not a single command: rtorrent ends a command at ';' or a
	 * newline and calls a parenthesised (command,args) found in a value, so a
	 * string that merely begins with an allowed command can carry others. The
	 * command name is therefore compared for equality, and each argument is
	 * quoted so that whatever it contains stays an argument.
	 *
	 * Arguments are split with the command's known arity. Quoted strings and
	 * escaped commas follow rtorrent's parser, while the final argument keeps
	 * raw commas for compatibility with labels and directories such as
	 * "Movies, Inc". A value the client already quoted is unquoted here, then
	 * re-quoted, rather than dropped: cross-seed and others send
	 * d.custom1.set="label".
	 */
	private static function rebuildSafeLoadParam($paramValue, $safeParams, $directory = null, $deny = null)
	{
		$separator = strpos($paramValue, '=');
		if($separator === false)
			return null;

		$command = trim(substr($paramValue, 0, $separator));
		if(self::isDeniedCommand($command, $deny !== null ? $deny : self::$denyPrefixes))
			return null;
		if(!in_array($command, $safeParams, true))
			return null;

		$maxArguments = isset(self::$multiArgCommands[$command])
			? self::$multiArgCommands[$command] : 1;
		$parts = self::splitLoadArguments(substr($paramValue, $separator + 1), $maxArguments);
		if($parts === null)
			return null;

		// Both shipped endpoints provide boundary options. Any directory setter
		// outside the configured boundary returns false here and makes the
		// outer request locally terminal with no transport call.
		if(($directory !== null) && in_array($command, self::$directoryCommands, true))
		{
			$path = isset($parts[0]) ? $parts[0] : '';
			if(!self::directoryIsAllowed($path, $directory))
				return false;
		}

		$arguments = array();
		foreach($parts as $argument)
		{
			// An argument whose first character is '$' is parsed and called as a
			// command after quoting is undone, so quoting cannot make it safe.
			// Unquoted values are already trimmed by the split; trimming again
			// here would not turn a leading space into a leading '$'.
			if(isset($argument[0]) && $argument[0] === '$')
				return null;

			$arguments[] = '"'.str_replace(array('\\', '"'), array('\\\\', '\\"'), $argument).'"';
		}

		return $command.'='.implode(',', $arguments);
	}

	/**
	 * Extract a command-param value from its <value> element.
	 *
	 * Handles both the typed form <value><string>foo</string></value> and
	 * the implicit-string form <value>foo</value>. For non-string types
	 * (<int>, <base64>) the raw text is returned; it simply won't match
	 * any allowed command name and will be stripped — safe default.
	 */
	private static function extractParamValue($paramElement)
	{
		if(isset($paramElement->string))
			return (string)$paramElement->string;
		return trim((string)$paramElement);
	}

	/**
	 * Rebuild a command-carrying call keeping only safe parameters.
	 *
	 *   Param 0: target                    (always kept)
	 *   Param 1: URL, raw data, or view    (always kept)
	 *   Param 2+: command strings (kept iff the command name is in the
	 *                              whitelist, otherwise stripped)
	 *
	 * Both families put their commands at param 2: load.start, load.normal,
	 * load.raw, load.raw_start, d.multicall, d.multicall2,
	 * d.multicall.filtered and t/f/p.multicall all do, on 0.9.8 and on 0.16.x
	 * alike. Measured by side effect against both, rather than read off a
	 * signature — the caller acts on the answer by deciding what is data.
	 *
	 * Public for unit testing — production callers should go through
	 * process().
	 *
	 * @return array ['xml' => string, 'kept' => int, 'stripped' => array,
	 *                'rebuiltAll' => bool] — rebuiltAll is false when any
	 *               parameter had to be carried over verbatim, which means
	 *               the call must not be sent as trusted.
	 */
	public static function rebuildLoadParams($xml, $methodName, $safeParams = array(), $directory = null)
	{
		$cleanXml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$cleanXml .= '<methodCall><methodName>' . htmlspecialchars($methodName) . '</methodName>';
		$cleanXml .= '<params>';

		$kept = 0;
		$stripped = array();

		$rebuiltAll = true;

		if(isset($xml->params->param))
		{
			$index = 0;
			foreach($xml->params->param as $param)
			{
				if($index < 2)
				{
					if(!isset($param->value))
					{
						$rebuiltAll = false;
						$index++;
						continue;
					}
					// Target and URL/data are values, never commands, but they
					// are re-emitted rather than copied so that what was read
					// and what is sent are the same bytes.
					$payload = self::rebuildDataParam($param->value);
					if($payload === null)
					{
						$payload = '<param>' . $param->value->asXML() . '</param>';
						$rebuiltAll = false;
					}
					$cleanXml .= $payload;
					$kept++;
				}
				else
				{
					if(!isset($param->value))
					{
						$index++;
						continue;
					}
					$value = self::extractParamValue($param->value);
					$rebuiltParam = self::rebuildSafeLoadParam($value, $safeParams, $directory);
					if($rebuiltParam !== null)
					{
						$cleanXml .= '<param><value><string>'
							. htmlspecialchars($rebuiltParam, ENT_NOQUOTES, 'UTF-8')
							. '</string></value></param>';
						$kept++;
					}
					else
					{
						$stripped[] = $value;
					}
				}
				$index++;
			}
		}

		$cleanXml .= '</params></methodCall>';

		return array('xml' => $cleanXml, 'kept' => $kept, 'stripped' => $stripped,
			'rebuiltAll' => $rebuiltAll);
	}

	/**
	 * Re-emit a target or URL/data parameter from its own content, keeping the
	 * type the client used. Returns null for a type this side cannot rebuild,
	 * which makes the whole request go untrusted.
	 */
	private static function rebuildDataParam($paramElement)
	{
		if($paramElement === null)
			return null;

		if(isset($paramElement->base64))
		{
			$decoded = base64_decode((string)$paramElement->base64, true);
			if($decoded === false)
				return null;
			return '<param><value><base64>'.base64_encode($decoded).'</base64></value></param>';
		}

		if(isset($paramElement->string) || count($paramElement->children()) === 0)
		{
			$text = isset($paramElement->string) ? (string)$paramElement->string : (string)$paramElement;
			return '<param><value><string>'
				. htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8')
				. '</string></value></param>';
		}

		return null;
	}
}
