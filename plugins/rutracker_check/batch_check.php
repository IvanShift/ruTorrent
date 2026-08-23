<?php

require_once( __DIR__ . '/launcher.php' );

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
		}
		catch(Exception $e)
		{
		}
	}

	static public function runHandover($handoverPath, $checker = null, $crawler = null,
		$deleter = null, $logger = null)
	{
		$raw = (is_string($handoverPath) && $handoverPath !== '' && file_exists($handoverPath))
			? @file_get_contents($handoverPath)
			: false;
		$hashes = ($raw !== false && $raw !== '')
			? @unserialize($raw, array('allowed_classes' => false))
			: null;

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
						self::log("batch_check: exception checking hash " . $hash . ": " . $e->getMessage(), $logger);
					}
					catch(Exception $e)
					{
						self::log("batch_check: exception checking hash " . $hash . ": " . $e->getMessage(), $logger);
					}
				}
			}
		}
		finally
		{
			if(is_string($handoverPath) && $handoverPath !== '' && file_exists($handoverPath))
			{
				$removed = false;
				try { $removed = RuTrackerBatchDispatch::removeHandover($handoverPath, $deleter); }
				catch(Throwable $e) { $removed = false; }
				catch(Exception $e) { $removed = false; }
				if(!$removed)
					self::log('batch_check: could not remove the handover file', $logger);
			}

			try
			{
				if($crawler !== null)
					call_user_func($crawler);
				else
					RuTrackerForumIndex::spawnCrawl();
			}
			catch(Throwable $e)
			{
				self::log("batch_check: exception spawning crawl: " . $e->getMessage(), $logger);
			}
			catch(Exception $e)
			{
				self::log("batch_check: exception spawning crawl: " . $e->getMessage(), $logger);
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
