<?php

require_once(dirname(__DIR__, 2).'/php/cli-only.php');
rutorrent_require_cli(__FILE__);
if(!isset($argv) || count($argv) !== 7) exit(2);
list($script, $user, $hash, $incarnation, $generation, $nonce, $digest) = $argv;
if(!preg_match('/^[a-z0-9_-]{0,128}$/D', $user)
	|| !preg_match('/^[A-F0-9]{40}$/D', $hash)
	|| !preg_match('/^[a-f0-9]{32}$/D', $incarnation)
	|| !preg_match('/^[a-f0-9]{16}$/D', $generation) || $generation === '0000000000000000'
	|| !preg_match('/^[a-f0-9]{32}$/D', $nonce)
	|| !preg_match('/^[a-f0-9]{64}$/D', $digest)) exit(2);
$_SERVER['REMOTE_USER'] = $user;
require_once(__DIR__.'/update.php');
$intent = ErasedataManifestCodec::canonicalNativeIntent(array('incarnation' => $incarnation,
	'generation' => $generation, 'user' => $user, 'nonce' => $nonce, 'digest' => $digest));
// Native owns and attests this inherited open file description. Reopening a
// pathname would create a different lock; PHP and every helper retain FD198.
$lease = @fopen('php://fd/198', 'r+');
$stat = is_resource($lease) ? @fstat($lease) : false;
if(!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) exit(1);
try
{
	if(erasedataNativeCall('collect_begin', $hash, $intent, getmypid()) !== 'active') exit(1);
	if(!ErasedataFilesystemOps::holdDeletionLease($lease)) exit(1);
	$listPath = erasedataQueuePath();
	if(!erasedataEnsureQueueDirectory($listPath)) exit(1);
	$service = erasedataCollectorService(new ErasedataFilesystemOps());
	$completed = $service->runNative($listPath, $hash, $intent);
	if(!is_array($completed))
	{
		// runNative records the classified exact retention under the hash lock.
		exit(1);
	}
	if(!erasedataNativeFinishIntent($hash, $intent, getmypid()))
	{
		erasedataNativeRetryRecordCompleted($completed,'finish-unresolved');
		exit(1);
	}
	// Only the inert queue metadata remains; no payload action follows finish.
	erasedataNativeRetryForgetCompleted($completed,$intent['user']);
	erasedataNativeRemoveCompleted($completed, new ErasedataFilesystemOps());
}
finally
{
	ErasedataFilesystemOps::holdDeletionLease(null);
	// Never LOCK_UN: a surviving descendant may hold the SAME description.
	if(is_resource($lease)) @fclose($lease);
}
