<?php
require_once dirname(__DIR__, 2) . '/php/cli-only.php';
rutorrent_require_cli(__FILE__);
if (count($argv) !== 5 || !preg_match('/^[0-9A-F]{40}$/D', $argv[1])
    || !preg_match('/^[0-9a-f]{32}$/D', $argv[2]) || !preg_match('/^[a-z0-9_-]*$/D', $argv[3])
    || !preg_match('/^[0-9a-f]{32}$/D', $argv[4])) exit(2);
if (!chdir(__DIR__)) exit(1);
$_SERVER['REMOTE_USER'] = $argv[3];
require_once __DIR__ . '/move_job_lib.php';
exit(AutoToolsMoveJob::run($argv[1], $argv[2], $argv[3], $argv[4]));
