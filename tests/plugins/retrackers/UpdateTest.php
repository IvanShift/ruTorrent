<?php

define('RETRACKERS_TEST_MODE', 1);

function getCmd($command)
{
    return $command;
}

function rtAssertSame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '; expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

function rtAssertTrue($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
}

class FileUtil
{
    public static $logs = array();
    public static $writeAttempts = 0;

    public static function toLog($message)
    {
        self::$writeAttempts++;
        self::$logs[] = (string) $message;
    }
}

class RetrackersTrace
{
    public static $events = array();
}

class rXMLRPCCommand
{
    public $command;
    public $params;

    public function __construct($command, $params = null)
    {
        $this->command = $command;
        $this->params = $params;
    }
}

class rXMLRPCRequest
{
    public static $requests = array();
    public static $responses = array();
    public static $constructions = 0;
    public $important = true;
    public $val = array();
    public $fault = false;
    public $faultString = '';
    private $commands;

    public function __construct($commands = null)
    {
        self::$constructions++;
        $this->commands = is_array($commands) ? $commands : array($commands);
    }

    public static function reset()
    {
        self::$requests = array();
        self::$responses = array();
        self::$constructions = 0;
    }

    public static function queue($run, $fault = false, $values = array(), $faultString = '')
    {
        self::$responses[] = compact('run', 'fault', 'values', 'faultString');
    }

    private function answer()
    {
        $response = count(self::$responses)
            ? array_shift(self::$responses)
            : array('run' => true, 'fault' => false, 'values' => array(), 'faultString' => '');
        $this->val = is_callable($response['values'])
            ? call_user_func($response['values'], $this->commands)
            : $response['values'];
        $this->fault = $response['fault'];
        $this->faultString = $response['faultString'];
        self::$requests[] = array(
            'commands' => $this->commands,
            'important' => $this->important,
        );
        $names = array();
        foreach ($this->commands as $command) $names[] = $command->command;
        RetrackersTrace::$events[] = 'rpc:' . implode(',', $names);
        return $response['run'];
    }

    public function success()
    {
        return $this->answer() && !$this->fault;
    }

    public function run()
    {
        return $this->answer();
    }
}

class rRetrackers
{
    public static $current;
    public static $loads = 0;
    public $list = array();
    public $todelete = array();
    public $dontAddPrivate = 1;
    public $addToBegin = 0;

    public static function load()
    {
        self::$loads++;
        return self::$current;
    }

    public function get()
    {
        return 'fixture';
    }
}

function rtBencodeIsList($value)
{
    $index = 0;
    foreach (array_keys($value) as $key) {
        if ($key !== $index++) return false;
    }
    return true;
}

function rtBencodeEncode($value)
{
    if (is_int($value) || is_float($value)) return 'i' . $value . 'e';
    if (is_array($value)) {
        if (rtBencodeIsList($value)) {
            $encoded = 'l';
            foreach ($value as $item) $encoded .= rtBencodeEncode($item);
            return $encoded . 'e';
        }
        ksort($value, SORT_STRING);
        $encoded = 'd';
        foreach ($value as $key => $item) {
            $encoded .= strlen((string) $key) . ':' . $key . rtBencodeEncode($item);
        }
        return $encoded . 'e';
    }
    $value = (string) $value;
    return strlen($value) . ':' . $value;
}

function rtBencodeDecodeString($data, &$offset, &$valid)
{
    $colon = strpos($data, ':', $offset);
    if ($colon === false) {
        $valid = false;
        return null;
    }
    $token = substr($data, $offset, $colon - $offset);
    if (!preg_match('/^(?:0|[1-9][0-9]*)$/D', $token)) {
        $valid = false;
        return null;
    }
    $length = (int) $token;
    $offset = $colon + 1;
    if ($length > strlen($data) - $offset) {
        $valid = false;
        return null;
    }
    $value = substr($data, $offset, $length);
    $offset += $length;
    return $value;
}

function rtBencodeDecodeValue($data, &$offset, &$valid)
{
    if (!$valid || $offset >= strlen($data)) {
        $valid = false;
        return null;
    }
    if ($data[$offset] === 'i') {
        $end = strpos($data, 'e', ++$offset);
        if ($end === false) {
            $valid = false;
            return null;
        }
        $token = substr($data, $offset, $end - $offset);
        if (!preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $token)) {
            $valid = false;
            return null;
        }
        $offset = $end + 1;
        return floatval($token);
    }
    if ($data[$offset] === 'l') {
        $offset++;
        $list = array();
        while ($valid && $offset < strlen($data) && $data[$offset] !== 'e') {
            $list[] = rtBencodeDecodeValue($data, $offset, $valid);
        }
        if (!$valid || $offset >= strlen($data)) {
            $valid = false;
            return null;
        }
        $offset++;
        return $list;
    }
    if ($data[$offset] === 'd') {
        $offset++;
        $dictionary = array();
        while ($valid && $offset < strlen($data) && $data[$offset] !== 'e') {
            $key = rtBencodeDecodeString($data, $offset, $valid);
            if (!$valid) return null;
            $dictionary[$key] = rtBencodeDecodeValue($data, $offset, $valid);
        }
        if (!$valid || $offset >= strlen($data)) {
            $valid = false;
            return null;
        }
        $offset++;
        return $dictionary;
    }
    return rtBencodeDecodeString($data, $offset, $valid);
}

