<?php

// One in-memory model of the native retrackers queue, shared by the queue suites
// instead of per-file adapters that match XML substrings.
//
// It answers the direct requests that RetrackersWorkerRpcAdapter and
// RetrackersQueueRpcAdapter send, with the acceptance rules and refusal texts
// of rTorrent rutorrent-native (src/command_download.cc and
// src/core/retrackers_queue_receipts.h): rr_queued_original,
// retrackers_retry_parse, the three-argument cancel and five-argument defer
// around rr_retire_uncaptured, rr_enqueue_original, system.retrackers.worker_start
// and rr_dequeue_original. $waits = true is the final native (r3fix/native
// 68dab7c and 2b14cb83): system.retrackers.retry_waits = 1 and its
// retrackers_retry_transition_valid wait, refusals that name their operation,
// a classified enqueue storage refusal and dequeue clearing the retry receipt.
// workerExited() is that native's worker-exit recovery, whose rollback spends
// one counted attempt. $waits = false is d883db88, whose refusals all said
// "cancel". PHP classifies these faults without parsing their texts; the
// texts are kept for fidelity.
//
// Load plugins/retrackers/update.php (and dispatch.php, for the queue adapter)
// before this file.

final class RetrackersWireFake
{
	// retrackers_retry_parse accepts one count character '1'..'6', due "0"
	// exactly at '6' and these four reasons; RetrackersQueueRetry must agree.
	const MAX_COUNT = 6;
	const REASONS = array('capture-refused', 'transport-unavailable', 'source-not-ready', 'preflight-refused');
	const WAITING = array('transport-unavailable', 'source-not-ready');
	const EXHAUSTED = 'retrackers: retries exhausted; inspect d.custom=retrackers-retry; queued work retained';
	// rr_enqueue_storage_refused_message (68dab7c, ACC-OWNB-03).
	const ENQUEUE_STORAGE_REFUSED = 'retrackers: enqueue refused: session-storage-failed; a later insert or recovery retries';
	// The captured 0.16.x fault for a download target that does not exist.
	const ABSENT = array('code' => '-500', 'code_type' => 'i8', 'message' => 'invalid parameters: info-hash not found');

	public $now = 1700000000;
	public $waits = false;
	public $waitsValue = '1';
	public $torrents = array();
	public $ledger = array();
	public $cursors = array();
	public $calls = array();
	public $payloads = array();
	public $fail = array();
	public $lose = array();
	public $started = array();
	public $saves = 0;
	// The error of a failed durable Q save (EACCES, ENOSPC) that the next
	// enqueue of a new Q meets; null saves.
	public $enqueueStorageError = null;
	// rr_log_refusal lines of worker-exit recovery.
	public $nativeLog = array();
	private $mint = 0;

	public static function method($payload)
	{
		return(preg_match('~<methodName>([^<]*)</methodName>~', $payload, $m) === 1 ?
			html_entity_decode($m[1], ENT_NOQUOTES, 'UTF-8') : '');
	}

	public static function params($payload)
	{
		preg_match_all('~<param><value><string>(.*?)</string></value></param>~s', $payload, $m);
		return(array_map(function ($value) {
			return(html_entity_decode($value, ENT_NOQUOTES, 'UTF-8'));
		}, $m[1]));
	}

	// A port of retrackers_retry_parse; it pins the native contract in PHP tests.
	public static function retryValid($retry, $queueToken)
	{
		$prefix = 'r1:' . $queueToken . ':';
		if (!is_string($retry) || strncmp($retry, $prefix, strlen($prefix)) !== 0 || strlen($retry) > 100) return(false);
		$count = strlen($prefix);
		$due = $count + 2;
		if (strlen($retry) <= $due || $retry[$count] < '1' || $retry[$count] > '6' || $retry[$count + 1] !== ':') return(false);
		$end = strpos($retry, ':', $due);
		if ($end === false || $end === $due || $end - $due > 10) return(false);
		$when = substr($retry, $due, $end - $due);
		if (strspn($when, '0123456789') !== strlen($when) || (strlen($when) > 1 && $when[0] === '0') ||
			($retry[$count] === '6') !== ($when === '0')) return(false);
		return(in_array(substr($retry, $end + 1), self::REASONS, true));
	}

