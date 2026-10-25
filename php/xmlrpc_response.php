<?php

/**
 * Byte-preserving XML-RPC response reader. TinyXML2 has historically emitted
 * non-UTF-8 bytes in string elements, so a Unicode XML DOM changes or refuses
 * valid daemon names. Decode the grammar directly into semantic values; a
 * retained markup tree would exhaust ordinary workers on large list replies.
 * DTDs and external entities are never expanded.
 */
final class rXMLRPCResponse
{
	private $xml;
	private $offset = 0;
	private $multicall;
	public $scalars = array();
	public $values = array();
	public $valueTypes = array();
	// One compact flat string-array width per top-level result; null invalid.
	public $stringArrayWidths = array();
	// One compact table width per top-level result: null invalid, -1 empty.
	public $stringTableWidths = array();
	// One compact proof per command result; native multicall wrappers remain in values.
	public $commandValues = array();
	public $commandTypes = array();
	public $commandStringTableWidths = array();
	public $fault = false;
	public $faultString = '';

	public function __construct($xml, $multicall = false)
	{
		$this->xml = $xml;
		$this->multicall = $multicall;
	}

	private function refuse($reason = 'malformed-response')
	{
		throw new UnexpectedValueException($reason);
	}

	private function space()
	{
		$this->offset += strspn($this->xml, " \t\r\n", $this->offset);
	}

	private function take($text)
	{
		if(substr($this->xml, $this->offset, strlen($text)) !== $text) $this->refuse();
		$this->offset += strlen($text);
	}

	/** Read one opening tag; only XML-RPC's attribute-free elements occur. */
	private function open()
	{
		if(!preg_match('/\G<([A-Za-z][A-Za-z0-9_.]*)(\s*\/?)>/A', $this->xml, $match, 0, $this->offset))
			$this->refuse();
		$this->offset += strlen($match[0]);
		return array($match[1], strpos($match[2], '/') !== false);
	}