function rtBencodeDecode($data)
{
    $offset = 0;
    $valid = is_string($data);
    $value = $valid ? rtBencodeDecodeValue($data, $offset, $valid) : null;
    return $valid && $offset === strlen($data) ? $value : false;
}

class Torrent
{
    public static $hasErrors = false;
    public static $constructorInputs = array();
    public static $pathReads = 0;
    public static $afterFirstConstruction = null;
    public static $hashAfterTrackerMutation = null;
    private $meta = array();
    private $hashOverride = null;
    private $errors = false;
    public $rtorrent = null;

    public function __construct($source)
    {
        self::$constructorInputs[] = $source;
        RetrackersTrace::$events[] = 'torrent:' . count(self::$constructorInputs);
        if (is_string($source) && is_file($source)) {
            self::$pathReads++;
            $source = file_get_contents($source);
        }
        $decoded = rtBencodeDecode($source);
        if (!is_array($decoded) || !isset($decoded['info']) || !is_array($decoded['info'])) {
            $this->errors = array(new RuntimeException('Bad torrent data'));
            $decoded = array('info' => array());
        }
        $this->meta = $decoded;
        if (array_key_exists('rtorrent', $decoded)) $this->rtorrent = $decoded['rtorrent'];

        if (count(self::$constructorInputs) === 1 && is_callable(self::$afterFirstConstruction)) {
            call_user_func(self::$afterFirstConstruction);
        }
    }

    public function errors()
    {
        return self::$hasErrors ? array(new RuntimeException('Injected torrent error')) : $this->errors;
    }

    public function hash_info()
    {
        return $this->hashOverride !== null
            ? $this->hashOverride
            : strtoupper(sha1(rtBencodeEncode($this->meta['info'])));
    }

    public function setHash($h)
    {
        $this->hashOverride = $h;
    }

    public function announce($value = null)
    {
        if ($value !== null) $this->meta['announce'] = $value;
        return isset($this->meta['announce']) ? $this->meta['announce'] : null;
    }

    public function announce_list($value = null)
    {
        if ($value !== null) {
            $this->meta['announce-list'] = $value;
            if (self::$hashAfterTrackerMutation !== null) {
                $this->hashOverride = self::$hashAfterTrackerMutation;
            }
        }
        return isset($this->meta['announce-list']) ? $this->meta['announce-list'] : null;
    }

    public function __toString()
    {
        $data = $this->meta;
        if (isset($this->rtorrent)) $data['rtorrent'] = $this->rtorrent;
        else unset($data['rtorrent']);
        return rtBencodeEncode($data);
    }
}

class rTorrent
{
    public static $sends = array();
    public static $sendResponses = array();

    public static function queueSend($result)
    {
        self::$sendResponses[] = $result;
    }

    public static function sendTorrent($torrent, $isStart, $isAddPath, $directory, $label,
        $saveTorrent, $isFast, $isNew = true, $addition = null)
    {
        $bytes = is_object($torrent) && method_exists($torrent, '__toString') ? (string) $torrent : null;
        self::$sends[] = compact('torrent', 'bytes', 'isStart', 'isAddPath', 'directory', 'label',
            'saveTorrent', 'isFast', 'isNew', 'addition');
        RetrackersTrace::$events[] = 'send:' . count(self::$sends);
        if (count(self::$sendResponses) > 0)
            return array_shift(self::$sendResponses);
        return is_object($torrent) && method_exists($torrent, 'hash_info') ? $torrent->hash_info() : false;
    }
}

class User
{
    public static function getUser()
    {
        return 'alice';
    }
}

class Utility
{
    public static function getPHP()
    {
        return '/usr/bin/php';
    }
}

class RetrackersSettingsFixture
{
    public $insertCommands = array();
    public $registered = false;

    public function getOnInsertCommand($args)
    {
        $command = new rXMLRPCCommand('on_insert', $args);
        $this->insertCommands[] = $command;
        return $command;
    }

    public function registerPlugin($name, $permissions)
    {
        $this->registered = true;
    }
}

function rtResetWorker()
{
    rXMLRPCRequest::reset();
    FileUtil::$logs = array();
    FileUtil::$writeAttempts = 0;
    rRetrackers::$loads = 0;
    rTorrent::$sends = array();
    rTorrent::$sendResponses = array();
    RetrackersTrace::$events = array();
    Torrent::$hasErrors = false;
    Torrent::$constructorInputs = array();
    Torrent::$pathReads = 0;
    Torrent::$afterFirstConstruction = null;
    Torrent::$hashAfterTrackerMutation = null;
    $config = new rRetrackers();
    $config->list = array(array('http://retracker.example/announce'));
    rRetrackers::$current = $config;
}

function rtInfoBytes($name = 'movie.mkv')
{
    return rtBencodeEncode(array(
        'length' => 1,
        'name' => $name,
        'piece length' => 16384,
        'pieces' => str_repeat('P', 20),
    ));
}

function rtFixtureHash($name = 'movie.mkv')
{
    return strtoupper(sha1(rtInfoBytes($name)));
}

function rtTorrentBytes($announce = 'http://tracker.example/announce', $announceList = null,
    $name = 'movie.mkv', $creationDate = '9007199254740993')
{
    if ($announceList === null) $announceList = array(array($announce));
    return 'd'
        . rtBencodeEncode('announce') . rtBencodeEncode($announce)
        . rtBencodeEncode('announce-list') . rtBencodeEncode($announceList)
        . rtBencodeEncode('creation date') . 'i' . $creationDate . 'e'
        . rtBencodeEncode('info') . rtInfoBytes($name)
        . rtBencodeEncode('rtorrent') . rtBencodeEncode(array('state' => 'fixture'))
        . 'e';
}

