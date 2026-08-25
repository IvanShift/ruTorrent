<?php

require_once( "../../php/Snoopy.class.inc" );
require_once( "../../php/rtorrent.php" );
require_once( "../../php/util.php" );

require_once( "trackers/rutracker.php" );
require_once( "trackers/anidub.php" );
require_once( "trackers/kinozal.php" );
require_once( "trackers/nnmclub.php" );
require_once( "trackers/tapocheknet.php" );
require_once( "trackers/tfile.php" );
require_once( "trackers/toloka.php" );
require_once( dirname(__FILE__) . "/runstate.php" );
require_once( dirname(__FILE__) . "/../erasedata/removewithdata.php" );
require_once( "metafetch.php" );

eval(FileUtil::getPluginConf( "rutracker_check" ));

class ruTrackerChecker
{
	const STE_INPROGRESS		= 1;
	const STE_UPDATED		= 2;
	const STE_UPTODATE		= 3;
	const STE_DELETED		= 4;
	const STE_CANT_REACH_TRACKER	= 5;
	const STE_ERROR			= 6;
	const STE_NOT_NEED		= 7;
	const STE_IGNORED		= 8;
	const STE_META_PENDING		= 9;
	const STE_ABSORBED		= 10;

	// awaitMetadata()'s polling interval, and the default seconds it waits
	// when $rutrackerMetaWait is not set.
	const METADATA_POLL_US		= 500000;
	const METADATA_WAIT_DEFAULT	= 10;

	// And its upper bound. conf.php promises out-of-range values are clamped,
	// but this one had a floor only. The wait happens INSIDE the per-hash claim,
	// whose lease is MAX_LOCK_TIME, so a mistyped value can outlive its own
	// claim and hand the hash to a second worker while the first is still in
	// it. A minute is already sixty times the median metadata wait measured on
	// the live fleet, so anything past it is a typo, not a preference.
	const METADATA_WAIT_MAX		= 60;

	// Not a status: a handler answers this when it has no data to judge by and
	// the verdict already stored must be left alone. Negative on purpose -- the
	// stored values are the STE_* above, and 0 means "never checked", both of
	// which a handler may legitimately want restored.
	const STE_UNCHANGED		= -1;
	// Not a stored status either: a handler returns this only when the URL it
	// received is outside its jurisdiction. The dispatcher may then ask another
	// handler; a real STE_NOT_NEED verdict is terminal and must not be overwritten
	// by a tracker from a cross-seed announce row.
	const STE_DECLINED		= -2;

	// chk-msg carries a machine token, never prose: "<token>|<parameter>".
	// The sentence itself is localised in the browser (init.js renders
	// theUILang.chkMessages[token] with the parameter substituted for its
	// %s), so a message written here reads in the user's own language
	// instead of the language of whoever wrote the handler. Anything that
	// is only of interest while debugging goes to logDebug() instead, and
	// clears chk-msg.
	const CHKMSG_SUPERSEDED		= 'superseded';		// param: 40-hex successor hash
	const CHKMSG_DELETING		= 'deleting';		// param: "N/M" confirmation cycles
	const CHKMSG_TOPIC_STATUS	= 'topic-status';	// param: dump tor_status
	const CHKMSG_FUSE		= 'fuse';		// param: announce host
	const CHKMSG_ABSORBED		= 'absorbed';		// param: topic id

	const MAX_LOCK_TIME		= 900;	// 15 min

	// load_raw inserts the torrent from a deferred rTorrent event-loop task,
	// so waiting for the staged copy to appear is the only wait in the
	// replacement transaction; every other command is synchronous.
	const LOAD_WAIT_ATTEMPTS	= 40;
	const LOAD_WAIT_DELAY_US	= 50000;
	const REPLACEMENT_MARKER_KEY	= 'chk-replacement';

	// The replacement transaction's second key. The marker answers "is the
	// download at this hash the one this process staged"; the record answers
	// "and what was it supposed to become". Both are written in the load
	// command list and cleared together, so a non-empty marker without a
	// record can only mean a row staged before this key existed.
	const INHERIT_KEY		= 'chk-replaces';

	// Written on the PREDECESSOR, in the same multicall that stops and closes
	// it, and cleared once it is safely back. Everything else this transaction
	// records lives on the staged copy -- which does not exist yet at that
	// point -- so without this the window between "stopped and closed" and
	// "staged copy loaded" left the user's torrent invisible: outside the
	// "seeding" view the cycle scans, carrying no marker either sweep looks
	// for, and with nothing anywhere recording why it stopped.
	//
	// Same encoding as INHERIT_KEY, read with decodeInheritance(), but the
	// hash field names the SUCCESSOR rather than the predecessor.
	const REPLACING_KEY		= 'chk-replacing';

	const USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
		. "AppleWebKit/537.36 (KHTML, like Gecko) "
		. "Chrome/120.0.0.0 Safari/537.36";

	private static $TRACKERS = array();
	private static $ANNOUNCES = array();
	private static $obsoleteCleanupSummary = array(
		'old' => 0,
		'new' => 0,
		'obsolete' => 0,
		'missing' => 0,
	);

	/**
	 * Register a tracker handler.
	 *
	 * @param string   $commentFilter  Regex pattern for torrent comment
	 * @param string   $announceFilter Regex pattern for announce URL list
	 * @param callable $handler        Handler function: handler($url, $hash, $torrent)
	 */
	static public function registerTracker($commentFilter, $announceFilter, $handler)
	{
		if(!array_key_exists($commentFilter, self::$TRACKERS))
		{
			self::$TRACKERS[$commentFilter] = array(
				'announceFilter' => $announceFilter,
				'handler' => $handler,
			);
			self::$ANNOUNCES[] = $announceFilter;
		}
	}

	static public function supportedTrackers()
	{
		return(self::$ANNOUNCES);
	}

	static public function isForeignComment($comment)
	{
		if((string)$comment === '')
			return false;
		if(count(self::$TRACKERS) > 0)
		{
			foreach(self::$TRACKERS as $commentFilter => $tracker)
			{
				if($tracker['handler'] !== 'RuTrackerCheckImpl::download_torrent' &&
					preg_match($commentFilter, (string)$comment))
				{
					return true;
				}
			}
		}
		if(preg_match('/kinozal\.|nnmclub\.|nnm-club\.|toloka\.|tfile\.|anidub\.|tapochek\./i', (string)$comment))
		{
			return true;
		}
		return false;
	}

	static public function hasForeignAuthoritativeComment($hash)
	{
		if(!class_exists('rTorrentSettings') || !method_exists('rTorrentSettings', 'get'))
			return false;
		$settings = rTorrentSettings::get();
		if(!$settings || empty($settings->session))
			return false;
		$fname = $settings->session . $hash . ".torrent";
		if(!is_file($fname))
			return false;
		$torrent = @new Torrent($fname);
		if($torrent->errors())
			return false;
		return self::isForeignComment((string) $torrent->comment());
	}

	/**
	 * Check whether rTorrent still knows a hash.
	 *
	 * @return bool|null true when present, false when the target is missing,
	 *                   null when presence cannot be proved either way
	 */
	static public function torrentExists( $hash )
	{
		$presence = erasedataTorrentPresence($hash);
		if($presence === ERASEDATA_TORRENT_PRESENT)
			return(true);
		if($presence === ERASEDATA_TORRENT_ABSENT)
			return(false);
		return(null);
	}

	static private function makeSafeRelativePath($components)
	{
		if(!is_array($components) || !count($components))
			return(null);
		$normalized = array();
		foreach($components as $component)
		{
			if(!is_string($component) && !is_numeric($component))
				return(null);
			$component = (string) $component;
			if($component === '' || $component === '.' || $component === '..'
				|| strpos($component, "\0") !== false || strpos($component, '/') !== false
				|| strpos($component, '\\') !== false)
				return(null);
			$normalized[] = $component;
		}
		return(implode('/', $normalized));
	}

	static private function collectTorrentPaths($torrent)
	{
		if(!is_object($torrent) || !isset($torrent->info) || !is_array($torrent->info))
			return(null);
		$info = $torrent->info;
		$single = array_key_exists('length', $info);
		$multi = array_key_exists('files', $info);
		if($single === $multi)
			return(null);
		$paths = array();
		$seen = array();
		if($multi)
		{
			if(!is_array($info['files']))
				return(null);
			foreach($info['files'] as $file)
			{
				if(!is_array($file) || !isset($file['path']) || !is_array($file['path']))
					return(null);
				$path = self::makeSafeRelativePath($file['path']);
				if($path === null)
					return(null);
				if(array_key_exists('attr', $file))
				{
					if(!is_string($file['attr']))
						return(null);
					if(strpos($file['attr'], 'p') !== false)
						continue;
				}
				$key = "p\0" . $path;
				if(!isset($seen[$key]))
				{
					$seen[$key] = true;
					$paths[] = $path;
				}
			}
		}
		elseif(array_key_exists('name', $info))
		{
			$path = self::makeSafeRelativePath(array($info['name']));
			if($path === null)
				return(null);
			$paths[] = $path;
		}
		else
			return(null);
		return($paths);
	}

