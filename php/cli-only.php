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
    echo 'Forbidden';
    // A worker's caller reads the status: a CGI binary run with a request's
    // environment must fail too, or an unpack script reports success, and with
    // $deleteAutoArchives deletes its archives, although nothing was placed.
    exit(1);
}
