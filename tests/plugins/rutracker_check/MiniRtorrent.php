<?php

require_once(__DIR__ . '/../../php/FakeRtorrentDaemon.php');
require_once(__DIR__ . '/../../php/RtorrentCommandParser.php');

/** A refusal the daemon would send back as an XMLRPC fault. */
class MiniRtorrentFault extends Exception
{
	public $faultCode;

	public function __construct($code, $message)
	{
		parent::__construct($message);
		$this->faultCode = $code;
	}
}

/**
 * A small rTorrent, for tests whose subject is the bytes the daemon holds.
 *
 * The replacement transaction reads a value from the daemon, quotes it into a
 * load command and later reads the stored result back. Whether that round
 * trip keeps the value's bytes is decided by three parsers: php/xmlrpc.php on
 * the way in, rTorrent::quoteCommandArg() on the way out, and the daemon's own
 * command parser at the far end. A queued test double stands in for the first
 * one and cannot answer the question; this class puts the real
 * php/xmlrpc.php and the real command builders against a daemon that parses
 * what it is sent the way rtorrent does.
 *
 * It answers through FakeRtorrentDaemon's SCGI server, so every reply is real
 * XMLRPC text: '&', '<' and '>' as entities and every other byte, '"' and '\'
 * included, literally -- what 0.16 (tinyxml2) and 0.9.8 (xmlrpc-c) both send.
 *
 * Command strings -- load additions and branch conditions and bodies -- go
 * through tests/php/RtorrentCommandParser.php, the transcription of
 * src/rpc/parse.cc, and a '$' argument is run before its command the way
 * parse_command_execute() does it. The commands themselves are the subset the
 * replacement transaction and its readers use, with the semantics of
 * src/command_ui.cc (branch, and, or, not, equal, less, cat, value) and of the
 * download getters and setters. Anything else is an input_error, as an
 * unknown command is in rtorrent, so a transaction that grows a new command
 * fails here loudly instead of being answered by a guess.
 *
 * The whole state is written to a JSON file after every request, so the test
 * reads what the daemon holds rather than what the code meant to send.
 */
class MiniRtorrent
{
	public $stateFile;
	public $sandbox;
	public $legacy;
	public $session;
	public $directory;
	public $downloads = array();
	public $loads = array();
	public $executed = array();
	public $calls = array();
	public $views = array('main', 'default', 'started', 'stopped', 'seeding');
	/**
	 * Method => count: that many requests carrying the method are executed
	 * and then left unanswered, the lost reply a readback exists for.
	 */
	public $loseReplies = array();
	private $localIds = 0;

	/**
	 * $sandbox bounds `execute mkdir`: a directory outside it is refused, so a
	 * test cannot create anything outside its own temporary tree. $legacy
	 * selects 0.9.8's missing-hash fault instead of 0.16's.
	 */
	public function __construct($stateFile, $sandbox, $session, $directory, $legacy = false)
	{
		$this->stateFile = $stateFile;
		$this->sandbox = rtrim($sandbox, '/');
		$this->session = $session;
		$this->directory = $directory;
		$this->legacy = $legacy;
	}

	/** A download already in the session, as a list of field overrides. */
	public function addDownload($hash, $fields)
	{
		$hash = strtoupper($hash);
		$this->downloads[$hash] = array_merge($this->newDownload($hash), $fields);
	}

	private function newDownload($hash)
	{
		$this->localIds++;
		return array(
			'hash' => $hash,
			'name' => '',
			'directory_base' => '',
			'directory' => '',
			'custom1' => '',
			'custom2' => '',
			'customs' => array(),
			'throttle_name' => '',
			'connection_seed' => '',
			'state' => 0,
			'open' => 0,
			'is_meta' => 0,
			'local_id' => strtoupper(sha1($hash . '/' . $this->localIds)),
			'views' => array('main'),
			'tied_to_file' => '',
			'message' => '',
			'chunks_hashed' => 0,
			'completed_bytes' => 0,
			'complete' => 0,
			'trackers' => array(),
			// Relative file paths, as f.path answers them, and whether the
			// metainfo is a multi-file one (d.is_multi_file).
			'files' => array(),
			'multi' => 1,
		);
	}

