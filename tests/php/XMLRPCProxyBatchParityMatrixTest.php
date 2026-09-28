<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/xmlrpc_proxy.php');

/**
 * Compare each validated call alone and as the sole member of a batch.
 * Matching calls must have the same decision, trust level and emitted scalar
 * values. A mixed-trust batch is a separate refusal: no member may borrow
 * another member's trusted connection. The corpora cover every elevation
 * shape and every load spelling in the current proxy policy.
 */
class XMLRPCProxyBatchParityMatrixTest extends TestCase
{
	private $safe = array('d.custom1.set', 'd.custom2.set', 'd.directory.set',
		'd.directory_base.set', 'd.priority.set', 'd.open', 'd.start');

	private $hash = '0123456789abcdef0123456789abcdef01234567';

	/** Divergences found in the family being walked, as readable lines. */
	private $bad;

	/** Cases compared in the family being walked. */
	private $cases;

	private function property($name)
	{
		$property = new ReflectionProperty('XMLRPCProxy', $name);
		self::makeAccessible($property);
		return $property->getValue();
	}

	/* ---------------------------------------------------------------- *
	 * Building the two shapes of the same call
	 * ---------------------------------------------------------------- */

	/**
	 * One parameter. A case gives a bare string, or array(text, type) to pick
	 * the XMLRPC type it arrives as -- base64 above all, which rtorrent
	 * decodes and reads as the same string, so it must not be a way past a
	 * check that reads the string.
	 */
	private function value($param)
	{
		$text = is_array($param) ? $param[0] : $param;
		$type = is_array($param) ? $param[1] : 'string';
		// The base64 envelope written out as given rather than encoded, which
		// is how a case says "base64 that is not base64".
		if($type === 'rawbase64')
			return '<value><base64>' . $text . '</base64></value>';
		// A complete value written by the case, for shapes the scalar helper is
		// deliberately unable to express.
		if($type === 'raw')
			return $text;
		if($type === 'base64')
			$text = base64_encode($text);
		if($type === 'implicit')
			return '<value>' . htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8') . '</value>';
		return '<value><' . $type . '>'
			. htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8')
			. '</' . $type . '></value>';
	}

	private function single($method, $params)
	{
		$xml = '<?xml version="1.0"?><methodCall><methodName>'
			. $method . '</methodName><params>';
		foreach($params as $param)
			$xml .= '<param>' . $this->value($param) . '</param>';
		return $xml . '</params></methodCall>';
	}

	private function member($method, $params)
	{
		$out = '<value><struct>'
			. '<member><name>methodName</name><value><string>' . $method
			. '</string></value></member>'
			. '<member><name>params</name><value><array><data>';
		foreach($params as $param)
			$out .= $this->value($param);
		return $out . '</data></array></value></member></struct></value>';
	}

	private function batch($members)
	{
		return '<?xml version="1.0"?><methodCall>'
			. '<methodName>system.multicall</methodName>'
			. '<params><param><value><array><data>' . implode('', $members)
			. '</data></array></value></param></params></methodCall>';
	}

	private function batched($method, $params)
	{
		return $this->batch(array($this->member($method, $params)));
	}

	private function decide($xml, $allowLocalPaths)
	{
		return XMLRPCProxy::decide($xml, 'sanitize', $this->safe, $allowLocalPaths,
			array('directory' => array('root' => '/', 'resolve' => null),
				'rtorrentVersion' => 0x1018));
	}

	/* ---------------------------------------------------------------- *
	 * Reading a decision back
	 * ---------------------------------------------------------------- */

	/**
	 * The string rtorrent reads out of one parameter, whichever type carries
	 * it -- the same reading extractParamValue() does, so that a value
	 * re-emitted as <i8> and the <string> it arrived as compare equal and a
	 * base64 one compares as what it decodes to.
	 */
	private function text($value)
	{
		if(isset($value->base64))
		{
			$decoded = base64_decode((string)$value->base64, true);
			return ($decoded === false) ? '' : $decoded;
		}
		if(isset($value->string))
			return (string)$value->string;
		// <i8>, <int>, <i4>, <boolean>: the text sits in the type element, and
		// SimpleXML's cast reads only the text directly inside the element it
		// is given, so an untyped cast of <value> would read every one of
		// these as empty.
		foreach($value->children() as $child)
			return (string)$child;
		return trim((string)$value);
	}

	/**
	 * The XMLRPC type that text arrives in, which the decoded value alone
	 * does not say: <string>3</string>, <i8>3</i8> and a base64 decoding to
	 * "3" all read as "3", while only one of them is the integer rtorrent is
	 * being handed. A value with no type element around it is the implicit
	 * string form, which xmlrpc-c reads as <string>.
	 */
	private function typeOf($value)
	{
		foreach($value->children() as $child)
			return $child->getName();
		return 'string';
	}

