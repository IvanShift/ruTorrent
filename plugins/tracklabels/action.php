<?php

require_once( dirname(__FILE__)."/../../php/util.php" );
require_once( dirname(__FILE__)."/../../php/Snoopy.class.inc" );

eval(FileUtil::getPluginConf('tracklabels'));

function bad($message) {
	http_response_code(400);
	header('Content-Type: text/plain; charset=UTF-8');
	header('X-Content-Type-Options: nosniff');
	echo UTF::display($message);
	exit;
}

// Definitive misses use a day-long marker. The n-th uncertain failure in a row
// waits min(5 min * 2^(n-1), 24 h). Both remain quiet for the browser and logs.
if (!defined('TRACKLABELS_FAVICON_MISS_TTL'))
	define('TRACKLABELS_FAVICON_MISS_TTL', 24 * 60 * 60);
if (!defined('TRACKLABELS_FAVICON_RETRY_TTL'))
	define('TRACKLABELS_FAVICON_RETRY_TTL', 5 * 60);
if (!defined('TRACKLABELS_FAVICON_STAGE_TTL'))
	define('TRACKLABELS_FAVICON_STAGE_TTL', 60 * 60);
// The WebUI asks for every tracker's favicon at once, and each fetch holds a
// PHP worker; unbounded, one page load exhausted the PHP-FPM pool.
if (!defined('TRACKLABELS_FAVICON_FETCH_SLOTS'))
	define('TRACKLABELS_FAVICON_FETCH_SLOTS', 2);
// Total HTTP transfer budget, including redirects and the PNG fallback.
// Synchronous DNS admission can return later; it cannot restart this budget.
if (!defined('TRACKLABELS_FAVICON_FETCH_TIMEOUT'))
	define('TRACKLABELS_FAVICON_FETCH_TIMEOUT', 3);
// Far past the attempt whose delay reaches the day-long cap.
if (!defined('TRACKLABELS_FAVICON_RETRY_ATTEMPTS_MAX'))
	define('TRACKLABELS_FAVICON_RETRY_ATTEMPTS_MAX', 16);
// Two tabs can pass admission before either writes a marker. Coalesce their
// near-simultaneous failures; a later retry still advances the backoff.
if (!defined('TRACKLABELS_FAVICON_RETRY_DEDUP_TTL'))
	define('TRACKLABELS_FAVICON_RETRY_DEDUP_TTL', 5);

function favicon_retry_delay($attempts)
{
	$delay = TRACKLABELS_FAVICON_RETRY_TTL;
	for ($attempt = 1; $attempt < $attempts && $delay < TRACKLABELS_FAVICON_MISS_TTL; $attempt++)
		$delay *= 2;
	return min($delay, TRACKLABELS_FAVICON_MISS_TTL);
}

// The marker holds the number of uncertain failures in a row. An empty or
// damaged marker counts as one; 1cb3a276 stored the delay in seconds
// instead, which resumes at the attempt that produced that delay.
function favicon_retry_attempts($retry_name)
{
	$value = @file_get_contents($retry_name, false, null, 0, 8);
	if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,6}$/D', $value))
		return 1;
	$value = (int)$value;
	if ($value <= TRACKLABELS_FAVICON_RETRY_ATTEMPTS_MAX)
		return $value;
	$attempts = 1;
	while ($attempts < TRACKLABELS_FAVICON_RETRY_ATTEMPTS_MAX && favicon_retry_delay($attempts) < $value)
		$attempts++;
	return $attempts;
}

// Takes one of the fixed fetch slots without waiting. A slot is an flock, so the
// kernel releases it when its holder exits, however it exits. Returns the held
// handle, or false: $fault is false when the slots are busy, and true when no
// slot file could be opened or locked at all.
function favicon_fetch_slot($dir, &$fault)
{
	$fault = true;
	for ($slot = 0; $slot < TRACKLABELS_FAVICON_FETCH_SLOTS; $slot++) {
		// curl must not inherit the flock and keep a dead PHP worker's slot busy.
		$lock = @fopen($dir . '/.fetch-slot-' . $slot . '.lock', 'c+be');
		if ($lock === false)
			continue;
		if (flock($lock, LOCK_EX | LOCK_NB, $wouldBlock))
			return $lock;
		fclose($lock);
		if ($wouldBlock)
			$fault = false;
	}
	return false;
}

