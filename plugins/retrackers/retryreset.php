<?php

// Operator recovery for an exhausted retrackers queue job.
//
// A job whose d.custom=retrackers-retry is r1:<Q>:6:0:<reason> is terminal: the
// dispatcher never starts it again, so a fixed cause (a resumed torrent, a
// finished hash check, a repaired filesystem) does not bring the tracker back.
// The WebUI raw door refuses d.custom.set by design. This tool runs over the
// profile's trusted SCGI socket, reads first, and clears only one reviewed
// terminal state of the caller's own exact Q; the dispatcher then treats that
// Q as new work. It never touches Q, a claim, a marker or the ledger.
//
//   php tools/retrackers-retry-reset.php [--user=NAME]                list terminal jobs
//   php tools/retrackers-retry-reset.php [--user=NAME] HASH           dry run for one job
//   php tools/retrackers-retry-reset.php [--user=NAME] HASH --apply   clear its terminal state
//
// A queue belongs to the ruTorrent profile that ran the WebUI. With
// $forbidUserSettings = true (the Docker image's default) every login shares
// the one unnamed profile, so --user=NAME selects that profile too; only with
// per-user settings does --user=NAME name the login's own profile.
// The WebUI init (init.php) counts the same terminal jobs for a one-line notice.
// Load plugins/retrackers/update.php before this file.

// The operator tool's read-only outage budget; it never replays its CAS.
if (!defined('RETRACKERS_RESET_READ_TIMEOUT'))
	define('RETRACKERS_RESET_READ_TIMEOUT', 30.0);

final class RetrackersRetryReset
{
	const USAGE = "usage: retrackers-retry-reset.php [--user=NAME] [HASH [--apply]]\n";

	/** One terminal job of $user, or false with a classified $failure. Read-only. */
	public static function inspect($adapter, $user, $hash, &$failure = null)
	{
		$userHash = hash('sha256', $user);
		$queued = self::read(function (&$failure) use ($adapter, $hash) {
			return($adapter->queueMarker($hash, $failure));
		}, $failure);
		if ($queued === false) return(self::refused($adapter, $hash, $failure));
		$marker = RetrackersQueueMarker::parse($queued);
		if ($marker === false || !hash_equals($userHash, $marker['user_hash'])) {
			$failure = 'queue-absent';
			return(false);
		}
		$claim = self::read(function (&$failure) use ($adapter, $hash) {
			return($adapter->queueClaim($hash, $failure));
		}, $failure);
		if ($claim === false) return(self::refused($adapter, $hash, $failure));
		$parsed = RetrackersQueueClaim::parse($claim);
		if ($claim !== 'none' && ($parsed === false || $parsed['kind'] !== 'original' ||
			!hash_equals($userHash, $parsed['user_hash']) || $parsed['queue_id'] !== $marker['queue_id'])) {
			// held, finalized or foreign work is never reset from here.
			$failure = 'claim-not-resettable';
			return(false);
		}
		$retry = self::read(function (&$failure) use ($adapter, $hash) {
			return($adapter->queueRetryState($hash, $failure));
		}, $failure);
		if ($retry === false) return(self::refused($adapter, $hash, $failure));
		$state = RetrackersQueueRetry::parse($retry, $marker['queue_id']);
		if ($state === false) {
			$failure = 'retry-state-invalid';
			return(false);
		}
		if (!RetrackersQueueRetry::isTerminal($state)) {
			$failure = 'retry-not-terminal';
			return(false);
		}
		$failure = null;
		return(array('hash' => $hash, 'queued' => $queued, 'queue_id' => $marker['queue_id'],
			'claim' => $claim === 'none' ? 'none' : 'original', 'retry' => $retry));
	}

	// A read fault may only say that rTorrent holds no torrent with this hash:
	// a mistyped hash, or a torrent removed since the queue was listed. d.hash
	// tells that apart from other faults; it never turns a fault into success.
	private static function refused($adapter, $hash, &$failure)
	{
		if ($failure !== 'rpc-unconfirmed') return(false);
		$probeFailure = null;
		$absent = $adapter->nativeTargetAbsent($hash, $probeFailure);
		if ($absent === true) $failure = 'torrent-absent';
		elseif (RetrackersBoundedRead::noAnswer($probeFailure)) $failure = 'transport-unconfirmed';
		return(false);
	}