	/**
	 * The argument values a decided payload ends up putting in front of
	 * $method, read the same way out of a top-level call and out of a
	 * system.multicall member so the two can be compared at all. Null when the
	 * payload does not carry that call, which is what a refusal looks like.
	 */
	/** One argument as both halves of what rtorrent is handed. */
	private function argument($value)
	{
		return array('type' => $this->typeOf($value), 'value' => $this->text($value));
	}

	private function arguments($payload, $method)
	{
		if(!is_string($payload) || ($payload === ''))
			return null;
		$xml = @simplexml_load_string($payload);
		if($xml === false)
			return null;

		if((string)$xml->methodName === $method)
		{
			$values = array();
			if(isset($xml->params->param))
				foreach($xml->params->param as $param)
					$values[] = $this->argument($param->value);
			return $values;
		}

		if(!isset($xml->params->param->value->array->data->value))
			return null;

		foreach($xml->params->param->value->array->data->value as $member)
		{
			if(!isset($member->struct->member))
				continue;
			$name = null;
			$values = null;
			foreach($member->struct->member as $field)
			{
				if(!isset($field->name))
					continue;
				if((string)$field->name === 'methodName')
					$name = isset($field->value->string)
						? (string)$field->value->string
						: trim((string)$field->value);
				else
				// A member with no arguments carries an empty <data/>, which is
				// zero arguments and not an unreadable member.
				if(((string)$field->name === 'params') &&
					isset($field->value->array->data))
				{
					$values = array();
					if(isset($field->value->array->data->value))
						foreach($field->value->array->data->value as $value)
							$values[] = $this->argument($value);
				}
			}
			if(($name === $method) && ($values !== null))
				return $values;
		}
		return null;
	}

	/* ---------------------------------------------------------------- *
	 * The comparison itself
	 * ---------------------------------------------------------------- */

	private function start()
	{
		$this->bad = array();
		$this->cases = 0;
	}

	/**
	 * One cell of the matrix: the same call decided both ways, checked against
	 * all four relations.
	 */
	private function compare($method, $params, $allowLocalPaths, $label)
	{
		$this->cases++;

		$alone = $this->decide($this->single($method, $params), $allowLocalPaths);
		$batch = $this->decide($this->batched($method, $params), $allowLocalPaths);

		$where = $method . ' ' . $label
			. ($allowLocalPaths ? ' [local paths allowed]' : ' [local paths denied]');

		if(($alone['action'] === 'reject') && ($batch['action'] !== 'reject'))
		{
			$this->bad[] = 'R1 ' . $where . ': alone=reject batched=' . $batch['action'];
			return;
		}

		if($alone['action'] !== $batch['action'])
		{
			$this->bad[] = 'R5 ' . $where . ': alone=' . $alone['action']
				. ' batched=' . $batch['action'];
			return;
		}

		if($batch['trusted'] && !$alone['trusted'])
			$this->bad[] = 'R3 ' . $where . ': batched is trusted where alone is not';

		if(($alone['action'] !== 'send') || ($batch['action'] !== 'send'))
			return;

		$sent = $this->arguments($alone['payload'], $method);
		$carried = $this->arguments($batch['payload'], $method);

		if(($sent === null) || ($carried === null))
		{
			$this->bad[] = 'R2 ' . $where . ': the call is not readable back out of '
				. (($sent === null) ? 'the single' : 'the batched') . ' payload';
			return;
		}

		foreach($sent as $index => $value)
		{
			if(!array_key_exists($index, $carried))
			{
				$this->bad[] = 'R4 ' . $where . ': parameter ' . $index
					. ' is kept alone and dropped batched';
				continue;
			}
			if($carried[$index] !== $value)
				$this->bad[] = 'R2 ' . $where . ': parameter ' . $index
					. ' is ' . $this->showArg($value) . ' alone and '
					. $this->showArg($carried[$index]) . ' batched';
		}
	}

	private function showArg($argument)
	{
		return $argument['type'] . ' ' . $this->show($argument['value']);
	}

	private function show($value)
	{
		$value = str_replace(array("\r", "\n"), ' ', $value);
		if(strlen($value) > 60)
			$value = substr($value, 0, 60) . '...';
		return '"' . $value . '"';
	}

	private function verdict($family)
	{
		$message = $family . ': ' . $this->cases . ' cases, '
			. count($this->bad) . ' divergences';
		if(count($this->bad) > 0)
			$message .= "\n  " . implode("\n  ", array_slice($this->bad, 0, 12))
				. ((count($this->bad) > 12)
					? "\n  ... and " . (count($this->bad) - 12) . ' more' : '');
		$this->assertTrue(count($this->bad) === 0, $message);
	}