function favicon_miss_is_fresh($miss_name, $ttl = TRACKLABELS_FAVICON_MISS_TTL)
{
	clearstatcache(true, $miss_name);
	$time = is_file($miss_name) ? filemtime($miss_name) : false;
	if ($time === false)
		return false;
	$age = time() - $time;
	// A marker dated in the future (clock step) is stale, so it cannot pin a miss.
	return $age >= 0 && $age < $ttl;
}

function favicon_record_miss($miss_name, $retry = false)
{
	$dir = dirname($miss_name);
	if (!is_dir($dir))
		FileUtil::makeDirectory($dir);
	$written = true;
	if ($retry) {
		$existed = is_file($miss_name);
		$lock = @fopen($miss_name, 'c+b');
		if ($lock === false)
			$written = false;
		else {
			try {
				$written = flock($lock, LOCK_EX);
				if ($written) {
					$stat = fstat($lock);
					$age = time() - $stat['mtime'];
					if ($stat['size'] > 0 && $age >= 0 && $age < TRACKLABELS_FAVICON_RETRY_DEDUP_TTL)
						return;
					$attempts = $existed || $stat['size'] > 0
						? min(favicon_retry_attempts($miss_name) + 1, TRACKLABELS_FAVICON_RETRY_ATTEMPTS_MAX) : 1;
					$value = (string)$attempts;
					$written = fwrite($lock, $value) === strlen($value)
						&& ftruncate($lock, strlen($value)) && fflush($lock)
						&& @touch($miss_name, time());
				}
			} finally {
				fclose($lock);
			}
		}
	}
	// An explicit time keeps the marker on the clock that favicon_miss_is_fresh() reads.
	if (!is_dir($dir) || !$written || (!$retry && !@touch($miss_name, time())))
		FileUtil::toLog('tracklabels: favicon miss cache write failed');
}

function favicon_private_file($stat, $uid)
{
	return is_array($stat) && ($stat['mode'] & 0170000) === 0100000
		&& ($stat['mode'] & 07777) === 0600 && $stat['uid'] === $uid && $stat['nlink'] === 1;
}

function favicon_same_file($first, $second)
{
	return is_array($first) && is_array($second)
		&& $first['dev'] === $second['dev'] && $first['ino'] === $second['ino'];
}

function favicon_reap_stages($dir, $uid)
{
	foreach (glob($dir . '/.favicon-stage-*.json') ?: array() as $record) {
		if (!preg_match('/^\.favicon-stage-[A-Za-z0-9]{6,32}\.json$/D', basename($record)))
			continue;
		clearstatcache(true, $record);
		$before = @lstat($record);
		if (!favicon_private_file($before, $uid) || $before['size'] > 512)
			continue;
		$lock = @fopen($record, 'r+b');
		if ($lock === false)
			continue;
		try {
			// The creator holds this lock before attestation and until publication.
			// Age alone never authorizes deletion of a live worker's stage.
			if (!flock($lock, LOCK_EX | LOCK_NB) || !favicon_same_file($before, fstat($lock)))
				continue;
			$data = json_decode(stream_get_contents($lock, 513), true);
			$name = basename($record, '.json');
			if (!is_array($data) || array_keys($data) !== array('version', 'name', 'dev', 'ino', 'uid')
				|| $data['version'] !== 1 || $data['name'] !== $name || $data['uid'] !== $uid)
				continue;
			$stage = $dir . '/' . $name;
			clearstatcache(true, $stage);
			$stat = @lstat($stage);
			$now = time();
			if ($now - $before['mtime'] < TRACKLABELS_FAVICON_STAGE_TTL)
				continue;
			if ($stat === false) {
				// A successful rename can precede worker death. Prove absence
				// with a readable parent listing, rather than guessing from lstat failure.
				$entries = @scandir($dir);
				clearstatcache(true, $record);
				if (is_array($entries) && !in_array($name, $entries, true)
					&& favicon_same_file($before, @lstat($record)) && !@unlink($record))
					FileUtil::toLog('tracklabels: favicon stage cleanup failed');
				continue;
			}
			if (!favicon_private_file($stat, $uid) || $stat['dev'] !== $data['dev'] || $stat['ino'] !== $data['ino']
				|| $now - $stat['mtime'] < TRACKLABELS_FAVICON_STAGE_TTL)
				continue;
			clearstatcache(true, $stage);
			clearstatcache(true, $record);
			if (!favicon_same_file($stat, @lstat($stage)) || !favicon_same_file($before, @lstat($record)))
				continue;
			if (!@unlink($stage) || !@unlink($record))
				FileUtil::toLog('tracklabels: favicon stage cleanup failed');
		} finally {
			fclose($lock);
		}
	}
}

