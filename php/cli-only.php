<?php

/** Refuse web execution before a worker reads argv or loads application state. */
function rutorrent_require_cli($entry)
{
    if(PHP_SAPI === 'cli')
        return;
    if(!isset($_SERVER['REQUEST_METHOD']) && PHP_SAPI !== 'cli-server') {
        error_log('cli-only: refused non-CLI worker: '.basename($entry)
            .'; configure $pathToExternals["php"] with a CLI binary');
        exit(1);
    }
    error_log('cli-only: refused web entry: '.basename($entry));
    http_response_code(403);
    exit('Forbidden');
}