	static private function fileIdentityIndexKey($identity)
	{
		if(!is_array($identity) || empty($identity['exists'])
			|| !isset($identity['stat']['dev'], $identity['stat']['ino']))
			return(false);
		return('i:' . $identity['stat']['dev'] . ':' . $identity['stat']['ino']);
	}

	static private function resolveSuccessorFileIdentity($candidate, $basePrefix)
	{
		$identity = XMLRPCPathResolver::filesystemIdentity($candidate);
		if($identity === false || !isset($identity['path'], $identity['exists'])
			|| !is_string($identity['path']) || strpos($identity['path'], $basePrefix) !== 0)
			return(false);
		if(empty($identity['exists']))
			return($identity);

		clearstatcache(true, $candidate);
		$lstat = @lstat($candidate);
		$stat = @stat($candidate);
		if(!is_file($candidate) || !is_array($lstat) || !is_array($stat)
			|| !isset($lstat['mode'], $stat['mode'], $lstat['dev'], $lstat['ino'], $stat['dev'], $stat['ino'])
			|| !isset($identity['lstat']['dev'], $identity['lstat']['ino'],
				$identity['stat']['dev'], $identity['stat']['ino'])
			|| (($lstat['mode'] & 0170000) !== 0100000 && ($lstat['mode'] & 0170000) !== 0120000)
			|| (($stat['mode'] & 0170000) !== 0100000)
			|| $identity['lstat']['dev'] !== $lstat['dev'] || $identity['lstat']['ino'] !== $lstat['ino']
			|| $identity['stat']['dev'] !== $stat['dev'] || $identity['stat']['ino'] !== $stat['ino'])
			return(false);
		return($identity);
	}

	static private function buildObsoleteCleanupFiles($oldTorrent, $newTorrent, $baseDir)
	{
		self::$obsoleteCleanupSummary = array('old' => 0, 'new' => 0, 'obsolete' => 0, 'missing' => 0);
		$oldPaths = self::collectTorrentPaths($oldTorrent);
		$newPaths = self::collectTorrentPaths($newTorrent);
		if(!is_array($oldPaths) || !is_array($newPaths))
			return(false);
		$newPathSet = array();
		foreach($newPaths as $path)
			$newPathSet["p\0" . $path] = true;
		$obsolete = array();
		foreach($oldPaths as $path)
			if(!isset($newPathSet["p\0" . $path]))
				$obsolete[] = $path;
		self::$obsoleteCleanupSummary = array(
			'old' => count($oldPaths),
			'new' => count($newPaths),
			'obsolete' => count($obsolete),
			'missing' => 0,
		);
		if(!count($obsolete))
			return(null);

		if(!is_string($baseDir) || $baseDir === '')
			return(false);
		$base = @realpath($baseDir);
		if($base === false || !is_dir($base) || $base === DIRECTORY_SEPARATOR)
			return(false);
		$base = rtrim($base, DIRECTORY_SEPARATOR);
		$basePrefix = $base . DIRECTORY_SEPARATOR;
		$newIdentityIndex = array();
		foreach($newPaths as $path)
		{
			$identity = self::resolveSuccessorFileIdentity($basePrefix . $path, $basePrefix);
			if($identity === false)
				return(false);
			if(!empty($identity['exists']))
			{
				$key = self::fileIdentityIndexKey($identity);
				if($key === false)
					return(false);
				$newIdentityIndex[$key] = $identity;
			}
		}

		$entries = array();
		foreach($obsolete as $path)
		{
			$candidate = $basePrefix . $path;
			$identity = XMLRPCPathResolver::filesystemIdentity($candidate);
			if($identity === false)
				return(false);
			if(empty($identity['exists']))
			{
				self::$obsoleteCleanupSummary['missing']++;
				continue;
			}
			clearstatcache(true, $candidate);
			$lstat = @lstat($candidate);
			$stat = @stat($candidate);
			if($identity['path'] !== $candidate || strpos($candidate, $basePrefix) !== 0
				|| is_link($candidate) || !is_file($candidate)
				|| !is_array($lstat) || !is_array($stat)
				|| !isset($lstat['mode'], $stat['mode'], $lstat['dev'], $lstat['ino'], $stat['dev'], $stat['ino'], $stat['size'], $stat['mtime'])
				|| (($lstat['mode'] & 0170000) !== 0100000) || (($stat['mode'] & 0170000) !== 0100000)
				|| $identity['lstat']['dev'] !== $lstat['dev'] || $identity['lstat']['ino'] !== $lstat['ino']
				|| $identity['stat']['dev'] !== $stat['dev'] || $identity['stat']['ino'] !== $stat['ino'])
				return(false);

			$key = self::fileIdentityIndexKey($identity);
			if($key === false)
				return(false);
			if(isset($newIdentityIndex[$key]))
			{
				$alias = erasedataExactFileAlias($identity, $newIdentityIndex[$key]);
				if($alias === ERASEDATA_FILE_ALIAS_UNKNOWN)
					return(false);
				if($alias === ERASEDATA_FILE_ALIAS_SAME)
					continue;
			}

			$current = XMLRPCPathResolver::filesystemIdentity($candidate);
			if($current === false || empty($current['exists']) || $current['path'] !== $candidate
				|| $current['lstat'] !== $identity['lstat'] || $current['stat'] !== $identity['stat'])
				return(false);
			$entries[] = array(
				'path' => $candidate,
				'identity' => array(
					'canonical' => $identity['path'],
					'lstat' => $identity['lstat'],
					'stat' => $identity['stat'],
					'size' => $stat['size'],
					'mtime' => $stat['mtime'],
				),
			);
		}
		return(count($entries) ? $entries : null);
	}

	static public function setState( $hash, $state )
	{
		return(self::writeCustomProjection($hash,
			self::stateCommands($hash, $state, time()), "setState"));
	}

	// Build every timestamped state projection from one captured clock value.
	// Besides avoiding a boundary-second disagreement between chk-time and
	// chk-stime, this lets the scheduler include the same projection in its
	// one-request fast-verdict bundle without copying setState()'s rules.
	static private function stateCommands($hash, $state, $now)
	{
		$commands = array(
			new rXMLRPCCommand(getCmd("d.set_custom"), array($hash, "chk-state", (string) $state)),
			new rXMLRPCCommand(getCmd("d.set_custom"), array($hash, "chk-time", (string) $now)),
		);
		if($state == self::STE_UPTODATE)
			$commands[] = new rXMLRPCCommand(getCmd("d.set_custom"),
				array($hash, "chk-stime", (string) $now));
		return($commands);
	}

	/**
	 * Write custom fields and accept success only when every command result was
	 * returned or the exact desired projection is measured afterwards.
	 *
	 * The legacy XML parser can return success with a truncated value list. A
	 * short positive response is therefore just as ambiguous as a failed one:
	 * some setters may have landed, or the reply may have been cut short.
	 */
	static private function writeCustomProjection($hash, $commands, $context)
	{
		return(RuTrackerCustomProjection::write($hash, $commands, $context));
	}

	/**
	 * Persist one scheduler fast-path verdict as one small daemon request.
	 *
	 * system.multicall is one scheduling/request boundary, not a rollback
	 * transaction. Therefore an aggregate failure is ambiguous: preceding
	 * members may already have landed, or the reply may simply have been lost.
	 * Read back exactly the selected projection and accept it only when every
	 * field has the desired value. A confirmed missing hash is the sole null
	 * outcome; every present or unknowable incomplete result stays retryable.
	 *
	 * @return bool|null true when the whole desired projection is observed,
	 *                   null when the target is confirmed absent, false otherwise
	 */
	static public function setFastVerdict($hash, $state, $message = null, $clearDeletion = false)
	{
		$now = time();
		$commands = self::stateCommands($hash, $state, $now);
		if($message !== null)
			$commands[] = new rXMLRPCCommand(getCmd("d.set_custom"),
				array($hash, "chk-msg", (string) $message));
		if($clearDeletion)
			$commands[] = new rXMLRPCCommand(getCmd("d.set_custom"),
				array($hash, "chk-del", ""));

		return(self::writeCustomProjection($hash, $commands, "setFastVerdict"));
	}

	// Writes the chk-msg custom -- a CHKMSG_* token plus its single
	// parameter, "<token>|<parameter>"; an empty string clears it.
	static public function setMessage( $hash, $message )
	{
		$req = new rXMLRPCRequest( new rXMLRPCCommand(
			getCmd("d.set_custom"), array($hash, "chk-msg", (string) $message) ) );
		$req->important = false;
		return($req->success());
	}