	// ------------------------------------------------------------ transport

	/**
	 * One request. A member that fails answers the whole request as one
	 * fault: rtorrent would fault that member alone, and php/xmlrpc.php
	 * reads any faultCode member as a faulted request either way.
	 */
	public function __invoke($request, $methods)
	{
		$results = array();
		$lose = false;
		try {
			foreach (self::parseRequest($request) as $call) {
				$this->calls[] = $call['method'];
				if (!empty($this->loseReplies[$call['method']])) {
					$this->loseReplies[$call['method']]--;
					$lose = true;
				}
				$results[] = $this->rpc($call['method'], $call['params']);
			}
			$batch = strpos($request, '<methodName>system.multicall</methodName>') !== false;
			$body = '';
			foreach ($results as $result)
				$body .= self::xmlValue($batch ? array($result) : $result);
			$reply = $lose ? null : new FakeRtorrentRawReply('<methodResponse><params><param>'
				. ($batch ? '<value><array><data>' . $body . '</data></array></value>' : $body)
				. '</param></params></methodResponse>');
		} catch (MiniRtorrentFault $fault) {
			$reply = FakeRtorrentDaemon::fault($fault->getMessage(), $fault->faultCode);
		} catch (RtorrentInputError $error) {
			$reply = FakeRtorrentDaemon::fault($error->getMessage(), $this->legacy ? -503 : -500);
		}
		$this->save();
		return $reply;
	}