	private function token()
	{
		return(str_pad(dechex(++$this->mint), 32, '0', STR_PAD_LEFT));
	}

	public function marker($hash)
	{
		$c = $this->torrents[$hash]['claim'];
		return('v2:original:' . $c['run'] . ':' . $c['local_id'] . ':' . $c['user_hash'] . ':' . $c['tx']);
	}

	/** A torrent whose insert hook enqueued Q and began its original claim. */
	public function add($hash, $localId, $user = 'alice', $state = '0', $claimed = true)
	{
		$this->torrents[$hash] = array('local_id' => $localId, 'incarnation' => $this->token(),
			'state' => $state, 'queued' => 'q1:' . hash('sha256', $user) . ':' . $this->token(),
			'retry' => '', 'finished' => '', 'recovery' => '', 'ack' => '', 'message' => '',
			'claim' => null, 'finalized' => null, 'held' => false);
		if ($claimed) $this->begin($hash);
		return($this->torrents[$hash]);
	}

	/** The same queued original with fixed identities chosen by a suite. */
	public function addExact($hash, $localId, $tx, $incarnation, $queueToken, $user = 'alice', $state = '0')
	{
		$this->add($hash, $localId, $user, $state, false);
		$t = &$this->torrents[$hash];
		$t['incarnation'] = $incarnation;
		$t['queued'] = 'q1:' . hash('sha256', $user) . ':' . $queueToken;
		$this->begin($hash);
		$t['claim']['tx'] = $tx;
		$t['recovery'] = $t['ack'] = $this->marker($hash);
		return($t);
	}

	public function begin($hash)
	{
		$t = &$this->torrents[$hash];
		$t['claim'] = array('tx' => $this->token(), 'incarnation' => $t['incarnation'],
			'local_id' => $t['local_id'], 'user_hash' => substr($t['queued'], 3, 64),
			'run' => $t['state'], 'queue_id' => substr($t['queued'], 68));
		$t['recovery'] = $t['ack'] = $this->marker($hash);
		if (is_array($this->ledger) && !in_array('wp:' . $t['local_id'], $this->ledger, true))
			$this->ledger[] = 'wp:' . $t['local_id'];
	}

	/** A worker completed: native finalized the claim and records the finished Q. */
	public function finish($hash)
	{
		$t = &$this->torrents[$hash];
		$c = $t['claim'];
		$t['finalized'] = array('tx' => $c['tx'], 'incarnation' => $c['incarnation']);
		$t['finished'] = 'qd1:' . $c['tx'] . ':' . $c['incarnation'] . ':' . $c['queue_id'];
		$t['claim'] = null;
		$t['recovery'] = $t['ack'] = '';
		$this->ledger = array_values(array_diff($this->ledger, array('wp:' . $t['local_id'])));
	}

