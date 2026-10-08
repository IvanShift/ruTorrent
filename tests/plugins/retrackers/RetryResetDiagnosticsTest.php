<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
require_once(__DIR__ . '/../../php/ScgiToolPeer.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_DISPATCH_IMPORT_ONLY', true);
define('RETRACKERS_DEFER_READ_TIMEOUT', 0.04);
define('RETRACKERS_RESET_READ_TIMEOUT', 0.04);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/dispatch.php');
require_once(__DIR__ . '/../../../plugins/retrackers/retryreset.php');
require_once(__DIR__ . '/RetrackersWireFake.php');

// A queue marker read that faults without naming a missing torrent.
class RetryResetOtherFaultAdapter extends RetrackersWireQueueAdapter
{
	public function queueMarker($hash, &$failure = null)
	{
		$failure = 'native-original-rpc-unconfirmed';
		return(false);
	}

	public function nativeTargetAbsent($hash, &$failure = null)
	{
		$failure = null;
		return(null);
	}
}

// A torrent that the operator or a plugin removes while the tool lists the
// queue: after the rows read, or after its own Q read.
class RetryResetVanishingAdapter extends RetrackersWireQueueAdapter
{
	public $afterRows = array();
	public $afterMarker = array();
	public $faultRetry = array();

	public function queueRetryRows(&$failure = null)
	{
		$rows = parent::queueRetryRows($failure);
		foreach ($this->afterRows as $hash) unset($this->wire->torrents[$hash]);
		return($rows);
	}

	public function queueMarker($hash, &$failure = null)
	{
		$marker = parent::queueMarker($hash, $failure);
		if (in_array($hash, $this->afterMarker, true)) unset($this->wire->torrents[$hash]);
		return($marker);
	}

	public function queueRetryState($hash, &$failure = null)
	{
		if (!in_array($hash, $this->faultRetry, true)) return(parent::queueRetryState($hash, $failure));
		$failure = 'native-original-rpc-unconfirmed';
		return(false);
	}
}

/**
 * What tools/retrackers-retry-reset.php tells an operator when the job is
 * not where the command looked (round 4: R4-DK-01, R4-DK-07, N11).
 */
class RetryResetDiagnosticsTest extends TestCase
{
	private $dir = null;
	private $socket = null;

	public function setUp()
	{
		$this->dir = sys_get_temp_dir() . '/rt-reset-diag-' . getmypid() . '-' . uniqid();
		mkdir($this->dir, 0700);
		$this->socket = sys_get_temp_dir() . '/rrd-' . getmypid() . '-' . substr(sha1($this->dir), 0, 8) . '.sock';
		@unlink($this->socket);
	}

	public function tearDown()
	{
		@unlink($this->socket);
		foreach (array('/profile', '/errors.log') as $entry)
			@is_dir($this->dir . $entry) ? self::removeTree($this->dir . $entry) : @unlink($this->dir . $entry);
		@rmdir($this->dir);
	}

	private static function removeTree($path)
	{
		if (is_link($path) || !is_dir($path)) return(@unlink($path));
		foreach (scandir($path) as $entry)
			if ($entry !== '.' && $entry !== '..') self::removeTree($path . '/' . $entry);
		return(@rmdir($path));
	}

	// One terminal job of the profile $owner.
	private static function terminal($owner)
	{
		$wire = new RetrackersWireFake();
		$hash = str_repeat('A', 40);
		$wire->add($hash, str_repeat('C', 40), $owner, '1', false);
		$wire->torrents[$hash]['retry'] = 'r1:' . substr($wire->torrents[$hash]['queued'], 68) . ':6:0:source-not-ready';
		return(array($wire, $hash, new RetrackersWireQueueAdapter($wire)));
	}

	private function main($adapter, array $args, array $context = array())
	{
		$out = fopen('php://memory', 'w+');
		$err = fopen('php://memory', 'w+');
		$code = RetrackersRetryReset::main($args, $adapter, $out, $err, $context);
		rewind($out);
		rewind($err);
		return(array($code, stream_get_contents($out), stream_get_contents($err)));
	}

	public function testAHashWithoutATorrentIsNoSuchTorrentNotAnOutage()
	{
		list($wire, , $adapter) = self::terminal('');
		list($code, $out, $err) = $this->main($adapter, array(str_repeat('F', 40)));
		$this->assertTrue($code === 1 && strpos($err, 'refused: torrent-absent') !== false &&
			strpos($err, 'transport') === false && !isset($wire->payloads['branch']),
			'a well-formed hash this rTorrent does not hold is a refusal (exit 1), not an outage: ' . $code . ' ' . $err);
		list($code, , $err) = $this->main($adapter, array(str_repeat('F', 40), '--apply'));
		$this->assertTrue($code === 1 && strpos($err, 'refused: torrent-absent') !== false && !isset($wire->payloads['branch']),
			'--apply on it changes nothing');
		list($wire, $hash) = self::terminal('');
		list($code, , $err) = $this->main(new RetryResetOtherFaultAdapter($wire), array($hash));
		$this->assertTrue($code === 2 && strpos($err, 'refused: rpc-unconfirmed') !== false,
			'any other fault stays unconfirmed (exit 2): ' . $err);
		list($wire, $hash, $adapter) = self::terminal('');
		$wire->fail['d.custom'] = array_fill(0, 500, 'connect-failed');
		list($code, , $err) = $this->main($adapter, array($hash));
		$this->assertTrue($code === 2 && strpos($err, 'refused: transport-unconfirmed') !== false,
			'an outage is still unconfirmed (exit 2): ' . $err);
	}

