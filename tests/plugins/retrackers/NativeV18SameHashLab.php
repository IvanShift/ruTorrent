<?php
// Run only in a disposable daemon container with a private session and socket.
if (!is_file('/.dockerenv')) {
	throw new RuntimeException('container-only v18 integration fixture');
}
require_once(__DIR__ . '/../../../php/rtorrent.php');
define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
$scgi_host = 'unix:///tmp/rr-mig.sock';
$scgi_port = 0;
$rpcTransferTimeOut = 5;
$rpcMaxResponseBytes = 67108864;
$tempDirectory = '/tmp/rr-mig/';
$tempDirectory_init_done = true;

function v18LabDecode($value)
{
	if (isset($value->array)) {
		$out = array();
		foreach ($value->array->data->value as $item) $out[] = v18LabDecode($item);
		return($out);
	}
	foreach (array('i8', 'i4', 'int', 'string', 'boolean') as $kind) {
		if (isset($value->$kind)) return((string)$value->$kind);
	}
	return((string)$value);
}

function v18LabRpc($method, $params, $allowFault = false)
{
	global $scgi_host, $scgi_port;
	$failure = null;
	$body = rSCGITransport::send($scgi_host, $scgi_port,
		retrackersBuildDirectRequest($method, $params), true, 0.25,
		$failure, 5, 67108864, rSCGITransport::RESPONSE_BODY);
	if ($body === null) throw new RuntimeException($method . ': ' . $failure);
	$xml = simplexml_load_string($body);
	if (!$xml) throw new RuntimeException($method . ': invalid XML');
	if (isset($xml->fault)) {
		if ($allowFault) return(array('fault' => true));
		throw new RuntimeException($method . ': RPC fault');
	}
	return(v18LabDecode($xml->params->param->value));
}

function v18LabBencode($value)
{
	if (is_int($value)) return('i' . $value . 'e');
	if (is_string($value)) return(strlen($value) . ':' . $value);
	if (array_is_list($value)) return('l' . implode('', array_map('v18LabBencode', $value)) . 'e');
	ksort($value, SORT_STRING);
	$out = 'd';
	foreach ($value as $key => $item) $out .= v18LabBencode((string)$key) . v18LabBencode($item);
	return($out . 'e');
}

function v18LabWaitHash($hash)
{
	for ($n = 0; $n < 100; $n++) {
		if (!is_array(v18LabRpc('d.hash', array($hash), true))) return;
		usleep(100000);
	}
	throw new RuntimeException('fixture torrent was not loaded');
}

function v18LabState($hash)
{
	$out = array();
	foreach (array('d.peers_min', 'd.peers_max', 'd.uploads_min', 'd.uploads_max',
		'd.downloads_min', 'd.downloads_max', 'd.views', 'd.up.total', 'd.down.total') as $method) {
		$out[$method] = v18LabRpc($method, array($hash));
	}
	$out['files'] = v18LabRpc('f.multicall', array($hash, '', 'f.path=', 'f.priority='));
	$out['extra'] = v18LabRpc('d.custom', array($hash, 'rr-v18-extra'));
	foreach (array('d.custom1', 'd.priority', 'd.throttle_name',
		'd.directory_base') as $method) {
		$out[$method] = v18LabRpc($method, array($hash));
	}
	return($out);
}