	/** Clear one inspected terminal state with one CAS; a lost reply is read back, never resent. */
	public static function apply($adapter, array $job, &$failure = null)
	{
		$callback = RetrackersLifecycleCallbacks::retryReset($job['hash'], $job['queued'], $job['retry']);
		if ($callback === false) {
			$failure = 'retry-not-terminal';
			return(false);
		}
		$result = $adapter->executeLifecycleCallback($callback, $failure);
		if ($result === $callback->changedSentinel()) {
			$failure = 'changed';
			return(false);
		}
		$retry = self::read(function (&$failure) use ($adapter, $job) {
			return($adapter->queueRetryState($job['hash'], $failure));
		}, $failure);
		$queued = $retry === false ? false : self::read(function (&$failure) use ($adapter, $job) {
			return($adapter->queueMarker($job['hash'], $failure));
		}, $failure);
		if ($retry === false || $queued === false) return(false);
		if ($retry === '' && $queued === $job['queued']) {
			$failure = null;
			return(true);
		}
		$failure = $retry === $job['retry'] && $queued === $job['queued'] ? 'not-applied' : 'changed';
		return(false);
	}

	/**
	 * The terminal jobs of $user in one queueRetryRows() answer (hash, local
	 * id, Q, retry state): the one filter of the listing and the notice.
	 */
	public static function terminalRows(array $rows, $user)
	{
		$userHash = hash('sha256', $user);
		$terminal = array();
		foreach ($rows as $row) {
			if (!is_array($row) || count($row) !== 4 || !isset($row[0], $row[2], $row[3])) continue;
			$marker = RetrackersQueueMarker::parse($row[2]);
			if ($marker === false || !hash_equals($userHash, $marker['user_hash'])) continue;
			if (RetrackersQueueRetry::isTerminal(RetrackersQueueRetry::parse($row[3], $marker['queue_id'])))
				$terminal[] = array('hash' => $row[0], 'queue_id' => $marker['queue_id'], 'retry' => $row[3]);
		}
		return($terminal);
	}

	/**
	 * Every terminal job of $user, including one that cannot be reset right now
	 * (its 'refused' names why, torrent-absent for one removed since the rows
	 * were read), or false when rTorrent stops answering.
	 */
	public static function terminalJobs($adapter, $user, &$failure = null)
	{
		$rows = self::read(function (&$failure) use ($adapter) {
			return($adapter->queueRetryRows($failure));
		}, $failure);
		if (!is_array($rows)) return(false);
		$jobs = array();
		foreach (self::terminalRows($rows, $user) as $row) {
			$job = self::inspect($adapter, $user, $row['hash'], $failure);
			if ($job === false && $failure === 'transport-unconfirmed') return(false);
			$jobs[] = $job !== false ? $job : array('hash' => $row['hash'], 'retry' => $row['retry'], 'refused' => $failure);
		}
		$failure = null;
		return($jobs);
	}

	/**
	 * The number of terminal jobs of $user from one rows read, or false with
	 * a classified $failure. It neither retries nor reads per torrent.
	 */
	public static function terminalCount($adapter, $user, &$failure = null)
	{
		$rows = $adapter->queueRetryRows($failure);
		if (!is_array($rows)) return(false);
		$failure = null;
		return(count(self::terminalRows($rows, $user)));
	}

	/**
	 * JavaScript for one WebUI session init: plugin.terminalJobs with the count
	 * and the list command, which init.js shows once; '' when there is nothing
	 * to show. Retry states exist only under the native queue (family 2), so a
	 * 0.9.8 daemon pays no read. An unanswered read shows nothing: the next
	 * init reads again, and the worker logged each job when it stopped.
	 */
	public static function sessionNotice($adapter, $user)
	{
		if ($adapter->getFamily() !== 2) return('');
		$failure = null;
		$count = self::terminalCount($adapter, $user, $failure);
		if (!is_int($count) || $count < 1) return('');
		return('plugin.terminalJobs = ' . json_encode(array('count' => $count,
			'command' => RetrackersWorkerRpcAdapter::retryResetCommand($user)), JSON_UNESCAPED_SLASHES) . ";\n");
	}

	// Reads only; a transport outage is retried, then reported without a
	// verdict. Any other unexpected answer is unconfirmed too, but named apart.
	private static function read(callable $read, &$failure)
	{
		$value = RetrackersBoundedRead::retry($read, $failure, RETRACKERS_RESET_READ_TIMEOUT,
			RetrackersBoundedRead::TRANSPORT_FAILURES);
		if ($value === false)
			$failure = RetrackersBoundedRead::noAnswer($failure) ? 'transport-unconfirmed' : 'rpc-unconfirmed';
		return($value);
	}

