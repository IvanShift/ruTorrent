<?php

// The DAEMON side of the erasedata aggregate-erase cases.
//
// erasedataEraseRequest() sends a whole batch as ONE aggregate request --
// d.set_custom5, d.delete_tied and d.erase per member, nine commands for three
// hashes -- so "the producer died between two executed erases" and "the daemon
// stopped between two executed erases" are boundaries INSIDE one request. A
// reply table keyed by request cannot express either: it answers a request that
// was never partly executed, and the mirror's `cut` fires BEFORE the request is
// written at all, so it can only ever model a client that never reached the
// daemon with it.
//
// This process is therefore a separate actor with a presence table of its own.
// It applies the commands of one request IN ORDER, one at a time, mutating that
// table as it goes, and it can be told to stop at an exact executed d.erase:
//
//   'pause-after-erase'  raise the barrier and wait to be released, so a test
//                        can kill the PRODUCER while the batch is half applied
//                        and then let this daemon finish it with no live client;
//   'stop-after-erase'   raise the barrier, close the transport and exit, so
//                        the executed prefix stands and the rest never runs.
//
// Three rules it exists to keep honest:
//
//   * every later reader of "is this download still there" asks THIS table, not
//     a list of desired answers written into an assertion. A member is absent
//     because its own d.erase was applied here, and for no other reason;
//   * a client that goes away does NOT cancel the batch. CAPTURED on
//     2026-09-05 against both daemons this fork supports: writing the exact
//     nine-command aggregate over SCGI and closing the socket without reading a
//     single byte still erased all three downloads, on rTorrent 0.9.8 and on
//     0.16.21. That is the wire fact the producer-death case rests on;
//   * a client that lost its transport is NOT told the batch succeeded. When
//     this process stops mid-batch it writes no reply of any kind. That models
//     what production would see, and the production stack agrees: rTorrent
//     dying mid-multicall closes the socket, rSCGITransport::readResponse()
//     answers null for a body that never arrived ('truncated-body' /
//     'closed-before-headers', php/scgitransport.php:200-230),
//     rXMLRPCRequest::send() turns that null into false, and run() then returns
//     false with no values at all. There is no "successful array of the first
//     replies" anywhere on that path, so this fixture must not invent one.
//
// Nothing here is reachable from production code, and this file requires no
// part of the plugin: the daemon under test must not be simulated by the code
// under test. It is started only through ErasedataProductionMirror.
//
// Usage: php AggregateEraseFixture.php <absolute path to a JSON config>

function aefFail($message)
{
	fwrite(STDERR, 'AggregateEraseFixture: '.$message."\n");
	exit(2);
}

function aefNow()
{
	return(round(microtime(true), 6));
}

// Is a process still alive? The same /proc answer production itself depends on
// for a force-2 descriptor capability (erasedataDescriptorCandidates()), so it
// adds no runtime requirement the feature under test does not already have.
// A runtime without /proc answers null -- unknown -- and never a confident
// "still running".
function aefProcessAlive($pid)
{
	if(!is_int($pid) || $pid <= 0 || !@is_dir('/proc'))
		return(null);
	clearstatcache(true, '/proc/'.$pid);
	return(@is_dir('/proc/'.$pid));
}

function aefConfig($file)
{
	if(!is_string($file) || $file === '' || $file[0] !== '/' || !is_file($file))
		aefFail('config argument must be one absolute filename');
	$raw = @file_get_contents($file);
	if(!is_string($raw))
		aefFail('config file is unreadable');
	$config = json_decode($raw, true);
	if(!is_array($config))
		aefFail('config file is not a JSON object');
	$required = array('dir' => 'string', 'hashes' => 'array',
		'aggregate' => 'array', 'idle' => 'number');
	if(count(array_diff(array_keys($config), array_keys($required)))
		|| count(array_diff(array_keys($required), array_keys($config))))
		aefFail('config keys must be exactly: '.implode(', ', array_keys($required)));
	if(!is_string($config['dir']) || $config['dir'] === '' || $config['dir'][0] !== '/'
		|| !is_dir($config['dir']))
		aefFail('config dir must be an existing absolute directory');
	if(!is_array($config['hashes']) || !count($config['hashes']))
		aefFail('config hashes must be a non-empty object');
	foreach($config['hashes'] as $hash => $entry)
	{
		// The canonical spelling erasedataCanonicalHash() produces, which is
		// what every command really carries on the wire.
		if(preg_match('/^[0-9A-F]{40}$/D', (string)$hash) !== 1)
			aefFail('config hashes must be keyed by canonical uppercase hashes');
		if(!is_array($entry) || !isset($entry['present'], $entry['base'], $entry['multi'],
			$entry['files']) || !is_bool($entry['present']) || !is_string($entry['base'])
			|| !is_int($entry['multi']) || !is_array($entry['files']))
			aefFail('config hash '.$hash.' must carry present/base/multi/files');
	}
	$aggregate = $config['aggregate'];
	$aggregateKeys = array('after_erase' => true, 'mode' => true, 'barrier' => true);
	if(count(array_diff(array_keys($aggregate), array_keys($aggregateKeys)))
		|| count(array_diff(array_keys($aggregateKeys), array_keys($aggregate))))
		aefFail('config aggregate keys must be exactly: after_erase, mode, barrier');
	if(!is_int($aggregate['after_erase']) || $aggregate['after_erase'] < 0)
		aefFail('aggregate after_erase must be a non-negative integer');
	if(!in_array($aggregate['mode'], array('none', 'pause-after-erase',
		'stop-after-erase'), true))
		aefFail('aggregate mode must be none, pause-after-erase or stop-after-erase');
	if(!is_string($aggregate['barrier']) || $aggregate['barrier'] === '')
		aefFail('aggregate barrier must be a non-empty label');
	if(!is_int($config['idle']) && !is_float($config['idle']))
		aefFail('config idle must be a number of seconds');
	return($config);
}

