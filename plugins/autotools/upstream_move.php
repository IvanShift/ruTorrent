<?php
require_once dirname(__DIR__, 2) . '/php/cli-only.php';
rutorrent_require_cli(__FILE__);
define('RUTORRENT_SYNCHRONOUS_HOOK', true);
$mode = isset($argv[1]) ? $argv[1] : '';
try {
    // The shared filesystem ancestor walk also consumes a pending array.
    if (!function_exists('array_shift')) exit(2);
    $argumentCount = count($argv);
    if (!is_int($argumentCount)
        || ($mode === 'check' && $argumentCount !== 8 && $argumentCount !== 9)
        || ($mode === 'move' && $argumentCount !== 9 && $argumentCount !== 10)
        || ($mode !== 'check' && $mode !== 'move')) exit(2);
    $args = @array_slice($argv, 2);
    if (!is_array($args) || count($args) !== $argumentCount - 2) exit(2);
    for ($index = 0; $index < $argumentCount - 2; ++$index)
        if (!isset($args[$index]) || !is_string($args[$index])) exit(2);
    $user = @array_pop($args);
    if (!is_string($user) || count($args) !== $argumentCount - 3
        || @preg_match('/^[a-z0-9_-]*$/D', $user) !== 1 || @chdir(__DIR__) !== true) exit(2);
    $sourceOffset = $mode === 'check' && $argumentCount === 8 ? 0 : 1;
    $sourcePath = $args[$sourceOffset];
    $sourceMulti = $args[$sourceOffset + 2];
    if ($sourcePath === '' || $sourcePath[0] !== '/' || ($sourceMulti !== '0' && $sourceMulti !== '1')) exit(2);
} catch (Throwable $error) { exit(2); }
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
    if ($mode === 'check') echo $sourceMulti === '1' ? rtrim($sourcePath, '/') : dirname($sourcePath);
    error_log('autotools: upstream-move refused: bootstrap-or-operation-failed');
    exit(1);
}