	public function testASharedProfileIsUsedWhateverLoginIsNamed()
	{
		list(, $hash, $adapter) = self::terminal('');
		list($code, $out, $err) = $this->main($adapter, array('--user=alice'), array('shared_profile' => true));
		$this->assertTrue($code === 0 && strpos($out, $hash . ' r1:') === 0 && strpos($out, '1 terminal job(s)') !== false &&
			strpos($err, '$forbidUserSettings = true') !== false && strpos($err, '--user=alice') !== false,
			'every login shares the one profile, so --user=alice lists its job and says why: ' . $out . $err);
		list($code, $out, $err) = $this->main($adapter, array('--user=alice', $hash), array('shared_profile' => true));
		$this->assertTrue($code === 0 && strpos($out, 'would reset ' . $hash) === 0, 'the dry run finds it too: ' . $err);
		list($code, $out, $err) = $this->main($adapter, array($hash), array('shared_profile' => true));
		$this->assertTrue($code === 0 && $err === '', 'without --user there is nothing to explain');
		list($code, $out, $err) = $this->main($adapter, array('--user=alice'), array('shared_profile' => false));
		$this->assertTrue($code === 0 && strpos($out, '0 terminal job(s)') !== false && $err === '',
			'with per-user profiles --user=alice is alice\'s own profile');
	}

	public function testARefusedSocketIsNamedNotMistakenForAnOldDaemon()
	{
		list($wire, , $adapter) = self::terminal('');
		$wire->fail['system.listMethods'] = array('connect-failed');
		$server = stream_socket_server('unix://' . $this->socket, $errno, $error);
		$this->assertTrue(is_resource($server), 'the test socket listens: ' . $error);
		chmod($this->socket, 0);
		list($code, , $err) = $this->main($adapter, array(), array('scgi' => array('host' => 'unix://' . $this->socket, 'port' => 0)));
		fclose($server);
		$this->assertTrue($code === 2 && strpos($err, 'cannot reach rTorrent at unix://' . $this->socket) !== false &&
			strpos($err, 'native retrackers queue commands') === false, 'a socket that refuses is not an old daemon: ' . $err);
		if (function_exists('posix_geteuid') && posix_geteuid() !== 0)
			$this->assertTrue(strpos($err, 'permission denied for uid ' . posix_geteuid()) !== false &&
				strpos($err, "run the tool as the rTorrent daemon's user") !== false,
				'EACCES names the uid and what to do: ' . $err);
		@unlink($this->socket);
		$wire->fail['system.listMethods'] = array('connect-failed');
		list($code, , $err) = $this->main($adapter, array(), array('scgi' => array('host' => 'unix://' . $this->socket, 'port' => 0)));
		$this->assertTrue($code === 2 && strpos($err, 'cannot reach rTorrent at unix://' . $this->socket) !== false &&
			stripos($err, 'no such file') !== false, 'a missing socket is named as missing: ' . $err);
		$legacy = new RetrackersWireQueueAdapter(new RetrackersWireFake());
		$legacy->setFamily(1);
		list($code, , $err) = $this->main($legacy, array());
		$this->assertTrue($code === 2 && strpos($err, 'does not answer with the native retrackers queue commands') !== false,
			'a daemon without the native queue keeps its own message');
	}

