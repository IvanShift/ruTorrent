<?php

// Operator admission for already loaded torrents. All mutation stays in the
// native enqueue CAS; a missing reply is never permission to send it again.
final class RetrackersApplyExisting
{
	const USAGE = "usage: retrackers-apply-existing.php [--user=NAME] [--apply] [--limit=N] [HASH...]\n";

	public static function main(array $args, $adapter, $out, $err, array $context = array())
	{
		$user = ''; $apply = false; $limit = 50; $hashes = array();
		foreach ($args as $arg) {
			if (strncmp($arg, '--user=', 7) === 0 && RetrackersProfileName::valid(substr($arg, 7))) $user = substr($arg, 7);
			elseif ($arg === '--apply') $apply = true;
			elseif (preg_match('/^--limit=([1-9][0-9]*)$/D', $arg, $match) === 1 && (string)(int)$match[1] === $match[1]) $limit = (int)$match[1];
			elseif (preg_match('/^[0-9a-fA-F]{40}$/D', $arg) === 1) $hashes[strtoupper($arg)] = true;
			else { fwrite($err, self::USAGE); return(2); }
		}
		if (!empty($context['shared_profile'])) $user = '';
		$failure = null;
		if (!$adapter->nativeQueueCapability($failure)) {
			fwrite($err, "retrackers-apply-existing: requires rTorrent with the native retrackers queue; capability or transport unconfirmed\n");
			return(2);
		}
		if ($hashes === array()) {
			$rows = $adapter->downloadLocalIdRows($failure);
			if (!is_array($rows)) { fwrite($err, "retrackers-apply-existing: transport-unconfirmed\n"); return(2); }
			foreach ($rows as $row) {
				if (!is_array($row) || !isset($row[0]) || !is_string($row[0]) || preg_match('/^[0-9A-F]{40}$/D', $row[0]) !== 1) {
					fwrite($err, "retrackers-apply-existing: read-unconfirmed\n"); return(2);
				}
				$hashes[$row[0]] = true;
			}
		}
		ksort($hashes, SORT_STRING);
		$wanted = isset($context['trackers']) ? $context['trackers'] : array();
		if (!is_array($wanted) || count(array_filter($wanted, 'is_string')) !== count($wanted)) {
			fwrite($err, "retrackers-apply-existing: profile-unconfirmed\n"); return(2);
		}
		$writes = 0; $queued = 0; $exit = 0;
		foreach ($hashes as $hash => $_) {
			$job = $adapter->applyExistingSnapshot($hash, $failure);
			if ($job === false) {
				$reason = $failure === 'target-absent' ? 'not-loaded' : 'transport-unconfirmed';
				$exit = max($exit, $reason === 'not-loaded' ? 1 : 2);
			} else {
				$reason = self::reason($hash, $job, $wanted, !empty($context['dont_add_private']));
				if ($reason === 'read-unconfirmed') $exit = 2;
				if ($reason === 'candidate' && $apply) {
					if ($writes >= $limit) $reason = 'limit';
					else {
						$writes++;
						$answer = $adapter->enqueueOriginal($hash, $job['local_id'], hash('sha256', $user), $job['state'], $failure, true);
						$handoff = RetrackersOriginalHandoff::parse($answer);
						if ($answer === 'queued' || (is_array($handoff) && $handoff['local_id'] === $job['local_id'] &&
							$handoff['user_hash'] === hash('sha256', $user) && $handoff['state'] === $job['state'])) {
							$reason = 'queued'; $queued++;
						}
						elseif ($answer === 'refused:changed') { $reason = $answer; $exit = max($exit, 1); }
						else { $reason = 'transport-unconfirmed'; $exit = 2; }
					}
				}
			}
			fwrite($out, $hash . ' ' . $reason . "\n");
			// A fault or missing mutation answer can follow durable Q/preclaim
			// publication. Stop; a later explicit run inspects ownership first.
			if ($reason === 'transport-unconfirmed') break;
		}
		return($exit === 1 && $queued > 0 ? 0 : $exit);
	}

	// One read at WebUI init keeps storage refusal visible even while tracker
	// status replaces d.message. Repair, then explicitly retry the same torrent.
	public static function sessionNotice($adapter, $user)
	{
		if ($adapter->getFamily() !== 2) return('');
		$failure = null;
		$rows = $adapter->enqueueRefusalRows($failure);
		if (!is_array($rows)) return('');
		$count = 0;
		$userHash = hash('sha256', $user);
		foreach ($rows as $row) {
			if (is_array($row) && count($row) === 4 && isset($row[2], $row[3]) &&
				$row[2] === 'session-storage-failed' && $row[3] === $userHash) $count++;
		}
		if ($count === 0) return('');
		return('plugin.enqueueRefusals = ' . json_encode(array('count' => $count,
			'command' => RetrackersWorkerRpcAdapter::applyExistingCommand($user)), JSON_UNESCAPED_SLASHES) . ";\n");
	}

	private static function reason($hash, array $job, array $wanted, $dontAddPrivate)
	{
		foreach (array('local_id', 'state', 'private', 'name', 'queued', 'recovery', 'ack', 'claim', 'lease', 'move') as $field)
			if (!isset($job[$field]) || !is_string($job[$field])) return('read-unconfirmed');
		if (preg_match('/^[0-9A-F]{40}$/D', $job['local_id']) !== 1 ||
			!in_array($job['state'], array('0', '1'), true) || !in_array($job['private'], array('0', '1'), true) ||
			!isset($job['trackers']) || !is_array($job['trackers']) || count(array_filter($job['trackers'], 'is_string')) !== count($job['trackers'])) return('read-unconfirmed');
		if ($job['queued'] !== '') return('queued');
		if ($job['claim'] !== 'none' || $job['recovery'] !== '' || $job['ack'] !== '') return('claimed');
		if ($job['lease'] !== '') return('stop-lease');
		if ($job['move'] !== 'none') return('move-pending');
		if ($dontAddPrivate && $job['private'] === '1') return('private');
		if (rTorrent::isMetadataStubName($job['name'], $hash)) return('metadata-stub');
		foreach ($wanted as $url) if (!in_array($url, $job['trackers'], true)) return('candidate');
		return('already-has-retrackers');
	}
}
