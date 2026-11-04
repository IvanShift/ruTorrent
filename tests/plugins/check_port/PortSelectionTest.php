<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../../plugins/check_port/ports.php');

class PortSelectionTest extends TestCase
{
    public function testFamilyOverridesSelectAdvertisedPorts()
    {
        $configured = ['network.listen.port' => '45000',
            'network.local_port.ipv4' => '46001', 'network.local_port.ipv6' => '46002'];
        $ports = check_port_effective_ports(
            45000,
            0x1018,
            function ($command) use ($configured) {
                return $configured[$command];
            },
        );
        $this->assertSame(
            ['listen' => 45000, 'ipv4' => 46001, 'ipv6' => 46002],
            $ports,
            'each address family checks the port reported to its tracker',
        );
    }

    public function testZeroAndUnavailableOverrideUseListeningPort()
    {
        $configured = ['network.listen.port' => '45000',
            'network.local_port.ipv4' => '0', 'network.local_port.ipv6' => null];
        $ports = check_port_effective_ports(
            45000,
            0x1018,
            function ($command) use ($configured) {
                return $configured[$command];
            },
        );
        $this->assertSame(
            ['listen' => 45000, 'ipv4' => 45000, 'ipv6' => 45000],
            $ports,
            'unset or unreadable overrides retain the listening port',
        );
    }

    public function testLiveListeningPortOverridesCachedSettingsAfterForcePort()
    {
        $calls = [];
        $ports = check_port_effective_ports(
            45000,
            0x1018,
            function ($command) use (&$calls) {
                $calls[] = $command;
                return $command === 'network.listen.port' ? '45001' : '0';
            },
        );
        $this->assertSame(
            ['listen' => 45001, 'ipv4' => 45001, 'ipv6' => 45001],
            $ports,
            'a later refresh uses the live listening port instead of the cached settings port',
        );
        $this->assertTrue(
            in_array('network.listen.port', $calls, true),
            'the plugin rereads the live listening port',
        );
    }

    public function testActionPassesEachResolvedPortToItsProvider()
    {
        $source = file_get_contents(__DIR__ . '/../../../plugins/check_port/action.php');
        $start = strpos($source, '// --- Main Execution ---');
        if ($start === false) {
            throw new RuntimeException('check_port action entry point not found');
        }

        rTorrentSettings::$settings = (object) ['port' => 45000, 'ip' => '', 'iVersion' => 0x1018];
        rXMLRPCRequest::$answers = [
            'network.listen.port' => '45001',
            'network.local_port.ipv4' => '46001',
            'network.local_port.ipv6' => '46002',
        ];
        $GLOBALS['checkPortProbePorts'] = [];
        $currentUseWebsiteIPv4 = 'fixture';
        $currentUseWebsiteIPv6 = 'fixture';
        $currentCheckPortTimeout = 1;
        $originalRequest = $_REQUEST;
        $_REQUEST = [];
        try {
            eval(substr($source, $start));
        } finally {
            $_REQUEST = $originalRequest;
        }
        $data = json_decode(CachedEcho::$payload, true);
        $this->assertSame(45001, $data['listen_port'], 'action returns the live listener');
        $this->assertSame(
            ['ipv4' => 46001, 'ipv6' => 46002],
            ['ipv4' => $data['ipv4_port'], 'ipv6' => $data['ipv6_port']],
            'action returns the advertised port for each family',
        );
        $this->assertSame(
            ['4' => 46001, '6' => 46002],
            $GLOBALS['checkPortProbePorts'],
            'action probes the advertised port for each family',
        );
    }

    public function testDaemonBeforeLocalPortOnlyReadsLiveListener()
    {
        $calls = [];
        $ports = check_port_effective_ports(
            45000,
            0x1014,
            function ($command) use (&$calls) {
                $calls[] = $command;
                return 45001;
            },
        );
        $this->assertSame(
            ['network.listen.port'],
            $calls,
            'older rTorrent reads its listener without calling unavailable local_port methods',
        );
        $this->assertSame(
            ['listen' => 45001, 'ipv4' => 45001, 'ipv6' => 45001],
            $ports,
            'older rTorrent still checks its live listening port',
        );
    }
}

// Execute action.php's real entry point with local, deterministic RPC and provider answers.
class rTorrentSettings
{
    public static $settings;
    public static function get()
    {
        return self::$settings;
    }
}
class rXMLRPCCommand
{
    public $command;
    public function __construct($command, $params = null)
    {
        $this->command = $command;
    }
}
class rXMLRPCRequest
{
    public static $answers = [];
    public $important = true;
    public $val = [];
    private $command;
    public function __construct($command)
    {
        $this->command = $command->command;
    }
    public function success()
    {
        $this->val = [self::$answers[$this->command]];
        return true;
    }
}
class FileUtil
{
    public static function toLog($message)
    {
        throw new RuntimeException($message);
    }
}
class CachedEcho
{
    public static $payload;
    public static function send($payload, $type)
    {
        self::$payload = $payload;
    }
}
class JSON
{
    public static function safeEncode($value)
    {
        return json_encode($value);
    }
}
function get_and_check_ip($version, $provider, $ip, $port, $timeout)
{
    $GLOBALS['checkPortProbePorts'][$version] = $port;
    return ['ip' => '127.0.0.1', 'status' => 2];
}