class ErasedataAggregateEraseDaemon
{
	private $dir;
	private $config;
	private $state;
	private $barrier;

	public function __construct(array $config)
	{
		$this->dir = $config['dir'];
		$this->config = $config;
		$this->barrier = $config['aggregate'];
	}

	// -- durable presence table ---------------------------------------------
	//
	// It is loaded from disk when it is already there, so a RESTART of this
	// daemon keeps exactly what it had executed. A restart that re-read the
	// configured presence would resurrect a download its own d.erase removed,
	// which is the one thing the recovery phase must not be handed for free.
	private function load()
	{
		$raw = @file_get_contents($this->dir.'/state.json');
		$decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
		if(is_array($decoded) && isset($decoded['hashes']) && is_array($decoded['hashes']))
		{
			$this->state = $decoded;
			return;
		}
		$this->state = array('hashes' => $this->config['hashes'],
			'applied' => 0, 'erased' => array());
	}

	private function persist()
	{
		$tmp = $this->dir.'/state.json.tmp';
		if(@file_put_contents($tmp, json_encode($this->state)) === false)
			return(false);
		return(@rename($tmp, $this->dir.'/state.json'));
	}

	private function event(array $fields)
	{
		$fields['at'] = aefNow();
		$fields['daemon_pid'] = getmypid();
		@file_put_contents($this->dir.'/events.jsonl',
			json_encode($fields)."\n", FILE_APPEND | LOCK_EX);
		return($fields);
	}

	// -- one command, applied ------------------------------------------------
	//
	// Every answer is derived from the presence table above. A command aimed at
	// a member this daemon does not hold faults with rTorrent's own wording, so
	// erasedataTorrentPresence() classifies it through the production list of
	// missing-hash faults rather than through anything this file decides.
	private function apply($command, $params)
	{
		$hash = is_array($params)
			? (array_key_exists(0, $params) ? (string)$params[0] : '')
			: (string)$params;
		$known = isset($this->state['hashes'][$hash])
			&& is_array($this->state['hashes'][$hash]);
		$present = $known && !empty($this->state['hashes'][$hash]['present']);
		$missing = array('ok' => true, 'fault' => 'invalid parameters: info-hash not found',
			'faultCode' => -500, 'values' => array(), 'hash' => $hash);
		if(!$present)
			return($missing);
		$entry = $this->state['hashes'][$hash];
		$values = array();
		switch((string)$command)
		{
			case 'd.hash':
				$values = array($hash);
				break;
			case 'd.get_base_path':
			case 'd.get_directory':
				$values = array($entry['base']);
				break;
			case 'd.is_multi_file':
				$values = array(empty($entry['multi']) ? '0' : '1');
				break;
			case 'f.multicall':
				$values = array_values($entry['files']);
				break;
			case 'd.set_custom5':
			case 'd.delete_tied':
				$values = array('');
				break;
			case 'd.erase':
				$this->state['hashes'][$hash]['present'] = false;
				$this->state['erased'][] = $hash;
				$values = array('');
				break;
			default:
				return(array('ok' => true, 'fault' => 'unsupported command',
					'faultCode' => -506, 'values' => array(), 'hash' => $hash));
		}
		return(array('ok' => true, 'fault' => null, 'faultCode' => 0,
			'values' => $values, 'hash' => $hash));
	}