class V18LabAdapter extends RetrackersWorkerRpcAdapter
{
	public $lastFailure = null;
	public $nativeFinishCalls = 0;
	public function nativeFinishOriginal($hash, $tx, $incarnation, &$failure = null)
	{
		$this->nativeFinishCalls++;
		return(parent::nativeFinishOriginal($hash, $tx, $incarnation, $failure));
	}
	public function nativeCaptureOriginal($hash, $localId, $marker, $tx, $digest,
		&$failure = null)
	{
		$result = parent::nativeCaptureOriginal($hash, $localId, $marker,
			$tx, $digest, $failure);
		return(getenv('RR_PHP_LOSE_REPLY') === 'capture' ? false : $result);
	}
	public function executeDirectMutation($method, array $params, &$failure = null)
	{
		if ($method === 'load.normal' && getenv('RR_PHP_BAD_COMMAND') === '1') {
			foreach ($params as &$param) {
				if (is_string($param) && strncmp($param, 'd.custom1.set=', 14) === 0)
					$param = 'd.rr_invalid_setter=' . substr($param, 14);
			}
			unset($param);
		}
		$result = parent::executeDirectMutation($method, $params, $failure);
		return($method === 'load.normal' && getenv('RR_PHP_LOSE_REPLY') === 'load' ?
			false : $result);
	}
	public function nativeCommitSameHash($hash, $localId, $tx, &$failure = null)
	{
		$result = parent::nativeCommitSameHash($hash, $localId, $tx, $failure);
		return(getenv('RR_PHP_LOSE_REPLY') === 'commit' ? false : $result);
	}
	public function nativeReleaseSameHash($hash, $tx, $incarnation, &$failure = null)
	{
		$result = parent::nativeReleaseSameHash($hash, $tx, $incarnation, $failure);
		return(getenv('RR_PHP_LOSE_REPLY') === 'release' ? false : $result);
	}
	public function recordRecoveryFailure($failure)
	{
		$this->lastFailure = retrackersBoundedFailureReason($failure);
		throw new RuntimeException('worker HOLD: ' . $this->lastFailure);
	}
}

class V18LabNoChangeAuthority
{
	public function prepare($path, $hash, $additions, $deletions, $addToBegin, $snapshot = null)
	{
		return(array('ok' => true, 'changed' => false, 'original' => '',
			'candidate' => null));
	}
}

class V18LabAuthority
{
	public function prepare($path, $hash, $additions, $deletions, $addToBegin, $snapshot = null)
	{
		$source = file_get_contents('/tmp/rr-mig/source.torrent');
		$candidate = file_get_contents('/tmp/rr-mig/candidate.torrent');
		return(array('ok' => true, 'changed' => true, 'original' => $source,
			'candidate' => $candidate, 'candidate_scan' => array('info_hash' => $hash),
			'projection' => array('ok' => true, 'changed' => true)));
	}
}

$phase = $argv[1] ?? '';
$base = '/tmp/rr-mig';
$metadataPath = $base . '/worker-hk.json';
$info = array('files' => array(
	array('length' => 1, 'path' => array('a.bin')),
	array('length' => 1, 'path' => array('b.bin'))),
	'name' => 'v18-worker-hk', 'piece length' => 16384,
	'pieces' => sha1("\0\0", true));
$hash = strtoupper(sha1(v18LabBencode($info)));

