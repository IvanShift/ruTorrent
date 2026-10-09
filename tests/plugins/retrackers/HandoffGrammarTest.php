<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_DEFER_READ_TIMEOUT', 0.04);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
require_once(__DIR__ . '/RetrackersWireFake.php');

/** One parser for the v2 original handoff marker (QUAL-RU-REUSE-4) and one init-owner predicate (R3-RT-Q7b). */
class HandoffGrammarTest extends TestCase
{
	public function testTheParserReadsTheHookMarkerAndRefusesEverythingElse()
	{
		$localId = str_repeat('B', 40);
		$user = hash('sha256', 'alice');
		$tx = str_repeat('c', 32);
		$this->assertEquals(array('state' => '1', 'local_id' => $localId, 'user_hash' => $user, 'tx' => $tx),
			RetrackersOriginalHandoff::parse('v2:original:1:' . $localId . ':' . $user . ':' . $tx),
			'the marker the insert hook writes is read field by field');
		foreach (array('v2:original:2:' . $localId . ':' . $user . ':' . $tx,
			'v2:original:1:' . strtolower($localId) . ':' . $user . ':' . $tx,
			'v2:original:1:' . $localId . ':' . strtoupper($user) . ':' . $tx,
			'v2:original:1:' . $localId . ':' . $user . ':' . strtoupper($tx),
			'v2:original:1:' . $localId . ':' . $user . ':' . $tx . "\n",
			'v2:original:1:' . $localId . ':' . $user . ':' . $tx . ':x',
			'v1:original:1:' . $localId . ':' . $user,
			'v2:candidate-ready:' . $tx, '', null, array()) as $wire) {
			$this->assertTrue(RetrackersOriginalHandoff::parse($wire) === false,
				'not a v2 original handoff: ' . json_encode($wire));
		}
	}

	// The readers keep the identity checks they had before the parser.
	public function testTheHandoffReadersStillCheckTheirIdentities()
	{
		$wire = new RetrackersWireFake();
		$hash = str_repeat('A', 40);
		$localId = str_repeat('B', 40);
		$wire->add($hash, $localId, 'alice', '1');
		$claim = $wire->torrents[$hash]['claim'];
		$adapter = new RetrackersWireWorkerAdapter($wire, $hash, 'alice');
		$foreign = 'v2:original:1:' . str_repeat('E', 40) . ':' . hash('sha256', 'alice') . ':' . $claim['tx'];
		$this->assertTrue(RetrackersRecoveryCoordinator::cancelBeforeStart($hash, $foreign, $localId, $adapter,
			'initial-hashing-pending') === false && $adapter->recoveryFailure() === 'initial-hashing-pending' &&
			$wire->calls === array(), 'a handoff of another local id is only recorded, never deferred');
		$bob = 'v2:original:1:' . $localId . ':' . hash('sha256', 'bob') . ':' . $claim['tx'];
		$failure = null;
		$outcome = null;
		$this->assertTrue($adapter->nativeDeferUncaptured($hash, $claim['tx'], $localId, $bob, null,
			'initial-hashing-pending', $failure, $outcome) === false &&
			$failure === 'native-uncaptured-cancel-unconfirmed' &&
			!in_array('d.retrackers.defer_original', $wire->calls, true),
			'a handoff whose user is not the claim\'s user defers nothing');
	}

	// The consumer resumes and a concurrent initializer waits for one and the
	// same unfinished init (R3-RT-Q7b).
	public function testOneInitOwnerPredicateForResumeAndWait()
	{
		$predicate = new ReflectionMethod('RetrackersLifecycleCoordinator', 'callerInitOwner');
		// Needed before PHP 8.1; deprecated in 8.5, where it has no effect.
		if (PHP_VERSION_ID < 80100) $predicate->setAccessible(true);
		$owner = array('phase' => 'INIT_OWNER', 'profile_count' => 1, 'caller' => array('is_owner' => true),
			'owner' => array('token' => str_repeat('a', 32), 'mode' => 'i', 'user_hash' => hash('sha256', 'alice')));
		$this->assertTrue($predicate->invoke(null, $owner, 'alice') === true, 'the caller\'s own init in mode i');
		foreach (array('phase' => 'IDLE_CURRENT', 'profile_count' => 2, 'caller' => array('is_owner' => false),
			'owner' => array('token' => str_repeat('a', 32), 'mode' => 'd', 'user_hash' => hash('sha256', 'alice'))) as $key => $value) {
			$decision = $owner;
			$decision[$key] = $value;
			$this->assertTrue($predicate->invoke(null, $decision, 'alice') === false, 'not resumable with ' . $key . ' changed');
		}
		$this->assertTrue($predicate->invoke(null, $owner, 'bob') === false, 'another user never resumes it');
		$source = file_get_contents(__DIR__ . '/../../../plugins/retrackers/update.php');
		$this->assertEquals(3, substr_count($source, 'callerInitOwner('),
			'the predicate, the consumer\'s resume and the concurrent-init wait');
	}

	public function testUpdatePhpSpellsTheHandoffGrammarOnce()
	{
		$source = file_get_contents(__DIR__ . '/../../../plugins/retrackers/update.php');
		$this->assertEquals(1, substr_count($source, "'/^v2:original:"),
			'every reader of a v2 original handoff goes through RetrackersOriginalHandoff::parse()');
	}