function rtSourcePath()
{
    static $path = null;
    if ($path === null) {
        $path = tempnam(sys_get_temp_dir(), 'retrackers-update-');
        if ($path === false) throw new RuntimeException('Unable to create retrackers source fixture');
    }
    return $path;
}

function rtWriteSource($bytes)
{
    $written = file_put_contents(rtSourcePath(), $bytes);
    if ($written !== strlen($bytes)) throw new RuntimeException('Unable to write retrackers source fixture');
    return rtSourcePath();
}

function rtInitialValues($hash, $label = 'Movies', $marker = '', $private = 0, $name = 'movie.mkv')
{
    $source = rtWriteSource(rtTorrentBytes());
    return array('', $source, $label, '/downloads', $private, $name, $marker);
}

function rtInitialValuesByCommand($storedState, $label = 'Movies', $marker = '', $private = 0,
    $name = 'movie.mkv')
{
    $source = rtWriteSource(rtTorrentBytes());
    $values = array(
        'get_session' => '',
        'd.get_custom4' => $storedState,
        'd.get_tied_to_file' => $source,
        'd.get_custom1' => $label,
        'd.get_directory_base' => '/downloads',
        'd.is_private' => $private,
        'd.get_name' => $name,
        'd.get_custom' => $marker,
    );
    return function ($commands) use ($values) {
        $response = array();
        foreach ($commands as $command) {
            if (!array_key_exists($command->command, $values))
                throw new RuntimeException('Unexpected initial command ' . $command->command);
            $response[] = $values[$command->command];
        }
        return $response;
    };
}

function rtInitialValuesFromBytes($hash, $bytes)
{
    $values = rtInitialValues($hash);
    rtWriteSource($bytes);
    return $values;
}

function rtCommandNames()
{
    $commands = array();
    foreach (rXMLRPCRequest::$requests as $request) {
        foreach ($request['commands'] as $command) $commands[] = $command->command;
    }
    return $commands;
}

$root = realpath(__DIR__ . '/../../..');
require_once($root . '/plugins/retrackers/guard.php');
require_once($root . '/plugins/retrackers/update.php');

$tests = array();

$tests['happy path loads candidate once without rollback'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend($hash);
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(true, $result, 'happy path returns true');
    rtAssertSame(2, count(rXMLRPCRequest::$requests), 'initial read and erase are issued');
    rtAssertSame(1, count(rTorrent::$sends), 'the candidate torrent is loaded once');
    rtAssertSame(array('d.set_custom3=1'), rTorrent::$sends[0]['addition'], 'loop suppression preserved');
};

$tests['started argv snapshot overrides stopped custom4 for candidate load'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend($hash);
    rXMLRPCRequest::queue(true, false, rtInitialValuesByCommand(0));
    rXMLRPCRequest::queue(true, false, array()); // d.erase

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(true, $result, 'candidate load succeeds with the immutable started snapshot');
    rtAssertSame(true, rTorrent::$sends[0]['isStart'], 'candidate start state follows argv, not stopped custom4');
    rtAssertTrue(!in_array('d.get_custom4', rtCommandNames(), true),
        'worker RPC never re-reads the mutable saved state');
};

$tests['stopped argv snapshot overrides started custom4 for candidate load'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend($hash);
    rXMLRPCRequest::queue(true, false, rtInitialValuesByCommand(1));
    rXMLRPCRequest::queue(true, false, array()); // d.erase

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '0'));

    rtAssertSame(true, $result, 'candidate load succeeds with the immutable stopped snapshot');
    rtAssertSame(false, rTorrent::$sends[0]['isStart'], 'candidate start state follows argv, not started custom4');
    rtAssertTrue(!in_array('d.get_custom4', rtCommandNames(), true),
        'worker RPC never re-reads the mutable saved state');
};

$tests['rollback start state follows argv when custom4 diverges'] = function () {
    $hash = rtFixtureHash();
    foreach (array(
        'started argv and stopped custom4' => array('1', 0, true),
        'stopped argv and started custom4' => array('0', 1, false),
    ) as $label => $case) {
        rtResetWorker();
        rTorrent::queueSend(false); // candidate send fails
        rTorrent::queueSend($hash); // rollback send succeeds
        rXMLRPCRequest::queue(true, false, rtInitialValuesByCommand($case[1]));
        rXMLRPCRequest::queue(true, false, array()); // d.erase
        rXMLRPCRequest::queue(true, true, array(), 'info-hash not found'); // presence: absent

        retrackersRunWorker(array('update.php', $hash, 'alice', $case[0]));

        rtAssertSame(2, count(rTorrent::$sends), $label . ': candidate and rollback are attempted');
        rtAssertSame($case[2], rTorrent::$sends[1]['isStart'],
            $label . ': rollback start state follows the immutable argv snapshot');
    }
};

$tests['candidate send false is not processed'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(false); // candidate send fails
    rTorrent::queueSend($hash); // rollback send succeeds
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(true, true, array(), 'Could not find info-hash.'); // presence probe: absent

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'failed candidate is not processed even after rollback');
    rtAssertSame(2, count(rTorrent::$sends), 'candidate send and rollback send both attempted');
};