	static protected function getState( $hash, &$state, &$time, &$label )
	{
		$state = self::STE_INPROGRESS;
		$time = time();
		$label = "";

		// Read first, probe only if that fails. The existence probe used to run
		// unconditionally ahead of the read, so every manual check paid two
		// round trips where one answers: a custom read against a hash rTorrent
		// does not know FAULTS (measured against the live daemon: -500), so a
		// successful read is itself proof the torrent is there. The probe still
		// exists for the one case that needs it -- telling "the torrent is
		// gone" apart from "the daemon did not answer" -- it just no longer
		// runs when nothing went wrong.
		//
		// Scheduler and manual checks both reach this live read under run()'s
		// claim. A scheduler snapshot is only dispatch input and may be stale.
		$req = new rXMLRPCRequest( array(
			new rXMLRPCCommand( getCmd("d.get_custom"), array($hash, "chk-state")  ),
			new rXMLRPCCommand( getCmd("d.get_custom"), array($hash, "chk-time") ),
			new rXMLRPCCommand( getCmd("d.get_custom1"), $hash )
			));
		$req->important = false;
		if($req->success() && isset($req->val[0], $req->val[1], $req->val[2]))
		{
			$state = intval($req->val[0]);
			$time = intval($req->val[1]);
			$label = $req->val[2];
			return(true);
		}

		$exists = self::torrentExists($hash);
		if($exists === false)
		{
			$state = self::STE_NOT_NEED;
			self::logDebug("getState: Torrent " . $hash . " not found, skipping state read");
			return(false);
		}
		return(false);
	}

	// The views rTorrent currently has, keyed by name; null when the list
	// itself could not be read. Views are runtime state: rTorrent recreates
	// none of the rat_N ones on restart -- ruTorrent's ratio plugin does, on
	// its next start (plugins/ratio/ratio.php, flush()).
	static private function existingViews()
	{
		// Only php/methods-0.9.4.php aliases "view_list" to view.list, but
		// php/settings.php layers the method tables cumulatively, so that
		// mapping is loaded for every daemon >= 0.9.4; on anything older the
		// name passes through unchanged and is the native command.
		$req = new rXMLRPCRequest( new rXMLRPCCommand(getCmd("view_list")) );
		$req->important = false;
		if(!$req->success())
			return(null);
		$views = array();
		foreach($req->val as $view)
			if(is_string($view))
				$views[$view] = true;
		return($views);
	}

	// $existingViews is existingViews()' answer, read by createTorrent()
	// while the old torrent was still running; null means unreadable.
	static private function buildReplacementAddition($connectionSeed, $throttle, $ratioViews, $existingViews, $state, $marker, $inherit, $topic = '', $forum = '')
	{
		$now = time();
		$addition = array(
			getCmd("d.set_custom")."=".self::REPLACEMENT_MARKER_KEY.",".$marker,
			// Shares the marker's privileged position for the same reason:
			// the first input_error aborts every command after it, and a
			// record that never lands leaves the row in the legacy branch --
			// which starts nothing -- rather than in a wrong guess.
			getCmd("d.set_custom")."=".self::INHERIT_KEY.",".$inherit,
			// d.set_connection_seed= resolves to d.connection_seed.set, which
			// rTorrent registers PRIVATE: it works here only because a load
			// command list is executed internally, not through the XMLRPC
			// entry point. Moving it into a post-load system.multicall would
			// silently fault.
			getCmd("d.set_connection_seed=").$connectionSeed,
			getCmd("d.set_custom")."=chk-state,".$state,
			getCmd("d.set_custom")."=chk-time,".$now,
			getCmd("d.set_custom")."=chk-stime,".$now,
		);
		// Only when the predecessor had them: an empty value would be written
		// as an empty custom, which resolveForum() and rememberTopic() would
		// then read as "set to nothing" rather than "never set".
		if($topic !== '')
			$addition[] = getCmd("d.set_custom")."=chk-topic,".$topic;
		if($forum !== '')
			$addition[] = getCmd("d.set_custom")."=chk-forum,".$forum;
		if(!empty($throttle))
			$addition[] = getCmd("d.set_throttle_name=").$throttle;

		// DownloadFactory runs this whole list inside one try block: the first
		// torrent::input_error aborts every command after it, plus the
		// d.state.set and the event.download.inserted_new that rTorrent itself
		// appends. view.set_visible throws exactly that error for a view that
		// does not exist. The abort is not fatal to the load -- the marker is
		// the first command, so waitForLoad() still confirms ownership, and
		// for a previously-started source activateReplacement()'s d.start
		// makes the download visible in the started view, whose view event
		// sets d.state=1. What it silently costs is every load command after
		// the failing one, the inserted_new side effects (history logging,
		// the ratio plugin's default-group hook), and -- since that d.start
		// rescue only reaches sources activateReplacement() starts -- a
		// previously-stopped source staying closed at state 0.
		//
		// So memberships are forwarded in two tiers. A view confirmed against
		// the live view list gets view.set_visible, which also records the
		// membership in the d.views attribute (rat_N views are persistent,
		// and a persistent view's event_added runs d.views.push_back_unique).
		// An unconfirmed one gets d.views.push_back_unique directly: it never
		// throws and records the attribute only. Dropping it instead would be
		// worse than a lost group -- a replacement with an empty d.views is
		// re-homed by the ratio plugin's default-group insert hook (a branch
		// over d.views.has, ratio.php flush()) into the DEFAULT ratio group,
		// whose action can be erase-data. The attribute keeps that hook a
		// no-op, and the ratio plugin's correct() pass turns it back into
		// visible membership once the views exist again.
		$attributeOnly = array();
		foreach($ratioViews as $ratioView)
		{
			if($existingViews !== null && isset($existingViews[$ratioView]))
				$addition[] = getCmd("view.set_visible=").$ratioView;
			else
			{
				$addition[] = getCmd("d.views.push_back_unique=").$ratioView;
				$attributeOnly[] = $ratioView;
			}
		}
		if(count($attributeOnly))
			self::logDebug("buildReplacementAddition: forwarded ".implode(',', $attributeOnly)
				." as the d.views attribute only (".($existingViews === null
					? "the view list could not be read"
					: "no such view in rTorrent")
				."): view.set_visible would throw input_error and abort the rest of"
				." the load command list; the ratio plugin restores the visible"
				." membership once the views exist again");
		return($addition);
	}

	/**
	 * Wait for the staged torrent to be inserted by rTorrent's deferred load.
	 * The addition commands run in the same event-loop step as the insert, so
	 * once the hash resolves, the marker is authoritative.
	 *
	 * @return string 'ours' | 'foreign' | 'missing'
	 */
	static private function waitForLoad($hash, $marker)
	{
		for($attempt = 0; $attempt < self::LOAD_WAIT_ATTEMPTS; $attempt++)
		{
			if($attempt)
				usleep(self::LOAD_WAIT_DELAY_US);
			$req = new rXMLRPCRequest( new rXMLRPCCommand(
				getCmd("d.get_custom"), array($hash, self::REPLACEMENT_MARKER_KEY) ) );
			$req->important = false;
			if(!$req->run() || $req->fault)
				continue;
			return((isset($req->val[0]) && (string) $req->val[0] === (string) $marker) ? 'ours' : 'foreign');
		}
		return('missing');
	}

	// Compare-and-swap on a check, keyed by hash. chk-state cannot do this:
	// d.set_custom is an unconditional write, so two processes can both read
	// the old state and both write STE_INPROGRESS. That is not merely wasted
	// work -- a check erases stubs, hands bytes to createTorrent(), and stops
	// and reloads the user's torrent.
	//
	// The window is wider than the two statements around the write, because
	// the scheduler dispatches on a chk-state captured by update.php's
	// cycle-start multicall: a click that starts first stays invisible to that
	// pass for the rest of the cycle, which the paced announce sleeps and dump
	// fetches make minutes long. And batch_check.php takes no cycle lock, so a
	// click during the pass is the ordinary case, not a rare one.
	//
	// Claims expire after MAX_LOCK_TIME, the same allowance the chk-state lock
	// gets, so a process killed mid-check does not wedge the torrent: the next
	// cycle takes the claim and re-derives everything from the stored markers.
	// Expired entries are pruned on every write, mirroring touchDump()'s
	// prune-on-write, so the document stays bounded.
	// The stored entry's timestamp, whichever shape it is in. Entries written
	// before claims carried an owner are a bare integer, and one can still be
	// on disk when this version starts; it ages out within MAX_LOCK_TIME.
	static private function claimSince($entry)
	{
		return(is_array($entry) ? (int) ($entry['since'] ?? 0) : (int) $entry);
	}

	// Returns the owner token on success and false when the hash is already
	// claimed. The token is what makes releasing safe: a timestamp alone
	// cannot tell "my claim" from "the claim that replaced mine after I
	// overran the lease", and releaseCheck() used to drop whichever it found.
	static private function claimCheck($hash, $now)
	{
		$token = bin2hex(random_bytes(8));
		$granted = false;
		$stored = RuTrackerState::update('meta-claims', function($claims) use ($hash, $now, $token, &$granted) {
			foreach($claims as $held => $entry)
				if(($now - self::claimSince($entry)) > self::MAX_LOCK_TIME) unset($claims[$held]);
			if(isset($claims[$hash])) return($claims);
			$granted = true;
			$claims[$hash] = array('since' => (int) $now, 'token' => $token);
			return($claims);
		});
		// A claim nobody could write down is not a claim: the next process
		// would read the same free slot and pump too.
		return(($granted && $stored) ? $token : false);
	}