if ($phase === 'capability') {
	$adapter = new V18LabAdapter();
	$failure = null;
	$ledger = $adapter->ensureLedgerExists($failure);
	$native = $ledger && $adapter->nativeOriginalCapability($failure);
	echo json_encode(array('family' => $adapter->getFamily(),
		'native' => $native, 'failure' => $failure)), "\n";
	if ($adapter->getFamily() === 2 && !$native) exit(2);
	exit(0);
}
if ($phase === 'legacy-daemon') {
	$version = v18LabRpc('system.client_version', array(''));
	$adapter = new V18LabAdapter();
	$failure = null;
	if (strpos($version, '0.9.8') !== 0 ||
		!$adapter->ensureLedgerExists($failure) || $adapter->getFamily() !== 1)
		throw new RuntimeException('expected 0.9.8 and family-1 v1 route');
	$phase = 'legacy-grammar';
}
if ($phase === 'legacy-grammar') {
	$action = retrackersBuildInsertAction('/plugin/run.sh', '/usr/bin/php85', 'alice', false);
	if (strpos($action, 'v1:original') === false ||
		strpos($action, 'd.retrackers.begin_original') !== false)
		throw new RuntimeException('legacy hook route changed');
	echo "legacy-v1-grammar-ok\n";
	exit(0);
}
if ($phase === 'stage' || $phase === 'stage-profile') {
	file_put_contents($base . '/source.torrent', v18LabBencode(array(
		'announce' => 'http://127.0.0.1:9/old', 'info' => $info)));
	file_put_contents($base . '/candidate.torrent', v18LabBencode(array(
		'announce' => 'http://127.0.0.1:9/new',
		'comment' => 'same info bytes', 'info' => $info)));
	v18LabRpc('load.normal', array('', $base . '/source.torrent',
		'd.directory.set=' . $base));
	v18LabWaitHash($hash);
	v18LabRpc('d.peers_min.set', array($hash, '19'));
	v18LabRpc('d.peers_max.set', array($hash, '71'));
	v18LabRpc('d.uploads_min.set', array($hash, '3'));
	v18LabRpc('d.uploads_max.set', array($hash, '9'));
	v18LabRpc('d.downloads_min.set', array($hash, '4'));
	v18LabRpc('d.downloads_max.set', array($hash, '12'));
	v18LabRpc('d.views.push_back_unique', array($hash, 'rr-v18-view'));
	v18LabRpc('f.priority.set', array($hash . ':f0', '2'));
	v18LabRpc('f.priority.set', array($hash . ':f1', '0'));
	v18LabRpc('d.update_priorities', array($hash));
	v18LabRpc('d.custom.set', array($hash, 'rr-v18-extra', 'keep-me'));
	v18LabRpc('d.custom1.set', array($hash, 'scalar-keep-me'));
	v18LabRpc('d.priority.set', array($hash, '2'));
	v18LabRpc('d.save_full_session', array($hash));
	$local = v18LabRpc('d.local_id', array($hash));
	if ($phase === 'stage-profile') {
		$failure = null;
		$ready = RetrackersLifecycleCoordinator::init('alice',
			'/rutorrent/app/plugins/retrackers/run.sh', '/usr/bin/php85',
			$failure, new RetrackersLifecycleRpcAdapter());
		if (!$ready) throw new RuntimeException('profile init: ' . $failure);
	} else {
		v18LabRpc('method.insert', array('', 'rr.receipts.v1', 'multi|private'));
	}
	$sourceI = v18LabRpc('d.incarnation', array($hash));
	$marker = v18LabRpc('d.retrackers.begin_original', array($hash, $local,
		hash('sha256', 'alice'), '0'));
	$tx = substr($marker, -32);
	v18LabRpc('method.set_key', array('', 'rr.receipts.v1', 'wp:' . $local, '1'));
	$before = v18LabState($hash);
	file_put_contents($metadataPath, json_encode(array('hash' => $hash,
		'local' => $local, 'sourceI' => $sourceI, 'marker' => $marker,
		'tx' => $tx, 'before' => $before)));
	echo json_encode(array('stage' => 'ready', 'hash' => $hash,
		'marker' => $marker, 'before' => $before)), "\n";
	exit(0);
}
$meta = is_file($metadataPath) ? json_decode(file_get_contents($metadataPath), true) : null;
if (!is_array($meta) || $meta['hash'] !== $hash)
	throw new RuntimeException('missing or foreign stage metadata');