$tests['candidate wrong hash is not processed'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    $wrong = str_repeat('F', 40);
    rTorrent::queueSend($wrong); // wrong hash returned
    rTorrent::queueSend($hash); // rollback send
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(true, true, array(), 'info-hash not found'); // probe: absent

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'wrong hash is not processed');
    rtAssertSame(2, count(rTorrent::$sends), 'rollback issued after wrong hash');
};

$tests['candidate confirmation requires exact canonical hash'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(strtolower($hash));
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(true, false, array($hash)); // presence: exact matching hash

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'case-folded candidate confirmation is rejected');
    rtAssertSame(1, count(rTorrent::$sends), 'an unconfirmed candidate is not loaded twice');
};

$tests['source hash mismatch fails before erase'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rXMLRPCRequest::queue(true, false, rtInitialValuesFromBytes($hash,
        rtTorrentBytes('http://tracker.example/announce', null, 'wrong.mkv')));
    rXMLRPCRequest::queue(true, false, array()); // pre-erase recovery d.start

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'a source carrying another info hash is rejected');
    rtAssertTrue(!in_array('d.erase', rtCommandNames(), true), 'source mismatch never erases a torrent');
    rtAssertSame(array(), rTorrent::$sends, 'source mismatch never loads metainfo');
};

$tests['candidate hash mismatch fails before erase'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    Torrent::$hashAfterTrackerMutation = str_repeat('D', 40);
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // pre-erase recovery d.start

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'a candidate carrying another info hash is rejected');
    rtAssertTrue(!in_array('d.erase', rtCommandNames(), true), 'candidate mismatch never erases a torrent');
    rtAssertSame(array(), rTorrent::$sends, 'candidate mismatch never loads metainfo');
};

$tests['malformed metainfo fails before erase'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rXMLRPCRequest::queue(true, false, rtInitialValuesFromBytes($hash, 'not-metainfo'));
    rXMLRPCRequest::queue(true, false, array()); // pre-erase recovery d.start

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'malformed metainfo is rejected');
    rtAssertTrue(!in_array('d.erase', rtCommandNames(), true), 'malformed metainfo never erases a torrent');
    rtAssertSame(array(), rTorrent::$sends, 'malformed metainfo never loads a torrent');
};

$tests['unconfirmed erase never falls through to d.start'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(false, false, array()); // d.erase transport uncertainty

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'unconfirmed erase is not processed');
    rtAssertSame(array('get_session', 'd.get_tied_to_file', 'd.get_custom1',
        'd.get_directory_base', 'd.is_private', 'd.get_name', 'd.get_custom', 'd.erase'), rtCommandNames(),
        'no blind d.start follows an issued erase');
    rtAssertSame(array(), rTorrent::$sends, 'candidate is not loaded after unconfirmed erase');
};

$tests['confirmed absence rolls back immutable original once'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(false);
    rTorrent::queueSend($hash);
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash, 'Label1'));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(true, true, array(), 'Could not find info-hash.'); // probe: absent

    retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(2, count(rTorrent::$sends), 'rollback load is sent');
    rtAssertSame('Label1', rTorrent::$sends[1]['label'], 'rollback preserves label');
    rtAssertSame(true, rTorrent::$sends[1]['isStart'], 'rollback preserves start state');
    rtAssertSame(array('d.set_custom3=1'), rTorrent::$sends[1]['addition'], 'rollback carries suppression marker');
};

$tests['confirmed present after unconfirmed send does not blind-load'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(false);
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(true, false, array($hash)); // probe: clean matching (present!)

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'unconfirmed send is not processed');
    rtAssertSame(1, count(rTorrent::$sends), 'no second load when hash is present');
};

$tests['unknown presence does not blind-load'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(false);
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(false, false, array()); // probe: transport failure (unknown!)

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'unknown presence is not processed');
    rtAssertSame(1, count(rTorrent::$sends), 'no blind load on unknown presence');
};

$tests['clean empty presence is unknown and does not rollback'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(false);
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(true, false, array('')); // probe: clean empty is unknown

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'clean empty presence is not processed');
    rtAssertSame(1, count(rTorrent::$sends), 'clean empty presence does not authorize rollback');
    rtAssertTrue(strpos(implode("\n", FileUtil::$logs), 'presence=unknown') !== false,
        'clean empty presence is diagnosed as unknown');
};

$tests['missing fault after transport failure is unknown'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(false);
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(false, true, array(), 'Could not find info-hash.');

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'failed transport cannot confirm absence');
    rtAssertSame(1, count(rTorrent::$sends), 'failed transport does not authorize rollback');
};

$tests['partial missing-hash fault is unknown'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(false);
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(true, true, array(), 'Permission denied: info-hash not found');

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'an unrelated fault containing missing-hash text is unknown');
    rtAssertSame(1, count(rTorrent::$sends), 'partial missing-hash text does not authorize rollback');
};

$tests['rollback failure remains unsuccessful'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(false);
    rTorrent::queueSend(false); // rollback send also fails
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(true, true, array(), 'Could not find info-hash.'); // probe: absent

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'rollback failure is not processed');
};

$tests['rollback confirmation requires exact canonical hash'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(false);
    rTorrent::queueSend(strtolower($hash));
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(true, true, array(), 'Could not find info-hash.');

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'case-folded rollback confirmation remains unsuccessful');
    rtAssertTrue(strpos(implode("\n", FileUtil::$logs), 'rollback-load-failed') !== false,
        'case-folded rollback confirmation is diagnosed as failed');
};