	/**
	 * rTorrent's worker-exit recovery (r3fix/native 68dab7c, the counted
	 * rollback since 2b14cb83; R4-RT-X1) once it has reaped the exact worker
	 * H/T/I of a captured transaction ($t['held']). No other process continues
	 * that transaction.
	 *
	 * A committed candidate is released: 'finished', Q completed, and the
	 * dispatcher dequeues it; no attempt is spent. Anything else is rolled
	 * back: the original is restored from its claim and released with Q owed
	 * (claim none, no completion marker), the dead job's wp:L is retired, and
	 * the released state spends one counted attempt, capture-refused with due
	 * now + 2^count, the sixth terminal with due 0
	 * (core::retrackers_retry_counted_next, the step RetrackersQueueRetry::next
	 * takes). A receipt of another Q, an invalid one or a terminal one stays
	 * as it is. Returns the outcome that the native log line carries.
	 */
	public function workerExited($hash, $committed)
	{
		$t = &$this->torrents[$hash];
		if ($committed) {
			$this->finish($hash);
			$outcome = 'finished';
		} else {
			$c = $t['claim'];
			$queueToken = $c['queue_id'];
			if ($t['queued'] === '' || substr($t['queued'], 68) !== $queueToken) {
				$counted = 'attempt not counted: Q changed';
			} elseif ($t['retry'] !== '' && !self::retryValid($t['retry'], $queueToken)) {
				$counted = 'attempt not counted: retry receipt invalid';
			} elseif ($t['retry'] !== '' && (int)$t['retry'][36] >= self::MAX_COUNT) {
				$counted = 'retries already exhausted';
			} else {
				$count = ($t['retry'] === '' ? 0 : (int)$t['retry'][36]) + 1;
				$terminal = $count === self::MAX_COUNT;
				$t['retry'] = 'r1:' . $queueToken . ':' . $count . ':' .
					($terminal ? 0 : $this->now + (1 << $count)) . ':capture-refused';
				// RPC d.message derives the exhaustion status from this receipt.
				if ($terminal && $t['message'] === '') $t['message'] = self::EXHAUSTED;
				$counted = 'attempt ' . $count . ' of ' . self::MAX_COUNT . ($terminal ? ', retries exhausted' : '');
			}
			$t['finalized'] = array('tx' => $c['tx'], 'incarnation' => $c['incarnation']);
			$t['finished'] = '';
			$t['claim'] = null;
			$t['recovery'] = $t['ack'] = '';
			$this->ledger = array_values(array_diff($this->ledger, array('wp:' . $t['local_id'])));
			$outcome = 'rolled back; ' . $counted;
		}
		$t['held'] = false;
		$this->nativeLog[] = 'RR worker exit recovery: ' . $hash . ': ' . $outcome;
		return($outcome);
	}

	public function claimWire($hash)
	{
		if (!isset($this->torrents[$hash])) return('none');
		$t = $this->torrents[$hash];
		if ($t['held']) return('held');
		if ($t['claim'] !== null) {
			$c = $t['claim'];
			if ($c['incarnation'] !== $t['incarnation'] || $c['local_id'] !== $t['local_id'] ||
				substr($t['queued'], 68) !== $c['queue_id'] || $t['recovery'] !== $this->marker($hash) ||
				$t['ack'] !== $this->marker($hash)) return('held');
			return('original:' . $c['tx'] . ':' . $c['incarnation'] . ':' . $c['local_id'] . ':' .
				$c['user_hash'] . ':' . $c['run'] . ':' . $c['queue_id']);
		}
		if ($t['finalized'] !== null && $t['queued'] !== '' && $t['finished'] === 'qd1:' .
			$t['finalized']['tx'] . ':' . $t['finalized']['incarnation'] . ':' . substr($t['queued'], 68))
			return('finalized:' . $t['finalized']['tx'] . ':' . $t['finalized']['incarnation'] . ':' .
				$t['local_id'] . ':' . substr($t['queued'], 3, 64) . ':' . $t['state'] . ':' . substr($t['queued'], 68));
		return('none');
	}

	private static function ok($value)
	{
		return(array('ok' => true, 'family' => 2, 'value' => $value));
	}

	// A command's torrent::input_error: TinyXML2 reports it as
	// XMLRPC_INTERNAL_ERROR -500 (src/rpc/xmlrpc_tinyxml2.cc), the code of the
	// captured missing-hash answer ABSENT.
	private static function fault($message)
	{
		return(array('ok' => false, 'family' => 2, 'fault' => array('code' => '-500',
			'code_type' => 'i8', 'message' => $message)));
	}

	// An unregistered method: XMLRPC_NO_SUCH_METHOD_ERROR -506 on TinyXML2.
	private static function unknownMethod($name)
	{
		return(array('ok' => false, 'family' => 2, 'fault' => array('code' => '-506',
			'code_type' => 'i8', 'message' => "method '" . $name . "' not defined")));
	}