	// $token null releases whatever is there, which is only correct for a
	// caller that did not take the claim itself. Every production caller holds
	// its own token and passes it, so an overrun worker can no longer free the
	// successor's claim on its way out.
	static private function releaseCheck($hash, $token = null)
	{
		RuTrackerState::update('meta-claims', function($claims) use ($hash, $token) {
			if(!isset($claims[$hash])) return($claims);
			$entry = $claims[$hash];
			// A legacy entry has no owner recorded, so there is nothing to
			// disagree with and the old unconditional behaviour stands.
			if($token !== null && is_array($entry)
				&& isset($entry['token']) && $entry['token'] !== $token)
				return($claims);
			unset($claims[$hash]);
			return($claims);
		});
	}

	static public function claimCheckForWorker($hash, $now)
	{
		return(self::claimCheck($hash, $now));
	}

	static public function releaseCheckForWorker($hash, $token)
	{
		self::releaseCheck($hash, $token);
	}

	// Erase a hash that was verified to carry our replacement marker.
	static private function eraseStaged($hash, $marker = null, $record = null)
	{
		if($marker === null || $record === null
			|| (string) $marker === '' || (string) $record === '')
			return(false);
		return(RuTrackerAtomicOwnership::erase(
			$hash,
			array(
				self::REPLACEMENT_MARKER_KEY => (string) $marker,
				self::INHERIT_KEY => (string) $record,
			),
			array(
				'state' => 0,
				'is_open' => 0,
			)
		) === RuTrackerAtomicOwnership::ACTED);
	}

	// Puts a torrent this transaction stopped and closed back the way it was,
	// and MEASURES the outcome. Both halves matter to the callers:
	//
	// d.open before d.start, the order ruTorrent's own UI sends
	// (plugins/httprpc/action.php, case "start"), because the transaction's
	// own d.close closed the download and a bare d.start on a closed one can
	// leave it closed.
	//
	// And the answer is the reading taken afterwards, never the ack: rTorrent
	// accepts d.start on a download whose files it cannot open -- removed
	// media, an unmounted path, a permission change -- and reports that only
	// in its own log, so the XML-RPC reply carries no fault. The rollback
	// erases the staged copy on this answer, and that copy holds the only
	// chk-replacement marker the sweep can find the transaction by, so an ack
	// mistaken for a restore leaves a stopped, closed torrent nothing in the
		// plugin will ever look at again. The state check and generation clear
		// therefore stay inside the same daemon-side conditional command.
	static private function restoreExistingTorrent($hash, $wasOpen, $wasStarted, $expectedReplacing = null)
	{
		if($expectedReplacing === null || (string) $expectedReplacing === '')
			return(false);
		$expectedCustoms = array(self::REPLACING_KEY => (string) $expectedReplacing);
		$expectedValues = array('state' => 0, 'is_open' => 0);
		// A torrent the user had stopped stays stopped; only this exact recovery
		// generation is retired. Open/started policies restore and verify state,
		// then retire the same generation inside that one daemon command.
		$status = (!$wasOpen && !$wasStarted)
			? RuTrackerAtomicOwnership::clearCustoms(
				$hash, $expectedCustoms, array(self::REPLACING_KEY), $expectedValues)
			: RuTrackerAtomicOwnership::runState(
				$hash, $expectedCustoms, $wasStarted, $expectedValues,
				array(self::REPLACING_KEY => ''));
		return($status === RuTrackerAtomicOwnership::ACTED);
	}

	// Runs after the commit point: failures are logged and the replacement is
	// left stopped rather than reported as a failed check.
	static private function activateReplacement($hash, $wasOpen, $wasStarted, $marker = null, $record = null,
		$closeTransaction = true)
	{
		if(!$wasOpen && !$wasStarted)
		{
			// Deliberate: a torrent the user had stopped must not be
			// resurrected by its replacement. This branch used to be silent,
			// which is exactly why a live run that left five replacements
			// stopped could not be told apart from one that never got here.
			self::logDebug("activateReplacement: " . $hash
				. " left stopped and closed: the old torrent was neither open nor started");
			if($marker === null || $record === null)
				return(true);
			if(!$closeTransaction)
				return(true);
			return(self::clearReplacementRecord($hash, $marker, $record,
				array('state' => 0, 'is_open' => 0)));
		}
		if($marker === null || $record === null
			|| (string) $marker === '' || (string) $record === '')
			return(false);
		$expectedCustoms = array(
			self::REPLACEMENT_MARKER_KEY => (string) $marker,
			self::INHERIT_KEY => (string) $record,
		);
		$expectedValues = array('state' => 0, 'is_open' => 0);
		$status = $closeTransaction
			? RuTrackerAtomicOwnership::runState($hash, $expectedCustoms, $wasStarted, $expectedValues,
				array(self::INHERIT_KEY => '', self::REPLACEMENT_MARKER_KEY => ''))
			: RuTrackerAtomicOwnership::runState($hash, $expectedCustoms, $wasStarted, $expectedValues);
		if($status === RuTrackerAtomicOwnership::ACTED)
			return(true);
		if($status === RuTrackerAtomicOwnership::SKIPPED)
			self::logDebug("activateReplacement: Skipped activation of " . $hash . " due to ownership or state change");
		self::logDebug("activateReplacement: Could not confirm activation of " . $hash);
		return(false);
	}

	static private function logCleanupFailure($message)
	{
		FileUtil::toLog('rutracker_check: ' . preg_replace('/[\r\n]+/', ' ', (string) $message));
	}

	/**
	 * Reconcile a transaction already present at NEW before mutating either
	 * generation. OLD presence is the commit proof; successor run state is not.
	 *
	 * @return string|null 'rollback' | 'committed' | null for retained retry
	 */
	static private function reconcileExistingCleanup($oldHash, $newHash, $marker, $record)
	{
		$oldExists = self::torrentExists($oldHash);
		if($oldExists === null)
			return(null);
		if($oldExists)
		{
			$status = erasedataCancelObsoleteCleanupGeneration($oldHash, $newHash, $marker, $record);
			return($status === ERASEDATA_CLEANUP_NONE || $status === ERASEDATA_CLEANUP_READY
				? 'rollback' : null);
		}

		$status = erasedataRecoverObsoleteCleanup($oldHash, $newHash, $marker, $record);
		if($status !== ERASEDATA_CLEANUP_NONE && $status !== ERASEDATA_CLEANUP_READY)
			return(null);
		if($status === ERASEDATA_CLEANUP_READY && !erasedataKickCollector($oldHash))
			self::logCleanupFailure('targeted obsolete cleanup kick failed for ' . strtoupper($oldHash)
				. '; the durable job remains scheduled for retry');
		return('committed');
	}

	/**
	 * Close the replacement transaction: the marker and the record go away
	 * together, so the invariant a later cycle relies on holds -- a non-empty
	 * record can only accompany a non-empty marker.
	 *
	 * Called only where the transaction is known to be over: after a confirmed
	 * activation, and where a live torrent turns out to carry a stale marker.
	 * Never on a row whose fate is unknown -- clearing is the irreversible
	 * step, because createTorrent() treats an unmarked existing hash as
	 * foreign and refuses to reuse it from then on.
	 */
	static public function clearReplacementRecord($hash, $marker = null, $record = null,
		$expectedValues = array())
	{
		if($marker === null || $record === null
			|| (string) $marker === '' || (string) $record === '')
			return(false);
		return(RuTrackerAtomicOwnership::clearCustoms(
			$hash,
			array(
				self::REPLACEMENT_MARKER_KEY => (string) $marker,
				self::INHERIT_KEY => (string) $record,
			),
			array(self::INHERIT_KEY, self::REPLACEMENT_MARKER_KEY),
			$expectedValues
		) === RuTrackerAtomicOwnership::ACTED);
	}

	/**
	 * The predecessor's identity and run state, as one comma-free string.
	 *
	 * Three fields, each earning its place: the hash separates "the commit
	 * never happened" (the predecessor is still there) from "the commit
	 * happened and the activation did not" (it is gone); the token is the
	 * (started, open) pair read at the commit point, which until now died with
	 * the PHP process; the epoch lets a sweep tell a crashed transaction from
	 * one that is simply still running.
	 *
	 * Hyphen-separated: a 40-hex hash, a lowercase token and an integer
	 * contain none, so the split is unambiguous, and the whole value stays
	 * comma-free -- d.custom.set splits its own arguments on commas.
	 */
	static public function encodeInheritance($oldHash, $wasStarted, $wasOpen, $now)
	{
		return(RuTrackerReplacementRecord::encode($oldHash, $wasStarted, $wasOpen, $now));
	}

	static public function isPluginReplacementMarker($value)
	{
		return(RuTrackerReplacementRecord::isPluginMarker($value));
	}

	/**
	 * The record, or null when the value is not one this class wrote.
	 *
	 * null is the legacy signal -- the row predates the record, or its load
	 * command list aborted before the record landed -- and every caller must
	 * route it according to whether the raw value was absent (legacy) or
	 * non-empty but malformed (fail closed). Unknown run tokens are malformed:
	 * silently converting one to stopped can authorize an erase or key clear.
	 */
	static public function decodeInheritance($value)
	{
		return(RuTrackerReplacementRecord::decode($value));
	}

