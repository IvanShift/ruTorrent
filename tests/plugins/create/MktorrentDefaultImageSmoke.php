<?php
// Run with the packaged mktorrent binary; standalone installations stay optional.
$profile = sys_get_temp_dir().'/rutorrent-create-image-'.getmypid().'-'.bin2hex(random_bytes(4));
mkdir($profile, 0700);
putenv('RU_PROFILE_PATH='.$profile);
$_ENV['RU_PROFILE_PATH'] = $profile;
require_once(__DIR__.'/../../../plugins/_task/task.php');
require_once(__DIR__.'/../../../php/Torrent.php');
require(__DIR__.'/../../../plugins/create/conf.php');
if ($useExternal !== 'mktorrent' || $pathToCreatetorrent !== '/usr/local/bin/mktorrent')
    throw new RuntimeException('The packaged creator is not selected by the default plugin configuration');
$task = new rTask(array('name'=>'packaged mktorrent create smoke'));
$directory = rTask::formatPath($task->id);
try {
    mkdir($directory, 0700, true);
    $payload = $profile.'/payload';
    file_put_contents($payload, str_repeat('mktorrent image smoke\n', 200));
    $request = array('trackers'=>'https://tracker.invalid/announce', 'comment'=>'packaged creator',
        'source'=>'', 'private'=>0, 'start_seeding'=>0);
    file_put_contents($directory.'/params', serialize($request));
    $process = proc_open(array(dirname(__DIR__, 3).'/plugins/create/mktorrent.sh', $task->id,
        PHP_BINARY, $pathToCreatetorrent, $payload, '18', User::getUser(), $directory), array(
        0=>array('file','/dev/null','r'), 1=>array('file','php://stdout','w'),
        2=>array('file','php://stderr','w')), $pipes);
    if (!is_resource($process) || proc_close($process) !== 0)
        throw new RuntimeException('The real Create mktorrent wrapper failed');
    $external = new Torrent($directory.'/temp.torrent');
    $result = new Torrent($directory.'/result.torrent');
    if ($result->errors() || $result->hash_info() !== $external->hash_info()
        || $result->announce() !== $request['trackers'] || $result->comment() !== $request['comment']
        || (int)$result->info['length'] !== filesize($payload)
        || $result->info['pieces'] !== sha1(file_get_contents($payload), true))
        throw new RuntimeException('The real Create wrapper assertions failed: '.json_encode(array('errors'=>$result->errors(), 'same_info_hash'=>$result->hash_info() === $external->hash_info(), 'announce'=>$result->announce(), 'comment'=>$result->comment(), 'length'=>$result->info['length'] ?? null, 'expected_length'=>filesize($payload), 'same_pieces'=>$result->info['pieces'] === sha1(file_get_contents($payload), true))));
    echo "ok - packaged mktorrent default creates and corrects a torrent\n";
} finally { FileUtil::deleteDirectory($profile); }
