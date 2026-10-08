<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
define('RETRACKERS_PREFLIGHT_TIMEOUT', 0.05);
define('RETRACKERS_PREFLIGHT_POLL', 0.001);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');
require_once(__DIR__ . '/RetrackersBindingSample.php');

// Lifecycle reads come from real classified samples; after one of this init's
// own successful callbacks the daemon holds exactly that callback's state.
class InitPeerCallSiteAdapter extends RetrackersLifecycleRpcAdapter
{
	public $samples;
	public $replies = array();
	public $writes = array();
	public $arms = 0;
	private $last = null;
	private $own = false;

	public function __construct(array $samples)
	{
		$this->samples = $samples;
		$this->family = 2;
	}
	public function ensureLedgerExists(&$failure = null) { $failure = null; return(true); }
	public function nativeOriginalCapability(&$failure = null) { $failure = null; return(true); }
	public function nativeQueueCapability(&$failure = null) { $failure = null; return(true); }
	public function attestLedger(&$failure = null)
	{
		$failure = null;
		return(new RetrackersHistoricalLedgerAttestation(true, true, true, true));
	}
	public function historicalBindingSample(&$failure = null)
	{
		$failure = null;
		return(count($this->samples) > 1 ? array_shift($this->samples) : $this->samples[0]);
	}
	public function executeLifecycleCallback(RetrackersLifecycleCallbacks $callback, &$failure = null)
	{
		$this->writes[] = $callback->name();
		$this->last = $callback;
		$reply = isset($this->replies[$callback->name()]) ? $this->replies[$callback->name()] : 'ACQUIRED';
		$this->own = $reply === 'ACQUIRED';
		$failure = null;
		return($this->own ? $callback->successSentinel() : $callback->changedSentinel());
	}
	public function coherentLifecycleReadback($user, $action, &$failure = null)
	{
		if (!$this->own) return(parent::coherentLifecycleReadback($user, $action, $failure));
		$state = $this->last->expectedState();
		$failure = null;
		return(array('phase' => $state['phase'], 'persistent_epoch' => $state['epoch'],
			'owner' => $state['owner'], 'transaction_receipts' => $state['required_receipts'],
			'binding' => array('family' => 2)));
	}
	public function armNativeQueueDispatcher($user, &$failure = null)
	{
		$this->arms++;
		$failure = null;
		return(true);
	}
}

/**
 * The production init call sites that let a known CAS loser accept an identical
 * newer initializer (R2-INIT-02), pinned through init() itself (R3-RT-03).
 */
class InitPeerCallSiteTest extends TestCase
{
	// The functional action embeds only the eligibility switches, not the list.
	private static function settings($dontAddPrivate)
	{
		return((object)array('list' => array(array('http://retracker.invalid/announce')), 'todelete' => array(),
			'dontAddPrivate' => $dontAddPrivate));
	}

	private static function functional($settings)
	{
		return(retrackersBuildInsertAction('/plugin/run.sh', '/usr/bin/php', 'alice', true, true, $settings));
	}

	private function init($adapter, $settings)
	{
		$failure = null;
		$ok = RetrackersLifecycleCoordinator::init('alice', '/plugin/run.sh', '/usr/bin/php', $failure, $adapter, $settings);
		return(array($ok, $failure));
	}

	public function testAcquireCasLoserAcceptsTheIdenticalPeerWithoutAnotherWrite()
	{
		$settings = self::settings(0);
		$old = RetrackersBindingSample::idle('alice', self::functional(self::settings(1)));
		$installed = RetrackersBindingSample::idle('alice', self::functional($settings));
		$adapter = new InitPeerCallSiteAdapter(array($old, $old, $installed));
		$adapter->replies['current-init-acquire'] = 'CHANGED';
		list($ok, $failure) = $this->init($adapter, $settings);
		$this->assertTrue($ok === true && $failure === null && $adapter->writes === array('current-init-acquire') &&
			$adapter->arms === 1,
			'a settings change whose acquire lost to an identical newer init succeeds without acquiring again');
	}