	private function save()
	{
		$state = array(
			'downloads' => $this->downloads,
			'loads' => $this->loads,
			'executed' => $this->executed,
			'calls' => $this->calls,
		);
		$tmp = $this->stateFile . '.tmp';
		file_put_contents($tmp, json_encode($state, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
		rename($tmp, $this->stateFile);
	}

	/** The calls of one php/xmlrpc.php request body, with typed parameters. */
	public static function parseRequest($body)
	{
		if (!preg_match('|<methodName>(.*?)</methodName>|s', $body, $outer))
			throw new MiniRtorrentFault(-32600, 'no methodName');
		if ($outer[1] !== 'system.multicall') {
			$params = preg_match('|<params>(.*)</params>|s', $body, $match) ? $match[1] : '';
			return array(array('method' => $outer[1], 'params' => self::parseValues($params)));
		}
		$calls = array();
		preg_match_all('|<struct><member><name>methodName</name><value><string>(.*?)</string></value></member>'
			. '<member><name>params</name><value><array><data>(.*?)</data></array></value></member></struct>|s',
			$body, $members, PREG_SET_ORDER);
		foreach ($members as $member)
			$calls[] = array('method' => $member[1], 'params' => self::parseValues($member[2]));
		return $calls;
	}

	private static function parseValues($xml)
	{
		preg_match_all('#<value><(string|i4|i8|base64)>(.*?)</\1></value>#s', $xml, $matches, PREG_SET_ORDER);
		$values = array();
		foreach ($matches as $match) {
			if ($match[1] === 'i4' || $match[1] === 'i8')
				$values[] = (int) $match[2];
			elseif ($match[1] === 'base64')
				$values[] = array('base64' => base64_decode($match[2]));
			else
				$values[] = htmlspecialchars_decode($match[2], ENT_NOQUOTES);
		}
		return $values;
	}

	// Preserve command wrappers and table rows, as the real XMLRPC grammar does.
	// XML text stays byte-wise: quotes and backslashes are ordinary string bytes.
	private static function xmlValue($value)
	{
		if (is_array($value)) {
			$body = '';
			foreach ($value as $item)
				$body .= self::xmlValue($item);
			return '<value><array><data>' . $body . '</data></array></value>';
		}
		if ($value === null || is_int($value))
			return '<value><i8>' . (int) $value . '</i8></value>';
		return '<value><string>' . strtr((string) $value,
			array('&' => '&amp;', '<' => '&lt;', '>' => '&gt;')) . '</string></value>';
	}

	// --------------------------------------------------------------- RPC

	private function missing()
	{
		return $this->legacy
			? new MiniRtorrentFault(-501, 'Could not find info-hash.')
			: new MiniRtorrentFault(-500, 'invalid parameters: info-hash not found');
	}

	private function target($hash)
	{
		$hash = strtoupper((string) $hash);
		if (!isset($this->downloads[$hash]))
			throw $this->missing();
		return $hash;
	}

	private function rpc($method, $params)
	{
		switch ($method) {
		case 'execute': case 'execute2': case 'execute.throw': case 'execute.nothrow':
			return $this->execute($params);
		case 'load_raw': case 'load.raw': case 'load_raw_start': case 'load.raw_start':
			return $this->loadRaw($params, strpos($method, 'start') !== false);
		case 'get_session': case 'session.path':
			return $this->session;
		case 'get_directory': case 'directory.default':
			return $this->directory;
		case 'view_list': case 'view.list':
			return $this->views;
		case 'd.multicall': case 'd.multicall2':
			return $this->downloadMulticall($params);
		case 't.multicall':
		case 'f.multicall':
			$hash = $this->target(array_shift($params));
			array_shift($params);
			return $this->subMulticall($hash, $method[0], $params);
		}
		if (strpos($method, 'd.') === 0 || $method === 'branch' || $method === 'view.set_visible') {
			$hash = $this->target(array_shift($params));
			return $this->call($method, $params, $hash);
		}
		throw new MiniRtorrentFault(-506, 'Method \'' . $method . '\' not defined');
	}

	private function execute($params)
	{
		$this->executed[] = $params;
		if (count($params) === 3 && $params[0] === 'mkdir' && $params[1] === '-p') {
			$dir = (string) $params[2];
			if ($dir === '' || $dir[0] !== '/' || strpos($dir . '/', $this->sandbox . '/') !== 0)
				throw new MiniRtorrentFault(-503, 'execute outside the test sandbox');
			if (!is_dir($dir) && !@mkdir($dir, 0777, true))
				throw new MiniRtorrentFault(-503, 'mkdir failed');
			return 0;
		}
		throw new MiniRtorrentFault(-503, 'execute is limited to mkdir -p here');
	}

	/**
	 * load_raw: the metainfo, then each parameter run as commands against the
	 * new download. The first input_error ends the list, as DownloadFactory's
	 * one try block around it does.
	 */
	private function loadRaw($params, $start)
	{
		if (isset($params[0]) && $params[0] === '')
			array_shift($params);
		$raw = array_shift($params);
		if (!is_array($raw) || !isset($raw['base64']))
			throw new MiniRtorrentFault(-503, 'load_raw needs base64 metainfo');
		$metainfo = MiniBencode::decode($raw['base64']);
		$hash = strtoupper(sha1($metainfo['info_bytes']));
		$info = $metainfo['value']['info'];
		$download = $this->newDownload($hash);
		$download['name'] = (string) $info['name'];
		$download['multi'] = isset($info['files']) ? 1 : 0;
		if (isset($info['files'])) {
			foreach ($info['files'] as $file)
				$download['files'][] = implode('/', $file['path']);
		} else {
			$download['files'][] = (string) $info['name'];
		}
		$download['directory_base'] = rtrim($this->directory, '/')
			. (isset($info['files']) ? '/' . $info['name'] : '');
		$download['directory'] = $download['directory_base'];
		$this->downloads[$hash] = $download;
		// rtorrent keeps its own copy of every loaded metainfo in the session
		// directory, which is where rTorrent::getSource() reads it back.
		if ($this->session !== '' && is_dir($this->session))
			file_put_contents(rtrim($this->session, '/') . '/' . $hash . '.torrent', $raw['base64']);
		$record = array('hash' => $hash, 'params' => $params, 'aborted' => null);
		foreach ($params as $index => $command) {
			try {
				$this->exec((string) $command, $hash);
			} catch (RtorrentInputError $error) {
				$record['aborted'] = array('index' => $index, 'error' => $error->getMessage());
				break;
			} catch (MiniRtorrentFault $fault) {
				$record['aborted'] = array('index' => $index, 'error' => $fault->getMessage());
				break;
			}
		}
		$this->loads[] = $record;
		if ($start) {
			$this->downloads[$hash]['state'] = 1;
			$this->downloads[$hash]['open'] = 1;
		}
		return 0;
	}

	private function downloadMulticall($params)
	{
		$view = (string) array_shift($params);
		$values = array();
		foreach ($this->downloads as $hash => $download) {
			if ($view !== '' && $view !== 'main' && !in_array($view, $download['views'], true))
				continue;
			$row = array();
			foreach ($params as $command)
				$row[] = $this->exec((string) $command, $hash);
			$values[] = $row;
		}
		return $values;
	}

	/** t.multicall and f.multicall: each command against each tracker or file. */
	private function subMulticall($hash, $kind, $commands)
	{
		$values = array();
		$rows = $kind === 't' ? $this->downloads[$hash]['trackers'] : $this->downloads[$hash]['files'];
		foreach (array_keys($rows) as $index) {
			$row = array();
			foreach ($commands as $command)
				$row[] = $this->exec((string) $command, $hash, array($kind, $index));
			$values[] = $row;
		}
		return $values;
	}

	// ------------------------------------------------------- command strings

	/** Run a command string the way parse_command() does; the last result. */
	public function exec($string, $hash, $sub = null)
	{
		$result = null;
		foreach (RtorrentCommandParser::commands($string) as $command) {
			$args = array();
			foreach ($command['args'] as $arg) {
				if (!is_string($arg))
					throw new RtorrentInputError('lists and calls are not modelled');
				// parse_command_execute(): a '$' string is replaced by the
				// result of running it, after the quotes came off.
				$args[] = (isset($arg[0]) && $arg[0] === '$') ? $this->exec(substr($arg, 1), $hash, $sub) : $arg;
			}
			$result = $this->call($command['name'], $args, $hash, $sub);
		}
		return $result;
	}

	private static function truth($value)
	{
		if (is_int($value)) return $value !== 0;
		if (is_string($value)) return $value !== '';
		if (is_array($value)) return count($value) && self::truth(reset($value));
		return false;
	}

	private static function text($value)
	{
		if (is_array($value)) {
			$out = '';
			foreach ($value as $item) $out .= self::text($item);
			return $out;
		}
		return $value === null ? '' : (string) $value;
	}

	private static function one($args)
	{
		return count($args) ? $args[0] : '';
	}

	private function compare($args, $hash, $sub)
	{
		if (!count($args))
			throw new RtorrentInputError('Wrong argument count.');
		$left = $this->exec((string) $args[0], $hash, $sub);
		$right = $this->exec((string) $args[count($args) - 1], $hash, $sub);
		if (gettype($left) !== gettype($right))
			throw new RtorrentInputError('Type mismatch.');
		return is_int($left) ? $left - $right : strcmp($left, $right);
	}

	private function call($name, $args, $hash, $sub = null)
	{
		switch ($name) {
		case 'branch':
			$condition = $this->exec((string) self::one($args), $hash, $sub);
			$arm = self::truth($condition) ? 1 : 2;
			return isset($args[$arm]) ? $this->exec((string) $args[$arm], $hash, $sub) : null;
		case 'and':
			foreach ($args as $arg)
				if (!self::truth($this->exec((string) $arg, $hash, $sub))) return 0;
			return 1;
		case 'or':
			foreach ($args as $arg)
				if (self::truth($this->exec((string) $arg, $hash, $sub))) return 1;
			return 0;
		case 'not':
			// apply_not() reads its argument as a value, not as a command;
			// a '$' argument has already been run above.
			return self::truth(self::one($args)) ? 0 : 1;
		case 'equal':
			return $this->compare($args, $hash, $sub) === 0 ? 1 : 0;
		case 'less':
			return $this->compare($args, $hash, $sub) < 0 ? 1 : 0;
		case 'cat':
			return self::text($args);
		case 'value':
			return (int) self::one($args);
		case 'view.set_visible':
		case 'd.views.push_back_unique':
			$view = (string) self::one($args);
			if ($name === 'view.set_visible' && !in_array($view, $this->views, true))
				throw new RtorrentInputError('Could not find view: ' . $view);
			if (!in_array($view, $this->downloads[$hash]['views'], true))
				$this->downloads[$hash]['views'][] = $view;
			return null;
		}
		if ($sub !== null && $sub[0] === 't' && strpos($name, 't.') === 0) {
			$row = $this->downloads[$hash]['trackers'][$sub[1]];
			switch ($name) {
			case 't.url': case 't.get_url': return (string) $row['url'];
			case 't.is_enabled': return (int) $row['enabled'];
			case 't.failed_counter': return (int) $row['failed'];
			case 't.success_counter': return (int) $row['success'];
			}
		}
		if ($sub !== null && $sub[0] === 'f' && strpos($name, 'f.') === 0) {
			$download = $this->downloads[$hash];
			$path = $download['files'][$sub[1]];
			switch ($name) {
			case 'f.path': case 'f.get_path': return $path;
			// Filled in only while the download is open, as rtorrent does.
			case 'f.frozen_path': case 'f.get_frozen_path':
				return $download['open'] ? rtrim($download['directory'], '/') . '/' . $path : '';
			}
		}
		if (strpos($name, 'd.') !== 0)
			throw new RtorrentInputError('Command "' . $name . '" does not exist.');
		if ($hash === null || !isset($this->downloads[$hash]))
			throw $this->missing();
		$download =& $this->downloads[$hash];
		$value = self::one($args);
		switch ($name) {
		case 'd.hash': case 'd.get_hash': return $hash;
		case 'd.name': case 'd.get_name': return $download['name'];
		case 'd.directory_base': case 'd.get_directory_base': return $download['directory_base'];
		case 'd.directory': case 'd.get_directory': return $download['directory'];
		case 'd.custom1': case 'd.get_custom1': return $download['custom1'];
		case 'd.custom2': case 'd.get_custom2': return $download['custom2'];
		case 'd.throttle_name': case 'd.get_throttle_name': return $download['throttle_name'];
		case 'd.connection_seed': case 'd.get_connection_seed': return $download['connection_seed'];
		case 'd.state': case 'd.get_state': return (int) $download['state'];
		case 'd.is_open': return (int) $download['open'];
		case 'd.is_meta': return (int) $download['is_meta'];
		case 'd.local_id': case 'd.get_local_id': return $download['local_id'];
		case 'd.tied_to_file': case 'd.get_tied_to_file': return $download['tied_to_file'];
		case 'd.message': case 'd.get_message': return $download['message'];
		case 'd.views': return $download['views'];
		case 'd.chunks_hashed': case 'd.get_chunks_hashed': return (int) $download['chunks_hashed'];
		case 'd.completed_bytes': case 'd.get_completed_bytes': return (int) $download['completed_bytes'];
		case 'd.complete': case 'd.get_complete': return (int) $download['complete'];
		case 'd.tracker_size': case 'd.get_tracker_size': return count($download['trackers']);
		case 'd.is_multi_file': return (int) $download['multi'];
		case 'd.base_path': case 'd.get_base_path':
			if (!$download['open']) return '';
			return $download['multi'] ? $download['directory_base']
				: rtrim($download['directory_base'], '/') . '/' . $download['name'];
		case 'd.custom': case 'd.get_custom':
			return isset($download['customs'][$value]) ? $download['customs'][$value] : '';
		case 'd.custom.set': case 'd.set_custom':
			if (count($args) !== 2)
				throw new RtorrentInputError('Wrong argument count.');
			$download['customs'][(string) $args[0]] = (string) $args[1];
			return null;
		case 'd.custom1.set': case 'd.set_custom1': $download['custom1'] = (string) $value; return null;
		case 'd.custom2.set': case 'd.set_custom2': $download['custom2'] = (string) $value; return null;
		case 'd.directory_base.set': case 'd.set_directory_base':
			$download['directory_base'] = $download['directory'] = (string) $value;
			return null;
		case 'd.directory.set': case 'd.set_directory':
			$download['directory'] = (string) $value;
			$download['directory_base'] = rtrim((string) $value, '/') . '/' . $download['name'];
			return null;
		case 'd.throttle_name.set': case 'd.set_throttle_name': $download['throttle_name'] = (string) $value; return null;
		case 'd.connection_seed.set': case 'd.set_connection_seed': $download['connection_seed'] = (string) $value; return null;
		case 'd.stop': $download['state'] = 0; return null;
		case 'd.close': $download['open'] = 0; return null;
		case 'd.open': $download['open'] = 1; return null;
		case 'd.start': $download['state'] = 1; $download['open'] = 1; return null;
		case 'd.erase':
			unset($download);
			unset($this->downloads[$hash]);
			return null;
		case 'd.save_resume': case 'd.save_full_session': return null;
		}
		throw new RtorrentInputError('Command "' . $name . '" does not exist.');
	}

	// -------------------------------------------------------------- process

	/**
	 * Serve this daemon from a child process. The worker loads this file as
	 * well as FakeRtorrentDaemon.php, so the plan can carry this object.
	 * Returns array('port' => int, 'process' => resource).
	 */
	public function start($logFile)
	{
		$source = 'require ' . var_export(__FILE__, true) . '; FakeRtorrentDaemon::runWorker();';
		// exec: the shell must not stay between proc_terminate() and the
		// worker, or the listener outlives the test (AGENTS.md, fixture leak).
		$command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($source);
		$process = proc_open($command, array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('file', $logFile . '.server.err', 'a'),
		), $pipes, dirname($logFile));
		if (!is_resource($process))
			throw new RuntimeException('cannot start the mini rtorrent');
		fwrite($pipes[0], serialize(array('replies' => $this, 'logFile' => $logFile)));
		fclose($pipes[0]);
		stream_set_timeout($pipes[1], 10);
		$ready = fgets($pipes[1]);
		fclose($pipes[1]);
		if (!is_string($ready) || !preg_match('/^[1-9][0-9]*\n$/D', $ready)) {
			proc_terminate($process);
			proc_close($process);
			throw new RuntimeException('the mini rtorrent did not announce a port: '
				. @file_get_contents($logFile . '.server.err'));
		}
		return array('port' => (int) $ready, 'process' => $process);
	}

	public static function stop($served)
	{
		if (!is_array($served) || !is_resource($served['process']))
			return;
		$status = proc_get_status($served['process']);
		if ($status['running'])
			proc_terminate($served['process']);
		proc_close($served['process']);
	}

	/** What the daemon held after its last request. */
	public function state()
	{
		$state = is_file($this->stateFile) ? json_decode(file_get_contents($this->stateFile), true) : null;
		if (!is_array($state))
			throw new RuntimeException('the mini rtorrent left no readable state');
		return $state;
	}
}

/** Just enough bencode to find a metainfo's info dictionary and its bytes. */
class MiniBencode
{
	public static function decode($bytes)
	{
		$offset = 0;
		$infoBytes = null;
		$value = self::value($bytes, $offset, 0, $infoBytes);
		if ($offset !== strlen($bytes) || !is_array($value) || !isset($value['info']) || $infoBytes === null)
			throw new MiniRtorrentFault(-503, 'not a metainfo');
		return array('value' => $value, 'info_bytes' => $infoBytes);
	}

	private static function value($bytes, &$offset, $depth, &$infoBytes)
	{
		$c = $bytes[$offset];
		if ($c === 'i') {
			$end = strpos($bytes, 'e', $offset);
			$value = (int) substr($bytes, $offset + 1, $end - $offset - 1);
			$offset = $end + 1;
			return $value;
		}
		if ($c === 'l' || $c === 'd') {
			$offset++;
			$out = array();
			while ($bytes[$offset] !== 'e') {
				if ($c === 'l') {
					$out[] = self::value($bytes, $offset, $depth + 1, $infoBytes);
					continue;
				}
				$key = self::value($bytes, $offset, $depth + 1, $infoBytes);
				$start = $offset;
				$out[$key] = self::value($bytes, $offset, $depth + 1, $infoBytes);
				if ($depth === 0 && $key === 'info')
					$infoBytes = substr($bytes, $start, $offset - $start);
			}
			$offset++;
			return $out;
		}
		$colon = strpos($bytes, ':', $offset);
		$length = (int) substr($bytes, $offset, $colon - $offset);
		$value = substr($bytes, $colon + 1, $length);
		$offset = $colon + 1 + $length;
		return $value;
	}
}
