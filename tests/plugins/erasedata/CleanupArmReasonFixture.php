<?php

namespace ErasedataCleanupArmFixture;

require_once(__DIR__.'/../../php/SourceDefinitionFixture.php');

// Script collaborator outcomes at the arm owner's boundaries. The function
// under test is loaded verbatim, so no refusal/classification logic is copied.
class Scenario
{
	public static $failure;
	public static $renameReason;
	public static $reads;
	public static $diagnostics;
	public static $releases;
	public static $lock;

	public static function reset($failure)
	{
		self::$failure = $failure;
		self::$renameReason = 'rename-noreplace-unsupported';
		self::$reads = 0;
		self::$diagnostics = array();
		self::$releases = 0;
		self::$lock = fopen('php://temp', 'w+');
	}
}

class ErasedataFilesystemOps extends \ErasedataFilesystemOps
{
	public static function reportRenameRefusal($component = 'erasedata') {}
	public static function lastRenameRefusal() { return Scenario::$renameReason; }
}

function erasedataDrainScheduleKey($user) { return 'fixture-user'; }
function erasedataReadExactCleanupJob($job) { return Scenario::$failure === 'job' ? false : array(); }
function erasedataAcquireDrainStateLock($path) { return Scenario::$failure === 'lock' ? false : Scenario::$lock; }
function erasedataReadDrainState($path)
{
	Scenario::$reads++;
	return Scenario::$failure === 'state' ? false : array('generation' => '0000000000000000', 'acknowledged' => '0000000000000001');
}
function erasedataDrainStateBelongsTo($state, $user)
{
	return !(Scenario::$failure === 'changed' && Scenario::$reads > 1);
}
function erasedataGenerationIncrement($generation) { return Scenario::$failure === 'generation' ? false : '0000000000000001'; }
function erasedataWriteDrainState($path, array $state) { return Scenario::$failure !== 'write'; }
function erasedataReleaseDrainStateLock($lock) { Scenario::$releases++; }
function erasedataRearmDrainScheduleRun(array $dependencies) { return Scenario::$failure !== 'rearm'; }
function erasedataWaitForDrainAcknowledgement($path, $generation, $timeout, $poll) { return Scenario::$failure !== 'no-ack'; }
function erasedataGenerationCompare($ack, $generation) { return 0; }
function erasedataCleanupRenameReady($path, $manifest, $filesystem) { return Scenario::$failure !== 'rename'; }
function erasedataDrainDiagnostic($log, $reason, $generation, $count, $consequence)
{
	Scenario::$diagnostics[] = $reason;
}

eval('namespace '.__NAMESPACE__.'; '.\loadFunctionDefinition(
	__DIR__.'/../../../plugins/erasedata/removewithdata.php', 'erasedataArmObsoleteCleanupRun'));
