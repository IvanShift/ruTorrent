<?php
require_once dirname(__DIR__, 2) . '/php/cli-only.php';
rutorrent_require_cli(__FILE__);
define('RUTORRENT_SYNCHRONOUS_HOOK', true);
$mode = isset($argv[1]) ? $argv[1] : '';
if (($mode === 'check' && count($argv) !== 8) || ($mode === 'move' && count($argv) !== 9)
    || !in_array($mode, array('check', 'move'), true)) exit(2);
$args = array_slice($argv, 2);
$user = array_pop($args);
if (!preg_match('/^[a-z0-9_-]*$/D', $user) || !chdir(__DIR__)) exit(2);
$_SERVER['REMOTE_USER'] = $user;
try {
    require_once __DIR__ . '/util_rt.php';
    require_once __DIR__ . '/autotools.php';
    require_once __DIR__ . '/../erasedata/filesystem.php';
    require_once __DIR__ . '/upstream_move_lib.php';
    if ($mode === 'check') echo AutoToolsUpstreamMove::check($args);
    else AutoToolsUpstreamMove::move($args);
} catch (Throwable $e) {
    // Returning the source preserves the synchronous check contract on bootstrap refusal.
    if ($mode === 'check') echo $args[2] === '1' ? rtrim($args[0], '/') : dirname($args[0]);
    error_log('autotools: upstream-move refused: bootstrap-or-operation-failed');
    exit(1);
}