function favicon_stage($dir, &$lock)
{
	$lock = null;
	$stage = is_dir($dir) ? @tempnam($dir, '.favicon-stage-') : false;
	// tempnam may fall back to the system temp directory on a write fault.
	if ($stage !== false && dirname($stage) !== realpath($dir)) {
		@unlink($stage);
		$stage = false;
	}
	if ($stage === false)
		return false;
	$record = $stage . '.json';
	$lock = @fopen($record, 'x+b');
	if ($lock !== false) {
		if (@chmod($record, 0600) && flock($lock, LOCK_EX | LOCK_NB)) {
			$stat = @lstat($stage);
			$owner = fstat($lock);
			if (favicon_private_file($stat, $owner['uid'])) {
				$data = json_encode(array('version' => 1, 'name' => basename($stage),
					'dev' => $stat['dev'], 'ino' => $stat['ino'], 'uid' => $stat['uid']));
				// No remote bytes are written until the exact stage has an ownership record.
				if (fwrite($lock, $data) === strlen($data) && fflush($lock))
					return $stage;
			}
		}
		@unlink($record);
		fclose($lock);
	}
	$lock = null;
	@unlink($stage);
	return false;
}

function favicon_finish_stage($stage, $lock)
{
	@unlink($stage);
	@unlink($stage . '.json');
	fclose($lock);
}

$basepath = realpath(FileUtil::getSettingsPath());
$knownAliases = [
	'app'       => ['apps', 'applications', 'программы', 'трограммы'],
	'book'      => ['books', 'livres', 'книги'],
	'film'      => ['films', 'фильм', 'фильмы'],
	'game'      => ['jeux', 'игра'],
	'game2'     => ['games', 'игры'],
	'image'     => ['images', 'изображения', 'картинки', 'фото'],
	'kid'       => ['kids', 'enfants', 'детское', 'детям'],
	'movie'     => ['movies', 'serial', 'serials', 'сериал', 'сериалы'],
	'music'     => ['musique', 'музыка'],
	'other'     => ['others', 'autres', 'другое'],
	'pack'      => ['packs'],
	'porn'      => ['porno', 'pornos', 'порно'],
	'serie'     => ['series', 'tv', 'тв'],
	'software'  => ['softwares', 'logiciels', 'софт'],
	'sport'     => ['sports', 'спорт'],
	'video'     => ['videos', 'видео'],
];

function get_alias($name)
{
	global $knownAliases, $labelAliases;
	foreach (array_merge_recursive($knownAliases, (array) $labelAliases) as $alias => $aliases) {
		if ((is_array($aliases) && in_array($name, $aliases)) || $aliases === $name) {
			$name = $alias;
			break;
		}
	}
	return $name;
}

