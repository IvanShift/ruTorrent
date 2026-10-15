<?php

require_once(__DIR__ . '/../../php/TestCase.php');

class User
{
    public static function getUser() { return 'fixture'; }
}

class SeedingtimeSettingsFixture
{
    public function getOnFinishedCommand($args) { return $args; }
    public function getOnInsertCommand($args) { return $args; }
    public function getOnHashdoneCommand($args) { return $args; }
    public function registerPlugin($name, $perms) { }
}

class rXMLRPCCommand
{
    public function __construct($command, $args = null) { }
}

// Records every request in order: the handlers first, then the backfill pass.
class rXMLRPCRequest
{
    public static $requests = array();
    public function __construct($commands) { self::$requests[] = $commands; }
    public function success() { return true; }
    public function run() { return true; }
}

function getCmd($name) { return $name; }

class SeedingtimeInitCommandTest extends TestCase
{
    public function testBothTimestampHooksStoreEpochWithoutLineEnding()
    {
        $theSettings = new SeedingtimeSettingsFixture();
        $plugin = array('name' => 'seedingtime');
        $pInfo = array('perms' => array());
        $jResult = '';
        require __DIR__ . '/../../../plugins/seedingtime/init.php';

        $handlers = rXMLRPCRequest::$requests[0];
        $this->assertEquals(3, count($handlers), 'both timestamp hooks and the fallback hook register');
        foreach (array(0 => 'seedingtime', 1 => 'addtime') as $index => $field) {
            $expected = 'd.set_custom='.$field.',"$execute_capture={sh,-c,printf %s $(date +%s)}"';
            $this->assertEquals($expected, $handlers[$index][1],
                $field.' captures an epoch without date\'s trailing newline');
        }
    }
}