	/* ---------------------------------------------------------------- *
	 * The corpora
	 * ---------------------------------------------------------------- */

	/**
	 * What a load.* parameter 1 can be. The network forms are the four
	 * $networkUri accepts; everything else rtorrent opens as a path on its own
	 * filesystem, which is what $allowLocalPaths decides about.
	 */
	private function uris()
	{
		return array(
			'network http'      => 'http://example.invalid/a.torrent',
			'network https'     => 'https://example.invalid/a.torrent',
			'network ftp'       => 'ftp://example.invalid/a.torrent',
			'network magnet'    => 'magnet:?xt=urn:btih:' . str_repeat('a', 40),
			'absolute path'     => '/etc/probe-parity.torrent',
			'relative path'     => 'probe-parity.torrent',
			'dotted path'       => './probe-parity.torrent',
			'parent path'       => '../probe-parity.torrent',
			'session path'      => '/var/lib/rtorrent/session/probe-parity.torrent',
			'double slash'      => '//example.invalid/a.torrent',
			'file scheme'       => 'file:///etc/probe-parity.torrent',
			'uppercase scheme'  => 'HTTP://example.invalid/a.torrent',
			'unknown scheme'    => 'gopher://example.invalid/a.torrent',
			'empty'             => '',
		);
	}

	private function torrent()
	{
		return 'd8:announce20:http://tr.invalid/a4:infod6:lengthi12e4:name8:file.txt'
			. '12:piece lengthi16384e6:pieces20:' . str_repeat("\x01", 20) . 'ee';
	}

	/** A value each shape accepts, and values it must not. */
	private function shapeValues($shape, $sizeLimitMax)
	{
		switch($shape)
		{
			case 'hash':
				return array(
					'good' => array('lowercase hash' => $this->hash,
						'uppercase hash' => strtoupper($this->hash)),
					'bad'  => array('short hash' => substr($this->hash, 1),
						'long hash' => $this->hash . '0',
						'non hex hash' => str_repeat('g', 40),
						'empty hash' => '',
						'hash with a command' => $this->hash . ';execute=/bin/id'));

			case 'file_target':
				return array('good' => array('file zero' => $this->hash . ':f0',
					'file ten' => strtoupper($this->hash) . ':f10'),
					'bad' => array('bare hash' => $this->hash,
						'leading zero' => $this->hash . ':f01',
						'negative file' => $this->hash . ':f-1'));

			case 'scheduler_throttle':
				return array('good' => array('none' => '', 'null' => 'NULL'),
					'bad' => array('named' => 'fast'));

			case 'scheduler_key':
				return array('good' => array('ignore key' => 'sch_ignore'),
					'bad' => array('other key' => 'chk-state'));

			case 'scheduler_state':
				return array('good' => array('cleared' => '', 'set' => '1'),
					'bad' => array('other state' => '2'));

			case 'ratio_view':
				return array('good' => array('view zero' => 'rat_0',
					'view one' => 'rat_1'),
					'bad' => array('other view' => 'main',
						'leading zero' => 'rat_01',
						'too long' => 'rat_1000000'));

			case 'empty':
				return array(
					'good' => array('empty target' => ''),
					'bad'  => array('named target' => 'main',
						'space target' => ' '));

			// What an integer may be written as is xmlrpc-c's question, not this
			// side's: a spelling the daemon reads as a number and this side does
			// not is a number that arrives with nothing applied to it. Measured
			// against 1.59.03 -- a sign and leading zeros are part of the
			// spelling, the number of digits is not a limit of its own, and
			// space around it is refused.
			case 'int':
				return array(
					'good' => array('zero' => '0', 'small' => '3',
						'negative' => '-1',
						'eighteen digits' => str_repeat('9', 18)),
					'bad'  => array('signed positive' => '+3',
						'leading zeros' => '0003',
						'int64 max' => '9223372036854775807',
						'int64 min' => '-9223372036854775808',
						'above int64' => '9223372036854775808',
						'below int64' => '-9223372036854775809',
						'nineteen nines' => str_repeat('9', 19),
						'fractional' => '1.5', 'alphabetic' => 'high',
						'empty int' => '', 'hex' => '0x3',
						'leading space' => ' 3', 'trailing space' => '3 ',
						'sign alone' => '+', 'two signs' => '++3'));

			case 'size':
				return array(
					'good' => array('one' => '1',
						'under the ceiling' => (string)($sizeLimitMax - 1),
						'at the ceiling' => (string)$sizeLimitMax,
						'over the ceiling' => (string)($sizeLimitMax + 1),
						'far over the ceiling' => '99999999999',
						'eighteen digits' => str_repeat('9', 18)),
					'bad'  => array('signed far over ceiling' => '+99999999999',
						'zero padded' => '0000000000000016777217',
						'int64 max' => '9223372036854775807',
						'zero' => '0', 'negative' => '-1',
						'signed zero' => '+0', 'alphabetic' => 'lots',
						'empty size' => '', 'leading space' => ' 1024',
						'above int64' => '9223372036854775808',
						'nineteen nines' => str_repeat('9', 19)));

			case 'text':
				return array(
					'good' => array('plain' => 'a label',
						'punctuated' => 'Movies (2024), imported',
						'quoted' => 'say "hi" now',
						'dollar' => '$execute.capture=/bin/hostname',
						'separators' => 'x;execute=/bin/id',
						'empty text' => ''),
					'bad'  => array());
		}
		return array('good' => array(), 'bad' => array());
	}

