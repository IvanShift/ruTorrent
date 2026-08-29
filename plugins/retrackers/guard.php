<?php

// Shared string markers keep retrackers independent from rutracker_check's PHP
// classes while still protecting its short-lived metadata service torrents.
define('RETRACKERS_SERVICE_LABEL', '.chk-meta');
define('RETRACKERS_SERVICE_MARKER', 'chk-meta-old');
define('RETRACKERS_RECOVERY_MARKER', 'retrackers-recovery');
define('RETRACKERS_RECOVERY_ACK', 'retrackers-recovery-ack');

function retrackersIsServiceTorrent($label, $marker)
{
	return rawurldecode((string) $label) === RETRACKERS_SERVICE_LABEL
		|| (string) $marker !== '';
}

function retrackersIsSafeInsertStaticValue($value, $allowEmpty)
{
	$value = (string) $value;
	return ($allowEmpty || $value !== '') && strpos($value, "\0") === false
		&& strpos($value, "\r") === false && strpos($value, "\n") === false
		&& ($value === '' || $value[0] !== '$');
}

function retrackersBuildInsertAction($rootPath, $php, $user)
{
	$script = rtrim((string) $rootPath, '/') . '/plugins/retrackers/run.sh';
	$php = (string) $php;
	$user = (string) $user;
	if($script === '' || $script[0] !== '/'
		|| !retrackersIsSafeInsertStaticValue($script, false)
		|| !retrackersIsSafeInsertStaticValue($php, false)
		|| !retrackersIsSafeInsertStaticValue($user, true)
		|| preg_match('/^[a-z0-9_-]*$/D', $user) !== 1)
		return(false);

	$q = function($value)
	{
		return rTorrent::quoteCommandArg($value);
	};
	$handoff = '$cat=v1:original:,$d.state=,:,$d.local_id=';
	$clearAck = '$d.custom.set=' . RETRACKERS_RECOVERY_ACK . ',';
	$setMarker = '$d.custom.set=' . RETRACKERS_RECOVERY_MARKER . ',' . $q($handoff);
	$launch = '$execute.throw.bg={sh,' . $q($script) . ',' . $q($php)
		. ',$d.hash=,' . $q($user) . ',$d.custom=' . RETRACKERS_RECOVERY_MARKER . '}';
	$ordinary = 'cat=' . $q($clearAck) . ',' . $q($setMarker) . ',' . $q($launch);

	// Service torrents are metadata probes owned by rutracker_check. Only the
	// ordinary branch is guarded: transaction acknowledgement and the legacy
	// custom3 suppression handshake must remain unconditional.
	$markerGuard = 'branch=' . $q('d.custom=' . RETRACKERS_SERVICE_MARKER)
		. ',' . $q('cat=') . ',' . $q($ordinary);
	$ordinaryGuard = 'branch=' . $q('$equal=d.custom1=,cat=' . RETRACKERS_SERVICE_LABEL)
		. ',' . $q('cat=') . ',' . $q($markerGuard);
	$legacyOrOrdinary = 'branch=' . $q('$equal=d.custom3=,cat=1')
		. ',' . $q('d.custom3.set=') . ',' . $q($ordinaryGuard);

	return 'branch=' . $q('d.custom=' . RETRACKERS_RECOVERY_MARKER)
		. ',' . $q('d.custom.set=' . RETRACKERS_RECOVERY_ACK
			. ',$d.custom=' . RETRACKERS_RECOVERY_MARKER)
		. ',' . $q($legacyOrOrdinary);
}

function retrackersIsCompleteMissingHashFault($request)
{
	if(!is_object($request) || empty($request->fault))
		return(false);
	$message = isset($request->rawFaultString) && is_string($request->rawFaultString)
		? $request->rawFaultString
		: (isset($request->faultString) && is_string($request->faultString) ? $request->faultString : '');
	return in_array(strtolower($message), array(
		'info-hash not found',
		'info-hash not found.',
		'could not find info-hash',
		'could not find info-hash.',
		'invalid parameters: info-hash not found',
	), true);
}

function retrackersSafeHashForLog($hash)
{
	return preg_match('/^[0-9A-Fa-f]{40}$/D', (string) $hash) === 1
		? strtoupper((string) $hash)
		: '(invalid-hash)';
}
