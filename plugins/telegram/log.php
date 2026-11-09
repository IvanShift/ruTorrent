<?php

function telegramLogMessage($message, $alsoErrorLog = false)
{
    $line = 'telegram: ' . $message;
    $usedFileUtil = false;
    try {
        // On PHP 7.4 disabled functions return null instead of throwing.
        if (@class_exists('FileUtil') && @function_exists('strlen') === true
            && @function_exists('fopen') === true && @function_exists('fputs') === true
            && @function_exists('fclose') === true) {
            FileUtil::toLog($line);
            $usedFileUtil = true;
        }
    } catch (Throwable $error) {
        // The PHP error log remains available when the normal logger fails.
    }
    // FileUtil::toLog() does not report a failed write; critical refusals
    // also go to the PHP error log even when the call returned normally.
    if (!$usedFileUtil || $alsoErrorLog) {
        try {
            @error_log($line);
        } catch (Throwable $error) {
            // No logging destination is available.
        }
    }
}
