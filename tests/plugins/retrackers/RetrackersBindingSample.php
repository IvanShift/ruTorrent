<?php

// A minimal valid historical binding sample, the decoded answer of the
// lifecycle's one sampling request. Load update.php before this file.
final class RetrackersBindingSample
{
	/** RR4 rows: array(hash, local_id, marker, ack) each, sorted by hash. */
	public static function rows(array $rows)
	{
		$packed = "RR4\0" . pack('N', count($rows));
		foreach ($rows as $row)
			foreach ($row as $cell) $packed .= pack('N', strlen($cell)) . $cell;
		return($packed);
	}

	/** $user's idle F/S profile carrying $functional, with the given recovery rows. */
	public static function idle($user, $functional, array $rows = array())
	{
		$ledger = array('pf:' . hash('sha256', $user) . ':' . hash('sha256', $functional),
			'pv:' . str_repeat('1', 32));
		foreach ($rows as $row)
			if (preg_match('/^v2:original:[01]:([0-9A-F]{40}):/', $row[2], $original) === 1)
				$ledger[] = 'wp:' . $original[1];
		return(self::make(array('tadd_trackers1' . $user => $functional,
			'tadd_trackers2' . $user => retrackersBuildSafetyOnlyInsertAction()), $ledger, $rows));
	}

	/** Any hook actions and ledger keys, as the daemon would answer them. */
	public static function make(array $actions, array $ledger, array $rows = array())
	{
		ksort($actions, SORT_STRING);
		sort($ledger, SORT_STRING);
		$entries = array();
		foreach ($actions as $name => $action)
			$entries[] = array('name' => $name, 'type' => 'string',
				'digest' => retrackersRestrictedHashString($action), 'value' => $action);
		$digest = hash('sha256', serialize(array($actions, $ledger, $rows)));
		$component = hash('sha256', 'component:' . $digest);
		return(array('ok' => true, 'family' => 2, 'value' => array(
			'digest' => $digest, 'event_key_digest' => $component, 'event_map_digest' => $component,
			'recovery_rows_digest' => $component, 'ledger_key_digest' => $component,
			'ledger_map_digest' => $component, 'ledger_digest' => $component,
			'event_keys' => array_keys($actions), 'actions' => $entries,
			'recovery_rows' => self::rows($rows), 'ledger_keys' => $ledger,
			'counts' => array('event_keys' => count($actions), 'actions' => count($actions),
				'recovery_rows' => count($rows), 'ledger_keys' => count($ledger)),
			'production_accepted' => false)));
	}
}
