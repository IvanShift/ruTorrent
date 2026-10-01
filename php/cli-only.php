<?php

/** Refuse web execution before a worker reads argv or loads application state. */
function rutorrent_require_cli($entry)
{
    if(PHP_SAPI === 'cli')
        return;
    error_log('cli-only: refused web entry: '.basename($entry));
    http_response_code(403);
    exit('Forbidden');
}