function image_name($prefix, $req_field, $values = null)
{
	global $basepath;
	$values = $values === null ? $_REQUEST : $values;
	$res = null;
	if (isset($values[$req_field])) {
		$dir = $basepath . '/' . $prefix;
		if (!is_string($values[$req_field]))
			bad('Invalid ' . $req_field);
		// PHP already decoded the query/form value. A second pass changes
		// literal percent sequences into a different icon target.
		$name = $values[$req_field];
		if (strpos($name, "\0") !== false)
			bad('Invalid ' . $req_field);
		if ($prefix === 'trackers' && strpos($name, '/') !== false)
			bad('Invalid ' . $req_field);
		if(UTF::isValid($name))
			$name = get_alias(function_exists('mb_strtolower')
				? mb_strtolower($name, 'utf-8')
				: strtolower($name));
		$res = $dir . '/' . $name . '.png';
		if (!strlen($name)
			|| strlen($name) > 200
			|| strpos($res, '/./') !== false
			|| strpos($res, '/../') !== false
			|| strpos($res, '//') !== false)
			// restrict to sane sub-directory paths
			bad('Invalid ' . $req_field . ': '.$name);
	}
	return $res;
}

$fetchRequested = isset($_POST['fetch']) && $_POST['fetch'] === '1';
if ($fetchRequested && (isset($_POST['upload']) || isset($_POST['delete']))) {
    error_log('tracklabels: refused conflicting icon actions');
    bad('Conflicting icon actions');
}
$mutationRequested = $fetchRequested || isset($_POST['upload']) || isset($_POST['delete']);
if ($mutationRequested && (!isset($_SERVER['REQUEST_METHOD']) ||
    $_SERVER['REQUEST_METHOD'] !== 'POST' || !Requests::isSameOriginAjaxRequest())) {
    error_log('tracklabels: refused mutation: same-origin AJAX POST required');
    header('HTTP/1.0 403 Forbidden', true, 403);
    die('Forbidden');
}
$isTracker = $fetchRequested;
$png_name = $fetchRequested ? image_name('trackers', 'tracker', $_POST)
    : image_name('labels', 'label');
if ($png_name === null && !$fetchRequested) {
    $png_name = image_name('trackers', 'tracker');
    $isTracker = $png_name !== null;
}
if ($fetchRequested && $png_name === null)
    bad('Missing tracker');