	// R4-RT-11: the queue marker grammar, the profile name rule and the profile
	// lookup each have one owner; the shared operator bootstrap and dispatcher
	// validate REMOTE_USER before update.php may load.
	public function testQueueMarkerProfileNameAndLookupHaveOneOwnerEach()
	{
		$root = __DIR__ . '/../../../';
		$sources = array();
		foreach (array_merge(glob($root . 'plugins/retrackers/*.php'), glob($root . 'tools/retrackers-*.php')) as $path)
			$sources[basename($path)] = file_get_contents($path);
		$this->assertTrue(count($sources) > 6 && isset($sources['update.php'], $sources['dispatch.php'],
			$sources['retryreset.php'], $sources['retrackers-retry-reset.php']), 'the plugin and tool sources are read');
		$count = function ($needle) use ($sources) {
			$found = array();
			foreach ($sources as $name => $source) if (substr_count($source, $needle)) $found[$name] = substr_count($source, $needle);
			ksort($found);
			return($found);
		};
		$this->assertTrue($count("'/^q1:") === array('update.php' => 1) && $count("'q1:'") === array(),
			'every reader of d.custom=retrackers-queued goes through RetrackersQueueMarker::parse()');
		$this->assertTrue($count("'/^[a-z0-9_-]*\$/D'") === array('clitool.php' => 1, 'dispatch.php' => 1,
			'update.php' => 1), 'one profile name rule, repeated only before the two profile bootstraps: ' . json_encode($count("'/^[a-z0-9_-]*\$/D'")));
		$this->assertTrue(strpos($sources['update.php'], 'function profileForUser') === false &&
			substr_count($sources['update.php'], "foreach (\$decision['profiles'] as \$profile)") === 1,
			'one profile lookup, RetrackersHistoricalBindingClassifier::profileOf()');
		$this->assertTrue(RetrackersQueueMarker::parse('q1:' . hash('sha256', 'alice') . ':' . str_repeat('a', 32)) ===
			array('user_hash' => hash('sha256', 'alice'), 'queue_id' => str_repeat('a', 32)) &&
			RetrackersQueueMarker::parse('q1:' . hash('sha256', 'alice') . ':' . str_repeat('A', 32)) === false &&
			RetrackersQueueMarker::parse('q2:' . hash('sha256', 'alice') . ':' . str_repeat('a', 32)) === false &&
			RetrackersQueueMarker::parse(null) === false, 'the marker reads field by field and nothing else');
	}

	private function assertSourceOrder($source, array $needles, $message)
	{
		$previous = -1;
		foreach ($needles as $needle) {
			$position = strpos($source, $needle);
			if ($position === false || $position <= $previous) {
				$this->assertTrue(false, $message . ': missing or misplaced ' . $needle);
				return;
			}
			$previous = $position;
		}
		$this->assertTrue(true, $message);
	}

	public function testOwnedCliEntrypointsKeepBootstrapAdmissionBeforeLoadingSettings()
	{
		$root = __DIR__ . '/../../../';
		$shared = "require_once(dirname(__DIR__) . '/plugins/retrackers/clitool.php');";
		foreach (array('apply-existing' => array($shared, 'rRetrackers::load()', 'RetrackersApplyExisting::main('),
			'retry-reset' => array($shared, 'RetrackersRetryReset::main(')) as $tool => $order) {
			$this->assertSourceOrder(file_get_contents($root . 'tools/retrackers-' . $tool . '.php'), $order,
				$tool . ' uses the shared guarded bootstrap before settings or operator RPC');
		}
		$this->assertSourceOrder(file_get_contents($root . 'plugins/retrackers/clitool.php'), array(
			'rutorrent_require_cli(__FILE__);', "preg_match('/^[a-z0-9_-]*\$/D'", "\$_SERVER['REMOTE_USER'] = \$user;",
			"define('RUTORRENT_SETTINGS_READ_ONLY', true);", "require_once(__DIR__ . '/retrackers.php');",
			"require_once(__DIR__ . '/../../php/xmlrpc.php');", "require_once(__DIR__ . '/dispatch.php');"),
			'shared operator bootstrap validates the profile and sets read-only context before profile imports');
		$this->assertSourceOrder(file_get_contents($root . 'plugins/retrackers/dispatch.php'), array(
			'rutorrent_require_cli(__FILE__);', "preg_match('/^[a-z0-9_-]*\$/D'", "\$_SERVER['REMOTE_USER']=\$argv[1];",
			"require_once(__DIR__ . '/update.php');"),
			'dispatcher validates its profile before loading the application');
		// The recovery-marker tool is a self-contained raw-SCGI operator and
		// does not load the application profile/cache bootstrap.
		$markers = file_get_contents($root . 'tools/retrackers-clear-markers.php');
		$this->assertSourceOrder($markers, array('require_once $cliGuard;', 'rutorrent_require_cli(__FILE__);',
			'$apply = isset(', "@include('/rutorrent/app/conf/config.php');"),
			'clear-markers admits CLI and validates arguments before its standalone configuration');
		$this->assertTrue(strpos($markers, '/plugins/retrackers/clitool.php') === false &&
			strpos($markers, 'rCache') === false && strpos($markers, 'rTorrentSettings') === false,
			'clear-markers has no application settings bootstrap that can create a cold profile');
	}
}