	public function testFinalizationCasLoserAcceptsTheIdenticalPeer()
	{
		$settings = self::settings(0);
		$old = RetrackersBindingSample::idle('alice', self::functional(self::settings(1)));
		$installed = RetrackersBindingSample::idle('alice', self::functional($settings));
		$adapter = new InitPeerCallSiteAdapter(array($old, $old, $installed));
		$adapter->replies['init-finalization'] = 'CHANGED';
		list($ok, $failure) = $this->init($adapter, $settings);
		$this->assertTrue($ok === true && $failure === null &&
			$adapter->writes === array('current-init-acquire', 'init-finalization') && $adapter->arms === 1,
			'a finalization that lost to an identical newer init is accepted after one write each');
	}

	public function testPreConsumerBusyWaitsForTheIdenticalProfile()
	{
		$settings = self::settings(0);
		$functional = self::functional($settings);
		$defer = retrackersBuildDeferOnlyInsertAction();
		// Bob's init owns the lifecycle; Alice's identical profile is already installed.
		$busy = RetrackersBindingSample::make(array(
			'tadd_trackers1alice' => $functional, 'tadd_trackers2alice' => retrackersBuildSafetyOnlyInsertAction(),
			'tadd_trackers1bob' => $defer, 'tadd_trackers2bob' => $defer),
			array('pv:' . str_repeat('1', 32), 'ta:1', 'to:' . str_repeat('2', 32) . ':i:' . hash('sha256', 'bob'),
				'pf:' . hash('sha256', 'alice') . ':' . hash('sha256', $functional),
				'pf:' . hash('sha256', 'bob') . ':' . hash('sha256', 'functional-bob')));
		$installed = RetrackersBindingSample::idle('alice', $functional);
		$adapter = new InitPeerCallSiteAdapter(array($busy, $busy, $installed));
		list($ok, $failure) = $this->init($adapter, $settings);
		$this->assertTrue($ok === true && $failure === null && $adapter->writes === array() && $adapter->arms === 1,
			'a busy observation before any callback waits read-only for the identical profile and rearms');
		$adapter = new InitPeerCallSiteAdapter(array($busy));
		list($ok, $failure) = $this->init($adapter, $settings);
		$this->assertTrue($ok === false && $failure === 'lifecycle-busy' && $adapter->writes === array() && $adapter->arms === 0,
			'a lifecycle that stays foreign remains busy without a write');
	}

	public function testReadbackOptionsAreNamedAndChecked()
	{
		$callback = RetrackersLifecycleCallbacks::initFinalization(str_repeat('a', 32), str_repeat('b', 32),
			str_repeat('c', 32), hash('sha256', 'alice'), 'tadd_trackers1alice', 'tadd_trackers2alice', 'cat=', 'cat=');
		$method = new ReflectionMethod('RetrackersLifecycleCoordinator', 'executeAndReadback');
		if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
		$failure = null;
		$thrown = false;
		try {
			$method->invokeArgs(null, array(new InitPeerCallSiteAdapter(array()), $callback, 'alice', 'cat=',
				'hook-install-unconfirmed', &$failure, array('reconcile_peer' => true)));
		} catch (InvalidArgumentException $error) {
			$thrown = true;
		}
		$this->assertTrue($thrown, 'a misspelled readback option fails loudly instead of disabling the option');
		$this->assertTrue($callback->readbackRetryFailures() === array('profile-binding-unstable') &&
			RetrackersLifecycleCallbacks::cancelPending(str_repeat('a', 32), str_repeat('b', 32), hash('sha256', 'alice'),
				str_repeat('A', 40), str_repeat('B', 40), 'v1:original:0:' . str_repeat('B', 40) . ':' . hash('sha256', 'alice'))
				->readbackRetryFailures() === array(),
			'only acquire and finalization callbacks re-read an unstable sample after their mutation');
	}
}
