<?php
require_once dirname(__DIR__) . '/php/cli-only.php';
rutorrent_require_cli(__FILE__);
$user = ''; $apply = false; $hash = null;
for ($i = 1; $i < count($argv); ++$i) {
    if ($argv[$i] === '--apply') $apply = true;
    elseif ($argv[$i] === '--user' && isset($argv[$i+1])) $user = $argv[++$i];
    elseif ($hash === null && preg_match('/^[0-9A-Fa-f]{40}$/D', $argv[$i])) $hash = strtoupper($argv[$i]);
    else { fwrite(STDERR, "Usage: autotools-move-reset.php [HASH] [--apply] [--user USER]\n"); exit(2); }
}
if (!preg_match('/^[a-z0-9_-]*$/D', $user) || ($apply && $hash === null)) exit(2);
if (!$apply) define('RUTORRENT_SETTINGS_READ_ONLY', true);
$_SERVER['REMOTE_USER'] = $user;
chdir(dirname(__DIR__) . '/plugins/autotools');
require_once './util_rt.php';
require_once './move_queue.php';
try {
    $hashes = $hash !== null ? array($hash) : array();
    if ($hash === null) $hashes = array_column(AutoToolsMoveQueue::downloadRows(), 0);
    foreach ($hashes as $item) {
        if (!is_string($item) || !preg_match('/^[0-9A-F]{40}$/D', $item)) throw new RuntimeException();
        $state = AutoToolsMoveQueue::state($item);
        if ($state['phase'] === 'none') continue;
        if (AutoToolsMoveQueue::owner($item, $state) !== hash('sha256', $user)) {
            if ($hash !== null) throw new RuntimeException();
            continue;
        }
        echo $item . ' ' . $state['phase'] . (isset($state['reason']) ? ': ' . $state['reason'] : '') . "\n";
        if ($apply) {
            if ($state['phase'] !== 'terminal' && $state['phase'] !== 'held') throw new RuntimeException();
            $q = array('rTorrent', 'quoteCommandArg');
            $condition = 'equal=' . $q('system.autotools.move_owner=' . $item) . ',' . $q('cat=' . hash('sha256', $user));
            try { $answer = AutoToolsMoveQueue::call('branch', array('', $condition,
                'system.autotools.move_reset=' . $item, 'cat=CHANGED')); }
            catch (RuntimeException $e) { $answer = ''; }
            if ($answer !== 'reset') {
                $after = AutoToolsMoveQueue::state($item);
                if (!in_array($after['phase'], array('queued', 'leased'), true)
                    || AutoToolsMoveQueue::owner($item, $after) !== hash('sha256', $user)
                    || ($after['phase'] === 'queued' && $after['retry'] !== '')
                    || (isset($state['qid']) && $after['qid'] !== $state['qid'])) throw new RuntimeException();
            }
            echo $item . " reset; native dispatcher will retry automatically\n";
        }
    }
} catch (Throwable $e) { fwrite(STDERR, "AutoTools reset refused: state unconfirmed; no mutation replayed\n"); exit(2); }
