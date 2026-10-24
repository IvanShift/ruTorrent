<?php

$_ENV['RU_PROFILE_PATH'] = sys_get_temp_dir().'/rutorrent-task-display-'.getmypid().'-'.bin2hex(random_bytes(4));

require_once(__DIR__.'/../../php/TestCase.php');
define('RTASK_KILL_HELPER', '/nonexistent-task-display-kill');
define('RTASK_SUPERVISOR_HELPER', '/nonexistent-task-display-supervisor');
require_once(__DIR__.'/../../../plugins/_task/task.php');

class TaskDisplayTest extends TestCase
{
    private $id;
    private $dir;

    public function setUp()
    {
        $this->id = uniqid(time(), true);
        $this->dir = rTask::formatPath($this->id);
        FileUtil::makeDirectory($this->dir);
        foreach(array('pid'=>'12345', 'flags'=>'0', 'status'=>'0',
            'supervisor.version'=>"1\n", 'supervisor.complete'=>"1\n", 'supervisor.outcome'=>"normal\n") as $name=>$value)
            file_put_contents($this->dir.'/'.$name, $value);
    }

    public function tearDown()
    {
        FileUtil::deleteDirectory($_ENV['RU_PROFILE_PATH']);
    }

    public function testCheckAndListProjectLegacyTextWithoutChangingTaskStorageOrIdentity()
    {
        $params = array('name'=>'mediainfo', 'requester'=>'mediainfo', 'arg'=>"Фильм \xFF.mkv",
            'hash'=>str_repeat('A', 40), 'no'=>'83', 'path'=>"/data/Фильм \xFF.mkv");
        $stored = array('params'=>serialize($params), 'start.sh'=>"printf '%s' '/data/Фильм \xFF.mkv'\n",
            'log'=>"Фильм \xFF.mkv\n映画 🎬\n-=*=-\n0\n", 'errors'=>"error <b>\xFE</b>\n");
        foreach($stored as $name=>$value) file_put_contents($this->dir.'/'.$name, $value);
        $task = rTask::check($this->id);
        $this->assertSame($params, $task['params'], 'original parameters retain exact bytes, hash and file index');
        $this->assertSame(array("Фильм \xFF.mkv", '映画 🎬', '-=*=-', '0'), $task['log'], 'raw output and callback markers retain their bytes');
        $this->assertSame(array('arg'=>'Фильм \\xFF.mkv',
            'log'=>array('Фильм \\xFF.mkv', '映画 🎬', '-=*=-', '0'),
            'errors'=>array('error <b>\\xFE</b>')), $task['display'] ?? null,
            'only the separate display fields escape unknown bytes and retain valid Unicode');
        $this->assertSame($task, rTask::check($this->id), 'repeat polling produces the same projection without double escaping');
        $listed = rTaskManager::obtain();
        $this->assertSame($task['display'] ?? null, $listed[$this->id]['display'] ?? null, 'background listing uses the same projection');
        $this->assertSame($params['arg'], $listed[$this->id]['params']['arg'], 'listing retains the raw parameter');
        $this->assertSame('mediainfo', $listed[$this->id]['requester'], 'requester remains the callback identity');
        $json = json_decode(JSON::safeEncode($task), true);
        $this->assertSame('Фильм \\xFF.mkv', $json['display']['arg'] ?? null, 'the display argument survives JSON without replacement characters');
        foreach($stored as $name=>$value)
            $this->assertSame($value, file_get_contents($this->dir.'/'.$name), $name.' is never rewritten during display');
    }

    public function testValidUnicodeNeedsNoDisplayEnvelope()
    {
        $params = array('arg'=>'映画/Фильм 🎬', 'hash'=>str_repeat('B', 40), 'no'=>'0');
        file_put_contents($this->dir.'/params', serialize($params));
        file_put_contents($this->dir.'/log', "映画/Фильм 🎬\n-=*=-\n0\n");
        file_put_contents($this->dir.'/errors', "Ошибка: déjà vu\n");
        $task = rTask::check($this->id);
        $this->assertSame(false, isset($task['display']), 'ordinary Unicode retains the existing response shape');
        $this->assertSame($params, $task['params'], 'valid parameters are unchanged');
        $this->assertSame(array('映画/Фильм 🎬', '-=*=-', '0'), $task['log'], 'valid text and protocol markers stay unchanged');
    }

    public function testProjectionFollowsExistingConsoleFilteringAndTail()
    {
        file_put_contents($this->dir.'/flags', (string)(rTask::FLG_STRIP_LOGS | rTask::FLG_REMOVE_ASCII));
        $lines = array_fill(0, 82, "old\n");
        $lines[] = "first\rФинал \xFF\n";
        $lines[] = "ab\x08C\xFE\n";
        file_put_contents($this->dir.'/log', implode('', $lines));
        $task = rTask::check($this->id);
        $this->assertSame(array(), $task['params'], 'missing parameter metadata does not prevent log display');
        $this->assertSame(80, count($task['log']), 'the existing console limit remains unchanged');
        $this->assertSame(array("Финал \xFF", "aC\xFE"), array_slice($task['log'], -2), 'raw console filtering still handles carriage returns and backspaces first');
        $this->assertSame(array('Финал \\xFF', 'aC\\xFE'), array_slice($task['display']['log'] ?? array(), -2),
            'display rows align with the retained raw rows after console filtering');
    }

    public function testFailedTaskWithoutPidStillShowsLegacyErrorBytes()
    {
        unlink($this->dir.'/pid');
        file_put_contents($this->dir.'/errors', "cannot open \xFF\n");
        $task = rTask::check($this->id);
        $this->assertSame(255, $task['status'], 'the original failed-start result is retained');
        $this->assertSame(array("cannot open \xFF"), $task['errors'], 'failure details remain raw in the result');
        $this->assertSame(array('cannot open \\xFF'), $task['display']['errors'] ?? null, 'failed-start errors receive the same display projection');
    }

    public function testNonTextArgumentsKeepTheirShapeWhenOnlyOutputNeedsProjection()
    {
        file_put_contents($this->dir.'/log', "unknown \xFF\n");
        foreach(array(0, null, array('identity'=>"raw \xFE")) as $arg)
        {
            $params = array('arg'=>$arg);
            file_put_contents($this->dir.'/params', serialize($params));
            $task = rTask::check($this->id);
            $this->assertSame($params, $task['params'], 'non-text parameters are not coerced or projected');
            $this->assertSame(array('log'=>array('unknown \\xFF')), $task['display'],
                'the optional envelope contains only the changed output field');
        }
    }
}
