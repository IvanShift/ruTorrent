<?php

// Shared string markers keep retrackers independent from rutracker_check's PHP
// classes while still protecting its short-lived metadata service torrents.
define('RETRACKERS_SERVICE_LABEL', '.chk-meta');
define('RETRACKERS_SERVICE_MARKER', 'chk-meta-old');

function retrackersIsServiceTorrent($label, $marker)
{
	return rawurldecode((string) $label) === RETRACKERS_SERVICE_LABEL
		|| (string) $marker !== '';
}

function retrackersGuardInsertAction($action)
{
	$labelIsNotService = '$' . getCmd('not') . '=$' . getCmd('equal')
		. '={$' . getCmd('d.get_custom1') . '=,$' . getCmd('cat')
		. '=' . RETRACKERS_SERVICE_LABEL . '}';
	$markerIsEmpty = '$' . getCmd('not') . '=$' . getCmd('d.get_custom')
		. '=' . RETRACKERS_SERVICE_MARKER;
	$nonService = '$' . getCmd('and') . '={"' . $labelIsNotService
		. '","' . $markerIsEmpty . '"}';
	return getCmd('branch') . '=' . $nonService . ',"' . $action . '"';
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
