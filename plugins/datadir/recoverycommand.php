<?php

require_once(__DIR__.'/../../php/utility/utility.php');

// Shell argv and the daemon's execute command are separate parsers. Quote
// each at its own boundary, including administrator-configured PHP paths.
function rtDataDirRecoveryCommand($php, $script)
{
    $command = Utility::escapeShellArg($php) . ' ' . Utility::escapeShellArg($script) . ' --all &';
    return getCmd('execute') . '={' . rTorrent::quoteCommandArg('sh') . ','
        . rTorrent::quoteCommandArg('-c') . ',' . rTorrent::quoteCommandArg($command) . '}';
}