	// rr_retire_uncaptured names its operation since 68dab7c (R4-N-09).
	private function retireFault($operation, $tail)
	{
		return(self::fault('RR original ' . ($this->waits ? $operation : 'cancel') . ' ' . $tail));
	}

	private function defer($hash, array $args)
	{
		$t = &$this->torrents[$hash];
		$operation = count($args) === 5 ? 'defer' : 'cancel';
		list($tx, $incarnation, $queueToken) = $args;
		foreach (array($tx, $incarnation, $queueToken) as $token)
			if (preg_match('/^[0-9a-f]{32}$/D', $token) !== 1)
				return($this->retireFault($operation, 'needs exact transaction, incarnation and queue token.'));
		$c = $t['claim'];
		if ($t['queued'] === '' || substr($t['queued'], 68) !== $queueToken || $c === null ||
			$c['tx'] !== $tx || $c['incarnation'] !== $incarnation || $c['queue_id'] !== $queueToken ||
			$t['incarnation'] !== $incarnation || $t['recovery'] !== $this->marker($hash) ||
			$t['ack'] !== $this->marker($hash))
			return($this->retireFault($operation, 'preclaim authority changed.'));
		if (count($args) === 5) {
			list(, , , $prior, $next) = $args;
			if ($t['retry'] !== $prior || ($prior !== '' && !self::retryValid($prior, $queueToken)) ||
				!self::retryValid($next, $queueToken))
				return($this->retireFault('defer', 'retry state changed or is invalid.'));
			$before = $prior === '' ? 0 : (int)$prior[36];
			$after = (int)$next[36];
			$wait = $this->waits && in_array(substr($next, strrpos($next, ':') + 1), self::WAITING, true) &&
				$after === max(1, $before);
			if ($before >= self::MAX_COUNT || ($after !== $before + 1 && !$wait))
				return(self::fault($this->waits ? 'RR original defer retry count must advance once or wait.' :
					'RR original cancel retry count must advance once.'));
		}
		// Explicit refusals precede every mutation.
		if ($t['held'] || $t['finished'] !== '')
			return($this->retireFault($operation, 'is blocked by capture, release, lease or completion.'));
		if (count($args) === 5) {
			$next = $args[4];
			$t['retry'] = $next;
			if (substr($next, 36, 4) === '6:0:' && $t['message'] === '') $t['message'] = self::EXHAUSTED;
		}
		$t['claim'] = null;
		$t['recovery'] = $t['ack'] = '';
		$this->ledger = array_values(array_diff($this->ledger, array('wp:' . $t['local_id'])));
		return(self::ok('cancelled'));
	}

	private function enqueue($hash, array $args)
	{
		$t = &$this->torrents[$hash];
		if (count($args) !== 3) return(self::fault('RR enqueue needs local ID, user digest and run bit.'));
		list($localId, $userHash, $run) = $args;
		if ($localId !== $t['local_id'] || $run !== $t['state'])
			return(self::fault('RR enqueue identity invalid.'));
		if ($t['queued'] === '') {
			if ($t['claim'] !== null || $t['held'] || $t['recovery'] !== '' || $t['ack'] !== '')
				return(self::fault('RR enqueue prior authority must be retired.'));
			if ($this->waits && $this->enqueueStorageError !== null) {
				// The durable save of a new Q failed: Q is not published, the prior
				// values stay, d.message names it and the hook gets an input_error.
				$t['message'] = self::ENQUEUE_STORAGE_REFUSED;
				return(self::fault('RR enqueue refused: session-storage-failed: ' . $this->enqueueStorageError));
			}
			$t['queued'] = 'q1:' . $userHash . ':' . $this->token();
			$t['finished'] = $t['retry'] = '';
			if ($t['message'] === self::EXHAUSTED) $t['message'] = '';
		} elseif (substr($t['queued'], 3, 64) !== $userHash) {
			return(self::fault('RR queue belongs to another owner.'));
		}
		$this->begin($hash);
		return(self::ok('begun'));
	}

