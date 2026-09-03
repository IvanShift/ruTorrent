<?php

require_once( dirname(__FILE__).'/launcher.php' );

/**
 * The detached worker for a manual "check for update".
 *
 * It is started by action.php with the path of a handover file holding the
 * hashes the user selected. Nothing observes this process: its output goes to
 * /dev/null and its exit status is discarded, so whatever it fails to do it
 * fails to do silently. That is why each torrent is isolated from the next and
 * why the handover is removed on every path out.
 */
class ruTrackerBatchCheck
{
	static private function log($message, $logger = null)
	{
		try
		{
			if($logger !== null)
				call_user_func($logger, $message);
			elseif(class_exists('ruTrackerChecker', false))
				ruTrackerChecker::logDebug($message);
		}
		catch(Throwable $e)
		{
			// The log is a diagnostic, not a step of the batch.
		}
	}

	/**
	 * Check every hash named by the handover, remove it, then start the forum
	 * crawl that resolves topics queued by the checks.
	 *
	 * @param callable|null $checker Injection seam for tests.
	 * @param callable|null $crawler Injection seam for the fork's forum index.
	 * @param callable|null $deleter Injection seam for tests.
	 * @param callable|null $logger  Injection seam for tests.
	 */
	static public function runHandover($handoverPath, $checker = null, $crawler = null,
		$deleter = null, $logger = null)
	{
		$raw = (is_string($handoverPath) && $handoverPath !== '' && file_exists($handoverPath))
			? @file_get_contents($handoverPath)
			: false;
		$hashes = ($raw !== false && $raw !== '')
			? @unserialize($raw, array('allowed_classes' => false))
			: null;

		if(!is_array($hashes))
			self::log('batch_check: the handover file held no usable selection', $logger);

		try
		{
			if(is_array($hashes))
			{
				foreach($hashes as $hash)
				{
					if(!is_string($hash) || $hash === '')
						continue;
					try
					{
						if($checker !== null)
							call_user_func($checker, $hash);
						else
							ruTrackerChecker::run($hash);
					}
					catch(Throwable $e)
					{
						// One unreachable tracker is the ordinary case, not a
						// reason to abandon the rest of a deliberate selection.
						// Keep remote exception text out of the routine log.
						self::log('batch_check: a selected torrent could not be checked', $logger);
					}
				}
			}
		}
		finally
		{
			// The handover is this process's own temporary file. Left behind by a
			// batch that threw, it accumulates in the temp directory forever.
			$removed = false;
			try
			{
				$removed = RuTrackerBatchDispatch::removeHandover($handoverPath, $deleter);
			}
			catch(Throwable $e)
			{
				$removed = false;
			}
			if(!$removed)
				self::log('batch_check: could not remove the handover file', $logger);

			// Manual checks can be the only pass when the scheduler is disabled.
			// Spawn after all checks so every unresolved topic has been queued.
			try
			{
				if($crawler !== null)
					call_user_func($crawler);
				else
					RuTrackerForumIndex::spawnCrawl();
			}
			catch(Throwable $e)
			{
				self::log('batch_check: the forum crawl could not be started', $logger);
			}
		}
	}
}

if( count( $argv ) > 2 )
	$_SERVER['REMOTE_USER'] = $argv[2];

if(( count( $argv ) > 1 ) && chdir(dirname( __FILE__)))
{
	require_once( "check.php" );

	ruTrackerBatchCheck::runHandover($argv[1]);
}
