<?php

require_once(__DIR__ . '/../../php/TestCase.php');

function getCmd($command) { return $command; }

class rTorrentSettings
{
    public static $cap = 16777216;
    public static function get()
    {
        return new class {
            public function maxContentSize() { return rTorrentSettings::$cap; }
        };
    }
}

class rXMLRPCCommand
{
    public $command;
    public $params;
    public function __construct($command, $params = array())
    {
        $this->command = $command;
        $this->params = $params;
    }
}

class rXMLRPCRequest
{
    public static $reply = 4096;
    public static $calls = array();
    public $important = true;
    public $val = array();
    private $command;

    public function __construct($command) { $this->command = $command; }
    public function success()
    {
        self::$calls[] = array($this->command->command, $this->command->params);
        if (self::$reply === false) return false;
        $this->val = array(self::$reply);
        return true;
    }
}

define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');

class WorkerCapTest extends TestCase
{
    public function testWorkerUsesMeasuredDaemonCapBeforeCommit()
    {
        $adapter = new RetrackersWorkerRpcAdapter();
        rXMLRPCRequest::$calls = array();
        rXMLRPCRequest::$reply = 4096;
        $failure = null;
        $this->assertEquals(4096, $adapter->maxContentSize($failure),
            'a lowered live XMLRPC limit bounds the commit wire below the API cap');
        $this->assertEquals(null, $failure, 'a valid measured cap is accepted');
        $this->assertEquals(array(array('get_xmlrpc_size_limit', array())), rXMLRPCRequest::$calls,
            'the worker reads the daemon limit with one standalone getter');
        rXMLRPCRequest::$reply = '4096';
        $this->assertEquals(4096, $adapter->maxContentSize($failure),
            'a canonical decimal RPC scalar is accepted when a daemon returns text');
        rXMLRPCRequest::$reply = 33554432;
        $this->assertEquals(16777216, $adapter->maxContentSize($failure),
            'the known API ceiling still bounds an oversized daemon setting');
    }

    public function testUnreadableOrMalformedDaemonCapRefusesCommit()
    {
        $adapter = new RetrackersWorkerRpcAdapter();
        foreach (array(false, '4096garbage', '04096', 0) as $reply) {
            rXMLRPCRequest::$reply = $reply;
            $failure = null;
            $this->assertEquals(false, $adapter->maxContentSize($failure),
                'an unproved daemon limit cannot authorize a commit');
            $this->assertEquals('commit-request-limit-invalid', $failure,
                'the refusal uses the existing classified reason');
        }
        rTorrentSettings::$cap = '16777216';
        rXMLRPCRequest::$reply = 4096;
        $failure = null;
        $this->assertEquals(false, $adapter->maxContentSize($failure),
            'a malformed API ceiling is also refused');
        $this->assertEquals('commit-request-limit-invalid', $failure,
            'the malformed ceiling uses the existing classified reason');
        rTorrentSettings::$cap = 16777216;
    }
}