	/* ---------------------------------------------------------------- *
	 * The matrix
	 * ---------------------------------------------------------------- */

	/**
	 * Reading the lists off the class covers a method added later only as far
	 * as this file knows what its arguments look like. A shape with no corpus
	 * would be walked with nothing to vary and would pass by testing nothing,
	 * which is the failure this file exists to prevent, so adding one to
	 * $elevate fails here until shapeValues() states what it accepts.
	 *
	 * The subset is the other half of the same invariant: the single-call path
	 * applies the URI rule inside its $sanitizeMethods branch, so an alias
	 * listed as carrying a URI and not as a load would be judged on it in a
	 * batch and not alone -- an asymmetry the other four relations, which only
	 * ever require a batch to be no weaker, would let through.
	 */
	public function testTheListsThisMatrixIsDrivenFromAreUsable()
	{
		$this->start();

		$sizeLimitMax = $this->property('sizeLimitMax');
		$seen = array();
		foreach($this->property('elevate') as $method => $shapes)
			foreach($shapes as $shape)
			{
				if(isset($seen[$shape]))
					continue;
				$seen[$shape] = true;
				$this->cases++;
				$values = $this->shapeValues($shape, $sizeLimitMax);
				if(count($values['good']) === 0)
					$this->bad[] = 'the shape "' . $shape
						. '" has no accepted value in shapeValues(), so every'
						. ' signature that uses it is walked without being tested';
			}

		$sanitizeMethods = $this->property('sanitizeMethods');
		$rawMethods = $this->property('rawLoadMethods');
		$uriMethods = array_diff($sanitizeMethods, $rawMethods);
		$this->cases++;
		if(count($uriMethods) === 0 || count($rawMethods) === 0
			|| count(array_diff($rawMethods, $sanitizeMethods)) > 0
			|| count(array_unique($sanitizeMethods)) !== count($sanitizeMethods))
			$this->bad[] = 'raw and URI load families must be distinct, nonempty '
				. 'and covered once by $sanitizeMethods';

		$this->verdict('the lists this matrix is driven from');
	}

	/**
	 * Every load alias that carries a URI at parameter 1, with local paths
	 * both denied and allowed.
	 */
	public function testEveryUriLoadAliasIsJudgedTheSameBatched()
	{
		$this->start();
		foreach(array_values(array_diff($this->property('sanitizeMethods'),
			$this->property('rawLoadMethods'))) as $method)
			foreach(array(false, true) as $allowLocalPaths)
				foreach($this->uris() as $label => $uri)
					foreach(array('string', 'base64') as $type)
						$this->compare($method, array('', array($uri, $type)),
							$allowLocalPaths, $label . ' as ' . $type);
		$this->verdict('every URI load alias, both local-path settings');
	}

	/**
	 * An implicit string carries its whitespace. rtorrent compares the URI
	 * prefix from byte zero, so leading whitespace on a network-looking value
	 * makes it a local path; trimming it only for the policy check would allow
	 * that path through.
	 */
	public function testWhitespaceCannotMakeAnImplicitLocalPathLookLikeANetworkUri()
	{
		foreach(array_values(array_diff($this->property('sanitizeMethods'),
			$this->property('rawLoadMethods'))) as $method)
			foreach(array('alone' => $this->single($method,
					array('', array(' http://example.invalid/a.torrent', 'implicit'))),
				'batched' => $this->batched($method,
					array('', array(' http://example.invalid/a.torrent', 'implicit'))))
				as $shape => $xml)
			{
				$decision = $this->decide($xml, false);
				$this->assertTrue($decision['action'] === 'reject', $shape . ' '
					. $method . ' refuses a network-looking local path with leading whitespace');
			}
	}