$tests['unknown presence after erase issues neither rollback load nor d.start'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(false);
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(false, false, array()); // probe: transport failure

    retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(1, count(rTorrent::$sends), 'only candidate send was attempted');
    $commands = array();
    foreach (rXMLRPCRequest::$requests as $req) {
        foreach ($req['commands'] as $cmd) {
            $commands[] = $cmd->command;
        }
    }
    rtAssertTrue(!in_array('d.start', $commands), 'd.start must not be called after erase with unknown presence');
};

$tests['failed rollback never starts an unconfirmed hash'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(false);
    rTorrent::queueSend(false); // rollback fails
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(true, true, array(), 'Could not find info-hash.'); // probe: absent

    retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    $commands = array();
    foreach (rXMLRPCRequest::$requests as $req) {
        foreach ($req['commands'] as $cmd) {
            $commands[] = $cmd->command;
        }
    }
    rtAssertTrue(!in_array('d.start', $commands), 'd.start must not be called on failed rollback');
};

$tests['confirmed-present unconfirmed send does not issue redundant d.start'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend(false);
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(true, false, array($hash)); // probe: clean matching

    retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    $commands = array();
    foreach (rXMLRPCRequest::$requests as $req) {
        foreach ($req['commands'] as $cmd) {
            $commands[] = $cmd->command;
        }
    }
    rtAssertTrue(!in_array('d.start', $commands), 'd.start must not be called after erase when hash is confirmed present');
};

$tests['one immutable byte snapshot survives source replacement and rollback'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    $originalBytes = rtTorrentBytes();
    $replacementBytes = rtTorrentBytes('http://replacement.example/announce');
    rtAssertTrue(strpos($originalBytes, '13:creation datei9007199254740993e') !== false,
        'fixture carries the exact valid large integer token');
    rtAssertTrue(rtBencodeEncode(rtBencodeDecode($originalBytes)) !== $originalBytes,
        'decoded Torrent-style re-encoding demonstrably changes the captured metainfo');
    rtWriteSource($originalBytes);
    Torrent::$afterFirstConstruction = function () use ($replacementBytes) {
        rtWriteSource($replacementBytes);
    };
    rTorrent::queueSend(false);
    rTorrent::queueSend($hash);
    rXMLRPCRequest::queue(true, false, array('', rtSourcePath(), 'Movies', '/downloads', 0, 'movie.mkv', ''));
    rXMLRPCRequest::queue(true, false, array()); // d.erase
    rXMLRPCRequest::queue(true, true, array(), 'Could not find info-hash.'); // probe: absent

    retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(0, Torrent::$pathReads, 'Torrent objects are never constructed from the mutable path');
    rtAssertSame(array($originalBytes, $originalBytes), Torrent::$constructorInputs,
        'original and candidate are independently parsed from one immutable snapshot');
    rtAssertSame($replacementBytes, file_get_contents(rtSourcePath()), 'source replacement happened after snapshot');
    rtAssertSame(2, count(rTorrent::$sends), 'candidate and rollback are each sent once');
    $candidateList = rTorrent::$sends[0]['torrent']->announce_list();
    $rollbackList = rTorrent::$sends[1]['torrent']->announce_list();
    rtAssertSame(array(array('http://tracker.example/announce')), $rollbackList,
        'rollback object preserves original trackers independently');
    rtAssertTrue(in_array(array('http://retracker.example/announce'), $candidateList, true),
        'candidate alone receives the retracker mutation');
    rtAssertSame($originalBytes, rTorrent::$sends[1]['bytes'], 'rollback bytes are identical to the immutable source snapshot');
    rtAssertSame(array('rpc:get_session,d.get_tied_to_file,d.get_custom1,d.get_directory_base,d.is_private,d.get_name,d.get_custom',
        'torrent:1', 'torrent:2', 'rpc:d.erase', 'send:1', 'rpc:d.hash', 'send:2'), RetrackersTrace::$events,
        'snapshot parsing, erase, candidate, probe, and rollback retain exact ordering');
};

$tests['private and legacy meta-name exclusions only restore prior run state'] = function () {
    $hash = str_repeat('D', 40);
    foreach (array(
        'private' => rtInitialValues($hash, 'Movies', '', 1),
        'legacy meta name' => rtInitialValues($hash, 'Movies', '', 0, $hash . '.meta'),
    ) as $label => $values) {
        rtResetWorker();
        rXMLRPCRequest::queue(true, false, $values);
        rXMLRPCRequest::queue(true, false, array());

        retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

        rtAssertSame(2, count(rXMLRPCRequest::$requests), $label . ': only read and restart');
        rtAssertSame('d.start', rXMLRPCRequest::$requests[1]['commands'][0]->command,
            $label . ': the stopped user torrent is restored');
        rtAssertSame(array(), rTorrent::$sends, $label . ': no reload');
    }
};