	/**
	 * The decoded answer for one request, false with $failure for an injected
	 * transport failure, or null for a method this model does not serve.
	 */
	public function answer($payload, &$failure)
	{
		$failure = null;
		$method = self::method($payload);
		$params = self::params($payload);
		$this->calls[] = $method;
		$this->payloads[$method][] = $payload;
		if (!empty($this->fail[$method])) {
			$failure = array_shift($this->fail[$method]);
			return(false);
		}
		$lose = !empty($this->lose[$method]);
		if ($lose) $this->lose[$method]--;
		$hash = isset($params[0]) ? $params[0] : '';
		$present = isset($this->torrents[$hash]);
		switch ($method) {
		case 'system.listMethods':
			$methods = array('d.retrackers.enqueue_original', 'd.retrackers.dequeue_original',
				'system.retrackers.original_claim', 'system.retrackers.worker_start',
				'system.retrackers.dispatcher_start', 'system.retrackers.queue_cursor',
				'system.retrackers.queue_cursor.set', 'd.retrackers.preflight_stage',
				'd.retrackers.cancel_original', 'd.retrackers.defer_original', 'schedule.if_absent');
			if ($this->waits) $methods[] = 'system.retrackers.retry_waits';
			$answer = self::ok($methods);
			break;
		case 'system.retrackers.retry_waits':
			$answer = $this->waits ? self::ok($this->waitsValue) : self::unknownMethod($method);
			break;
		case 'system.retrackers.original_claim':
			$answer = self::ok($this->claimWire($params[1]));
			break;
		case 'd.custom':
			if (!$present) { $answer = array('ok' => false, 'family' => 2, 'fault' => self::ABSENT); break; }
			$keys = array('retrackers-retry' => 'retry', 'retrackers-queued' => 'queued',
				'retrackers-recovery' => 'recovery', 'retrackers-recovery-ack' => 'ack');
			$answer = self::ok($this->torrents[$hash][$keys[$params[1]]]);
			break;
		case 'd.hash':
		case 'd.incarnation':
			$answer = $present ? self::ok($method === 'd.hash' ? $hash : $this->torrents[$hash]['incarnation']) :
				array('ok' => false, 'family' => 2, 'fault' => self::ABSENT);
			break;
		case 'd.retrackers.defer_original':
		case 'd.retrackers.cancel_original':
			$defer = $method === 'd.retrackers.defer_original';
			if (!$present) $answer = array('ok' => false, 'family' => 2, 'fault' => self::ABSENT);
			elseif (count($params) !== ($defer ? 6 : 4))
				$answer = self::fault($defer ? 'RR original defer needs transaction, incarnation, queue and retry CAS.' :
					'RR original cancel needs exact transaction, incarnation and queue token.');
			else $answer = $this->defer($hash, array_slice($params, 1));
			break;
		case 'd.retrackers.enqueue_original':
			$answer = $present ? $this->enqueue($hash, array_slice($params, 1)) :
				array('ok' => false, 'family' => 2, 'fault' => self::ABSENT);
			break;
		case 'system.retrackers.worker_start':
			$claim = RetrackersQueueClaim::parse($this->claimWire($params[1]));
			if ($claim === false || $claim['kind'] !== 'original' || $claim['tx'] !== $params[2] ||
				$claim['incarnation'] !== $params[3]) {
				$answer = self::ok('held');
				break;
			}
			$this->started[] = $params[1];
			$answer = self::ok('spawned');
			break;
		case 'd.retrackers.dequeue_original':
			if (!$present) {
				$answer = array('ok' => false, 'family' => 2, 'fault' => self::ABSENT);
				break;
			}
			if (count($params) !== 4) {
				$answer = self::fault('RR dequeue needs transaction, incarnation and queue token.');
				break;
			}
			$t = $this->torrents[$hash];
			if ($t['queued'] === '' || substr($t['queued'], 68) !== $params[3] ||
				strncmp($this->claimWire($hash), 'finalized:' . $params[1] . ':' . $params[2] . ':', 76) !== 0) {
				$answer = self::fault('RR dequeue finalized authority changed.');
				break;
			}
			$this->torrents[$hash]['queued'] = $this->torrents[$hash]['finished'] = '';
			if ($this->waits) {
				// A completed job owes nothing: 68dab7c clears its retry receipt
				// and the exhaustion message (ACC-SCALE-04); d883db88 kept them.
				$this->torrents[$hash]['retry'] = '';
				if ($t['message'] === self::EXHAUSTED) $this->torrents[$hash]['message'] = '';
			}
			$answer = self::ok('dequeued');
			break;
		case 'system.retrackers.queue_cursor':
			$answer = self::ok(isset($this->cursors[$params[1]]) ? $this->cursors[$params[1]] : '');
			break;
		case 'system.retrackers.queue_cursor.set':
			$this->cursors[$params[1]] = $params[2];
			$answer = self::ok($params[2]);
			break;
		case 'd.multicall2':
			// One cell per requested slot, as rTorrent answers it.
			$columns = array('d.hash=' => 'hash', 'd.local_id=' => 'local_id',
				'd.custom=retrackers-queued' => 'queued', 'd.custom=retrackers-retry' => 'retry',
				'd.custom=retrackers-recovery' => 'recovery', 'd.custom=retrackers-recovery-ack' => 'ack',
				'cat=$d.state=' => 'state');
			$slots = array_slice($params, 2);
			if (array_slice($params, 0, 2) !== array('', 'main') || $slots === array() ||
				array_diff($slots, array_keys($columns)) !== array()) return(null);
			$rows = array();
			foreach ($this->torrents as $rowHash => $t) {
				$t['hash'] = $rowHash;
				$rows[] = array_map(function ($slot) use ($columns, $t) { return($t[$columns[$slot]]); }, $slots);
			}
			usort($rows, function ($left, $right) { return(strcmp($left[0], $right[0])); });
			$answer = self::ok($rows);
			break;
		case 'branch':
			// Only the retry reset CAS is modeled: it holds exactly when the
			// request equals the callback built from the current state.
			if (!isset($params[2]) || strpos($params[2], 'd.custom.set=retrackers-retry,') === false) return(null);
			$current = $present ? RetrackersLifecycleCallbacks::retryReset($hash,
				$this->torrents[$hash]['queued'], $this->torrents[$hash]['retry']) : false;
			if ($current !== false && $params === $current->params()) {
				$this->torrents[$hash]['retry'] = '';
				$this->saves++;
				$answer = self::ok('ACQUIRED');
			} else {
				$answer = self::ok('CHANGED');
			}
			break;
		case 'system.multicall':
			$answer = self::ok(array(array('value' => $this->ledger), array('value' => array())));
			break;
		default:
			return(null);
		}
		if ($lose) {
			$failure = 'closed-before-headers';
			return(false);
		}
		return($answer);
	}
}

// Route one adapter's requests to the shared model and its clock.
trait RetrackersWireFakeTransport
{
	public $wire;

	protected function send($payload, $plan, &$failure = null, $historical = false)
	{
		$answer = $this->wire->answer($payload, $failure);
		if ($answer === null) throw new RuntimeException('unexpected retrackers RPC ' . RetrackersWireFake::method($payload));
		return($answer);
	}

	protected function now()
	{
		return($this->wire->now);
	}
}

class RetrackersWireWorkerAdapter extends RetrackersWorkerRpcAdapter
{
	use RetrackersWireFakeTransport;

	public function __construct($wire, $hash = null, $user = null)
	{
		parent::__construct($hash, $user);
		$this->wire = $wire;
		$this->family = 2;
	}
}

if (class_exists('RetrackersQueueRpcAdapter', false)) {
	class RetrackersWireQueueAdapter extends RetrackersQueueRpcAdapter
	{
		use RetrackersWireFakeTransport;

		public function __construct($wire)
		{
			$this->wire = $wire;
			$this->family = 2;
		}
	}
}