	/**
	 * Wait for a magnet download to acquire its metainfo.
	 *
	 * Lives here, beside createTorrent(), because it is the same kind of
	 * service every handler may need rather than anything RuTracker-specific:
	 * it takes a hash and answers whether rTorrent still calls that download a
	 * metadata stub. A handler that loads a magnet can therefore finish the
	 * replacement in the cycle it started, instead of leaving it to the next
	 * scheduled run. Today only the RuTracker handler loads magnets -- NNMClub
	 * and Kinozal fetch the .torrent from the site and already replace within
	 * one cycle -- but nothing here knows which tracker is asking.
	 *
	 * Waiting pays because metadata almost always arrives at once: across 21
	 * replacements measured on a live fleet the median wait was 1 second and
	 * 20 of the 21 were under 5, the lone outlier taking 83. The default ten
	 * seconds thus turns nearly every fetch into a same-cycle replacement,
	 * while a slow one still falls through to whatever the caller does next.
	 * It costs an idle cycle nothing: it runs only once a fetch has begun.
	 *
	 * @param  string $hash The magnet download to watch
	 * @return bool   true once the download carries real metainfo
	 *
	 * No $seconds override: nothing ever passed one. The bound itself stays
	 * testable through metadataWaitSeconds(), which is where it belongs -- a
	 * wait is not something a test should have to sit through to assert.
	 */
	// The resolved, bounded wait: the caller's override when given, otherwise
	// $rutrackerMetaWait, otherwise the default -- clamped at both ends.
	// Separate from awaitMetadata() so the bound can be asserted without
	// spending the wait it describes.
	static public function metadataWaitSeconds( $override = null )
	{
		global $rutrackerMetaWait;
		$seconds = $override;
		if(is_null($seconds))
			$seconds = isset($rutrackerMetaWait) ? $rutrackerMetaWait : self::METADATA_WAIT_DEFAULT;
		return(min(self::METADATA_WAIT_MAX, max(0, (int) $seconds)));
	}

	static public function awaitMetadata( $hash )
	{
		$seconds = self::metadataWaitSeconds();
		$until = microtime(true) + $seconds;
		$expectedHash = strtoupper((string) $hash);
		$reason = 'metadata-pending';
		$actualHash = '(not read)';
		for(;;)
		{
			$meta = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.is_meta"), $hash));
			$meta->important = false;
			$success = $meta->success();
			// d.is_meta can turn zero before rTorrent atomically replaces the
			// session file. Readiness therefore requires both the live state and
			// parseable session bytes carrying the exact expected info-hash.
			if($success && isset($meta->val[0])
				&& ($meta->val[0] === 0 || $meta->val[0] === '0'))
			{
				$torrent = rTorrent::getSource($hash);
				if(!is_object($torrent))
				{
					$reason = 'session-unreadable';
					$actualHash = '(unreadable)';
				}
				else if($torrent->errors())
				{
					$reason = 'session-invalid';
					$actualHash = '(invalid)';
				}
				else
				{
					$actualHash = strtoupper((string) $torrent->hash_info());
					if($actualHash === $expectedHash)
						return(true);
					$reason = 'session-hash-stale';
				}
			}
			else if(!$success || $meta->fault || !isset($meta->val[0]))
			{
				$reason = 'state-unreadable';
				$actualHash = '(not read)';
			}
			else
			{
				$reason = 'metadata-pending';
				$actualHash = '(not read)';
			}
			if(microtime(true) >= $until)
			{
				self::logDebug('metadata readiness: ' . $expectedHash
					. ' outcome=wait-timeout reason=' . $reason
					. ' expected=' . $expectedHash . ' actual=' . $actualHash);
				return(false);
			}
			usleep(self::METADATA_POLL_US);
		}
	}

	/**
	 * Whether these bytes are torrent metainfo at all.
	 *
	 * createTorrent() below reads an unparseable payload as proof the topic is
	 * gone -- legacy handlers rely on that, feeding it HTTP-200 "topic removed"
	 * pages. The corollary is that nothing else may reach it: a login wall, a
	 * ratio gate or a protection page served with 200 is not a removed topic,
	 * and calling it one flags a live torrent as deleted. Lifted from
	 * KinozalCheckImpl, which has always guarded its own payload this way.
	 */
	static public function isMetainfo($payload)
	{
		if(!is_string($payload) || $payload === '') return(false);
		// PHP 7.4 warns when Torrent probes binary metainfo as a filename.
		$torrent = @new Torrent($payload);
		return(!$torrent->errors() && strlen((string) $torrent->hash_info()) === 40);
	}

	/**
	 * Common validated replacement tail for HTTP download clients.
	 *
	 * Reusable across sibling handlers: validates HTTP 200 and metainfo validity
	 * before handing bytes to createTorrent(). Returns STE_CANT_REACH_TRACKER on
	 * non-200 or non-metainfo responses.
	 */
	static public function createTorrentFromDownload($client, $hash, $oldTorrent = null)
	{
		if(!is_object($client) || intval($client->status) !== 200)
			return(self::STE_CANT_REACH_TRACKER);
		$payload = (string) $client->results;
		if(!self::isMetainfo($payload))
			return(self::STE_CANT_REACH_TRACKER);
		return(self::createTorrent($payload, $hash, $oldTorrent));
	}

	static private function quoteRtorrentArgument($value)
	{
		return RuTrackerAtomicOwnership::quoteRtorrentArgument($value);
	}

	static private function replacementStopBody($marker)
	{
		return(getCmd('cat=')
			. '"$' . getCmd('d.set_custom=') . self::REPLACING_KEY . ',' . $marker . '"'
			. ',$' . getCmd('d.stop=')
			. ',$' . getCmd('d.close=')
			. ',' . $marker);
	}

	// One daemon-side command selects the live state and, in that same command
	// execution, writes the matching recovery marker before stop/close. Its
	// exact returned marker is the only state source PHP accepts afterwards.
	static private function replacementStopCommand($oldHash, $newHash, $stagedAt,
		$stoppedFallback = null)
	{
		$started = self::encodeInheritance($newHash, true, true, $stagedAt);
		$open = self::encodeInheritance($newHash, false, true, $stagedAt);
		$stopped = $stoppedFallback !== null
			? (string) $stoppedFallback
			: self::encodeInheritance($newHash, false, false, $stagedAt);
		$notStarted = getCmd('branch=') . getCmd('d.is_open=')
			. ',' . self::quoteRtorrentArgument(self::replacementStopBody($open))
			. ',' . self::quoteRtorrentArgument(self::replacementStopBody($stopped));
		return(new rXMLRPCCommand('branch', array(
			$oldHash,
			getCmd('d.get_state='),
			self::replacementStopBody($started),
			$notStarted,
		)));
	}

