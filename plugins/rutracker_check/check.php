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
require_once( dirname(__FILE__) . "/fetcherror.php" );
require_once( dirname(__FILE__) . "/runstate.php" );
require_once( dirname(__FILE__) . "/../../php/xmlrpc_path.php" );
require_once( dirname(__FILE__) . "/../erasedata/removewithdata.php" );
require_once( "metafetch.php" );

eval(FileUtil::getPluginConf( "rutracker_check" ));

// Tests shorten this courtesy delay without changing the production wait budget.
if(!defined('RUTRACKER_CHECK_LOAD_WAIT_DELAY_US')) define('RUTRACKER_CHECK_LOAD_WAIT_DELAY_US', 50000);

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
	const CHKMSG_SUCCESSOR_MISSING = 'successor-missing'; // param: 40-hex missing hash
	const CHKMSG_DELETING		= 'deleting';		// param: "N/M" confirmation cycles
	const CHKMSG_TOPIC_STATUS	= 'topic-status';	// param: dump tor_status
	const CHKMSG_FUSE		= 'fuse';		// param: announce host
	const CHKMSG_ABSORBED		= 'absorbed';		// param: topic id

	const MAX_LOCK_TIME		= 900;	// 15 min
	const ORPHAN_SWEEP_BATCH	= 16;	// max direct presence probes per cycle
	const ORPHAN_SWEEP_BUDGET_NS = 2000000000;	// stop after a slow probe

	// load_raw inserts the torrent from a deferred rTorrent event-loop task,
	// so waiting for the staged copy to appear is the only wait in the
	// replacement transaction; every other command is synchronous.
	const LOAD_WAIT_ATTEMPTS	= 40;
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
	// One operator-visible line per uninterrupted storage outage. A successful
	// update resets the latch, so a later independent outage is reported too.
	private static $claimStoreFailureLogged = false;
	// Stage the handler's message while a terminal verdict remains visible.
	// It is published with a definitive new verdict or discarded on retry.
	private static $activeRunIdentity = null;
	private static $terminalMessageHash = null;
	private static $pendingTerminalMessage = null;
	private static $missingSuccessorMarker = null;
	private static $obsoleteCleanupSummary = array(
		'old' => 0,
		'new' => 0,
		'obsolete' => 0,
		'missing' => 0,
	);

	/**
	 * Register a tracker handler. The first registration of a comment filter
	 * wins; later registrations of that same filter are ignored.
	 *
	 * Registration order is ownership order: run() asks the handlers whose
	 * comment filter matches in this order, and the first that does not
	 * decline owns the torrent. check.php registers RuTracker first.
	 *
	 * @param string      $commentFilter     Regex pattern for torrent comment
	 * @param string      $announceFilter    Regex pattern for announce URL list
	 * @param callable    $handler           Handler function: handler($url, $hash, $torrent)
	 * @param array|null  $announceAuthority Optional. Declares that on this
	 *        tracker a successful announce proves the topic is still current,
	 *        so the scheduler may answer UPTODATE from the counters it already
	 *        holds. It is the LIST OF HOSTS on which that holds -- each, or a
	 *        subdomain of it, matched whole through UrlHost -- rather than a
	 *        flag: see RuTrackerDetector::announceVerdict() for why the loose
	 *        $announceFilter must not be the one to certify a topic alive.
	 * @param string|null $topicPattern      The EXACT test the handler applies
	 *        to a comment before it does anything -- the pattern it would
	 *        decline on. ownerOf() reads it whether or not an authority is
	 *        declared, so a handler may state it alone to make the owner walk
	 *        exact; an authority, on the other hand, is never reached without
	 *        it. $commentFilter is a loose substring test that decides who is
	 *        asked first, not who owns the torrent: run() moves on when the
	 *        handler it asked declines, and a Kinozal filter matches an NNMClub
	 *        topic URL that merely mentions kinozal.tv. The scheduler cannot
	 *        ask a handler without spending a request, so a handler that wants
	 *        the free pass states its acceptance test here and ownerOf() reads
	 *        it. An authority declared without this test is never reached:
	 *        ownerOf() answers null at a handler that declared no test, and
	 *        the free pass is granted to owners only.
	 */
	static public function registerTracker($commentFilter, $announceFilter, $handler,
		$announceAuthority = null, $topicPattern = null)
	{
		if(!array_key_exists($commentFilter, self::$TRACKERS))
		{
			self::$TRACKERS[$commentFilter] = array(
				'announceFilter' => $announceFilter,
				'handler' => $handler,
				'announceAuthority' => $announceAuthority,
				'topicPattern' => $topicPattern,
			);
		}
	}

	/**
	 * The registry record of the handler that owns a torrent, read from its
	 * comment the way run() reads it -- declared rather than performed.
	 *
	 * The walk is run()'s walk made without asking anyone: a handler that
	 * declared its topic pattern is taken to accept exactly what the pattern
	 * accepts and to decline the rest; one that declared none cannot be
	 * second-guessed, so the answer is null rather than a guess. The contract
	 * -- why the comment filter decides who is asked and not who owns -- is
	 * stated once, at registerTracker()'s $topicPattern.
	 *
	 * @return array|null the owner's registry record, or null when the owner
	 *                    cannot be told from the comment alone
	 */
	static public function ownerOf($comment)
	{
		$comment = (string)$comment;
		if($comment === '')
			return null;
		foreach(self::$TRACKERS as $commentFilter => $tracker)
		{
			if(!preg_match($commentFilter, $comment))
				continue;
			if(empty($tracker['topicPattern']))
				return null;
			if(preg_match($tracker['topicPattern'], $comment))
				return $tracker;
		}
		return null;
	}

	/**
	 * The jurisdiction filter and authority hosts declared by the handler
	 * that owns this comment. announceVerdict() is the single place that
	 * checks enabled rows in that jurisdiction and trusted announce hosts.
	 *
	 * @param string $comment topic comment used to resolve ownership
	 * @return array|null owner's jurisdiction and authority, or null when no
	 *                    owner can be established or it has not opted in
	 */
	static public function announceAuthorityFor($comment)
	{
		$owner = self::ownerOf($comment);
		if($owner === null || empty($owner['announceAuthority']))
			return null;
		return array(
			'jurisdiction' => $owner['announceFilter'],
			'authority' => $owner['announceAuthority'],
		);
	}

	// A scheduling/telemetry bucket records the first handler asked by run_ex().
	// The filter is not proof that this handler owns the topic.
	static public function schedulingBucket($comment)
	{
		foreach(self::$TRACKERS as $commentFilter => $tracker)
			if(preg_match($commentFilter, (string)$comment)) return $commentFilter;
		return 'fallback';
	}

	static public function supportedTrackers()
	{
		return array_values(array_column(self::$TRACKERS, 'announceFilter'));
	}

	// Shared by the scheduler rest gate and run()'s retryable-verdict guard.
	// A foreign DELETED/ABSORBED state is not a final RuTracker topic answer;
	// its only settled state here is a known successor pointer.
	static public function isSettledStatus($state, $time, $message, $foreign = false)
	{
		if($time <= 0) return false;
		if(!$foreign && ($state === self::STE_DELETED || $state === self::STE_ABSORBED))
			return true;
		if($state !== self::STE_NOT_NEED) return false;
		$token = explode('|', (string)$message, 2)[0];
		if($token === self::CHKMSG_SUPERSEDED) return true;
		return !$foreign && $token === self::CHKMSG_TOPIC_STATUS;
	}

	// Read a settled NOT_NEED token under the per-hash run claim. An unreadable
	// value is not permission to overwrite a possibly settled verdict.
	static private function storedMessage($hash)
	{
		$req = new rXMLRPCRequest(new rXMLRPCCommand(getCmd("d.get_custom"),
			array($hash, "chk-msg")));
		$req->important = false;
		return $req->success() && isset($req->val[0]) ? (string)$req->val[0] : null;
	}

	// A missing comment cannot establish ownership. If any enabled row may
	// belong to another registered handler, the scheduler must dispatch rather
	// than certify RuTracker from a successful cross-seed announce.
	static public function hasForeignAnnounceRow($trackers)
	{
		foreach(self::$TRACKERS as $tracker)
		{
			if($tracker['handler'] === 'RuTrackerCheckImpl::download_torrent') continue;
			foreach((array)$trackers as $row)
				if(!empty($row['enabled']) && isset($row['url'])
					&& preg_match($tracker['announceFilter'], (string)$row['url'])) return true;
		}
		return false;
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

	/**
	 * The comment of a torrent's session copy, or '' when it cannot be read.
	 *
	 * The scheduler's cycle multicall carries tracker rows and no comment.
	 * This file read gives the conservative dispatch gate and the announce
	 * authority gate the same comment once per row per cycle; handlers in
	 * run() make the final ownership decision.
	 */
	static public function sessionComment($hash)
	{
		if(!class_exists('rTorrentSettings') || !method_exists('rTorrentSettings', 'get'))
			return '';
		$settings = rTorrentSettings::get();
		if(!$settings || empty($settings->session))
			return '';
		$fname = $settings->session . $hash . ".torrent";
		if(!is_file($fname))
			return '';
		$torrent = @new Torrent($fname);
		if($torrent->errors())
			return '';
		return (string) $torrent->comment();
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

	static private function buildObsoleteCleanupFiles($oldTorrent, $newTorrent, $baseDir,
		$oldHash, $newHash, $marker, $record)
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
		$otherOwners = erasedataCleanupOtherOwnerSnapshot(
			$oldHash, $newHash, $marker, $record);
		if($otherOwners === false)
			return(false);
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

			$owner = erasedataCleanupOtherOwnerState($otherOwners,
				$candidate, $identity['stat']);
			if($owner === 'unknown')
				return(false);
			if($owner === 'claimed')
				continue;
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

	static public function setState( $hash, $state, $localId = null, $expectedCustoms = array() )
	{
		return(self::writeCustomProjection($hash,
			self::stateCommands($hash, $state, time()), "setState", $localId, $expectedCustoms));
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
	static private function writeCustomProjection($hash, $commands, $context, $localId = null,
		$expectedCustoms = array())
	{
		return(RuTrackerCustomProjection::write($hash, $commands, $context, $localId,
			$expectedCustoms));
	}

	/**
	 * Persist one fast-path verdict as one small daemon request.
	 *
	 * A scheduler-supplied local id guards the whole projection in one daemon
	 * branch. Other callers retain the existing multicall path. Either reply
	 * can be lost or truncated after effects landed, so read back the selected
	 * fields, and the local id when guarded, before accepting an unknown result.
	 * A confirmed missing hash is the sole null outcome.
	 *
	 * @return bool|null true when the whole desired projection is observed,
	 *                   null when the target is confirmed absent, false otherwise
	 */
	static public function setFastVerdict($hash, $state, $message = null, $clearDeletion = false,
		$localId = null, $expectedCustoms = array())
	{
		$now = time();
		$commands = self::stateCommands($hash, $state, $now);
		if($message !== null)
			$commands[] = new rXMLRPCCommand(getCmd("d.set_custom"),
				array($hash, "chk-msg", (string) $message));
		if($clearDeletion)
			$commands[] = new rXMLRPCCommand(getCmd("d.set_custom"),
				array($hash, "chk-del", ""));

		return(self::writeCustomProjection($hash, $commands, "setFastVerdict", $localId,
			$expectedCustoms));
	}

	// Retire an empty metadata generation only while the callback has not
	// published another claim. All predicates and writes run in one daemon
	// branch, so a late mark callback either blocks this verdict or restores M.
	static private function setRetiredMetaVerdict($hash, $state, $ignored, $localId)
	{
		$commands = self::stateCommands($hash, $state, time());
		if($ignored)
			$commands[] = new rXMLRPCCommand(getCmd("d.set_custom"),
				array($hash, "chk-msg", ""));
		return(self::writeCustomProjection($hash, $commands, "setRetiredMetaVerdict", $localId,
			array("chk-state" => (string) self::STE_META_PENDING,
				"chk-meta-new" => "", "chk-meta-until" => "")));
	}

	// Replace a disproved successor token with a durable retry marker before
	// asking later layers. A retained terminal's ordinary setMessage() buffer
	// cannot do this: an inconclusive answer would discard the clear.
	static public function recordMissingSuccessor($hash, $successor)
	{
		$localId = self::activeRunLocalId($hash);
		if($localId === null || !is_string($successor)
			|| preg_match('/^[0-9A-F]{40}$/D', $successor) !== 1)
		{
			self::logUnrepairable('recordMissingSuccessor: ' . $hash
				. ' invalid run identity or successor; chk-msg superseded verdict retained');
			return(false);
		}
		$marker = self::CHKMSG_SUCCESSOR_MISSING . '|' . $successor;
		$write = self::writeCustomProjection($hash, array(
			new rXMLRPCCommand(getCmd('d.set_custom'),
				array($hash, 'chk-msg', $marker))), 'recordMissingSuccessor', $localId);
		if($write !== true)
		{
			self::logUnrepairable('recordMissingSuccessor: ' . $hash
				. ' chk-msg marker write unconfirmed; successor verdict needs retry');
			return(false);
		}
		self::logRoutine('recordMissingSuccessor: ' . $hash . ' successor-missing=' . $successor
			. '; durable marker published; retry pending');
		return(self::retainMissingSuccessor($hash, $successor));
	}

	// A worker may resume after the marker landed but before ERROR did.
	// Confirm the same daemon generation before allowing later layers to
	// replace it, and buffer their messages until a final verdict exists.
	static public function retainMissingSuccessor($hash, $successor)
	{
		$localId = self::activeRunLocalId($hash);
		if($localId === null || !is_string($successor)
			|| preg_match('/^[0-9A-F]{40}$/D', $successor) !== 1)
		{
			self::logUnrepairable('retainMissingSuccessor: ' . $hash
				. ' invalid run identity or successor; chk-msg marker cannot be confirmed');
			return(false);
		}
		$marker = self::CHKMSG_SUCCESSOR_MISSING . '|' . $successor;
		$read = new rXMLRPCRequest(array(
			new rXMLRPCCommand(getCmd('d.get_custom'), array($hash, 'chk-msg')),
			new rXMLRPCCommand(getCmd('d.get_local_id'), array($hash))));
		$read->important = false;
		if(!$read->success() || $read->fault || !is_array($read->val)
			|| count($read->val) !== 2 || $read->val[0] !== $marker
			|| $read->val[1] !== $localId)
		{
			self::logUnrepairable('retainMissingSuccessor: ' . $hash
				. ' chk-msg marker readback unconfirmed; successor verdict needs retry');
			return(false);
		}
		self::$missingSuccessorMarker = $marker;
		self::$terminalMessageHash = $hash;
		self::$pendingTerminalMessage = '';
		return(true);
	}

	// Writes chk-msg outside a retained-terminal dispatch. Inside it, stage
	// the last token (or empty clear) until the new state and clock are known.
	static public function setMessage( $hash, $message )
	{
		if(self::$terminalMessageHash === $hash)
		{
			self::$pendingTerminalMessage = (string) $message;
			return(true);
		}
		return(self::writeHandlerCustom($hash, "chk-msg", $message));
	}

	// The hash alone cannot identify a torrent after erase + same-hash readd.
	static public function activeRunLocalId($hash)
	{
		return(self::$activeRunIdentity !== null && self::$activeRunIdentity['hash'] === $hash
			? self::$activeRunIdentity['localId'] : null);
	}

	// Handler writes share the run() identity captured with live state. Outside
	// a run, retain the ordinary single-field setter.
	static public function writeHandlerCustom($hash, $field, $value)
	{
		if(!in_array($field, array("chk-msg", "chk-del", "chk-topic"), true)) return(false);
		$command = new rXMLRPCCommand(getCmd("d.set_custom"),
			array($hash, $field, (string) $value));
		$localId = self::activeRunLocalId($hash);
		if($localId !== null)
			return(self::writeCustomProjection($hash, array($command), "writeHandlerCustom",
				$localId) === true);
		$req = new rXMLRPCRequest($command);
		$req->important = false;
		return($req->success());
	}

	static protected function getState( $hash, &$state, &$time, &$label, &$localId = null, &$message = null )
	{
		$state = self::STE_INPROGRESS;
		$time = time();
		$label = "";
		$localId = null;
		$message = null;

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
			new rXMLRPCCommand( getCmd("d.get_custom1"), $hash ),
			new rXMLRPCCommand( getCmd("d.get_local_id"), $hash ),
			new rXMLRPCCommand( getCmd("d.get_custom"), array($hash, "chk-msg") )
			));
		$req->important = false;
		$readOk = $req->success();
		$complete = $readOk && isset($req->val[0], $req->val[1], $req->val[2], $req->val[3], $req->val[4])
			&& is_string($req->val[4]);
		if($readOk && !$complete)
			self::logUnrepairable("getState: " . $hash
				. " chk-state/chk-time/chk-msg read incomplete; deferring without state mutation");
		if($complete)
		{
			// An UNSET custom reads back as '' -- that alone is the absent 0.
			// A reading that will not parse is NOT state 0 ("never checked"),
			// on which run() dispatches a full destructive check.
			$readState = $req->val[0] === '' ? 0 : RuTrackerRpcValue::canonicalNonnegativeInteger($req->val[0]);
			$readTime = $req->val[1] === '' ? 0 : RuTrackerRpcValue::canonicalNonnegativeInteger($req->val[1]);
			if($readState === null || $readTime === null)
			{
				// Ungated: this one never heals. A read that merely FAILED is
				// answered again next cycle, but bytes that will not parse are
				// still there next cycle, and nothing rewrites them -- run()
				// returns below before setState(), parseMulticall() drops the
				// row out of the scheduler snapshot, and flushVerdicts() leaves
				// it out of the fresh scan. The torrent is simply never checked
				// again. See logUnrepairable().
				self::logUnrepairable("getState: " . $hash . " answered with a malformed chk-state/chk-time;"
					. " nothing rewrites it, so this torrent will not be checked again until it is cleared");
				return(false);
			}
			if(!is_string($req->val[3]) || preg_match('/^[0-9A-F]{40}$/D', $req->val[3]) !== 1)
			{
				self::logUnrepairable("getState: " . $hash . " answered with an invalid d.local_id; deferring check");
				return(false);
			}
			$state = $readState;
			$time = $readTime;
			$label = $req->val[2];
			$localId = $req->val[3];
			$message = $req->val[4];
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
			rTorrent::additionCommand("d.set_custom",self::REPLACEMENT_MARKER_KEY,$marker),
			// Shares the marker's privileged position for the same reason:
			// the first input_error aborts every command after it, and a
			// record that never lands leaves the row in the legacy branch --
			// which starts nothing -- rather than in a wrong guess.
			rTorrent::additionCommand("d.set_custom",self::INHERIT_KEY,$inherit),
			// d.set_connection_seed= resolves to d.connection_seed.set, which
			// rTorrent registers PRIVATE: it works here only because a load
			// command list is executed internally, not through the XMLRPC
			// entry point. Moving it into a post-load system.multicall would
			// silently fault.
			rTorrent::additionCommand("d.set_connection_seed",$connectionSeed),
			rTorrent::additionCommand("d.set_custom","chk-state",$state),
			rTorrent::additionCommand("d.set_custom","chk-time",$now),
			rTorrent::additionCommand("d.set_custom","chk-stime",$now),
		);
		// Only when the predecessor had them: an empty value would be written
		// as an empty custom, which resolveForum() and rememberTopic() would
		// then read as "set to nothing" rather than "never set".
		if($topic !== '')
			$addition[] = rTorrent::additionCommand("d.set_custom","chk-topic",$topic);
		if($forum !== '')
			$addition[] = rTorrent::additionCommand("d.set_custom","chk-forum",$forum);
		if(!empty($throttle))
			$addition[] = rTorrent::additionCommand("d.set_throttle_name",$throttle);

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
				$addition[] = rTorrent::additionCommand("view.set_visible",$ratioView);
			else
			{
				$addition[] = rTorrent::additionCommand("d.views.push_back_unique",$ratioView);
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
				usleep(RUTRACKER_CHECK_LOAD_WAIT_DELAY_US);
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
	// work -- a check erases stubs, hands parsed metainfo to createTorrent(), and stops
	// and reloads the user's torrent.
	//
	// The window is wider than the two statements around the write, because
	// the scheduler dispatches on a chk-state captured by update.php's
	// cycle-start multicall: a click that starts first stays invisible to that
	// pass for the rest of the cycle, which the paced announce sleeps and dump
	// fetches make minutes long. And batch_check.php takes no cycle lock, so a
	// click during the pass is the ordinary case, not a rare one.
	//
	// A contender observes a PUBLISHED owner under the state-document lock.
	// Its lease begins then, not at the wall-clock stamp: a forward clock step
	// must not give the hash to two workers, and a backward step must not leave
	// an orphan waiting for wall time to catch up. Starting at grant would be
	// earlier than the durable write if that write stalled.
	static private function claimSince($entry)
	{
		return(RuTrackerRpcValue::canonicalNonnegativeInteger(is_array($entry) ? ($entry['since'] ?? null) : $entry));
	}

	static private function claimClock()
	{
		$boot = @file_get_contents('/proc/sys/kernel/random/boot_id');
		$offsets = @file_get_contents('/proc/self/timens_offsets');
		if(!is_string($boot) || !preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/D', trim($boot))
			|| !is_string($offsets)
			|| !preg_match('/^monotonic[ \t]+(-?(?:0|[1-9][0-9]*))[ \t]+(0|[1-9][0-9]*)[ \t]*$/m', $offsets, $match))
			return(null);
		$mono = hrtime(true);
		if(!is_int($mono) || $mono < 0) return(null);
		return(array('clock' => trim($boot) . ':' . $match[1] . ':' . $match[2], 'mono' => $mono));
	}

	static private function claimObservationMatches($observed, $since, $token, $clock)
	{
		return(is_array($observed)
			&& ($observed['since'] ?? null) === $since
			&& ($observed['token'] ?? null) === $token
			&& ($observed['clock'] ?? null) === $clock['clock']
			&& isset($observed['mono']) && is_int($observed['mono'])
			&& $observed['mono'] >= 0 && $observed['mono'] <= $clock['mono']);
	}

	static private function claimObservation($since, $token, $clock)
	{
		return(array('since' => $since, 'token' => $token,
			'clock' => $clock['clock'], 'mono' => $clock['mono']));
	}

	// Observe tokenized foreign generations even if nobody checks their hash
	// again. A missing hash is proved outside the state lock; the final write
	// still requires the exact generation and observation seen before the probe.
	static public function sweepOrphanClaims()
	{
		$candidates = array();
		$clockUnavailable = false;
		$stored = RuTrackerState::update('meta-claims', function($claims) use (&$candidates, &$clockUnavailable) {
			$clock = self::claimClock();
			if($clock === null)
			{
				$clockUnavailable = true;
				return($claims);
			}
			foreach($claims as $hash => $entry)
			{
				if(!is_string($hash) || !preg_match('/^[0-9a-fA-F]{40}$/D', $hash) || !is_array($entry))
					continue;
				$since = self::claimSince($entry);
				$token = $entry['token'] ?? null;
				if($since === null || !is_string($token) || !preg_match('/^[0-9a-f]{16}$/D', $token))
					continue;
				$observed = $entry['orphan_observed'] ?? null;
				if(!self::claimObservationMatches($observed, $since, $token, $clock))
				{
					$entry['orphan_observed'] = self::claimObservation($since, $token, $clock);
					$claims[$hash] = $entry;
				}
				elseif(($clock['mono'] - $observed['mono']) > self::MAX_LOCK_TIME * 1000000000)
					$candidates[] = array('hash' => $hash, 'since' => $since, 'token' => $token,
						'observed' => $observed);
			}
			return($claims);
		});
		if(!$stored || $clockUnavailable)
		{
			self::logUnrepairable('sweepOrphanClaims: meta-claims could not be observed; '
				. ($clockUnavailable ? 'monotonic-clock-unavailable' : 'claim-store-unavailable'));
			return(false);
		}
		if(!$candidates) return(true);
		usort($candidates, function($a, $b) {
			return($a['observed']['mono'] <=> $b['observed']['mono']);
		});
		$batchStarted = hrtime(true);
		foreach(array_slice($candidates, 0, self::ORPHAN_SWEEP_BATCH) as $candidate)
		{
			if(hrtime(true) - $batchStarted >= self::ORPHAN_SWEEP_BUDGET_NS) break;
			$presence = self::torrentExists($candidate['hash']);
			$removed = false;
			$requeued = false;
			$clockUnavailable = false;
			$settled = RuTrackerState::update('meta-claims', function($claims) use ($candidate, $presence, &$removed, &$requeued, &$clockUnavailable) {
				$hash = $candidate['hash'];
				$entry = $claims[$hash] ?? null;
				if(!is_array($entry) || self::claimSince($entry) !== $candidate['since']
					|| ($entry['token'] ?? null) !== $candidate['token']
					|| ($entry['orphan_observed'] ?? null) !== $candidate['observed'])
					return($claims);
				$clock = self::claimClock();
				if($clock === null)
				{
					$clockUnavailable = true;
					return($claims);
				}
				if(!self::claimObservationMatches($candidate['observed'],
					$candidate['since'], $candidate['token'], $clock)
					|| ($clock['mono'] - $candidate['observed']['mono']) <= self::MAX_LOCK_TIME * 1000000000)
					return($claims);
				if($presence === false)
				{
					unset($claims[$hash]);
					$removed = true;
				}
				else
				{
					// Requeue a present or uncertain hash behind other aged candidates.
					$entry['orphan_observed'] = self::claimObservation(
						$candidate['since'], $candidate['token'], $clock);
					$claims[$hash] = $entry;
					$requeued = true;
				}
				return($claims);
			});
			if(!$settled || $clockUnavailable)
			{
				self::logUnrepairable('sweepOrphanClaims: meta-claims could not be settled; '
					. ($clockUnavailable ? 'monotonic-clock-unavailable' : 'claim-store-unavailable'));
				return(false);
			}
			if($removed) self::logDebug('sweepOrphanClaims: reaped absent hash ' . $candidate['hash']);
			elseif($requeued && $presence === null)
				self::logUnrepairable('sweepOrphanClaims: retained ' . $candidate['hash'] . ': presence-unknown');
		}
		return(true);
	}

	// Returns the owner token on success, false when the hash is already
	// claimed, and null when the claim store could not be updated. All owner
	// changes happen in one RuTrackerState::update() transaction.
	static private function claimCheck($hash, $now, &$expiredPrevious = null)
	{
		$expiredPrevious = false;
		$token = bin2hex(random_bytes(8));
		$granted = false;
		$futureNotice = false;
		$leaseNotice = null;
		$leaseExpired = false;
		// $now may be a cycle-start snapshot, so diagnose future stamps with
		// a fresh wall reading. Wall time is never used to expire ownership.
		$wallNow = time();
		$stored = RuTrackerState::update('meta-claims', function($claims) use ($hash, $now, $wallNow, $token,
			&$granted, &$futureNotice, &$leaseNotice, &$leaseExpired) {
			if(array_key_exists($hash, $claims))
			{
				$entry = $claims[$hash];
				$since = self::claimSince($entry);
				if($since === null)
				{
					self::logUnrepairable('claimCheck: the claim on ' . $hash
						. ' carries an unreadable timestamp; it is kept and keeps blocking,'
						. ' and nothing will retire it');
					return($claims);
				}

				$reason = null;
				$owner = is_array($entry) ? ($entry['token'] ?? null) : null;
				if(!is_string($owner) || !preg_match('/^[0-9a-f]{16}$/D', $owner))
					$reason = 'legacy-or-invalid-token';
				else
				{
					$clock = self::claimClock();
					if($clock === null) $reason = 'monotonic-clock-unavailable';
					else
					{
						$observed = $entry['lease_observed'] ?? null;
						$matches = self::claimObservationMatches($observed, $since, $owner, $clock);
						if($matches && ($clock['mono'] - $observed['mono']) > self::MAX_LOCK_TIME * 1000000000)
						{
							unset($claims[$hash]);
							$leaseExpired = true;
						}
						elseif(!$matches)
						{
							// This sample follows the locked read of the existing entry.
							// A changed generation or clock begins a whole new lease.
							$entry['lease_observed'] = self::claimObservation($since, $owner, $clock);
							$reason = is_array($observed) ? 'monotonic-observation-reset' : null;
						}
					}
				}

				if(array_key_exists($hash, $claims))
				{
					if($reason !== null && (!is_array($entry) || ($entry['lease_notice'] ?? null) !== $reason))
					{
						if(!is_array($entry)) $entry = array('since' => $since);
						$entry['lease_notice'] = $reason;
						$leaseNotice = $reason;
					}
					if(($since - $wallNow) > self::MAX_LOCK_TIME)
					{
						if(!is_array($entry)) $entry = array('since' => $since);
						if(($entry['future_notice'] ?? null) !== $since
							|| ($entry['future_notice_token'] ?? null) !== $owner)
						{
							$entry['future_notice'] = $since;
							$entry['future_notice_token'] = $owner;
							$futureNotice = true;
						}
					}
					$claims[$hash] = $entry;
				}
			}
			if(array_key_exists($hash, $claims)) return($claims);
			$granted = true;
			$claims[$hash] = array('since' => (int) $now, 'token' => $token);
			return($claims);
		});
		if(!$stored)
		{
			if(!self::$claimStoreFailureLogged)
			{
				self::logUnrepairable('claimCheck: claim storage is unavailable for meta-claims;'
					. ' torrent checks are deferred until the document and its lock are writable');
				self::$claimStoreFailureLogged = true;
			}
			return(null);
		}
		self::$claimStoreFailureLogged = false;
		if($futureNotice)
			self::logUnrepairable('claimCheck: the claim on ' . $hash
				. ' has a future timestamp; retained for its possible owner and observed for a monotonic lease');
		if($leaseNotice !== null)
			self::logUnrepairable('claimCheck: the claim on ' . $hash
				. ' is retained: ' . $leaseNotice . '; automatic expiry deferred');
		if($leaseExpired)
			self::logUnrepairable('claimCheck: the claim on ' . $hash
				. ' completed its observed monotonic lease; a new owner was granted');
		$expiredPrevious = $leaseExpired && $granted;
		return($granted ? $token : false);
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
		if(!is_string($token) || $token === '')
			return(false);
		self::releaseCheck($hash, $token);
		return(true);
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
		// One line per outcome. A SKIPPED used to log both "Skipped" and
		// "Could not confirm" for the same status, which reads as a
		// contradiction and names two different things to go and look at: a
		// SKIPPED is the guard refusing on purpose because the row no longer
		// matches, anything else is an attempt whose result was not readable.
		self::logDebug("activateReplacement: activation of " . $hash
			. ($status === RuTrackerAtomicOwnership::SKIPPED
				? " was skipped: the row no longer carries the expected ownership or run state"
				: " could not be confirmed"));
		return(false);
	}

	/**
	 * THE ungated channel, and the only one in this plugin.
	 *
	 * logDebug() below is gated on $rutrackerCheckDebug, which conf.php ships
	 * as false, so everything reported there says nothing at all at the
	 * shipped default. That is RIGHT for a refusal that repairs itself: a line
	 * per cycle for every corrupt value would be noise in ruTorrent's shared
	 * application log, and the next cycle fixes it anyway. It is wrong for a
	 * refusal that never heals, which then wedges something for good and
	 * announces it to nobody -- the fault two earlier rounds of this work were
	 * rejected for. A refusal must be self-healing or visible; anything that
	 * cannot be the first has to be the second, and this is how.
	 *
	 * The public logUnrepairable() wrapper serves refusals in forumindex.php,
	 * announce.php, metafetch.php and here; announce.php guards its call because
	 * that file also loads standalone. Successful publication of a durable
	 * missing-successor marker uses this same writer to expose the retry cause.
	 */
	static private function logRoutine($message)
	{
		FileUtil::toLog('rutracker_check: ' . preg_replace('/[\r\n]+/', ' ', (string) $message));
	}

	static public function logUnrepairable($message)
	{
		self::logRoutine($message);
	}

	// A cleanup that could not be confirmed leaves a durable inconsistency
	// between the two generations behind it, so it reports on the same ungated
	// channel for the same reason. Kept as its own name because that is what
	// its call sites are about, not because it is a second channel.
	static private function logCleanupFailure($message)
	{
		self::logUnrepairable($message);
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
	static public function decodeInheritance($value, &$shaped = null)
	{
		return(RuTrackerReplacementRecord::decode($value, $shaped));
	}

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
	 * The one place downloaded bytes become torrent metainfo.
	 *
	 * Answers with the parsed Torrent, or null when the bytes are not metainfo
	 * at all. Null says nothing about the topic: a login wall, a ratio gate, a
	 * protection page and a truncated download all arrive here as HTTP 200 and
	 * all come back null, so every caller has to treat that as "could not
	 * check" and retry. Whoever needs the torrent afterwards takes the object
	 * returned here; decoding the same bytes twice is both wasted work and a
	 * chance for two callers to disagree about what they hold.
	 */
	static public function parseMetainfo($payload)
	{
		if(!is_string($payload) || $payload === '') return(null);
		// PHP 7.4 warns when Torrent probes binary metainfo as a filename.
		$torrent = @new Torrent($payload);
		if($torrent->errors()) return(null);
		$hash = $torrent->hash_info();
		if(!is_string($hash) || !preg_match('/^[0-9a-fA-F]{40}$/D', $hash)) return(null);
		// An empty info.name is refused here, before the bytes can reach
		// load.raw_start. rTorrent 0.16.20 does not decline such a torrent --
		// it ABORTS: proven on a bench container, where the payload 0.16.21
		// declines gracefully terminates 0.16.20 outright and takes every
		// torrent on the daemon with it. These bytes come from a tracker, so
		// one malformed response would be a fleet-wide outage.
		//
		// Deliberately NOT gated on the daemon version. On 0.16.21 the torrent
		// is still not inserted, only quietly, so refusing it early is right
		// there too -- and a version gate would fail OPEN exactly when the
		// version probe is stale or unanswered, which is when it is needed.
		// hash_info() above proves the info dictionary parsed, so a missing
		// name is a genuinely nameless torrent, not a decode failure.
		$name = $torrent->name();
		if(!is_string($name) || $name === '') return(null);
		return($torrent);
	}

	/**
	 * Common validated replacement tail for HTTP download clients.
	 *
	 * Reusable across sibling handlers: validates HTTP 200 and parses the body
	 * once, then hands the parsed Torrent to createTorrent(). Returns
	 * STE_CANT_REACH_TRACKER on non-200 or non-metainfo responses, both of
	 * which are retryable and neither of which proves a topic was removed.
	 */
	static public function createTorrentFromDownload($client, $hash, $oldTorrent = null)
	{
		if(!is_object($client) || intval($client->status) !== 200)
			return(self::STE_CANT_REACH_TRACKER);
		$torrent = self::parseMetainfo((string) $client->results);
		if($torrent === null)
			return(self::STE_CANT_REACH_TRACKER);
		return(self::createTorrent($torrent, $hash, $oldTorrent));
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
		$stoppedFallback = null, $localId = null)
	{
		$started = self::encodeInheritance($newHash, true, true, $stagedAt);
		$open = self::encodeInheritance($newHash, false, true, $stagedAt);
		$stopped = $stoppedFallback !== null
			? (string) $stoppedFallback
			: self::encodeInheritance($newHash, false, false, $stagedAt);
		$notStarted = getCmd('branch=') . getCmd('d.is_open=')
			. ',' . self::quoteRtorrentArgument(self::replacementStopBody($open))
			. ',' . self::quoteRtorrentArgument(self::replacementStopBody($stopped));
		$startedBody = self::replacementStopBody($started);
		if($localId !== null)
		{
			$stateBranch = getCmd('branch=') . getCmd('d.get_state=')
				. ',' . self::quoteRtorrentArgument($startedBody)
				. ',' . self::quoteRtorrentArgument($notStarted);
			return(new rXMLRPCCommand('branch', array(
				$oldHash,
				'equal=' . getCmd('d.get_local_id=') . ',cat=' . $localId,
				$stateBranch,
				'cat=stale-generation',
			)));
		}
		return(new rXMLRPCCommand('branch', array(
			$oldHash,
			getCmd('d.get_state='),
			$startedBody,
			$notStarted,
		)));
	}

	/**
	 * Hand rTorrent::sendTorrent() a replacement that claims no file on disk.
	 *
	 * sendTorrent() reads Torrent::getFileName() as "a file this plugin owns":
	 * it unlinks it when $saveUploadedTorrents is off, reuses its path for a
	 * torrent too large for one XMLRPC packet, and advertises it as x-filename.
	 * A replacement handed to us by a tracker handler carries no filename, but
	 * one harvested through rTorrent::getSource() carries rTorrent's own
	 * session copy -- or, when getSource() falls back to d.get_tied_to_file, a
	 * .torrent that belongs to the user. None of those are ours to delete or
	 * reuse, and before metainfo was parsed once none of them ever reached
	 * sendTorrent(), because the replacement was rebuilt from bytes here.
	 *
	 * Clearing the field is deliberate: rebuilding the object would decode the
	 * same metainfo a second time, which is exactly what parsing it once exists
	 * to prevent. Torrent exposes no setter, so this reaches the protected
	 * field directly rather than re-parsing.
	 *
	 * It mutates the object it is given and hands the SAME instance back -- it
	 * is not a copy, whatever an earlier name suggested. Anything still holding
	 * that reference sees the cleared field, which is what the caller wants and
	 * what every present caller relies on. Returns null if the field could not
	 * actually be cleared.
	 */
	static private function disownFile($torrent)
	{
		if(!($torrent instanceof Torrent) || $torrent->getFileName() === null)
			return($torrent);
		$disown = Closure::bind(function () {
			$this->filename = null;
		}, $torrent, 'Torrent');
		$disown();
		// Verify, do not assume. The closure names a protected field by string,
		// so if Torrent ever renames it this quietly creates a DYNAMIC property
		// instead and getFileName() keeps returning the path -- the guard would
		// fail open, and no test can see it because the suite substitutes its
		// own Torrent. Reading the field back turns that into a refusal.
		if($torrent->getFileName() !== null)
		{
			self::logCleanupFailure('createTorrent: the replacement still claims '
				. 'a file on disk after being disowned, so it was not handed to '
				. 'rTorrent; Torrent::$filename has probably been renamed');
			return(null);
		}
		return($torrent);
	}

	// A collision is a retryable transaction refusal, not topic evidence.
	// It must remain visible even when a truthful terminal state is retained.
	static private function logReplacementCollision($oldHash, $newHash, $kind)
	{
		self::logUnrepairable('createTorrent: same-hash-collision old=' . $oldHash
			. ' new=' . $newHash . ' kind=' . $kind . '; retaining both torrents');
	}

	/**
	 * Replace $hash with an already parsed replacement.
	 *
	 * $torrent is the Torrent parseMetainfo() returned, or the one a handler
	 * downloaded and patched itself -- never bytes. Metainfo is decoded at one
	 * boundary and the object travels from there, so this never decodes again.
	 *
	 * Anything else is a caller that could not produce metainfo, and the answer
	 * is STE_ERROR: an error to retry next cycle, with nothing changed. It is
	 * not a deletion. A tracker that really has removed a topic says so in its
	 * own words, and each handler recognises that signal for itself, well
	 * before it gets here.
	 */
	static public function createTorrent($torrent, $hash, $oldTorrent = null){
		global $saveUploadedTorrents;

		if(!($torrent instanceof Torrent) || $torrent->errors())
		{
			self::logDebug("createTorrent: " . $hash . " was handed metainfo that is not a parsed"
				. " torrent; nothing is changed and the check stays retryable");
			return self::STE_ERROR;
		}

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
			// A matching hash alone cannot prove external supersession or this
			// transaction's ownership. Leave both torrents untouched and retry.
			if(!$markerReq->success() || !isset($markerReq->val[3]))
			{
				self::logDebug("createTorrent: " . $hash . " could not read the marker of the existing "
					. $newHash . "; the replacement is abandoned with nothing changed");
				return self::STE_ERROR;
			}
			if((string) $markerReq->val[0] === '')
			{
				self::logReplacementCollision($hash, $newHash, 'unmarked');
				return self::STE_ERROR;
			}
			if(!self::isPluginReplacementMarker((string) $markerReq->val[0]))
			{
				self::logReplacementCollision($hash, $newHash, 'foreign-marker');
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
				self::logReplacementCollision($hash, $newHash,
					$stagedRecord === null ? 'invalid-record' : 'different-predecessor');
				return self::STE_ERROR;
			}
			// d.get_state and d.is_open are 0/1 and nothing else, proved BEFORE
			// any branch below erases the occupant, retires its keys or
			// activates the replacement: intval() turned every unreadable
			// answer into the "stopped and closed" that authorises the erase.
			$stagedState = RuTrackerRpcValue::canonicalNonnegativeInteger($markerReq->val[1]);
			$stagedOpen = RuTrackerRpcValue::canonicalNonnegativeInteger($markerReq->val[2]);
			if(!in_array($stagedState, array(0, 1), true) || !in_array($stagedOpen, array(0, 1), true))
			{
				self::logDebug("createTorrent: " . $hash . " found " . $newHash
					. " reporting an unreadable run state; the occupant and its keys are retained");
				return self::STE_ERROR;
			}
			$reconciled = self::reconcileExistingCleanup(
				$hash, $newHash, (string) $markerReq->val[0], $rawStagedRecord);
			if($reconciled === null)
				return self::STE_ERROR;
			if($reconciled === 'committed')
			{
				if($stagedState !== 0 || $stagedOpen !== 0)
				{
					self::clearReplacementRecord($newHash, (string) $markerReq->val[0], $rawStagedRecord,
						array('state' => $stagedState, 'is_open' => $stagedOpen));
					return self::STE_ERROR;
				}
				else
					self::activateReplacement($newHash, $stagedRecord['run']['open'], $stagedRecord['run']['started'],
						(string) $markerReq->val[0], $rawStagedRecord);
				return null;
			}
			if($stagedState !== 0 || $stagedOpen !== 0)
			{
				// OLD presence has already selected cancellation or recovery.
				// Only after that durable state is reconciled may a live successor
				// retire the owned keys; it is never treated as disposable.
				self::clearReplacementRecord($newHash, (string) $markerReq->val[0], $rawStagedRecord,
					array('state' => $stagedState, 'is_open' => $stagedOpen));
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
		// Canonicalised for the load command list, which is a DSL, not XML.
		// These two are the only values in that list whose bytes come from a
		// field a third party writes freely: the marker is hex from
		// random_bytes, the inheritance record is comma-free by construction
		// (see encodeInheritance()), the rest are ints or enums, and the label
		// is rawurlencode'd. d.custom.set splits its own arguments on commas,
		// so a comma here would make it a three-argument call -- an
		// input_error which, by the abort semantics documented at
		// buildReplacementAddition(), would drop the tail of the list. That
		// truncation is a conclusion from those documented semantics, not an
		// observed failure. A non-canonical id is wrong even without a comma:
		// "007" names no topic to topicsAwaitingForum() or resolveForum(), so
		// forwarding it writes a value the successor's own readers refuse.
		// trim() only, and only on the COPY: transport whitespace is not the
		// question, the spelling of the id is. Nothing here writes back to the
		// predecessor's own customs, and that restraint is load-bearing for
		// exactly ONE of these two keys. chk-forum is compared as BYTES by its
		// only other writer: RuTrackerForumIndex::writeForumMapping() holds its
		// lock and, on a call that is NOT authoritative, tests the stored value
		// verbatim against $expectedForum -- a crawl's pre-sweep snapshot --
		// answering FORUM_WRITE_SUPERSEDED on any difference. An authoritative
		// caller skips that guard, so today only the crawl reaches it. The
		// second byte test has no such qualifier: every call compares the
		// stored value verbatim against the id it means to write and answers
		// FORUM_WRITE_CURRENT on a match. Rewriting those bytes
		// anywhere else therefore changes what that compare-and-swap sees, which
		// is why " 22" is left as " 22" on the row it is stored on. chk-topic is
		// the other shape: its guard canonicalises BOTH sides, so " 22" names no
		// topic there and answers FORUM_WRITE_OBSOLETE no matter what this
		// function does. Do not read the byte-comparison hazard onto chk-topic.
		$topicId = RuTrackerRpcValue::canonicalPositiveInt32(trim((string) $req->val[4]));
		$forumId = RuTrackerRpcValue::canonicalForumId((string) $req->val[5]);
		$topic = $topicId === null ? '' : (string) $topicId;
		$forum = $forumId === null ? '' : (string) $forumId;

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
			$hash, $newHash, $stagedAt, $stoppedFallback, self::activeRunLocalId($hash)));
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

		// Nothing is handed over until the replacement provably claims no file
		// on disk: rTorrent::sendTorrent() unlinks whatever filename the object
		// carries, and getSource() may have filled that in with the user's own
		// tied .torrent. A refusal here costs one retryable cycle; getting it
		// wrong costs a file that was never ours.
		$disowned = self::disownFile($torrent);
		if($disowned === null)
			return self::STE_ERROR;

		// Stage stopped: a failed pre-commit replacement cannot write shared data.
		$loadedHash = rTorrent::sendTorrent($disowned, false, false, $baseDir,
			$label, $saveUploadedTorrents, false, true, $addition);
		$owner = self::waitForLoad($newHash, $marker);
		// The checker has its own staged marker. It can confirm a core-pending
		// load without mistaking an older download with this hash for our load.
		if($loadedHash === false || (is_string($loadedHash)
			&& strcasecmp($loadedHash, (string) $newHash) !== 0) || $owner !== 'ours')
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
			$loadResult = $loadedHash === false ? 'dispatch-failed'
				: ($loadedHash === null ? 'pending'
					: (is_string($loadedHash) && strcasecmp($loadedHash, $newHash) === 0
						? 'expected-hash' : 'different-hash'));
			self::logUnrepairable('createTorrent: ' . $hash . ' -> ' . $newHash
				. ' staging refused: load=' . $loadResult
				. ' owner=' . (in_array($owner, array('ours', 'foreign', 'missing'), true)
					? $owner : 'unknown')
				. ' restore=' . ($restored ? 'confirmed' : 'unconfirmed'));
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

		$cleanupFiles = self::buildObsoleteCleanupFiles($oldTorrent, $torrent, $baseDir,
			$hash, $newHash, $marker, $stagedRecordStr);
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

		if($cleanupJob !== null && !erasedataArmObsoleteCleanup($cleanupJob))
		{
			self::logCleanupFailure('obsolete cleanup drain not confirmed before predecessor erase; replacement remains retryable');
			if(!erasedataCancelObsoleteCleanup($cleanupJob))
			{
				self::logCleanupFailure('prepared obsolete cleanup cancellation failed after drain refusal; both generations and recovery markers were retained');
				return self::STE_ERROR;
			}
			if(self::restoreExistingTorrent($hash, $wasOpen, $wasStarted, $selectedMarker))
				self::eraseStaged($newHash, $marker, $stagedRecordStr);
			else
				self::logDebug('createTorrent: predecessor restore failed after cleanup drain refusal; keeping staged successor for sweep');
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

	static public function run_ex($hash, $source, &$performed = null){
		$performed = false;
		$torrent = ($source instanceof Torrent) ? $source : new Torrent( $source );
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
				. (is_string($source) ? $source : 'daemon source')
				. "); nothing could be read from it, so nothing is concluded");
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
			// It already answered, from better input. Only two of the seven
			// handlers -- anidub and tapochek -- register one pattern as both
			// filters, but identity is not what makes this guard necessary: a
			// torrent from its own tracker normally carries both a comment the
			// comment filter matched and an announce the announce filter matches.
			// That is what rutracker (/rutracker\./ against
			// /rutracker\.|t-ru\.org/) and toloka (/toloka\./ against
			// /toloka\.to/) look like -- differing patterns that still both hit
			// the same host. So without this the same handler ran twice for one
			// torrent -- once on the comment it is written to read, then again on
			// an announce URL it cannot.
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
		// A BEP-9 .meta source is only the info dictionary: announce and
		// comment belong to the missing outer metainfo envelope. An empty pair
		// therefore proves no tracker ownership either way. Wait for the full
		// source instead of permanently settling it as "not our business".
		if($comment === '' && !count($announces))
		{
			self::logDebug("run_ex: " . $hash
				. " has no tracker identity in its current source; nothing is concluded");
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
		if($status === Snoopy::RESPONSE_BODY_FAILED)
			return('transport=response-body status=' . $status . ' reason=body-refusal');
		if($status < 0)
		{
			$reasons = array(-100 => 'timeout', -5 => 'connect', -4 => 'dns', -3 => 'socket-create');
			$reason = isset($reasons[$status]) ? $reasons[$status] : 'socket';
			return('transport=socket status=' . $status . ' reason=' . $reason);
		}
		// Zero is NOT a curl exit code here, and the old text said so twice
		// over -- zero is curl's code for SUCCESS -- which made sixteen lines
		// of a live log diagnose nothing at all.
		//
		// It is also not one single condition. Snoopy seeds $status with 0 and
		// overwrites it with curl's exit code, with the code parsed out of an
		// "HTTP/..." header line, or with fsockopen's $errno, so a surviving 0
		// is any of four things:
		//   (a) connected, but no parseable status line came back -- the header
		//       loops set $status only on a line matching |^HTTP/|;
		//   (b) the socket path failed with errno 0, which is what PHP reports
		//       for a getaddrinfo/DNS failure (verified: fsockopen on an
		//       unresolvable host gives errno 0);
		//   (c) Snoopy refused before sending -- an invalid protocol, an
		//       unresolvable host, or one resolving to a non-public address
		//       (Snoopy.class.inc checkTarget());
		//   (d) fetch() bailed on a URI parse_url() could not read, before
		//       touching either field.
		// Naming any one of them would misdiagnose the rest: an earlier draft
		// called a DNS outage "the server sent no status line" and would have
		// sent an operator to look at Cloudflare. So this says only what is
		// certainly true, and makeClient() logs classifyFetchError()'s token
		// for $client->error beside it.
		//
		// That token is NOT always there, and the difference matters more than
		// it looks: Snoopy writes the message on (b) and (c) but on neither
		// (a) nor (d), because the header loops and the early bail touch only
		// $status. (a) is exactly what produced the sixteen log lines cited
		// above -- they are all curl-path fetches that exited 0 with no status
		// line parsed -- so for the motivating case the field is absent and
		// this text is the whole diagnosis. An absent error= is therefore
		// evidence in its own right: it narrows a status 0 to (a) or (d).
		if($status === 0)
			return('transport=no-status reason=unset');
		$reasons = array(
			5 => 'proxy-dns', 6 => 'dns', 7 => 'connect', 28 => 'timeout',
			35 => 'tls', 51 => 'tls-certificate', 52 => 'empty-reply',
			56 => 'receive', 60 => 'tls-certificate',
		);
		$reason = isset($reasons[$status]) ? $reasons[$status] : 'curl';
		return('transport=curl-exit code=' . $status . ' reason=' . $reason);
	}

	/**
	 * Snoopy's failure sentence, reduced to one greppable token.
	 *
	 * transportFailureDetail() alone cannot separate the several conditions
	 * that all arrive as status 0, and Snoopy has already written the one
	 * sentence that distinguishes them.
	 *
	 * The rule itself lives in fetcherror.php, which the NNMClub guest path
	 * requires too: both used to carry their own copy of the same eight
	 * token/pattern pairs, and the copies had drifted over how they normalised
	 * whitespace. The method stays as a delegate so that de-duplicating the
	 * rule changed no call site's name or shape; makeClient() below is its
	 * only caller and it may be inlined.
	 *
	 * @return string A token, or '' when Snoopy wrote no message
	 */
	static public function classifyFetchError( $error )
	{
		return(RuTrackerFetchError::classify($error));
	}

	static public function fetchStatusDetail($status)
	{
		if($status === null || $status === '') return('');
		$status = (int) $status;
		return($status < 100 ? self::transportFailureDetail($status) : 'http-status=' . $status);
	}

	// $agent defaults to the browser agent, which reduces 403/anti-bot errors
	// on the forum and the API. It is WRONG for an announce endpoint, where
	// Cloudflare refuses browser agents outright -- see
	// RuTrackerAnnounce::PROBE_USER_AGENT for the measurements and for what
	// that silently cost. One default cannot be right for all three callers,
	// so the one that differs says so at the call site.
	static public function makeClient( $url, $method="GET", $content_type="", $body="", $agent=self::USER_AGENT )
	{
		$client = new Snoopy();
		$client->read_timeout = 5;
		$client->_fp_timeout  = 5;

		$client->agent = (string) $agent;

		@$client->fetchComplex($url, $method, $content_type, $body);

		// Socket errors are negative; the https path stores curl's exit code,
		// which is below any real HTTP status.
		if($client->status < 100)
		{
			$host = @parse_url($url, PHP_URL_HOST);
			// Snoopy's own message, classified, not just the numeric map. The
			// number alone cannot separate the several conditions that all
			// arrive as status 0 (see transportFailureDetail()), and Snoopy has
			// already written the one sentence that distinguishes them --
			// "connection failed (0)" for an unresolvable host, "Refusing to
			// fetch: ..." when it declined to send at all. This plugin used to
			// read $client->error nowhere at all, so that sentence was thrown
			// away exactly when it was the only thing worth having.
			//
			// A token rather than the sentence, for two reasons. It is the
			// spelling this plugin's other reader of the same Snoopy field
			// uses (NNMClubCheckImpl::guestFetch()), so one grep now reads the
			// field wherever the plugin writes it; two shapes needed two.
			// And it needs no redaction audit: this plugin's hardest log rule
			// is that a probe URL, which spells the user's passkey in its query
			// string, must never reach the log (RuTrackerAnnounce::buildUrl
			// strips it for the same reason), and Snoopy is a vendored file
			// that some later merge may well teach to quote the URL it failed
			// on. classifyFetchError() answers 'unclassified' to anything it
			// does not recognise, so that merge cannot leak through here.
			$error = self::classifyFetchError($client->error);
			self::logDebug("Snoopy fetch failed: host=".(is_string($host) ? $host : 'unknown')." "
				. self::transportFailureDetail($client->status)
				. ($error === '' ? '' : ' error=' . $error));
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

	static public function run( $hash, $state = null, $time = null, $label = null, &$performed = null, $prepareOrdinaryCheck = null )
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
		$expiredPrevious = false;
		$claimToken = self::claimCheck($hash, time(), $expiredPrevious);
		if($claimToken === null)
			return(false);
		if($claimToken === false)
		{
			self::logDebug("run: " . $hash . " is already being checked by another process; leaving it to that one");
			return(true);
		}

		$previousIdentity = self::$activeRunIdentity;
		try
		{
			if(!self::getState( $hash, $state, $time, $label, $localId, $storedMessage ))
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

			self::$activeRunIdentity = array('hash' => $hash, 'localId' => $localId);
			self::$missingSuccessorMarker = null;

			$recoverableP = $state == self::STE_INPROGRESS
				&& ($expiredPrevious || (time()-$time)>self::MAX_LOCK_TIME);
			$emptyPGuard = array();
			$promotedMeta = false;
			if($state == self::STE_INPROGRESS)
			{
				// Older workers published the owned marks before the M state.
				// Observe the same daemon generation before an ignored label or
				// an alive shortcut can hide that unfinished fetch.
				$marks = new rXMLRPCRequest(array(
					new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, "chk-meta-new")),
					new rXMLRPCCommand(getCmd("d.get_custom"), array($hash, "chk-meta-until")),
					new rXMLRPCCommand(getCmd("d.get_local_id"), $hash),
				));
				$marks->important = false;
				if(!$marks->success() || $marks->fault || !is_array($marks->val)
					|| count($marks->val) !== 3 || (string) $marks->val[2] !== $localId)
				{
					self::logUnrepairable('run: ' . $hash
						. ' chk-meta-new/chk-meta-until generation read unconfirmed; deferring P verdict');
					return(false);
				}
				if((string) $marks->val[0] !== '' || (string) $marks->val[1] !== '')
				{
					if(!$recoverableP) return(true); // the fresh P worker still owns this fetch
					if(self::setState($hash, self::STE_META_PENDING, $localId) !== true)
					{
						self::logUnrepairable('run: ' . $hash
							. ' chk-state META_PENDING promotion unconfirmed;'
							. ' retaining chk-meta-new/chk-meta-until for retry');
						return(false);
					}
					$state = self::STE_META_PENDING;
					$promotedMeta = true;
				}
				else
					$emptyPGuard = array("chk-state" => (string) self::STE_INPROGRESS,
						"chk-meta-new" => "", "chk-meta-until" => "");
			}

			if($state == self::STE_META_PENDING)
			{
				// Keep the durable state until pump() has retired its exact
				// generation. The per-hash claim already excludes other workers;
				// INPROGRESS here could strand the marks after a worker crash.
				$state = RuTrackerMetaFetch::pump($hash, time());
				// null is pump()'s success contract: createTorrent() committed,
				// so this hash no longer exists and there is nothing to write.
				if(is_null($state)) return(true);
				if($state == self::STE_META_PENDING)
					return($promotedMeta || self::setState($hash, $state, $localId) !== false);
				$ignored = self::isIgnoredLabel($label);
				$written = self::setRetiredMetaVerdict($hash,
					$ignored ? self::STE_IGNORED : $state, $ignored, $localId);
				if($written === false)
				{
					self::logUnrepairable('run: ' . $hash . ' metafetch-final-verdict-unconfirmed;'
						. ' chk-state after retired chk-meta-new/chk-meta-until needs retry');
					return(false);
				}
				return($ignored || $state != self::STE_CANT_REACH_TRACKER);
			}

			// Keep the ignored state and its cleared message on the same
			// daemon generation. The scheduler applies the same projection.
			if(self::isIgnoredLabel($label))
				return(self::setFastVerdict($hash, self::STE_IGNORED, '', false, $localId,
					$emptyPGuard) !== false);

			// The claim may have expired on monotonic time while chk-time is still
			// future-dated after a wall-clock rollback. Only that proved takeover
			// may bypass the old wall-clock in-progress check.
			if($recoverableP) $state = 0;
			$recoveredEmptyP = $recoverableP && $emptyPGuard !== array();

			if($state!==self::STE_INPROGRESS){
				if($prepareOrdinaryCheck !== null && !call_user_func($prepareOrdinaryCheck, $hash)) return(false);
				// The marker may predate this process. Arm its retry guard before
				// INPROGRESS can replace the old clock or a handler can clear chk-msg.
				if(strpos($storedMessage, self::CHKMSG_SUCCESSOR_MISSING) === 0)
				{
					$prefix = self::CHKMSG_SUCCESSOR_MISSING . '|';
					$successor = substr($storedMessage, strlen($prefix));
					if(strpos($storedMessage, $prefix) !== 0
						|| preg_match('/^[0-9A-F]{40}$/D', $successor) !== 1)
					{
						self::logUnrepairable('run: ' . $hash
							. ' malformed chk-msg successor-missing marker; check deferred');
						return(false);
					}
					if(!self::retainMissingSuccessor($hash, $successor)) return(false);
				}
				$previous = $state;
				$terminal = in_array($previous, array(self::STE_DELETED, self::STE_ABSORBED), true);
				if($previous === self::STE_NOT_NEED && $time > 0)
				{
					$message = self::storedMessage($hash);
					if($message === null) return(false);
					$terminal = self::isSettledStatus($previous, $time, $message);
				}
				$state = self::STE_INPROGRESS;
				// claimCheck() serializes workers. Keep a terminal verdict visible
				// while checking. The same-value preflight leaves chk-time intact
				// and detects a missing hash. A matching readback of this value
				// cannot, by itself, prove that the write succeeded.
				if($terminal)
					$stateWrite = self::writeCustomProjection($hash, array(
						new rXMLRPCCommand(getCmd("d.set_custom"),
							array($hash, "chk-state", (string) $previous))), "confirmTerminalState", $localId);
				else
					$stateWrite = self::setState( $hash, $state, $localId,
						$recoveredEmptyP ? $emptyPGuard : array() );
				if($stateWrite === null) return(true);
				if(!$stateWrite) return(false);
				$handlerPerformed = false;
				$handlerVerdict = null;
				$source = rTorrent::getSource($hash);
				$pendingMessage = null;
				if($terminal)
				{
					self::$terminalMessageHash = $hash;
					self::$pendingTerminalMessage = null;
				}
				try
				{
					if($source !== false)
					{
						$handlerVerdict = self::run_ex($hash, $source, $handlerPerformed);
						$state = $handlerVerdict;
					}
					else self::logDebug("run: " . $hash
						. " has no readable session copy or tied daemon source;"
						. " no handler verdict is available");
				}
				finally
				{
					if($terminal || self::$missingSuccessorMarker !== null)
					{
						$pendingMessage = self::$pendingTerminalMessage;
						self::$terminalMessageHash = null;
						self::$pendingTerminalMessage = null;
					}
				}
				if($state===self::STE_UNCHANGED) $state = $previous;
				if($state==self::STE_INPROGRESS) $state=self::STE_ERROR;
				// A retryable failure cannot refute a terminal topic verdict.
				// Do not refresh its rest clock without a new answer: the next
				// scheduled recheck must still arrive at its original deadline.
				$resultState = $state;
				$finalPGuard = $recoveredEmptyP && $state !== self::STE_META_PENDING
					? $emptyPGuard : array();
				$missingRetry = self::$missingSuccessorMarker !== null
					&& ($handlerVerdict === null || in_array($handlerVerdict,
						array(self::STE_UNCHANGED, self::STE_CANT_REACH_TRACKER, self::STE_ERROR), true));
				$retainedTerminal = $terminal && !$missingRetry
					&& in_array($state, array(self::STE_CANT_REACH_TRACKER, self::STE_ERROR), true);
				if($retainedTerminal) $state = $previous;
				$finalWrite = null;
				if($missingRetry)
				{
					// The marker makes even an intermediate NOT_NEED schedulable.
					// Publish ERROR without moving the original retry deadline.
					$state = self::STE_ERROR;
					$resultState = self::STE_CANT_REACH_TRACKER;
					$finalWrite = self::writeCustomProjection($hash, array(
						new rXMLRPCCommand(getCmd('d.set_custom'),
							array($hash, 'chk-state', (string) $state)),
						new rXMLRPCCommand(getCmd('d.set_custom'),
							array($hash, 'chk-time', $time > 0 ? (string) $time : '')),
						new rXMLRPCCommand(getCmd('d.set_custom'),
							array($hash, 'chk-msg', self::$missingSuccessorMarker))),
						'recordMissingSuccessorError', $localId, $finalPGuard);
					if($finalWrite !== true)
						self::logUnrepairable('run: ' . $hash
							. ' chk-state/chk-time/chk-msg successor-missing verdict unconfirmed; retry pending');
				}
				elseif(!is_null($state) && !($terminal
					&& ($retainedTerminal || $handlerVerdict === self::STE_UNCHANGED)))
				{
					if($handlerVerdict === self::STE_UNCHANGED)
						// INPROGRESS briefly stamped the claim clock. An answer that
						// learned nothing must restore it without touching chk-stime.
						$finalWrite = self::writeCustomProjection($hash, array(
							new rXMLRPCCommand(getCmd("d.set_custom"),
								array($hash, "chk-state", (string) $previous)),
							new rXMLRPCCommand(getCmd("d.set_custom"),
								array($hash, "chk-time", $time > 0 ? (string) $time : ""))), "restoreUnchanged", $localId,
							$finalPGuard);
					else
					{
						// A conclusive answer retires a persisted missing-successor
						// marker even if the handler had no sentence to publish.
						$finalMessage = self::$missingSuccessorMarker !== null
							? ($pendingMessage === null ? '' : $pendingMessage)
							: ($terminal ? $pendingMessage : null);
						$finalWrite = $finalMessage !== null
							? self::setFastVerdict($hash, $state, $finalMessage, false, $localId,
								$finalPGuard)
							: self::setState($hash, $state, $localId, $finalPGuard);
					}
				}
				// Handler invocation is not durable consumption. In particular,
				// STE_UNCHANGED means it learned nothing, and a false/null final
				// write leaves no verdict for a correction to acknowledge.
				$performed = $handlerPerformed
					&& !in_array($handlerVerdict, array(self::STE_UNCHANGED,
						self::STE_CANT_REACH_TRACKER, self::STE_ERROR), true)
					&& $finalWrite === true;
				return($resultState != self::STE_CANT_REACH_TRACKER);
			}
			return(true); // only a still-held INPROGRESS state reaches here
		}
		finally
		{
			// An early state/message read or preflight failure may skip the
			// handler's finally block after arming this process-local buffer.
			if(self::$terminalMessageHash === $hash)
			{
				self::$terminalMessageHash = null;
				self::$pendingTerminalMessage = null;
			}
			self::$missingSuccessorMarker = null;
			self::$activeRunIdentity = $previousIdentity;
			self::releaseCheck($hash, $claimToken);
		}
	}

}