$tests['malformed and unexpected failed reads are sanitised early exits'] = function () {
    $hash = str_repeat('E', 40);
    foreach (array(
        'malformed' => array(true, false, array('short'), '', 'malformed-response'),
        'fault' => array(true, true, array(), 'Permission denied passkey=do-not-log', 'rpc-fault'),
        'transport' => array(false, false, array(), '', 'transport-failure'),
    ) as $label => $case) {
        rtResetWorker();
        rXMLRPCRequest::queue($case[0], $case[1], $case[2], $case[3]);

        retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

        rtAssertSame(2, count(rXMLRPCRequest::$requests), $label . ': only initial read and state recovery');
        rtAssertSame('d.start', rXMLRPCRequest::$requests[1]['commands'][0]->command,
            $label . ': the saved started state is restored');
        rtAssertSame(1, count(FileUtil::$logs), $label . ': one concise diagnostic');
        rtAssertTrue(strpos(FileUtil::$logs[0], $case[4]) !== false,
            $label . ': safe reason code is present');
        rtAssertTrue(strpos(FileUtil::$logs[0], 'do-not-log') === false,
            $label . ': raw fault text is absent');
    }
};

$tests['hook stores custom4 before stop and launches worker only after stop'] = function () use ($root) {
    rXMLRPCRequest::reset();
    $theSettings = new RetrackersSettingsFixture();
    $rootPath = '/srv/rutorrent';
    $plugin = array('name' => 'retrackers');
    $pInfo = array('perms' => 'r');
    $jResult = '';

    $previousCwd = getcwd();
    chdir($root . '/php');
    try {
        include($root . '/plugins/retrackers/init.php');
    } finally {
        chdir($previousCwd);
    }

    $commands = $theSettings->insertCommands;
    rtAssertSame(2, count($commands), 'both on-insert hooks are installed');
    $cmd1 = $commands[0]->params[1];
    $cmd2 = $commands[1]->params[1];
    rtAssertTrue(strpos($cmd1, 'd.set_custom4=$cat=$d.get_state=') !== false,
        'hook 1 saves trustworthy pre-stop state into custom4');
    rtAssertTrue(strpos($cmd2, 'd.stop') < strpos($cmd2, 'execute'),
        'hook 2 stops torrent before detached execute');
    rtAssertTrue(strpos($cmd2, '$d.get_custom4=') !== false,
        'hook 2 passes saved custom4 to execute');
    rtAssertTrue(strpos($cmd2, '$d.get_state=') === false,
        'hook 2 never re-reads live state after stop');
};

$tests['run.sh executes worker with exact argv handover'] = function () use ($root) {
    $directory = sys_get_temp_dir() . '/retrackers argv ' . getmypid() . '-' . mt_rand();
    rtAssertTrue(mkdir($directory, 0700), 'argv fixture directory is created');
    $fakePhp = $directory . '/fake php';
    $capture = $directory . '/captured argv';
    $fakeScript = <<<'SH'
#!/bin/sh
{
    printf '%s\n' "$#"
    printf '<%s>\n' "$@"
} > "$RETRACKERS_ARGV_CAPTURE"
SH;
    rtAssertSame(strlen($fakeScript), file_put_contents($fakePhp, $fakeScript),
        'fake PHP executable is written');
    rtAssertTrue(chmod($fakePhp, 0700), 'fake PHP executable is executable');
    $hash = str_repeat('A', 40);
    $user = 'alice user;$(false)';
    $command = 'RETRACKERS_ARGV_CAPTURE=' . escapeshellarg($capture)
        . ' ' . escapeshellarg($root . '/plugins/retrackers/run.sh')
        . ' ' . escapeshellarg($fakePhp)
        . ' ' . escapeshellarg($hash)
        . ' ' . escapeshellarg($user)
        . ' ' . escapeshellarg('1');
    $output = array();
    $status = 0;
    exec($command . ' 2>&1', $output, $status);
    for ($attempt = 0; $attempt < 100 && !is_file($capture); $attempt++) usleep(10000);
    try {
        rtAssertSame(0, $status, 'run.sh accepts the exact four-argument contract');
        rtAssertTrue(is_file($capture), 'detached fake worker records argv');
        rtAssertSame("4\n<update.php>\n<" . $hash . ">\n<" . $user . ">\n<1>\n",
            file_get_contents($capture), 'run.sh preserves each worker argument without reinterpretation');
    } finally {
        @unlink($capture);
        @unlink($fakePhp);
        @rmdir($directory);
    }
};

$tests['started snapshot restarts once after transport fault'] = function () {
    rtResetWorker();
    $hash = str_repeat('E', 40);
    rXMLRPCRequest::queue(false, false, array()); // initial read transport failure
    rXMLRPCRequest::queue(true, false, array()); // recovery d.start

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'transport failure is not processed');
    rtAssertSame(2, count(rXMLRPCRequest::$requests), 'initial read and recovery d.start issued');
    rtAssertSame('d.start', rXMLRPCRequest::$requests[1]['commands'][0]->command, 'd.start was issued');
    rtAssertSame($hash, rXMLRPCRequest::$requests[1]['commands'][0]->params,
        'd.start restores the exact worker hash');
    rtAssertSame(false, rXMLRPCRequest::$requests[1]['important'], 'recovery start is non-important');
};

$tests['started snapshot reports failed recovery transport'] = function () {
    rtResetWorker();
    $hash = str_repeat('E', 40);
    rXMLRPCRequest::queue(false, false, array()); // initial read transport failure
    rXMLRPCRequest::queue(false, false, array()); // recovery d.start transport failure

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'failed state recovery never reports worker success');
    rtAssertSame(2, count(rXMLRPCRequest::$requests), 'recovery start is attempted exactly once');
    rtAssertTrue(strpos(implode("\n", FileUtil::$logs), 'state-recovery-failed reason=transport-failure') !== false,
        'failed recovery transport is logged with a safe reason');
};