if ($phase === 'readback') {
	echo json_encode(array(
		'hash' => v18LabRpc('d.hash', array($hash), true),
		'receipt' => v18LabRpc('system.retrackers.receipt', array('', $hash)),
		'prepared' => v18LabRpc('system.retrackers.prepared', array('', $hash)),
		'finalized' => v18LabRpc('system.retrackers.finalized', array('', $hash)),
		'ledger' => v18LabRpc('method.list_keys', array('', 'rr.receipts.v1'), true))), "\n";
	exit(0);
}
if ($phase === 'set-wh' || $phase === 'clear-wh') {
	v18LabRpc('method.set_key', $phase === 'set-wh' ?
		array('', 'rr.receipts.v1', 'wh:' . $meta['local'], '1') :
		array('', 'rr.receipts.v1', 'wh:' . $meta['local']));
	echo $phase, "\n";
	exit(0);
}
if ($phase === 'retire-direct' || $phase === 'retire-foreign') {
	$adapter = new RetrackersLifecycleRpcAdapter();
	$failure = null;
	if (!$adapter->ensureLedgerExists($failure))
		throw new RuntimeException('ledger init: ' . $failure);
	v18LabRpc('method.set_key', array('', 'rr.receipts.v1', 'wp:' . $meta['local'], '1'));
	$sourceI = $meta['sourceI'];
	if (v18LabRpc('d.incarnation', array($hash)) !== $sourceI)
		throw new RuntimeException('terminal source I changed');
	$expectedI = $phase === 'retire-foreign' ? str_repeat('f', 32) : $sourceI;
	$callback = RetrackersLifecycleCallbacks::retireNativePending(
		$hash, $meta['local'], $meta['tx'], $expectedI);
	$result = $adapter->executeLifecycleCallback($callback, $failure);
	$keys = $adapter->getLedgerKeys($failure);
	$hasWp = is_array($keys) && in_array('wp:' . $meta['local'], $keys, true);
	echo json_encode(array('result' => $result, 'wp' => $hasWp,
		'currentLocal' => v18LabRpc('d.local_id', array($hash)),
		'oldLocal' => $meta['local'], 'I' => $sourceI,
		'finalized' => v18LabRpc('system.retrackers.finalized', array('', $hash)))), "\n";
	if ($phase === 'retire-foreign' ? ($result !== 'CHANGED' || !$hasWp) :
		($result !== 'ACQUIRED' || $hasWp))
		throw new RuntimeException('terminal wp CAS result mismatch');
	exit(0);
}
if ($phase === 'inspect') {
	echo json_encode(array('oldLocal' => $meta['local'],
		'local' => v18LabRpc('d.local_id', array($hash)),
		'state' => v18LabRpc('d.state', array($hash)),
		'incarnation' => v18LabRpc('d.incarnation', array($hash)),
		'marker' => v18LabRpc('d.custom', array($hash, 'retrackers-recovery')),
		'ack' => v18LabRpc('d.custom', array($hash, 'retrackers-recovery-ack')),
		'wp' => v18LabRpc('method.list_keys', array('', 'rr.receipts.v1'), true))), "\n";
	exit(0);
}
if ($phase === 'finish-direct') {
	$incarnation = v18LabRpc('d.incarnation', array($hash));
	$result = v18LabRpc('d.retrackers.finish_original',
		array($hash, $meta['tx'], $incarnation));
	if ($result !== 'finalized') throw new RuntimeException('native finish did not finalize');
	echo json_encode(array('native' => $result,
		'wp' => v18LabRpc('method.list_keys', array('', 'rr.receipts.v1')),
		'finalized' => v18LabRpc('system.retrackers.finalized', array('', $hash)))), "\n";
	exit(0);
}
if ($phase === 'done' || $phase === 'done-active') {
	$failure = null;
	$result = RetrackersLifecycleCoordinator::done('alice', $failure,
		new RetrackersLifecycleRpcAdapter());
	$keys = v18LabRpc('method.list_keys', array('', 'rr.receipts.v1'));
	echo json_encode(array('done' => $result, 'failure' => $failure,
		'wp' => in_array('wp:' . $meta['local'], $keys, true))), "\n";
	$hasWp = in_array('wp:' . $meta['local'], $keys, true);
	if ($phase === 'done-active') {
		if ($result !== false || $failure !== 'hook-teardown-pending' || !$hasWp)
			throw new RuntimeException('active v2 pending lease was not held');
	} elseif (!$result || $hasWp) {
		throw new RuntimeException('terminal done left wp');
	}
	exit(0);
}
if ($phase === 'worker' || $phase === 'nochange' || $phase === 'cold-retry' ||
	$phase === 'foreign-terminal') {
	if ($phase === 'foreign-terminal') {
		if (!is_array(v18LabRpc('d.hash', array($hash), true))) {
			v18LabRpc('d.erase', array($hash));
			for ($attempt = 0; $attempt < 100; $attempt++) {
				if (is_array(v18LabRpc('d.hash', array($hash), true))) break;
				usleep(100000);
			}
			if (!is_array(v18LabRpc('d.hash', array($hash), true)))
				throw new RuntimeException('old same-hash source still loaded');
		}
		v18LabRpc('load.normal', array('', $base . '/source.torrent'));
		v18LabWaitHash($hash);
		if (v18LabRpc('d.incarnation', array($hash)) === $meta['sourceI'])
			throw new RuntimeException('foreign same-hash I was not created');
	}
	$adapter = new V18LabAdapter($hash);
	try {
		$result = RetrackersRecoveryCoordinator::run($hash, 'alice', $meta['marker'],
			'0', $meta['local'], $adapter, $phase === 'worker' ? new V18LabAuthority() :
			new V18LabNoChangeAuthority(), null,
			(object)array('list' => array(), 'todelete' => array(),
				'addToBegin' => false, 'dontAddPrivate' => false), '/usr/bin/php85');
	} catch (RuntimeException $error) {
		if ($phase === 'cold-retry' && $error->getMessage() ===
			'worker HOLD: native-original-finalized-before-worker') {
			echo "cold-original-retry-needed\n";
			exit(0);
		}
		if ($phase === 'foreign-terminal' && $error->getMessage() !==
			'worker HOLD: native-original-finalized-before-worker') {
			echo "foreign-incarnation-held: " . $error->getMessage() . "\n";
			exit(0);
		}
		echo 'worker-error=' . $error->getMessage() . "\n";
		throw $error;
	}
	if ($result !== true) throw new RuntimeException('worker did not complete ' . $phase);
	if ($phase === 'nochange') {
		$keys = v18LabRpc('method.list_keys', array('', 'rr.receipts.v1'));
		if (in_array('wp:' . $meta['local'], $keys, true) ||
			$adapter->nativeFinishCalls !== 1)
			throw new RuntimeException('native no-change wp/finish invariant failed');
		echo "nochange-wp-clean finish-calls=1\n";
	} else echo "worker-hk-complete\n";
	exit(0);
}
if ($phase === 'verify' || $phase === 'verify-rollback') {
	v18LabWaitHash($hash);
	$after = v18LabState($hash);
	$marker = v18LabRpc('d.custom', array($hash, 'retrackers-recovery'));
	$ack = v18LabRpc('d.custom', array($hash, 'retrackers-recovery-ack'));
	$finalized = v18LabRpc('system.retrackers.finalized', array('', $hash));
	$same = $meta['before'] === $after;
	$keys = v18LabRpc('method.list_keys', array('', 'rr.receipts.v1'), true);
	if (is_array($keys) && isset($keys['fault'])) $keys = array();
	if (in_array('wp:' . $meta['local'], $keys, true) ||
		in_array('wa:' . $meta['tx'], $keys, true))
		throw new RuntimeException('worker ledger keys survive terminal receipt');
	$matchingReceipt = $finalized === 'finalized:' . $meta['tx'] . ':' .
		v18LabRpc('d.incarnation', array($hash));
	if (!$same || $marker !== '' || $ack !== '' || !$matchingReceipt)
		throw new RuntimeException('settings or terminal T/I changed: ' . json_encode(array(
			'same' => $same, 'marker' => $marker, 'ack' => $ack,
			'finalized' => $finalized)));
	if ($phase === 'verify' && v18LabRpc('d.incarnation', array($hash)) === $meta['sourceI'])
		throw new RuntimeException('candidate was not loaded');
	if ($phase === 'verify-rollback' &&
		v18LabRpc('d.incarnation', array($hash)) !== $meta['sourceI'])
		throw new RuntimeException('cold rollback did not restore source incarnation');
	echo json_encode(array('phase' => $phase, 'same' => true,
		'finalized' => $finalized)), "\n";
	exit(0);
}
throw new RuntimeException('unknown phase');
