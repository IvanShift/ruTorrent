<?php

require_once( dirname(__FILE__)."/autotools.php");

$at = rAutoTools::load();
if($at->setHandlers())
{
	$jResult .= $at->get();
	if( $do_diagnostic )
	{
		if( $at->enable_move )
		{
			$path_to_finished = $at->path_to_finished;
			if(!rTorrentSettings::get()->correctDirectory($path_to_finished))
				$path_to_finished = '';
			if( $path_to_finished == '' )
				$jResult .= "plugin.showError('theUILang.autotoolsNoPathToFinished');";
			else
			{
				$session = (string)rTorrentSettings::get()->session;
				// The session path is the daemon's own bytes: an apostrophe or
				// a trailing backslash in a quoted literal would break the
				// script every plugin's init is served in, so it goes in as a
				// JSON string literal.
				if( !strlen($session) || !@file_exists(FileUtil::addslash($session).'.') )
					$jResult .= "plugin.disable(); noty(".JSON::jsValue($plugin["name"].": ").
						"+theUILang.webBadSessionWarning+' ('+".JSON::jsValue($session)."+').','error');";
			}
		}
		if( $at->enable_watch )
		{
			$path_to_watch = $at->path_to_watch;
			if(!rTorrentSettings::get()->correctDirectory($path_to_watch))
				$path_to_watch = '';
			if( $path_to_watch == '' )
				$jResult .= "plugin.showError('theUILang.autotoolsNoPathToWatch');";
		}
	}
	$theSettings->registerPlugin($plugin["name"],$pInfo["perms"]);
	// Outcomes a tracker answer erased from d.message, once per session. A
	// missing file must not break the page every plugin is served from.
	if( is_file( dirname(__FILE__)."/../erasedata/outcome.php" ) )
	{
		require_once( dirname(__FILE__)."/../erasedata/outcome.php");
		$jResult .= FileOperationOutcome::sessionNotice('autotools');
	}
}
else
        $jResult .= "plugin.disable(); noty('autotools: '+theUILang.pluginCantStart,'error');";
$jResult .= $at->modeNotice();

if ($at->moveStatus === 'queue') {
    require_once __DIR__ . '/move_queue.php';
    $terminalMoves = AutoToolsMoveQueue::terminalCount(User::getUser());
    if ($terminalMoves > 0)
        $jResult .= "noty('AutoTools: " . (int)$terminalMoves . " Move jobs need attention; inspect the torrent message and tools/autotools-move-reset.php.', 'error');";
}