	// -- the barrier ---------------------------------------------------------
	//
	// Returns false when this daemon must stop right here. The wait is bounded:
	// a barrier nobody releases records its own timeout and is a FAILURE the
	// test can see, never a quiet fall-through that looks like success.
	private function reachBarrier($request, $ordinal, $hash, $executed, $clientPid,
		$generation)
	{
		$record = $this->event(array('event' => 'barrier-reached',
			'barrier' => $this->barrier['barrier'], 'mode' => $this->barrier['mode'],
			'request' => $request, 'ordinal' => $ordinal, 'command' => 'd.erase',
			'hash' => $hash, 'erased' => $executed, 'client_pid' => $clientPid,
			'client_alive' => aefProcessAlive($clientPid), 'generation' => $generation));
		@file_put_contents($this->dir.'/barrier', json_encode($record));
		if($this->barrier['mode'] === 'stop-after-erase')
			return(false);
		$deadline = microtime(true) + 60.0;
		while(microtime(true) < $deadline)
		{
			// A paused daemon can also be told to DIE here rather than to carry
			// on, which is how a case observes the producer's held locks and
			// descriptors first and only then closes the transport under it.
			clearstatcache(true, $this->dir.'/stop');
			if(@file_exists($this->dir.'/stop'))
				return(false);
			clearstatcache(true, $this->dir.'/release');
			if(@file_exists($this->dir.'/release'))
			{
				$this->event(array('event' => 'barrier-released',
					'request' => $request, 'ordinal' => $ordinal, 'hash' => $hash,
					'erased' => $executed, 'client_pid' => $clientPid,
					'client_alive' => aefProcessAlive($clientPid),
					'generation' => $generation));
				return(true);
			}
			usleep(5000);
		}
		$this->event(array('event' => 'barrier-timeout', 'request' => $request,
			'ordinal' => $ordinal, 'hash' => $hash, 'erased' => $executed,
			'client_pid' => $clientPid, 'generation' => $generation));
		return(true);
	}

	// -- one request, applied command by command -----------------------------
	private function serve($file)
	{
		$raw = @file_get_contents($file);
		$envelope = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
		if(!is_array($envelope) || !isset($envelope['id'], $envelope['commands'])
			|| !is_array($envelope['commands']))
		{
			@rename($file, $file.'.malformed');
			return(true);
		}
		$request = (string)$envelope['id'];
		$clientPid = isset($envelope['client_pid']) ? (int)$envelope['client_pid'] : 0;
		$generation = isset($envelope['generation']) ? $envelope['generation'] : null;
		$this->event(array('event' => 'request-received', 'request' => $request,
			'commands' => count($envelope['commands']), 'client_pid' => $clientPid,
			'client_alive' => aefProcessAlive($clientPid), 'generation' => $generation));
		$values = array();
		$fault = null;
		$executed = 0;
		$ordinal = 0;
		$stopped = false;
		foreach($envelope['commands'] as $entry)
		{
			$ordinal++;
			$command = isset($entry['command']) ? (string)$entry['command'] : '';
			$params = isset($entry['params']) ? $entry['params'] : null;
			$applied = $this->apply($command, $params);
			$this->state['applied']++;
			if($command === 'd.erase' && $applied['fault'] === null)
				$executed++;
			$this->persist();
			$this->event(array('event' => 'command-applied', 'request' => $request,
				'ordinal' => $ordinal, 'command' => $command,
				'hash' => $applied['hash'], 'erased' => $executed,
				'fault' => $applied['fault'], 'client_pid' => $clientPid,
				'client_alive' => aefProcessAlive($clientPid),
				'generation' => $generation));
			if($applied['fault'] !== null)
			{
				// CAPTURED, 2026-09-05, on both daemons this fork supports: a
				// faulted item does NOT stop a system.multicall. Sending the
				// nine-command aggregate with an undefined method at positions
				// 1, 4 and 7 returned a fault struct at each of those positions
				// and STILL erased all three downloads, on rTorrent 0.9.8 and
				// on 0.16.21 alike. So the batch goes on here too; an earlier
				// draft of this fixture stopped, and that was a model the wire
				// contradicts.
				//
				// The answer carries the fault struct at the item's position,
				// whose faultCode and faultString are both <value><i.> /
				// <value><string> and so are both scraped by the production
				// parser, in that order. The wording is the daemons' own:
				// 0.16.21 answers 'invalid parameters: info-hash not found'
				// with faultCode -500 and 0.9.8 'Could not find info-hash.'
				// with -501, and erasedataTorrentPresence() lists both.
				$fault = $applied['fault'];
				$values[] = (string)$applied['faultCode'];
				$values[] = $applied['fault'];
				continue;
			}
			foreach($applied['values'] as $value)
				$values[] = $value;
			if($command === 'd.erase' && $this->barrier['mode'] !== 'none'
				&& $this->barrier['after_erase'] > 0
				&& $executed === $this->barrier['after_erase'])
			{
				if(!$this->reachBarrier($request, $ordinal, $applied['hash'],
					$executed, $clientPid, $generation))
				{
					$stopped = true;
					break;
				}
			}
		}
		if($stopped)
		{
			// The transport closes with NO reply: the executed prefix stands and
			// the client is told nothing at all about it.
			$this->event(array('event' => 'transport-closed', 'request' => $request,
				'applied' => $ordinal, 'erased' => $executed,
				'client_pid' => $clientPid, 'generation' => $generation));
			@rename($file, $file.'.abandoned');
			$this->persist();
			return(false);
		}
		// Every item's own value is in $values already, faults included and in
		// wire order, so the whole answer is handed back as one list exactly as
		// the production parser would scrape it.
		$reply = array('ok' => true, 'fault' => $fault !== null,
			'faultString' => $fault === null ? '' : $fault,
			'faultCode' => $fault === null ? 0 : -500, 'val' => $values);
		$tmp = $this->dir.'/rep/'.$request.'.json.tmp';
		@file_put_contents($tmp, json_encode($reply));
		@rename($tmp, $this->dir.'/rep/'.$request.'.json');
		@rename($file, $file.'.done');
		$this->event(array('event' => 'reply-delivered', 'request' => $request,
			'applied' => $ordinal, 'erased' => $executed, 'values' => count($reply['val']),
			'fault' => $fault, 'client_pid' => $clientPid,
			'client_alive' => aefProcessAlive($clientPid), 'generation' => $generation));
		return(true);
	}