	private function text($text)
	{
		if(preg_match('/&(?!(?:amp|lt|gt|quot|apos|#[0-9]+|#[xX][0-9a-fA-F]+);)/', $text))
			$this->refuse();
		// XML numeric references must name XML 1.0 scalar characters. Raw
		// legacy daemon bytes deliberately retain their compatibility path.
		if(preg_match_all('/&#([0-9]+|[xX][0-9a-fA-F]+);/', $text, $references))
			foreach($references[1] as $reference)
			{
				$hex = $reference[0] === 'x' || $reference[0] === 'X';
				$digits = ltrim($hex ? substr($reference, 1) : $reference, '0');
				if(strlen($digits) > ($hex ? 6 : 7)) $this->refuse();
				$code = $hex ? hexdec($digits === '' ? '0' : $digits) : (int)$digits;
				if(!in_array($code, array(9, 10, 13), true) &&
					!($code >= 0x20 && $code <= 0xD7FF) &&
					!($code >= 0xE000 && $code <= 0xFFFD) &&
					!($code >= 0x10000 && $code <= 0x10FFFF)) $this->refuse();
			}
		try { $decoded = @html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8'); }
		catch(Throwable $error) { $this->refuse('text-decoder-unavailable'); }
		if(!is_string($decoded)) $this->refuse('text-decoder-unavailable');
		return $decoded;
	}

	/** Scalar text is consumed once; CDATA is literal, entities decode once. */
	private function scalarText($tag)
	{
		$out = '';
		while(true)
		{
			$end = strpos($this->xml, '<', $this->offset);
			if($end === false) $this->refuse();
			$out .= $this->text(substr($this->xml, $this->offset, $end - $this->offset));
			$this->offset = $end;
			if(substr($this->xml, $end, 9) !== '<![CDATA[')
			{
				$this->take('</'.$tag.'>');
				return $out;
			}
			$end = strpos($this->xml, ']]>', $this->offset + 9);
			if($end === false) $this->refuse();
			$out .= substr($this->xml, $this->offset + 9, $end - $this->offset - 9);
			$this->offset = $end + 3;
		}
	}

	private function integerFits($text, $type)
	{
		if(!preg_match('/^[+-]?[0-9]+\z/', $text)) return false;
		$negative = $text[0] === '-';
		$digits = ltrim(ltrim($text, '+-'), '0');
		$bound = $type === 'i8' ? ($negative ? '9223372036854775808' : '9223372036854775807')
			: ($negative ? '2147483648' : '2147483647');
		return strlen($digits) < strlen($bound) ||
			(strlen($digits) === strlen($bound) && strcmp($digits, $bound) <= 0);
	}

	private function value($faultContext = false, $multicall = false, $depth = 0, &$resultType = null, &$rowWidth = null, &$tableWidth = null, &$wrappedResult = null)
	{
		$rowWidth = $tableWidth = $wrappedResult = null;
		if($depth > 64) $this->refuse();
		list($tag, $empty) = $this->open();
		if($tag !== 'value') $this->refuse();
		if($empty)
		{
			$resultType = 'string';
			$this->scalars[] = array('string', '');
			return '';
		}
		$end = strpos($this->xml, '<', $this->offset);
		if($end === false) $this->refuse();
		// An untyped value is an implicit string. Whitespace before a typed
		// child is envelope formatting; whitespace in an implicit value is data.
		if(substr($this->xml, $end, 8) === '</value>' || substr($this->xml, $end, 9) === '<![CDATA[')
		{
			$resultType = 'string';
			$text = $this->scalarText('value');
			$this->scalars[] = array('string', $text);
			return $text;
		}
		if(trim(substr($this->xml, $this->offset, $end - $this->offset)) !== '') $this->refuse();
		$this->offset = $end;
		list($type, $empty) = $this->open();
		$resultType = $type;
		if($type === 'array')
		{
			if($empty) $this->refuse();
			$this->space();
			list($data, $emptyData) = $this->open();
			if($data !== 'data') $this->refuse();
			$result = array();
			$rowWidth = 0;
			$tableWidth = -1;
			if(!$emptyData)
			{
				$this->space();
				while(substr($this->xml, $this->offset, 7) !== '</data>')
				{
					$child = $this->value($multicall, false, $depth + 1, $childType, $childRowWidth, $childTableWidth, $childWrappedResult);
					$result[] = $child;
					if($multicall)
					{
						// Only an XML array with exactly one result is a success wrapper.
						$this->commandValues[] = $childWrappedResult === null ? null : $child[0];
						$this->commandTypes[] = $childWrappedResult === null ? null : $childWrappedResult[0];
						$this->commandStringTableWidths[] = $childWrappedResult === null ? null : $childWrappedResult[1];
					}
					// Track only widths while parsing. No parallel tree of cell types
					// survives beside the semantic result and flat scalar accessors.
					if($rowWidth !== null)
						$rowWidth = in_array($childType, array('string', 'base64'), true) ? $rowWidth + 1 : null;
					if($tableWidth !== null)
					{
						if($childType !== 'array' || $childRowWidth === null) $tableWidth = null;
						else if($tableWidth === -1) $tableWidth = $childRowWidth;
						else if($tableWidth !== $childRowWidth) $tableWidth = null;
					}
					$this->space();
				}
				$this->take('</data>');
			}
			$this->space();
			$this->take('</array>');
			if($faultContext && $depth === 1 && count($result) === 1)
				$wrappedResult = array($childType, $childTableWidth);
		}
		else if($type === 'struct')
		{
			$result = array();
			if(!$empty)
			{
				$this->space();
				while(substr($this->xml, $this->offset, 9) !== '</struct>')
				{
					$this->take('<member>');
					$this->space();
					list($name, $emptyName) = $this->open();
					if($name !== 'name') $this->refuse();
					$key = $emptyName ? '' : $this->scalarText('name');
					if(array_key_exists($key, $result)) $this->refuse();
					$this->space();
					$result[$key] = $this->value(false, false, $depth + 1);
					$this->space();
					$this->take('</member>');
					$this->space();
				}
				$this->take('</struct>');
			}
			if($faultContext)
			{
				if(!isset($result['faultCode'], $result['faultString']) || !is_string($result['faultCode']) ||
					!preg_match('/^[+-]?[0-9]+\z/', $result['faultCode']) || !is_string($result['faultString'])) $this->refuse();
				$this->fault = true;
				$this->faultString = $result['faultString'];
			}
		}
		else
		{
			$text = $empty ? '' : $this->scalarText($type);
			switch($type)
			{
				case 'string': case 'dateTime.iso8601': break;
				case 'i4': case 'i8': case 'int':
					if(!$this->integerFits($text, $type)) $this->refuse();
					break;
				case 'boolean':
					if($text !== '0' && $text !== '1') $this->refuse();
					break;
				case 'double':
					if(!is_numeric($text) || !is_finite((float)$text)) $this->refuse();
					break;
				case 'nil':
					if($text !== '') $this->refuse();
					$text = null;
					break;
				case 'base64':
					// Strict decoding already accepts XML-RPC whitespace.
					try { $text = @base64_decode($text, true); }
					catch(Throwable $error) { $this->refuse('base64-decoder-unavailable'); }
					if($text === false) $this->refuse('invalid-base64');
					if(!is_string($text)) $this->refuse('base64-decoder-unavailable');
					break;
				default: $this->refuse('unsupported-response-type');
			}
			$this->scalars[] = array($type, $text);
			$result = $text;
		}
		$this->space();
		$this->take('</value>');
		return $result;
	}

	public function read()
	{
		$split = strpos($this->xml, "\r\n\r\n");
		if($split !== false && substr($this->xml, 0, 1) !== '<') $this->xml = substr($this->xml, $split + 4);
		$this->space();
		if(substr($this->xml, $this->offset, 5) === '<?xml')
		{
			$end = strpos($this->xml, '?>', $this->offset);
			if($end === false) $this->refuse();
			$this->offset = $end + 2;
			$this->space();
		}
		$this->take('<methodResponse>');
		$this->space();
		if(substr($this->xml, $this->offset, 7) === '<fault>')
		{
			$this->take('<fault>');
			$this->space();
			$resultType = null;
			$this->values[] = $this->value(true, false, 0, $resultType, $rowWidth, $tableWidth);
			$this->valueTypes[] = $resultType;
			$this->stringArrayWidths[] = $rowWidth;
			$this->stringTableWidths[] = $tableWidth;
			if(!$this->fault) $this->refuse();
			$this->space();
			$this->take('</fault>');
		}
		else
		{
			$this->take('<params>');
			$this->space();
			$this->take('<param>');
			$this->space();
			$resultType = null;
			$this->values[] = $this->value(false, $this->multicall, 0, $resultType, $rowWidth, $tableWidth);
			$this->valueTypes[] = $resultType;
			$this->stringArrayWidths[] = $rowWidth;
			$this->stringTableWidths[] = $tableWidth;
			$this->space();
			$this->take('</param>');
			$this->space();
			$this->take('</params>');
		}
		$this->space();
		$this->take('</methodResponse>');
		$this->space();
		if($this->offset !== strlen($this->xml)) $this->refuse();
		if(!$this->multicall)
		{
			$this->commandValues = $this->values;
			$this->commandTypes = $this->valueTypes;
			$this->commandStringTableWidths = $this->stringTableWidths;
		}
		return $this;
	}
}