if ($png_name !== null) {
	$targetdir = dirname($png_name);
	if (isset($_POST['delete'])) {
		@unlink($png_name);
		// delete empty sub-directories
		while (Utility::str_starts_with(dirname($targetdir), $basepath.'/') && @rmdir($targetdir)) {
			$targetdir = dirname($targetdir);
		}
		exit;
	} else if (isset($_POST['upload'])) {
		$upload = isset($_FILES['uploadfile']) && is_array($_FILES['uploadfile'])
			? $_FILES['uploadfile'] : array();
		if (!isset($upload['tmp_name'], $upload['error'])
			|| !is_string($upload['tmp_name']) || $upload['tmp_name'] === ''
			|| strpos($upload['tmp_name'], "\0") !== false
			|| $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']))
			bad('Invalid image upload');
		$tempname = $upload['tmp_name'];

		if (!is_dir($targetdir))
			FileUtil::makeDirectory($targetdir);
		if (!is_dir($targetdir))
			bad('Failed to create dir: ' . $targetdir);
		if (mime_content_type($tempname) !== 'image/png')
			bad('Only image/png supported!');
		if (!move_uploaded_file($tempname, $png_name))
			bad('Image upload failed!');
		exit;
	} else {
		SendFile::send($png_name, 'image/png');
		SendFile::send(dirname(__FILE__).substr($png_name, strlen($basepath)), 'image/png');


		$cacheFault = false;
		$fetchBusy = false;
		// The name below is fetched from as a host, so it has to be one: a name
		// that is not a host name gets the placeholder instead of a request.
		if ($isTracker && Utility::isHostname(basename($png_name, '.png'))) {
			$tracker = basename($png_name, '.png');
			$ico_name = $targetdir . '/' . $tracker . '.ico';
			SendFile::send($ico_name, 'image/x-icon');
			SendFile::send(dirname(__FILE__).substr($ico_name, strlen($basepath)), 'image/x-icon');

			$miss_name = $targetdir . '/' . $tracker . '.miss';
			$retry_name = $targetdir . '/' . $tracker . '.retry';
			if ($fetchRequested && !favicon_miss_is_fresh($miss_name)
				&& !favicon_miss_is_fresh($retry_name, favicon_retry_delay(favicon_retry_attempts($retry_name)))) {
				if (!is_dir($targetdir))
					FileUtil::makeDirectory($targetdir);
				$slot = favicon_fetch_slot($targetdir, $slotFault);
				if ($slot === false) {
					// Neither outcome writes a marker: a busy host keeps its turn,
					// and a local fault is not remembered as the host's miss.
					if ($slotFault)
						FileUtil::toLog('tracklabels: favicon fetch slot unavailable');
					$cacheFault = $slotFault;
					$fetchBusy = !$slotFault;
				} else {
					$published = false;
					try {
						ignore_user_abort(true);
						set_time_limit(0);
						// A shared lock file can have another owner. Only this process's
						// effective UID authorizes cleanup of its private staged bytes.
						$uid = Permission::effectiveUid();
						if ($uid !== false)
							favicon_reap_stages($targetdir, $uid);

						$client = new Snoopy();
						$client->block_private = true;
						$client->private_allowlist = array();
						$client->read_timeout = TRACKLABELS_FAVICON_FETCH_TIMEOUT;
						$client->setRequestDeadline(TRACKLABELS_FAVICON_FETCH_TIMEOUT);
						$retryNeeded = false;
						foreach (['favicon.ico', 'favicon.png'] as $favicon) {
							$url = $client->linkencode("http://{$tracker}/{$favicon}");
							$fetched = @$client->fetchComplex($url);
							if ($fetched && $client->status == 200) {
								// Readers never see a partial write or unvalidated remote content.
								$stage = favicon_stage($targetdir, $stageLock);
								if ($stage === false || @file_put_contents($stage, $client->results) !== strlen($client->results)) {
									if ($stage !== false)
										favicon_finish_stage($stage, $stageLock);
									FileUtil::toLog('tracklabels: favicon cache write failed');
									$cacheFault = true;
									break;
								}
								$mime = @mime_content_type($stage);
								if (!is_string($mime) || strpos($mime, 'image/') !== 0)
									favicon_finish_stage($stage, $stageLock);
								else if (!@rename($stage, $ico_name)) {
									favicon_finish_stage($stage, $stageLock);
									FileUtil::toLog('tracklabels: favicon cache write failed');
									$cacheFault = true;
									break;
								}
								else {
									favicon_finish_stage($stage, $stageLock);
									@unlink($miss_name);
									@unlink($retry_name);
									$published = true;
									break;
								}
							} else {
								$nonPublic = strpos($client->error, 'Refusing to fetch: host ') === 0;
								// Snoopy cannot distinguish NXDOMAIN from a resolver outage, and
								// a target refusal can leave the previous request's status intact.
								$terminalHttp = $client->status >= 400 && $client->status < 500
									&& !in_array((int)$client->status, array(408, 429), true);
								if (!$nonPublic && ($client->error !== ''
									|| !$terminalHttp))
									$retryNeeded = true;
								// No HTTP answer at all (target refused, DNS, connect, timeout or
								// reset): the other path is on the same host, so do not wait twice.
								if (!$fetched && $client->response_code === '')
									break;
							}
						}
						// No favicon there is an expected outcome: remember it, do not log it.
						if (!$cacheFault && !$published) {
							favicon_record_miss($retryNeeded ? $retry_name : $miss_name, $retryNeeded);
							if (!$retryNeeded)
								@unlink($retry_name);
						}
					} finally {
						flock($slot, LOCK_UN);
						fclose($slot);
					}
					if ($published)
						SendFile::send($ico_name, 'image/x-icon');
				}
			}
		}
	}
}

if ($fetchRequested) {
    if ($fetchBusy) {
        // Both WebUIs ask again after a 429, 30-45 s later and up to 20 times
        // per page, without using up the two retries of a failed fetch
        // (tracklabels/init.js, mobile/init.js); after a 404 they would not
        // ask again until the next page load.
        header('Retry-After: 30');
        http_response_code(429);
        exit('Favicon fetch busy');
    }
    // 404 settles the miss for the browser; only a local cache fault stays retryable.
    http_response_code($cacheFault ? 503 : 404);
    exit('Favicon unavailable');
}
SendFile::send('./trackers/unknown.png', 'image/png');