	static public function createTorrent($torrent, $hash, $oldTorrent = null){
		global $saveUploadedTorrents;
		// PHP 7.4 warns when Torrent probes binary metainfo as a filename.
		$torrent = @new Torrent( $torrent );

		// Legacy handlers feed HTTP-200 "topic removed" HTML pages straight in
		// here and rely on a parse failure meaning the topic is gone.
		if( $torrent->errors() ) return self::STE_DELETED;

		$newHash = $torrent->hash_info();
		if(!is_string($newHash) || !preg_match('/^[0-9a-fA-F]{40}$/D', $newHash))
		{
			self::logDebug("createTorrent: invalid or missing info_hash in replacement metainfo");
			return self::STE_ERROR;
		}
		$newHash = strtoupper($newHash);
		if(strcasecmp($newHash, (string) $hash) === 0) return self::STE_UPTODATE;

		$exists = self::torrentExists($newHash);
		if($exists === null) return self::STE_ERROR;
		$stagedRecord = null;
		if($exists === true)
		{
			// A staged copy abandoned by a crashed run still carries a marker
			// (it is only cleared on success) and is always stopped and closed;
			// discard it and redo the replacement. Anything unmarked is foreign
			// and must not be touched.
			$markerReq = new rXMLRPCRequest( array(
				new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, self::REPLACEMENT_MARKER_KEY)),
				new rXMLRPCCommand("d.get_state", $newHash),
				new rXMLRPCCommand("d.is_open", $newHash),
				new rXMLRPCCommand(getCmd("d.get_custom"), array($newHash, self::INHERIT_KEY)),
			) );
			$markerReq->important = false;
			// When the newly downloaded torrent hash is already present in rTorrent
			// but has no replacement marker, the predecessor has been superseded
			// by external means. Mark the predecessor as STE_NOT_NEED with
			// CHKMSG_SUPERSEDED so the scheduler will not retry the check endlessly.
			if(!$markerReq->success() || !isset($markerReq->val[3]))
			{
				self::logDebug("createTorrent: " . $hash . " could not read the marker of the existing "
					. $newHash . "; the replacement is abandoned with nothing changed");
				return self::STE_ERROR;
			}
			if((string) $markerReq->val[0] === '')
			{
				self::logDebug("createTorrent: " . $hash . " -> " . $newHash
					. " is already in the client and is not this plugin's: there is nothing to"
					. " replace, and neither torrent is touched");
				$setMsgOk = self::setMessage($hash, self::CHKMSG_SUPERSEDED . '|' . $newHash);
				return $setMsgOk ? self::STE_NOT_NEED : self::STE_ERROR;
			}
			if(!self::isPluginReplacementMarker((string) $markerReq->val[0]))
			{
				self::logDebug("createTorrent: " . $hash . " -> " . $newHash
					. " carries a non-plugin replacement marker; neither torrent is touched");
				return self::STE_ERROR;
			}
			// A nonce proves only that this plugin wrote something at this hash.
			// Ownership of THIS replacement additionally requires the strict
			// record to name the predecessor being replaced. Without both halves,
			// neither a stopped copy may be erased nor live keys repaired.
			$rawStagedRecord = (string) $markerReq->val[3];
			$stagedRecord = self::decodeInheritance($rawStagedRecord);
			if($stagedRecord === null
				|| strcasecmp($stagedRecord['old'], (string) $hash) !== 0)
			{
				self::logDebug("createTorrent: " . $hash . " found " . $newHash
					. " with a replacement nonce but "
					. ($stagedRecord === null
						? "no strict record"
						: "a strict record staged for " . $stagedRecord['old'])
					. " for this predecessor;"
					. " retaining the occupant and its recovery keys and leaving that transaction to the sweep");
				return self::STE_ERROR;
			}
			$reconciled = self::reconcileExistingCleanup(
				$hash, $newHash, (string) $markerReq->val[0], $rawStagedRecord);
			if($reconciled === null)
				return self::STE_ERROR;
			if($reconciled === 'committed')
			{
				if(intval($markerReq->val[1]) !== 0 || intval($markerReq->val[2]) !== 0)
				{
					self::clearReplacementRecord($newHash, (string) $markerReq->val[0], $rawStagedRecord,
						array('state' => (int) $markerReq->val[1], 'is_open' => (int) $markerReq->val[2]));
					return self::STE_ERROR;
				}
				else
					self::activateReplacement($newHash, $stagedRecord['run']['open'], $stagedRecord['run']['started'],
						(string) $markerReq->val[0], $rawStagedRecord);
				return null;
			}
			if(intval($markerReq->val[1]) !== 0 || intval($markerReq->val[2]) !== 0)
			{
				// OLD presence has already selected cancellation or recovery.
				// Only after that durable state is reconciled may a live successor
				// retire the owned keys; it is never treated as disposable.
				self::clearReplacementRecord($newHash, (string) $markerReq->val[0], $rawStagedRecord,
					array('state' => (int) $markerReq->val[1], 'is_open' => (int) $markerReq->val[2]));
				return self::STE_ERROR;
			}
			// The record the dead run wrote at ITS commit point, read before
			// the copy carrying it is erased. The dead run's own stop/close
			// is what left the predecessor stopped, so for this transaction
			// the live re-read below reports the crash, not the user -- the
			// record is the one truthful account of the state the torrent
			// was last in when the crashed generation selected its policy.
			if(!self::eraseStaged($newHash, (string) $markerReq->val[0], $rawStagedRecord))
				return self::STE_ERROR;
		}

		// Direct handlers already hold the parsed predecessor from run_ex(). Use
		// it only when its info dictionary identifies this exact hash; asynchronous
		// callers and mismatched objects retain the live source lookup fallback.
		if(!($oldTorrent instanceof Torrent) || $oldTorrent->errors()
			|| strcasecmp((string) $oldTorrent->hash_info(), (string) $hash) !== 0)
			$oldTorrent = rTorrent::getSource($hash);
		if(!($oldTorrent instanceof Torrent) || $oldTorrent->errors()
			|| strcasecmp((string) $oldTorrent->hash_info(), (string) $hash) !== 0)
			return self::STE_ERROR;

		try
		{
			$marker = bin2hex(random_bytes(16));
		}
		catch(Exception $error)
		{
			return self::STE_ERROR;
		}

		// Ratio-group membership lives in rat_N views (see plugins/ratio).
		$viewsReq = new rXMLRPCRequest( new rXMLRPCCommand(getCmd("d.views"), $hash) );
		$viewsReq->important = false;
		if(!$viewsReq->success()) return self::STE_ERROR;
		$ratioViews = array();
		foreach($viewsReq->val as $view)
			if(is_string($view) && preg_match('/^rat_\d+$/', $view))
				$ratioViews[$view] = true;
		$ratioViews = array_keys($ratioViews);

		// Confirm the memberships against the live view list now, while the
		// old torrent is still running: the round trip must not lengthen the
		// window between the stop/close below and the replacement load. It is
		// still a check-to-load TOCTOU either way -- the hoist leaves that gap
		// at the same order of magnitude, buildReplacementAddition() just
		// degrades an unconfirmed membership instead of trusting it. Skipped
		// entirely when the torrent belongs to no ratio group.
		$existingViews = empty($ratioViews) ? null : self::existingViews();

		// Snapshot only non-run metadata. Run state is deliberately absent: a UI
		// start/stop/pause after this request must win, and the daemon-side branch
		// below measures it at the same command boundary that writes the recovery
		// marker and stops/closes the predecessor.
		$req = new rXMLRPCRequest( array(
			new rXMLRPCCommand("d.get_directory_base",$hash),
			new rXMLRPCCommand("d.get_custom1",$hash),
			new rXMLRPCCommand("d.get_throttle_name",$hash),
			new rXMLRPCCommand("d.get_connection_seed",$hash),
			// Carried across the replacement, in the same request that is
			// already being made: a replacement is the same TOPIC with new
			// metadata, so the topic id and the forum it lives in are still
			// true of the successor. Omitting them made every successful
			// replacement forget where its topic lives, and the next check had
			// to resolve the forum again -- which, when the feed does not
			// happen to know it, is a walk of the whole tracker.
			new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, "chk-topic")),
			new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, "chk-forum")),
		));
		$req->important = false;
		if(!$req->success() || !isset($req->val[5]))
		{
			self::logDebug("createTorrent: " . $hash
				. " could not have its replacement metadata snapshotted; the replacement was not committed");
			return self::STE_ERROR;
		}

		$baseDir = $req->val[0];
		$label = rawurldecode($req->val[1]);
		$throttle = $req->val[2];
		$connectionSeed = $req->val[3];
		$topic = (string) $req->val[4];
		$forum = (string) $req->val[5];

		$stagedAt = time();
		$stoppedFallback = null;
		if($stagedRecord !== null
			&& ($stagedRecord['run']['started'] || $stagedRecord['run']['open']))
		{
			// Narrow crash-adoption exception: the predecessor's (0,0) may be
			// the dead transaction's own stop artifact. Only that selected branch
			// inherits the staged record; a fresh daemon-side started/open reading
			// still wins normally.
			$stoppedFallback = self::encodeInheritance($newHash,
				$stagedRecord['run']['started'], $stagedRecord['run']['open'], $stagedAt);
			self::logDebug("createTorrent: " . $hash
				. " will use the staged copy's recorded state only if the daemon selects"
				. " stopped/closed, inheriting the recorded state over the dead run's own stop");
		}
		$startedMarker = self::encodeInheritance($newHash, true, true, $stagedAt);
		$openMarker = self::encodeInheritance($newHash, false, true, $stagedAt);
		$stoppedMarker = $stoppedFallback !== null ? $stoppedFallback
			: self::encodeInheritance($newHash, false, false, $stagedAt);
		$stop = new rXMLRPCRequest(self::replacementStopCommand(
			$hash, $newHash, $stagedAt, $stoppedFallback));
		$stop->important = false;
		if(!$stop->success() || $stop->fault || !is_array($stop->val)
			|| count($stop->val) !== 1 || !is_string($stop->val[0])
			|| !in_array($stop->val[0],
				array($startedMarker, $openMarker, $stoppedMarker), true))
		{
			self::logDebug("createTorrent: " . $hash
				. " did not return a trustworthy daemon-selected marker while being stopped;"
				. " the outcome is left to the replacement sweep");
			return self::STE_ERROR;
		}
		$selectedMarker = (string) $stop->val[0];
		$wasStarted = ($selectedMarker === $startedMarker);
		$wasOpen = $wasStarted || ($selectedMarker === $openMarker);
		self::logDebug("createTorrent: " . $hash . " daemon-selected run state at stop: started="
			. ($wasStarted ? 1 : 0) . " open=" . ($wasOpen ? 1 : 0));
		$stagedRecordStr = self::encodeInheritance($hash, $wasStarted, $wasOpen, $stagedAt);
		$addition = self::buildReplacementAddition(
			$connectionSeed, $throttle, $ratioViews, $existingViews, self::STE_UPDATED, $marker,
			$stagedRecordStr,
			$topic, $forum
		);

		// Stage stopped: a failed pre-commit replacement cannot write shared data.
		$loadedHash = rTorrent::sendTorrent($torrent, false, false, $baseDir,
			$label, $saveUploadedTorrents, false, true, $addition);
		$owner = self::waitForLoad($newHash, $marker);
		if(!$loadedHash || strcasecmp((string) $loadedHash, (string) $newHash) !== 0 || $owner !== 'ours')
		{
			// Restore the old torrent even when the staged status is unknown:
			// d.start on it is safe to repeat and is the only recovery there
			// is. Its RESULT decides what happens to the staged copy: erasing
			// that copy also destroys the marker and record the sweep scans
			// for, so doing it before the predecessor is known to be back
			// would leave a stopped, closed torrent that nothing in the
			// plugin can find again. A failed restore therefore keeps the
			// staged copy, which is exactly what turns this into a stranded
			// transaction the sweep knows how to finish.
			$restored = self::restoreExistingTorrent($hash, $wasOpen, $wasStarted, $selectedMarker);
			// A successful restore clears its exact recovery marker inside the
			// same conditional command. A failed restore keeps it for the sweep.
			if($owner === 'ours')
			{
				if($restored)
					self::eraseStaged($newHash, $marker, $stagedRecordStr);
				else
					self::logDebug("createTorrent: " . $hash . " could not be restored after a failed"
						. " staging; keeping the staged copy " . $newHash
						. " so the sweep can finish the transaction");
			}
			return self::STE_ERROR;
		}

		$cleanupFiles = self::buildObsoleteCleanupFiles($oldTorrent, $torrent, $baseDir);
		self::logDebug('createTorrent: cleanup prepare ' . $hash . ' -> ' . $newHash
			. ' old=' . self::$obsoleteCleanupSummary['old']
			. ' new=' . self::$obsoleteCleanupSummary['new']
			. ' obsolete=' . self::$obsoleteCleanupSummary['obsolete']
			. ' missing=' . self::$obsoleteCleanupSummary['missing']);
		$cleanupJob = null;
		if($cleanupFiles !== false && $cleanupFiles !== null)
			$cleanupJob = erasedataPrepareObsoleteCleanup(
				$hash, $newHash, $marker, $stagedRecordStr, realpath($baseDir), $cleanupFiles);
		if($cleanupFiles === false || ($cleanupFiles !== null && !is_array($cleanupJob)))
		{
			self::logCleanupFailure('durable cleanup preparation failed for ' . $hash . ' -> ' . $newHash
				. '; replacement was aborted before predecessor erase');
			if(self::restoreExistingTorrent($hash, $wasOpen, $wasStarted, $selectedMarker))
				self::eraseStaged($newHash, $marker, $stagedRecordStr);
			else
				self::logDebug('createTorrent: ' . $hash . ' could not be restored after cleanup preparation failed;'
					. ' keeping staged copy ' . $newHash . ' for replacement sweep recovery');
			return self::STE_ERROR;
		}

		// Commit point: erase the old torrent.
		$eraseStatus = RuTrackerAtomicOwnership::erase(
			$hash,
			array(self::REPLACING_KEY => $selectedMarker),
			array('state' => 0, 'is_open' => 0)
		);
		$eraseSuccess = ($eraseStatus === RuTrackerAtomicOwnership::ACTED);
		if(!$eraseSuccess)
		{
			$oldExists = self::torrentExists($hash);
			if($oldExists === true)
			{
				if($cleanupJob !== null && !erasedataCancelObsoleteCleanup($cleanupJob))
				{
					self::logCleanupFailure('prepared obsolete cleanup cancellation failed for ' . $hash . ' -> ' . $newHash
						. '; both generations and recovery markers were retained');
					return self::STE_ERROR;
				}
				// Restore first, erase second, and only on a verified
				// restore -- the same order and the same reason as the
				// staging rollback above. Erasing the staged copy first
				// destroyed the marker before anything knew whether the
				// predecessor was coming back, and the restore's answer was
				// then thrown away.
				if(self::restoreExistingTorrent($hash, $wasOpen, $wasStarted, $selectedMarker))
				{
					self::eraseStaged($newHash, $marker, $stagedRecordStr);
				}
				else
					self::logDebug("createTorrent: " . $hash . " could not be restored after a failed"
						. " commit erase; keeping the staged copy " . $newHash
						. " so the sweep can finish the transaction");
				return self::STE_ERROR;
			}
			if($oldExists === null)
			{
				// Both fates are unknowable: keep the marked staged copy so a
				// later run can adopt it, and touch nothing else.
				return self::STE_ERROR;
			}
			// The old torrent is gone despite the failed erase: proceed.
		}

		$published = false;
		$closeTransaction = true;
		if($cleanupJob !== null)
		{
			$published = erasedataPublishObsoleteCleanup($cleanupJob);
			if(!$published)
			{
				$closeTransaction = false;
				self::logCleanupFailure('durable obsolete cleanup publish failed for ' . $hash . ' -> ' . $newHash
					. '; successor recovery markers were retained');
			}
		}

		$activated = self::activateReplacement(
			$newHash, $wasOpen, $wasStarted, $marker, $stagedRecordStr, $closeTransaction);
		// The transaction is closed only once the daemon has been seen in the
		// intended state. An unconfirmed activation keeps both keys, so the
		// next cycle's sweep finds the row instead of a torrent that merely
		// looks finished -- which is how a replacement sat stopped for a day.
		if(!$activated)
			self::logDebug("createTorrent: ".$newHash." keeps its replacement record: activation was not confirmed");
		if($published && !erasedataKickCollector($hash))
			self::logCleanupFailure('targeted obsolete cleanup kick failed for ' . $hash
				. '; the published job remains scheduled for retry');
		return null;
	}

	static private function appendAnnounceUrls($value, &$urls)
	{
		if(is_array($value))
		{
			foreach($value as $item)
				self::appendAnnounceUrls($item, $urls);
		}
		elseif(is_string($value) && $value !== '' && !in_array($value, $urls, true))
			$urls[] = $value;
	}

	static public function run_ex($hash, $fname, &$performed = null){
		$performed = false;
		$torrent = new Torrent( $fname );
		// Two facts, and they used to leave through the same STE_NOT_NEED at
		// the bottom. "The session copy does not parse" is a torrent nobody
		// could look at -- transient in principle, since rTorrent rewrites
		// that file -- while "no registered handler claims it" is a torrent
		// that is genuinely none of this plugin's business. Settling the first
		// as the second stops the plugin ever looking at the torrent again,
		// and says so in the UI under a word that means the opposite.
		if($torrent->errors())
		{
			self::logDebug("run_ex: " . $hash . " has a session copy that does not parse ("
				. $fname . "); nothing could be read from it, so nothing is concluded");
			return self::STE_CANT_REACH_TRACKER;
		}
		$comment = (string) $torrent->comment();
		// A handler says STE_DECLINED only when the URL is outside its
		// jurisdiction. Every stored verdict, including STE_NOT_NEED, is a real
		// answer and is final: another tracker in a cross-seed announce-list knows
		// nothing about the topic URL that produced it.
		$declinedFromAnnounce = false;
		$askedAlready = array();

		// A comment filter is a substring test over free text: a Kinozal
		// torrent whose description mentions "ранее на rutracker.org" matches
		// RuTracker's. The loop used to RETURN whatever the first match
		// answered, so that torrent was handed to the wrong handler for good
		// and nothing else ever got a look. A handler that declines now simply
		// passes the torrent on.
		foreach (self::$TRACKERS as $commentFilter => $tracker)
		{
			if(!preg_match($commentFilter, $comment)) continue;
			$askedAlready[$commentFilter] = true;
			$verdict = call_user_func($tracker['handler'], $comment, $hash, $torrent);
			if($verdict !== self::STE_DECLINED)
			{
				$performed = true;
				return $verdict;
			}
		}

		$announces = array();
		self::appendAnnounceUrls($torrent->announce(), $announces);
		self::appendAnnounceUrls($torrent->announce_list(), $announces);

		// Announce matching is a fallback only after every comment handler had
		// a chance to claim the topic URL.
		foreach (self::$TRACKERS as $commentFilter => $tracker)
		{
			// It already answered, from better input: five of the seven
			// handlers register the same pattern as both filters (anidub,
			// tapochek, toloka...), so without this the same handler ran twice
			// for one torrent -- once on the comment it is written to read,
			// then again on an announce URL it cannot.
			if(isset($askedAlready[$commentFilter])) continue;
			foreach($announces as $announce)
			{
				if(!preg_match($tracker['announceFilter'], $announce)) continue;
				// The handler is handed the ANNOUNCE url here, and every
				// handler's gate parses a TOPIC url -- so the gate declines. Before
				// STE_DECLINED existed that answer was STE_NOT_NEED, and a magnet-added
				// torrent, or one whose comment was stripped, ended up stamped
				// "No need to check" for ever, with no request and no log line.
				// And it is the torrent that needs looking at most: the pass
				// only sends a row for the full check once layer 1 calls it a
				// candidate, which is exactly when its announces are failing.
				$verdict = call_user_func($tracker['handler'], $announce, $hash, $torrent);
				if($verdict !== self::STE_DECLINED)
				{
					$performed = true;
					return $verdict;
				}
				$declinedFromAnnounce = true;
				break;   // this handler has answered; the next announce is not a second chance
			}
		}

		// Somebody was handed nothing but an announce URL, which no gate can
		// turn into a topic. That is "ask again later", never "nothing to do
		// here" -- and it outranks any comment-based decline, because it is the
		// one answer that established nothing.
		if($declinedFromAnnounce)
		{
			self::logDebug("run_ex: " . $hash . " is claimed by a registered tracker but no handler could"
				. " identify its topic from the comment or the announce; nothing is concluded");
			return self::STE_CANT_REACH_TRACKER;
		}
		// No registered filter claimed either input. A handler that reached a
		// real terminal verdict returned it above; only jurisdiction declines
		// can reach this dispatcher-level STE_NOT_NEED.
		return self::STE_NOT_NEED;
	}

	// The version of the rTorrent that is answering right now, as one log
	// fragment. rTorrentSettings::get() would answer from the cached
	// rtorrent.dat instead -- only a browser-driven get(true) ever refreshes
	// it -- so after an upgrade and a restart it can report the old version
	// for days, which is the single answer this diagnostic must never give.
	// The query is skipped entirely when the debug log is off: nothing pays
	// for a line that is not written.
	static public function liveVersionLabel()
	{
		global $rutrackerCheckDebug;
		$unknown = "client=? api=?";
		if(empty($rutrackerCheckDebug))
			return($unknown);
		$req = new rXMLRPCRequest( array(
			new rXMLRPCCommand(getCmd("system.client_version")),
			new rXMLRPCCommand(getCmd("system.api_version")),
		) );
		$req->important = false;
		if(!$req->success() || !isset($req->val[0], $req->val[1]))
			return($unknown);
		return("client=".trim((string) $req->val[0])." api=".trim((string) $req->val[1]));
	}

	static public function logDebug($message)
	{
		global $rutrackerCheckDebug;
		if(!empty($rutrackerCheckDebug))
			FileUtil::toLog('rutracker_check: ' . preg_replace('/[\r\n]+/', ' ', (string) $message));
	}

	static public function transportFailureDetail($status)
	{
		$status = (int) $status;
		if($status < 0)
		{
			$reasons = array(-100 => 'timeout', -5 => 'connect', -4 => 'dns', -3 => 'socket-create');
			$reason = isset($reasons[$status]) ? $reasons[$status] : 'socket';
			return('transport=socket status=' . $status . ' reason=' . $reason);
		}
		$reasons = array(
			5 => 'proxy-dns', 6 => 'dns', 7 => 'connect', 28 => 'timeout',
			35 => 'tls', 51 => 'tls-certificate', 52 => 'empty-reply',
			56 => 'receive', 60 => 'tls-certificate',
		);
		$reason = isset($reasons[$status]) ? $reasons[$status] : 'curl';
		return('transport=curl-exit code=' . $status . ' reason=' . $reason);
	}

	static public function fetchStatusDetail($status)
	{
		if($status === null || $status === '') return('');
		$status = (int) $status;
		return($status < 100 ? self::transportFailureDetail($status) : 'http-status=' . $status);
	}

	static public function makeClient( $url, $method="GET", $content_type="", $body="" )
	{
		$client = new Snoopy();
		$client->read_timeout = 5;
		$client->_fp_timeout  = 5;

		// Pretend to be a modern browser to reduce 403/anti-bot errors
		$client->agent = self::USER_AGENT;

		@$client->fetchComplex($url, $method, $content_type, $body);

		// Socket errors are negative; the https path stores curl's exit code,
		// which is below any real HTTP status.
		if($client->status < 100)
		{
			$host = @parse_url($url, PHP_URL_HOST);
			self::logDebug("Snoopy fetch failed: host=".(is_string($host) ? $host : 'unknown')." "
				. self::transportFailureDetail($client->status));
		}

		return $client;
	}

	// Shared by run() below and RuTrackerUpdatePass::run()'s direct-write
	// paths (updatepass.php), so an ignored torrent can never flap between
	// STE_IGNORED and a scheduler-derived state depending only on which of
	// the two ever ends up touching it in a given cycle.
	// The label is compared decoded. d.custom1 holds it percent-encoded on
	// every path that goes through rTorrent::sendTorrent() (php/rtorrent.php
	// rawurlencode()s it), and createTorrent() below already rawurldecode()s
	// it for its own use -- but getState() and update.php's fleet scan hand
	// the raw value straight to this function, so an $ignoreLabels entry
	// containing anything that needs escaping (a space, Cyrillic) silently
	// never matched. The shipped defaults ('tv-sonarr', 'radarr') need no
	// escaping, which is why this went unnoticed.
	static public function isIgnoredLabel( $label )
	{
		global $ignoreLabels;
		if(is_null($label) || !isset($ignoreLabels) || !is_array($ignoreLabels)) return(false);
		return( in_array($label, $ignoreLabels) || in_array(rawurldecode((string) $label), $ignoreLabels) );
	}

	static public function run( $hash, $state = null, $time = null, $label = null, &$performed = null )
	{
		// $performed is deliberately narrower than "this invocation changed
		// something": it acknowledges only a real tracker-handler run. The
		// scheduler uses it to retire durable forum-correction work, which an
		// ignored-label decision or a metadata-pump step has not consumed.
		$performed = false;
		// The scheduler passes a cycle-start snapshot, while a manual check can
		// finish and change chk-state before this row is reached. Take the real
		// per-hash claim first, then refresh state, time and label under that same
		// claim so the branch below is chosen from one live reading. A second
		// claim inside either branch would deadlock against our own token.
		$claimToken = self::claimCheck($hash, time());
		if($claimToken === false)
		{
			self::logDebug("run: " . $hash . " is already being checked by another process; leaving it to that one");
			return(true);
		}

		try
		{
			if(!self::getState( $hash, $state, $time, $label ))
			{
				// The torrent is gone: a stale worker, and a successful no-op.
				if($state == self::STE_NOT_NEED)
					return(true);
				// Anything else left getState()'s own STE_INPROGRESS default in
				// place -- and the dispatch below reads that as "another process
				// holds the lock", so the check is silently skipped and reported
				// as successful. A read that failed is not a lock. Say so, and
				// let the caller come back.
				self::logDebug("run: " . $hash . " state could not be read; deferring the check");
				return(false);
			}

			// Skip torrent if its label is in the ignore list
			if(self::isIgnoredLabel($label))
			{
				$state = self::STE_IGNORED;
				self::setState($hash, $state);
				// The sentence goes with the state it explained. init.js appends
				// chk-msg to whatever state is current, so a token left by an
				// earlier verdict reads as "Ignored -- ... confirmation cycle
				// 2/3", which is the opposite of what IGNORED means: nobody
				// looked. The scheduler's own STE_IGNORED write already clears it
				// (RuTrackerUpdatePass::run()); this is the same rule for the
				// path a "check" click takes.
				self::setMessage($hash, '');
				return(true);
			}

			if($state == self::STE_META_PENDING)
			{
				$claim = self::setState($hash, self::STE_INPROGRESS);
				if($claim === null) return(true);	// the torrent is gone
				if(!$claim) return(false);
				$state = RuTrackerMetaFetch::pump($hash, time());
				// null is pump()'s success contract: createTorrent() committed,
				// so this hash no longer exists and there is nothing to write.
				if(!is_null($state)) self::setState($hash, $state);
				return($state != self::STE_CANT_REACH_TRACKER);
			}

			if(($state==self::STE_INPROGRESS) && ((time()-$time)>self::MAX_LOCK_TIME)) $state = 0;

			if($state!==self::STE_INPROGRESS){
				// Kept across the dispatch so a handler that cannot judge (see
				// STE_UNCHANGED) can have this verdict put back: by then the
				// stored value is the STE_INPROGRESS lock written just below.
				$previous = $state;
				$state = self::STE_INPROGRESS;
				$stateWrite = self::setState( $hash, $state );
				if($stateWrite === null) return(true);
				if(!$stateWrite) return(false);

				$fname = rTorrentSettings::get()->session.$hash.".torrent";

				$handlerPerformed = false;
				$handlerVerdict = null;
				if(is_readable($fname))
				{
					$handlerVerdict = self::run_ex($hash, $fname, $handlerPerformed);
					$state = $handlerVerdict;
				}
				else self::logDebug("run: " . $hash . " has no readable session copy at " . $fname
					. "; nothing can be checked and the torrent is flagged");
				if($state===self::STE_UNCHANGED) $state = $previous;
				if($state==self::STE_INPROGRESS) $state=self::STE_ERROR;

				$finalWrite = null;
				if(!is_null($state)) $finalWrite = self::setState($hash, $state);
				// Handler invocation is not durable consumption. In particular,
				// STE_UNCHANGED means it learned nothing, and a false/null final
				// write leaves no verdict for a correction to acknowledge.
				$performed = $handlerPerformed
					&& $handlerVerdict !== self::STE_UNCHANGED
					&& $finalWrite === true;
			}
			return($state != self::STE_CANT_REACH_TRACKER);
		}
		finally
		{
			self::releaseCheck($hash, $claimToken);
		}
	}

}