	// The shipped tool takes the shared profile from ruTorrent's own
	// configuration ($forbidUserSettings, here through RU_FORBID_USER_SETTINGS).
	public function testTheShippedToolReadsTheSharedProfileFromConfiguration()
	{
		$queue = str_repeat('d', 32);
		$hash = str_repeat('A', 40);
		$queued = 'q1:' . hash('sha256', '') . ':' . $queue;
		$retry = 'r1:' . $queue . ':6:0:source-not-ready';
		$methods = array('d.retrackers.enqueue_original', 'd.retrackers.dequeue_original',
			'system.retrackers.original_claim', 'system.retrackers.worker_start', 'system.retrackers.dispatcher_start',
			'system.retrackers.queue_cursor', 'system.retrackers.queue_cursor.set', 'd.retrackers.preflight_stage',
			'd.retrackers.defer_original', 'schedule.if_absent');
		$respond = function ($request) use ($methods, $hash, $queued, $retry) {
			$method = ScgiToolPeer::method($request);
			$params = ScgiToolPeer::params($request);
			$strings = function (array $values) {
				$cells = '';
				foreach ($values as $value) $cells .= '<value><string>' . $value . '</string></value>';
				return('<array><data>' . $cells . '</data></array>');
			};
			if ($method === 'system.listMethods') return(ScgiToolPeer::scgiAnswer($strings($methods)));
			if ($method === 'd.multicall2')
				return(ScgiToolPeer::scgiAnswer('<array><data><value>' .
					$strings(array($hash, str_repeat('C', 40), $queued, $retry)) . '</value></data></array>'));
			if ($method === 'system.retrackers.original_claim') return(ScgiToolPeer::scgiAnswer('<string>none</string>'));
			if ($method === 'd.custom' && isset($params[1]) && $params[1] === 'retrackers-queued')
				return(ScgiToolPeer::scgiAnswer('<string>' . $queued . '</string>'));
			if ($method === 'd.custom' && isset($params[1]) && $params[1] === 'retrackers-retry')
				return(ScgiToolPeer::scgiAnswer('<string>' . $retry . '</string>'));
			return(null);
		};
		mkdir($this->dir . '/profile', 0700);
		$env = array('RU_SCGI_HOST' => 'unix://' . $this->socket, 'RU_SCGI_PORT' => '0',
			'RU_PROFILE_PATH' => $this->dir . '/profile', 'RU_LOG_FILE' => $this->dir . '/errors.log');
		$tool = dirname(__DIR__, 3) . '/tools/retrackers-retry-reset.php';
		list($code, $out, $err) = ScgiToolPeer::run($this->socket, $tool, array('--user=alice'), $respond,
			$env + array('RU_FORBID_USER_SETTINGS' => 'true'), false);
		$this->assertTrue($code === 0 && strpos($out, $hash . ' ' . $retry . ' claim=none') === 0 &&
			strpos($err, '$forbidUserSettings = true') !== false,
			'with a shared profile the login names that profile: exit ' . $code . ' ' . $out . $err);
		list($code, $out, $err) = ScgiToolPeer::run($this->socket, $tool, array('--user=alice'), $respond,
			$env + array('RU_FORBID_USER_SETTINGS' => 'false'), false);
		$this->assertTrue($code === 0 && strpos($out, '0 terminal job(s)') !== false && $err === '',
			'with per-user profiles --user=alice reads alice\'s own queue: exit ' . $code . ' ' . $out . $err);
	}

	// R4-RT-03: each row keeps its own classification; a torrent gone since the
	// rows were read is left out with a note instead of ending the listing.
	public function testAListingSkipsATorrentThatLeftAndClassifiesEachRow()
	{
		$wire = new RetrackersWireFake();
		$hashes = array(str_repeat('A', 40), str_repeat('B', 40), str_repeat('C', 40), str_repeat('D', 40));
		foreach ($hashes as $at => $hash) {
			$wire->add($hash, str_repeat((string)$at, 40), '', '1', false);
			$wire->torrents[$hash]['retry'] = 'r1:' . substr($wire->torrents[$hash]['queued'], 68) . ':6:0:source-not-ready';
		}
		$adapter = new RetryResetVanishingAdapter($wire);
		$adapter->afterRows = array($hashes[1]);
		$adapter->afterMarker = array($hashes[2]);
		$adapter->faultRetry = array($hashes[3]);
		list($code, $out, $err) = $this->main($adapter, array());
		$this->assertTrue($code === 0 && strpos($out, $hashes[0] . ' r1:') === 0 && strpos($out, 'claim=none') !== false &&
			strpos($out, $hashes[3] . ' r1:') !== false && strpos($out, 'not resettable now: rpc-unconfirmed') !== false &&
			strpos($out, $hashes[1]) === false && strpos($out, $hashes[2]) === false &&
			strpos($out, '2 terminal job(s)') !== false,
			'the listing finishes: a fault on one row is named on that row: ' . $out . $err);
		$this->assertTrue(strpos($err, 'note: ' . $hashes[1] . ' left rTorrent while the list was read; skipped') !== false &&
			strpos($err, 'note: ' . $hashes[2] . ' left rTorrent while the list was read; skipped') !== false &&
			strpos($err, 'unconfirmed') === false, 'each torrent that left is noted and skipped: ' . $err);
		list($wire, $hash, $adapter) = self::terminal('');
		$wire->fail['d.custom'] = array_fill(0, 500, 'connect-failed');
		list($code, $out, $err) = $this->main($adapter, array());
		$this->assertTrue($code === 2 && strpos($err, 'queue read unconfirmed: transport-unconfirmed') !== false &&
			strpos($out, 'terminal job(s)') === false, 'without answers there is no list: ' . $out . $err);
		list($wire, $hash) = self::terminal('');
		$adapter = new RetryResetVanishingAdapter($wire);
		$adapter->afterMarker = array($hash);
		list($code, , $err) = $this->main($adapter, array($hash));
		$this->assertTrue($code === 1 && strpos($err, 'refused: torrent-absent') !== false,
			'a single hash removed during its own reads is no such torrent: ' . $err);
	}
}