$tests['recovery transport failure outranks attached fault'] = function () {
    rtResetWorker();
    $hash = str_repeat('E', 40);
    rXMLRPCRequest::queue(false, false, array()); // initial read transport failure
    rXMLRPCRequest::queue(false, true, array(), 'info-hash not found');

    retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    $logs = implode("\n", FileUtil::$logs);
    rtAssertTrue(strpos($logs, 'state-recovery-failed reason=transport-failure') !== false,
        'failed recovery transport is diagnosed before fault contents');
    rtAssertTrue(strpos($logs, 'state-recovery-failed reason=rpc-fault') === false,
        'failed recovery transport is never misreported as an RPC fault');
};

$tests['started snapshot reports failed recovery fault without secret'] = function () {
    rtResetWorker();
    $hash = str_repeat('E', 40);
    rXMLRPCRequest::queue(false, false, array()); // initial read transport failure
    rXMLRPCRequest::queue(true, true, array(), 'Permission denied token=do-not-log');

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'faulting state recovery never reports worker success');
    rtAssertSame(2, count(rXMLRPCRequest::$requests), 'faulting recovery start is attempted exactly once');
    $logs = implode("\n", FileUtil::$logs);
    rtAssertTrue(strpos($logs, 'state-recovery-failed reason=rpc-fault') !== false,
        'faulting recovery is logged with a safe reason');
    rtAssertTrue(strpos($logs, 'do-not-log') === false, 'recovery log excludes raw fault text');
};

$tests['mixed missing-hash fault restores started snapshot'] = function () {
    rtResetWorker();
    $hash = str_repeat('E', 40);
    rXMLRPCRequest::queue(true, true, array(), 'Permission denied: info-hash not found');
    rXMLRPCRequest::queue(true, false, array()); // recovery d.start

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'mixed fault is unexpected and never reports success');
    rtAssertSame(2, count(rXMLRPCRequest::$requests), 'mixed fault triggers one recovery request');
    rtAssertSame('d.start', rXMLRPCRequest::$requests[1]['commands'][0]->command,
        'mixed fault restores the saved started state');
    rtAssertTrue(strpos(implode("\n", FileUtil::$logs), 'initial-read-failed reason=rpc-fault') !== false,
        'mixed fault is logged only by its safe reason');
};

$tests['only exact known missing-hash faults skip recovery'] = function () {
    $hash = str_repeat('E', 40);
    foreach (array(
        'info-hash not found',
        'info-hash not found.',
        'could not find info-hash',
        'could not find info-hash.',
        'invalid parameters: info-hash not found',
    ) as $message) {
        rtResetWorker();
        rXMLRPCRequest::queue(true, true, array(), $message);

        $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

        rtAssertSame(false, $result, $message . ': missing hash remains an unsuccessful early exit');
        rtAssertSame(1, count(rXMLRPCRequest::$requests), $message . ': exact missing hash does not restart');
        rtAssertSame(array(), FileUtil::$logs, $message . ': exact missing hash remains quiet');
    }
};

$tests['exact missing-hash fault on failed transport still restores'] = function () {
    rtResetWorker();
    $hash = str_repeat('E', 40);
    rXMLRPCRequest::queue(false, true, array(), 'info-hash not found');
    rXMLRPCRequest::queue(true, false, array()); // recovery d.start

    retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(2, count(rXMLRPCRequest::$requests), 'transport uncertainty triggers one recovery request');
    rtAssertSame('d.start', rXMLRPCRequest::$requests[1]['commands'][0]->command,
        'transport uncertainty restores the saved started state');
    rtAssertTrue(strpos(implode("\n", FileUtil::$logs), 'initial-read-failed reason=transport-failure') !== false,
        'initial transport uncertainty is diagnosed truthfully');
};

$tests['decorated missing-hash faults remain uncertain and restore'] = function () {
    $hash = str_repeat('E', 40);
    foreach (array(
        'Access denied: info-hash not found',
        'prefix info-hash not found',
        'info-hash not found suffix',
        ' info-hash not found',
        'info-hash not found ',
        '',
    ) as $message) {
        rtResetWorker();
        rXMLRPCRequest::queue(true, true, array(), $message);
        rXMLRPCRequest::queue(true, false, array());

        retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

        rtAssertSame(2, count(rXMLRPCRequest::$requests), var_export($message, true) . ': uncertainty restores once');
        rtAssertSame('d.start', rXMLRPCRequest::$requests[1]['commands'][0]->command,
            var_export($message, true) . ': uncertainty restores the exact hash');
    }
};

$tests['stopped snapshot does not start after transport fault'] = function () {
    rtResetWorker();
    $hash = str_repeat('E', 40);
    rXMLRPCRequest::queue(false, false, array()); // initial read transport failure

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '0'));

    rtAssertSame(false, $result, 'transport failure is not processed');
    rtAssertSame(1, count(rXMLRPCRequest::$requests), 'no recovery d.start for stopped snapshot');
};