	/**
	 * Why rTorrent did not answer, for the operator. The connect is tried once
	 * more to name its error: a socket this uid may not use (EACCES) is the
	 * common one, when the tool runs as another user than the daemon's.
	 */
	public static function transportDetail(array $scgi)
	{
		if (!isset($scgi['host'], $scgi['port'])) return('');
		$address = ((string)$scgi['port'] === '0') ? (string)$scgi['host'] :
			'tcp://' . $scgi['host'] . ':' . $scgi['port'];
		$errno = 0;
		$error = '';
		$socket = @stream_socket_client($address, $errno, $error, 1.0);
		if (is_resource($socket)) {
			fclose($socket);
			return(' at ' . $address . ': it accepts a connection now but gave no answer before');
		}
		if ($errno === 13) {
			$uid = function_exists('posix_geteuid') ? (string)posix_geteuid() : '?';
			return(' at ' . $address . ': permission denied for uid ' . $uid .
				"; run the tool as the rTorrent daemon's user, for example docker exec -u torrent");
		}
		return(' at ' . $address . ': ' . ($error !== '' ? $error : 'connect failed') . ($errno ? ' (errno ' . $errno . ')' : ''));
	}

	/**
	 * $context: 'shared_profile' is ruTorrent's $forbidUserSettings, under
	 * which every login uses the one unnamed profile; 'scgi' is the host and
	 * port the adapter talks to, named when rTorrent cannot be reached.
	 */
	public static function main(array $args, $adapter, $out, $err, array $context = array())
	{
		$user = '';
		$hash = null;
		$apply = false;
		foreach ($args as $arg) {
			if (strncmp($arg, '--user=', 7) === 0 && RetrackersProfileName::valid(substr($arg, 7))) $user = substr($arg, 7);
			elseif ($arg === '--apply' && $hash !== null) $apply = true;
			elseif ($hash === null && preg_match('/^[0-9A-Fa-f]{40}$/D', $arg) === 1) $hash = strtoupper($arg);
			else {
				fwrite($err, self::USAGE);
				return(2);
			}
		}
		if ($user !== '' && !empty($context['shared_profile'])) {
			// The queue belongs to the profile ruTorrent used, as User::getUser().
			fwrite($err, 'retrackers-retry-reset: note: this ruTorrent shares one profile ($forbidUserSettings = true), ' .
				'so --user=' . $user . " selects that shared profile; the option is not needed\n");
			$user = '';
		}
		$failure = null;
		if (!$adapter->nativeQueueCapability($failure)) {
			if (RetrackersBoundedRead::noAnswer($failure)) {
				fwrite($err, 'retrackers-retry-reset: cannot reach rTorrent' .
					self::transportDetail(isset($context['scgi']) ? $context['scgi'] : array()) . "; nothing was changed\n");
				return(2);
			}
			fwrite($err, "retrackers-retry-reset: this rTorrent does not answer with the native retrackers queue commands\n");
			return(2);
		}
		if ($hash === null) {
			$jobs = self::terminalJobs($adapter, $user, $failure);
			if ($jobs === false) {
				fwrite($err, 'retrackers-retry-reset: queue read unconfirmed: ' . $failure .
					($failure === 'transport-unconfirmed' ? self::transportDetail(isset($context['scgi']) ?
						$context['scgi'] : array()) : '') . "; nothing was changed\n");
				return(2);
			}
			$listed = 0;
			foreach ($jobs as $job) {
				if (isset($job['refused']) && $job['refused'] === 'torrent-absent') {
					fwrite($err, 'retrackers-retry-reset: note: ' . $job['hash'] .
						" left rTorrent while the list was read; skipped\n");
					continue;
				}
				$listed++;
				fwrite($out, $job['hash'] . ' ' . $job['retry'] . (isset($job['refused']) ?
					' not resettable now: ' . $job['refused'] : ' claim=' . $job['claim']) . "\n");
			}
			fwrite($out, $listed . " terminal job(s); review one, then pass its HASH\n");
			return(0);
		}
		$job = self::inspect($adapter, $user, $hash, $failure);
		if ($job === false) {
			fwrite($err, 'retrackers-retry-reset: ' . $hash . ' refused: ' . $failure .
				($failure === 'torrent-absent' ? ' (rTorrent holds no torrent with this hash)' : '') . "; nothing was changed\n");
			return(in_array($failure, array('transport-unconfirmed', 'rpc-unconfirmed'), true) ? 2 : 1);
		}
		if (!$apply) {
			fwrite($out, 'would reset ' . $hash . ' ' . $job['retry'] . ' claim=' . $job['claim'] .
				"; fix the cause first, then rerun with --apply\n");
			return(0);
		}
		if (!self::apply($adapter, $job, $failure)) {
			fwrite($err, 'retrackers-retry-reset: ' . $hash . ' not reset: ' . $failure . "; read it again before retrying\n");
			return(in_array($failure, array('transport-unconfirmed', 'rpc-unconfirmed'), true) ? 2 : 1);
		}
		fwrite($out, 'reset ' . $hash . '; the dispatcher requeues Q ' . $job['queue_id'] . "\n");
		return(0);
	}
}