	/**
	 * Vary the command tail independently of the URI decision.
	 */
	public function testAUriLoadCarryingACommandIsJudgedTheSameBatched()
	{
		$this->start();
		$commands = array('an allowed label' => 'd.custom1.set=tv',
			'an allowed directory' => 'd.directory.set=/torrents/tv',
			'a command not in the list' => 'd.peers_max.set=1');
		foreach(array_values(array_diff($this->property('sanitizeMethods'),
			$this->property('rawLoadMethods'))) as $method)
			foreach(array(false, true) as $allowLocalPaths)
				foreach(array('http://example.invalid/a.torrent',
					'/etc/probe-parity.torrent') as $uri)
					foreach($commands as $label => $command)
						$this->compare($method, array('', $uri, $command),
							$allowLocalPaths, $label . ' behind ' . $uri);
		$this->verdict('URI loads carrying a command parameter');
	}

	/**
	 * Raw loads carry torrent bytes at parameter 1. They must not be
	 * mistaken for URI loads when the same method appears in a batch.
	 */
	public function testTheRestOfSanitizeMethodsIsJudgedTheSameBatched()
	{
		$this->start();
		$uriMethods = array_values(array_diff($this->property('sanitizeMethods'),
			$this->property('rawLoadMethods')));
		foreach($this->property('sanitizeMethods') as $method)
		{
			if(in_array($method, $uriMethods, true))
				continue;
			foreach(array(false, true) as $allowLocalPaths)
			{
				$this->compare($method,
					array('', array($this->torrent(), 'base64'), 'd.custom1.set=tv'),
					$allowLocalPaths, 'a torrent by value');
				$this->compare($method, array('', '/etc/probe-parity.torrent'),
					$allowLocalPaths, 'a local path at parameter 1');
				$this->compare($method, array('', 'http://example.invalid/a.torrent'),
					$allowLocalPaths, 'a URI at parameter 1');
			}
		}
		$this->verdict('$sanitizeMethods raw load family');
	}

	/**
	 * Every multicall family, whose commands the member loop does read, as the
	 * control that says the two paths agreed here already.
	 */
	public function testEveryMulticallFamilyIsJudgedTheSameBatched()
	{
		$this->start();
		$commands = array('an allowed label' => 'd.custom1.set=tv',
			'a read command' => 'd.name=',
			'an allowed directory' => 'd.directory.set=/torrents/tv');
		foreach($this->property('multicallMethods') as $method)
			foreach(array(false, true) as $allowLocalPaths)
				foreach($commands as $label => $command)
					$this->compare($method, array($this->hash, 'main', $command),
						$allowLocalPaths, $label);
		$readers = array('d.multicall' => 'd.name=',
			'd.multicall2' => 'd.name=', 'd.multicall.filtered' => 'd.name=',
			't.multicall' => 't.url=', 'f.multicall' => 'f.path=',
			'p.multicall' => 'p.address=');
		$this->assertEquals(array_keys($readers), $this->property('multicallMethods'),
			'every multicall family has a positive read control');
		foreach($readers as $method => $reader)
		{
			$params = ($method === 'd.multicall.filtered')
				? array($this->hash, 'main', 'd.custom1.set=ok', $reader)
				: array($this->hash, 'main', $reader);
			$decision = $this->decide($this->single($method, $params), false);
			$this->assertTrue($decision['action'] === 'send' && $decision['trusted'],
				$method . ' has a working allowed result expression');
			$this->compare($method, $params, false, 'allowed result expression');
		}
		$this->verdict('$multicallMethods');
	}