	public function run()
	{
		@mkdir($this->dir.'/req', 0777, true);
		@mkdir($this->dir.'/rep', 0777, true);
		$this->load();
		// A restart resumes nothing. Any request left unanswered belongs to a
		// transport that is already gone, and re-running it would execute the
		// tail of a batch whose client died -- which is precisely the outcome
		// the daemon-death case must NOT be handed.
		$abandoned = array();
		foreach((array)@glob($this->dir.'/req/*.json') as $pending)
		{
			$abandoned[] = basename($pending);
			@rename($pending, $pending.'.abandoned');
		}
		@unlink($this->dir.'/barrier');
		@unlink($this->dir.'/release');
		$this->persist();
		$this->event(array('event' => 'daemon-started',
			'abandoned' => $abandoned, 'present' => $this->presentHashes()));
		@file_put_contents($this->dir.'/alive', (string)getmypid());
		$idle = (float)$this->config['idle'];
		$deadline = microtime(true) + $idle;
		while(microtime(true) < $deadline)
		{
			clearstatcache(true, $this->dir.'/stop');
			if(@file_exists($this->dir.'/stop'))
				break;
			$pending = (array)@glob($this->dir.'/req/*.json');
			sort($pending, SORT_STRING);
			if(!count($pending))
			{
				usleep(5000);
				continue;
			}
			foreach($pending as $file)
			{
				if(!$this->serve($file))
				{
					$this->close('stopped-at-barrier');
					return(0);
				}
				$deadline = microtime(true) + $idle;
			}
		}
		$this->close('stopped');
		return(0);
	}

	private function presentHashes()
	{
		$present = array();
		foreach($this->state['hashes'] as $hash => $entry)
			if(!empty($entry['present']))
				$present[] = $hash;
		sort($present, SORT_STRING);
		return($present);
	}

	// The transport is closed by REMOVING the liveness marker before this
	// process exits, so a client blocked on a read learns at once that its peer
	// is gone instead of waiting out a timeout it could mistake for anything
	// else.
	private function close($reason)
	{
		$this->persist();
		@unlink($this->dir.'/alive');
		$this->event(array('event' => 'daemon-stopped', 'reason' => $reason,
			'present' => $this->presentHashes(),
			'erased' => $this->state['erased']));
	}
}

if(PHP_SAPI !== 'cli' || !isset($_SERVER['SCRIPT_FILENAME'])
	|| realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__)
	aefFail('this fixture is a standalone daemon process, not a library');
if(!isset($argv) || count($argv) !== 2)
	aefFail('expects exactly one argument');
$daemon = new ErasedataAggregateEraseDaemon(aefConfig($argv[1]));
exit($daemon->run());
