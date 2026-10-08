<?php

/**
 * The erasedata cleanup queue, recorded instead of run, for RealStackDriver's
 * children. plugins/erasedata/removewithdata.php defines each of these only
 * when it is not defined yet, so loading this file first replaces exactly the
 * queue and the collector kick. The ownership scan
 * (erasedataCleanupOtherOwnerSnapshot) and the path identity helpers stay
 * real: they read the daemon and the filesystem, which is what the replacement
 * cases look at.
 */

if (!defined('ERASEDATA_CLEANUP_NONE')) define('ERASEDATA_CLEANUP_NONE', 'none');
if (!defined('ERASEDATA_CLEANUP_READY')) define('ERASEDATA_CLEANUP_READY', 'ready');
if (!defined('ERASEDATA_CLEANUP_RETRY')) define('ERASEDATA_CLEANUP_RETRY', 'retry');

function rsRecordErasedata($name, $arguments)
{
	global $rsErasedataLog;
	$calls = is_file($rsErasedataLog) ? json_decode(file_get_contents($rsErasedataLog), true) : array();
	$calls[] = array('name' => $name, 'arguments' => $arguments);
	file_put_contents($rsErasedataLog, json_encode($calls, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
}

function erasedataPrepareObsoleteCleanup($oldHash, $newHash, $marker, $record, $base, array $entries)
{
	rsRecordErasedata(__FUNCTION__, func_get_args());
	return array('old_hash' => strtoupper($oldHash), 'new_hash' => strtoupper($newHash),
		'marker' => $marker, 'replacement_record' => $record, 'base' => $base, 'entries' => $entries);
}

function erasedataArmObsoleteCleanup($job)
{
	rsRecordErasedata(__FUNCTION__, array($job));
	return true;
}

function erasedataPublishObsoleteCleanup(&$job)
{
	rsRecordErasedata(__FUNCTION__, array($job));
	return true;
}

function erasedataCancelObsoleteCleanup(&$job)
{
	rsRecordErasedata(__FUNCTION__, array($job));
	return true;
}

function erasedataRecoverObsoleteCleanup($oldHash, $newHash, $marker, $record, &$reason = null)
{
	rsRecordErasedata(__FUNCTION__, func_get_args());
	return ERASEDATA_CLEANUP_NONE;
}

function erasedataCancelObsoleteCleanupGeneration($oldHash, $newHash, $marker, $record, &$reason = null)
{
	rsRecordErasedata(__FUNCTION__, func_get_args());
	return ERASEDATA_CLEANUP_NONE;
}

function erasedataKickCollector($oldHash)
{
	rsRecordErasedata(__FUNCTION__, func_get_args());
	return true;
}