	/**
	 * Every elevate signature, argument by argument: valid and invalid
	 * values, arity and XMLRPC scalar types. Both paths must agree.
	 */
	public function testEveryElevateSignatureIsJudgedTheSameBatched()
	{
		$this->start();
		$sizeLimitMax = $this->property('sizeLimitMax');

		foreach($this->property('elevate') as $method => $shapes)
		{
			$good = array();
			foreach($shapes as $shape)
			{
				$values = $this->shapeValues($shape, $sizeLimitMax);
				$good[] = reset($values['good']);
			}

			$baseline = $this->decide($this->single($method, $good), false);
			$native = $baseline['action'] === 'send' && $baseline['trusted'];
			// Every accepted value of every argument, one argument varying at
			// a time, so a signature of three shapes does not need the product
			// of its corpora to have each of them read.
			foreach($shapes as $index => $shape)
			{
				$values = $this->shapeValues($shape, $sizeLimitMax);
				foreach($values['good'] as $label => $value)
				{
					$params = $good;
					$params[$index] = $value;
					$this->compare($method, $params, false,
						'argument ' . $index . ' ' . $label);
					if($native)
					{
						$decision = $this->decide($this->single($method, $params), false);
						if($decision['action'] !== 'send' || !$decision['trusted'])
							$this->bad[] = $method . ' valid ' . $shape . ' ' . $label
								. ' is not sent trusted';
					}
				}
				foreach($values['bad'] as $label => $value)
				{
					$params = $good;
					$params[$index] = $value;
					$this->compare($method, $params, false,
						'argument ' . $index . ' ' . $label);
					if($native)
					{
						$decision = $this->decide($this->single($method, $params), false);
						if($decision['action'] === 'send' && $decision['trusted'])
							$this->bad[] = $method . ' invalid ' . $shape . ' ' . $label
								. ' was sent trusted';
					}
				}
			}

			// Wrong arity, both directions, and every argument sent as base64
			// rather than as a string.
			$this->compare($method, array_slice($good, 0, count($good) - 1), false,
				'one argument too few');
			$this->compare($method, array_merge($good, array('extra')), false,
				'one argument too many');
			$this->compare($method, array(), false, 'no arguments at all');

			$encoded = array();
			foreach($good as $value)
				$encoded[] = array($value, 'base64');
			$this->compare($method, $encoded, false, 'every argument as base64');

			// A number written as the XMLRPC number it is, which is what an
			// ordinary client sends and what rtorrent takes as a value object.
			// SimpleXML reads no text directly inside <value> when a type
			// element is in the way, so a reader that casts the <value> sees
			// every one of these as empty and validates none of them.
			foreach(array('i8', 'int', 'i4') as $type)
			{
				$typed = array();
				$numeric = false;
				foreach($shapes as $index => $shape)
				{
					if(($shape === 'int') || ($shape === 'size'))
					{
						$typed[$index] = array($good[$index], $type);
						$numeric = true;
					}
					else
						$typed[$index] = $good[$index];
				}
				if($numeric)
					$this->compare($method, $typed, false,
						'every number as <' . $type . '>');
			}

			// The implicit string form, <value>text</value>, with no type
			// element around it at all.
			$implicit = array();
			foreach($good as $value)
				$implicit[] = array($value, 'implicit');
			$this->compare($method, $implicit, false, 'every argument implicitly typed');
		}
		$this->verdict('every $elevate signature');
	}

	/**
	 * A trusted call may not be combined with an untrusted reader in one
	 * system.multicall connection.
	 */
	public function testAnElevatedMemberCannotShareTrustWithAnUntrustedMember()
	{
		$this->start();
		$sizeLimitMax = $this->property('sizeLimitMax');
		foreach($this->property('elevate') as $method => $shapes)
		{
			$good = array();
			foreach($shapes as $shape)
			{
				$values = $this->shapeValues($shape, $sizeLimitMax);
				$good[] = reset($values['good']);
			}
			$alone = $this->decide($this->single($method, $good), false);
			if($alone['action'] !== 'send' || !$alone['trusted'])
				continue; // This spelling is not native on the selected daemon.
			$this->cases++;
			$batch = $this->decide($this->batch(array(
				$this->member($method, $good),
				$this->member('d.name', array($this->hash)))), false);
			if($batch['action'] !== 'reject' || $batch['payload'] !== '')
				$this->bad[] = $method . ' can share a trusted batch with d.name';
		}
		$this->assertTrue($this->cases > 0, 'at least one trusted elevation was tested');
		$this->verdict('trusted members mixed with an untrusted read');
	}