$tests['invalid or missing snapshot fails before RPC or mutation'] = function () {
    $hash = rtFixtureHash();
    foreach (array(
        'missing' => array('update.php', $hash, 'alice'),
        'empty' => array('update.php', $hash, 'alice', ''),
        'out of range' => array('update.php', $hash, 'alice', '2'),
        'integer' => array('update.php', $hash, 'alice', 1),
        'null' => array('update.php', $hash, 'alice', null),
        'array' => array('update.php', $hash, 'alice', array('1')),
    ) as $label => $arguments) {
        rtResetWorker();
        rTorrent::queueSend($hash);
        rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
        rXMLRPCRequest::queue(true, false, array()); // d.erase if invalid input continues

        $result = retrackersRunWorker($arguments);

        rtAssertSame(false, $result, $label . ': invalid snapshot is not processed');
        rtAssertSame(0, rXMLRPCRequest::$constructions, $label . ': no RPC object is constructed');
        rtAssertSame(0, rRetrackers::$loads, $label . ': retracker configuration is not loaded');
        rtAssertSame(array(), rXMLRPCRequest::$requests, $label . ': no RPC is constructed or used');
        rtAssertSame(array(), rTorrent::$sends, $label . ': no metainfo mutation is attempted');
        rtAssertSame(0, FileUtil::$writeAttempts, $label . ': no log or filesystem write is attempted');
        rtAssertSame(array(), FileUtil::$logs, $label . ': invalid CLI precondition remains silent');
    }
};

$tests['invalid CLI snapshot exits before dependency loading'] = function () use ($root) {
    $directory = sys_get_temp_dir() . '/retrackers-entry-' . getmypid() . '-' . mt_rand();
    rtAssertTrue(mkdir($directory, 0700), 'entrypoint fixture directory is created');
    $entrypoint = $directory . '/update.php';
    $source = file_get_contents($root . '/plugins/retrackers/update.php');
    rtAssertSame(strlen($source), file_put_contents($entrypoint, $source),
        'production entrypoint is copied without its dependencies');
    $command = 'cd ' . escapeshellarg($directory) . ' && ' . escapeshellarg(PHP_BINARY)
        . ' update.php ' . str_repeat('A', 40) . ' alice invalid 2>&1';
    $output = array();
    $status = 0;
    exec($command, $output, $status);
    try {
        rtAssertSame(0, $status, 'invalid snapshot returns before loading absent dependencies');
        rtAssertSame(array(), $output, 'invalid snapshot emits no dependency or logging output');
        rtAssertSame(array('update.php'), array_values(array_diff(scandir($directory), array('.', '..'))),
            'invalid entrypoint creates no filesystem side effect');
    } finally {
        @unlink($entrypoint);
        @rmdir($directory);
    }
};

$tests['missing hash snapshot remains quiet'] = function () {
    rtResetWorker();
    $hash = str_repeat('E', 40);
    rXMLRPCRequest::queue(true, true, array(), 'Could not find info-hash.'); // missing hash

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(false, $result, 'missing hash is not processed');
    rtAssertSame(1, count(rXMLRPCRequest::$requests), 'no recovery mutation on missing hash');
    rtAssertSame(array(), FileUtil::$logs, 'missing hash logs nothing');
};

$tests['successful flow has no extra restart'] = function () {
    rtResetWorker();
    $hash = rtFixtureHash();
    rTorrent::queueSend($hash);
    rXMLRPCRequest::queue(true, false, rtInitialValues($hash));
    rXMLRPCRequest::queue(true, false, array()); // d.erase

    $result = retrackersRunWorker(array('update.php', $hash, 'alice', '1'));

    rtAssertSame(true, $result, 'happy path returns true');
    rtAssertSame(2, count(rXMLRPCRequest::$requests), 'only read and erase');
    rtAssertSame(1, count(rTorrent::$sends), 'candidate loaded once');
};

$tests['hook wraps both mutations in label and marker guards'] = function () use ($root) {
    rXMLRPCRequest::reset();
    $theSettings = new RetrackersSettingsFixture();
    $rootPath = '/srv/rutorrent';
    $plugin = array('name' => 'retrackers');
    $pInfo = array('perms' => 'r');
    $jResult = '';

    $previousCwd = getcwd();
    chdir($root . '/php');
    try {
        include($root . '/plugins/retrackers/init.php');
    } finally {
        chdir($previousCwd);
    }

    $commands = $theSettings->insertCommands;
    rtAssertSame(2, count($commands), 'both on-insert hooks are installed');
    foreach ($commands as $index => $command) {
        $action = $command->params[1];
        $mutation = $index === 0 ? 'd.set_custom4' : 'd.stop';
        rtAssertTrue(strpos($action, 'd.get_custom1') < strpos($action, $mutation),
            'label guard precedes hook mutation ' . $index);
        rtAssertTrue(strpos($action, 'd.get_custom=chk-meta-old') < strpos($action, $mutation),
            'durable marker guard precedes hook mutation ' . $index);
    }
    rtAssertTrue(strpos($commands[1]->params[1], 'execute') !== false,
        'ordinary torrents retain the detached worker action');
};

$failures = 0;
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($message, 0, $severity, $file, $line);
});
foreach ($tests as $name => $test) {
    try {
        $test();
        echo 'ok - ' . $name . "\n";
    } catch (Throwable $error) {
        $failures++;
        echo 'not ok - ' . $name . "\n  " . get_class($error) . ': ' . $error->getMessage() . "\n";
    }
}
restore_error_handler();
@unlink(rtSourcePath());
echo count($tests) . ' tests, ' . $failures . " failures\n";
exit($failures ? 1 : 0);