	/**
	 * Accepted canonical sizes are clamped to the recovery floor and memory
	 * ceiling on both paths. Noncanonical spellings are rejected locally.
	 */
	public function testTheSizeCeilingHoldsAloneAndBatched()
	{
		$this->start();
		$sizeLimitMax = $this->property('sizeLimitMax');

		// Include spellings the daemon may parse but this policy refuses.
		$asked = array(
			'1' => 1,
			(string)$sizeLimitMax => $sizeLimitMax,
			(string)($sizeLimitMax + 1) => $sizeLimitMax + 1,
			'99999999999' => 99999999999,
			'+99999999999' => 99999999999,
			'+' . $sizeLimitMax => $sizeLimitMax,
			'0000000000000016777217' => 16777217,
			'009' => 9,
			str_repeat('9', 18) => (int)str_repeat('9', 18),
			'9223372036854775807' => PHP_INT_MAX);

		foreach($this->property('elevate') as $method => $shapes)
		{
			$index = array_search('size', $shapes, true);
			if($index === false)
				continue;

			foreach($asked as $spelling => $number)
			// Whichever type the number arrives in. An over-limit size sent as
			// the plain XMLRPC integer any client writes must leave clamped
			// too, not only one spelled out as a string.
			foreach(array('string', 'i8', 'int', 'implicit') as $type)
			{
				$params = array();
				foreach($shapes as $i => $shape)
				{
					$values = $this->shapeValues($shape, $sizeLimitMax);
					$params[$i] = ($i === $index)
						? array((string)$spelling, $type) : reset($values['good']);
				}

				// Accepted sizes leave as i8 within the floor and ceiling.
				$expected = array('type' => 'i8',
					'value' => (string)max($this->property('sizeLimitMin'),
					min($number, $sizeLimitMax)));

				foreach(array('alone' => $this->single($method, $params),
					'batched' => $this->batched($method, $params)) as $shape => $xml)
				{
					$this->cases++;
					$where = $method . ' asked for ' . $spelling . ' as <' . $type
						. '> ' . $shape;

					$decision = $this->decide($xml, false);
					if(!preg_match('/^[1-9][0-9]{0,17}$/', (string)$spelling))
					{
						if($decision['action'] !== 'reject' || $decision['payload'] !== '')
							$this->bad[] = $where . ': noncanonical size was forwarded';
						continue;
					}
					$carried = $this->arguments($decision['payload'], $method);
					if($carried === null)
					{
						$this->bad[] = $where . ': not readable back';
						continue;
					}
					if($carried[$index] !== $expected)
						$this->bad[] = $where . ': carries '
							. $this->showArg($carried[$index]) . ', not i8 '
							. $expected['value'];
				}
			}
		}
		$this->verdict('the $sizeLimitMax ceiling, alone and batched');
	}

	/**
	 * A mismatched elevation is refused, except for the explicitly listed
	 * WebUI batch methods that retain their legacy untrusted fallback on the
	 * current daemon. Those methods must never gain trust on mismatch.
	 */
	public function testAnElevatedCallThatCannotBeNormalisedIsRefused()
	{
		$this->start();
		$sizeLimitMax = $this->property('sizeLimitMax');
		$batchElevations = $this->property('batchElevations');

		foreach($this->property('elevate') as $method => $shapes)
		{
			$good = array();
			foreach($shapes as $shape)
			{
				$values = $this->shapeValues($shape, $sizeLimitMax);
				$good[] = reset($values['good']);
			}

			$cases = array('one argument too few' => array_slice($good, 0, count($good) - 1),
				'one argument too many' => array_merge($good, array('extra')),
				'no arguments at all' => array());

			// base64 that is not base64. xmlrpc-c answers a fault for it, so
			// there is no value here to validate and none to send on.
			foreach($shapes as $index => $shape)
			{
				$params = $good;
				$params[$index] = array('%%%%', 'rawbase64');
				$cases['malformed base64 at argument ' . $index] = $params;

				// A value with no scalar reading, and an ambiguous value with two
				// type elements. Neither may be collapsed to an empty string or to
				// whichever child the proxy checks first and then sent as a valid,
				// trusted state-changing call.
				$params = $good;
				$params[$index] = array('<value><array><data><value><string>nested'
					. '</string></value></data></array></value>', 'raw');
				$cases['compound value at argument ' . $index] = $params;

				$params = $good;
				$params[$index] = array('<value><string>first</string>'
					. '<base64>c2Vjb25k</base64></value>', 'raw');
				$cases['two type elements at argument ' . $index] = $params;

				// An implicit string has exactly the same bytes as an explicit
				// string. Whitespace is part of those bytes; trimming it would make
				// an otherwise invalid hash, integer or empty target match.
				if($shape !== 'text')
				{
					$params = $good;
					$params[$index] = array('<value> ' . htmlspecialchars($good[$index],
						ENT_NOQUOTES, 'UTF-8') . ' </value>', 'raw');
					$cases['whitespace around implicit string at argument ' . $index] = $params;
				}

				// xmlrpc-c refuses whitespace in an integer element. The lexical
				// check in integerValue() must see it rather than a trimmed copy.
				if(($shape === 'int') || ($shape === 'size'))
				{
					$params = $good;
					$params[$index] = array(' ' . $good[$index], 'i8');
					$cases['leading whitespace in typed integer at argument ' . $index] = $params;
				}
			}

			foreach($shapes as $index => $shape)
				foreach($this->shapeValues($shape, $sizeLimitMax)['bad'] as $label => $value)
				{
					$params = $good;
					$params[$index] = $value;
					$cases['argument ' . $index . ' ' . $label] = $params;
				}

			foreach($cases as $label => $params)
				foreach(array('alone' => $this->single($method, $params),
					'batched' => $this->batched($method, $params)) as $shape => $xml)
				{
					$this->cases++;
					$decision = $this->decide($xml, false);
					if($decision['action'] !== 'reject'
						&& !(in_array($method, $batchElevations, true)
							&& $decision['action'] === 'send' && !$decision['trusted']))
						$this->bad[] = $method . ' ' . $label . ' ' . $shape . ': '
							. $decision['action'] . ', carrying '
							. $this->show((string)$decision['payload']);
				}
		}
		$this->verdict('$elevate arguments that cannot be normalised');
	}

	/** An implicit string is not a licence to trim the value it carries. */
	public function testAnImplicitTextValueKeepsItsWhitespace()
	{
		$text = "  padded label\t";
		$expected = array('type' => 'string', 'value' => $text);

		foreach(array('alone' => $this->single('d.custom1.set',
				array($this->hash, array($text, 'implicit'))),
			'batched' => $this->batched('d.custom1.set',
				array($this->hash, array($text, 'implicit')))) as $shape => $xml)
		{
			$decision = $this->decide($xml, false);
			$this->assertTrue($decision['action'] === 'send',
				$shape . ' implicit text is sent');
			$arguments = $this->arguments($decision['payload'], 'd.custom1.set');
			$this->assertTrue(isset($arguments[1]) && ($arguments[1] === $expected),
				$shape . ' implicit text keeps its leading and trailing whitespace');
		}
	}

	/**
	 * XML text that cannot be represented without byte changes is refused,
	 * including when a client wrapped it in base64.
	 */
	public function testValuesXMLCannotRepresentAreRefused()
	{
		foreach(array("\xC3\x28", "a\x01b", "a\r\nb") as $raw)
		{
			foreach(array('alone' => $this->single('d.custom1.set',
				array($this->hash, array($raw, 'base64'))),
				'batched' => $this->batched('d.custom1.set',
				array($this->hash, array($raw, 'base64')))) as $shape => $xml)
			{
				$decision = $this->decide($xml, false);
				$this->assertTrue($decision['action'] === 'reject'
					&& $decision['payload'] === '',
					$shape . ' invalid XML text is refused before transport');
			}
		}
	}

	/**
	 * A member is a struct, so methodName and params are each one field. A
	 * member naming either of them twice is read by two readers here -- the
	 * policy above and the rebuild below -- and they need not land on the
	 * same field, which is a way to show a policy one URI and rtorrent
	 * another. The matrix cannot generate this: both shapes it builds name
	 * each field once.
	 */
	public function testAMemberNamingAStandardFieldTwiceIsRefused()
	{
		$params = function($uri) {
			return '<member><name>params</name><value><array><data>'
				. '<value><string></string></value>'
				. '<value><string>' . $uri . '</string></value>'
				. '</data></array></value></member>';
		};
		$name = '<member><name>methodName</name><value><string>load.start'
			. '</string></value></member>';

		$once = $this->decide($this->batch(array('<value><struct>' . $name
			. $params('/etc/probe.torrent') . '</struct></value>')), false);
		$this->assertEquals('reject', $once['action'],
			'a local path in the one params field a member has is refused');

		$twice = $this->decide($this->batch(array('<value><struct>' . $name
			. $params('http://example.invalid/safe.torrent')
			. $params('/etc/probe.torrent') . '</struct></value>')), false);
		$this->assertEquals('reject', $twice['action'],
			'a local path in a second params field is refused, not hidden '
			. 'behind the network URI in the first');

		$names = $this->decide($this->batch(array('<value><struct>' . $name
			. '<member><name>methodName</name><value><string>d.name</string>'
			. '</value></member>' . $params('http://example.invalid/x.torrent')
			. '</struct></value>')), false);
		$this->assertEquals('reject', $names['action'],
			'a member naming methodName twice is refused rather than judged '
			. 'as one of the two names');
	}

	/**
	 * Invalid UTF-8 in a stored text argument is terminal, never silently
	 * changed to an empty value.
	 */
	public function testNonUtf8TextCannotBypassValidationAsBase64()
	{
		$raw = "\xC3\x28 not utf-8";
		foreach(array('alone' => $this->single('d.custom1.set',
			array($this->hash, array($raw, 'base64'))),
			'batched' => $this->batched('d.custom1.set',
				array($this->hash, array($raw, 'base64')))) as $shape => $xml)
		{
			$decision = $this->decide($xml, false);
			$this->assertTrue($decision['action'] === 'reject'
				&& $decision['payload'] === '',
				$shape . ' non-UTF8 text does not reach rtorrent');
		}
	}
}
