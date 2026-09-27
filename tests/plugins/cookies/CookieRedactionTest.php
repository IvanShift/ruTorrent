<?php

require_once(__DIR__ . '/../../php/TestCase.php');

$cookieRedactionProfile = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/cookie-redaction-'
    . getmypid() . '-' . bin2hex(random_bytes(4));
$_ENV['RU_PROFILE_PATH'] = $cookieRedactionProfile;
putenv('RU_PROFILE_PATH=' . $cookieRedactionProfile);
require_once(__DIR__ . '/../../../plugins/cookies/cookies.php');

class CookieRedactionTest extends TestCase
{
    private $profile;

    public function setUpClass()
    {
        $this->profile = $_ENV['RU_PROFILE_PATH'];
    }

    public function tearDownClass()
    {
        if (!is_dir($this->profile)) return;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->profile,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $entry)
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->profile);
    }

    public function setUp()
    {
        $jar = new rCookies();
        $jar->list = array(
            'one.test' => array('sid' => 'first-secret'),
            'two.test' => array('auth' => 'second-secret'),
            'remove.test' => array('token' => 'third-secret'),
        );
        $this->assertTrue($jar->store(), 'synthetic cookie fixture stored');
    }

    private function action($mode, $post = array(), $host = null)
    {
        $code = '$_ENV["RU_PROFILE_PATH"] = $argv[1];'
            . '$_SERVER["REQUEST_METHOD"] = ' . var_export($mode === 'add' ? 'POST' : 'GET', true) . ';'
            . '$_REQUEST = array("mode" => ' . var_export($mode, true) . ');'
            . ($host === null ? '' : '$_REQUEST["host"] = ' . var_export($host, true) . ';')
            . '$_POST = json_decode($argv[2], true);'
            . 'require ' . var_export(__DIR__ . '/../../../plugins/cookies/action.php', true) . ';';
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=0', '-r', $code,
            $this->profile, json_encode($post)), array(1 => array('pipe', 'w'),
            2 => array('pipe', 'w')), $pipes, __DIR__ . '/../../../plugins/cookies');
        if (!is_resource($process)) throw new RuntimeException('Could not start cookie action');
        $body = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0 || $errors !== '')
            throw new RuntimeException('Cookie action failed: ' . $errors);
        return $body;
    }

    public function testCookieValuesNeverEnterBootstrapOrInfoResponses()
    {
        $jar = rCookies::load();
        $bootstrap = $jar->get();
        $this->assertTrue(strpos($bootstrap, "one.test") !== false, 'bootstrap retains host for editing');
        $this->assertTrue(strpos($bootstrap, "cookies: '********'") !== false,
            'bootstrap marks stored cookie as unchanged');
        $this->assertTrue(strpos($bootstrap, 'first-secret') === false,
            'bootstrap omits the first stored value');
        $this->assertTrue(strpos($bootstrap, 'second-secret') === false,
            'bootstrap omits the second stored value');
        $this->assertSame(array('one.test' => true, 'two.test' => true, 'remove.test' => true),
            $jar->getInfo(), 'info contains host presence only');
        $this->assertSame($jar->getInfo(), json_decode($this->action('info'), true),
            'info route contains host presence only');
        $this->assertSame(true, json_decode($this->action('info', array(), 'one.test'), true),
            'host info route reports presence without cookie pairs');
        $this->assertSame(false, json_decode($this->action('info', array(), 'missing.test'), true),
            'unknown host info route reports absence');
        $added = $this->action('add', array('host' => 'new.test',
            'cookies' => rawurlencode('session=fourth-secret')));
        $this->assertSame(true, json_decode($added, true)['new.test'],
            'add response reports host presence without its new cookie');
        $this->assertTrue(strpos($added, 'fourth-secret') === false,
            'add response omits new cookie value');
    }

    public function testRenamedMaskedHostRefusesTheWholeSaveAndKeepsExistingCookies()
    {
        $jar = new rCookies();
        $error = null;
        try {
            $jar->set('cookie=' . rawurlencode('renamed.test|********')
                . '&cookie=' . rawurlencode('two.test|auth=changed'));
        } catch (InvalidArgumentException $failure) {
            $error = $failure->getMessage();
        }
        $this->assertTrue(is_string($error) && strpos($error, 'full cookie string') !== false,
            'renaming a masked host requires a full cookie string');
        $saved = rCookies::load();
        $this->assertSame(array('sid' => 'first-secret'), $saved->getCookiesForHost('one.test'),
            'the original host keeps its cookie after refusal');
        $this->assertSame(array('auth' => 'second-secret'), $saved->getCookiesForHost('two.test'),
            'another edited row is not partly saved');
        $this->assertSame(array(), $saved->getCookiesForHost('renamed.test'),
            'the renamed host is not created from a masked value');
    }

    public function testPlaceholderKeepsStoredValueWhileOtherRowsChangeOrDisappear()
    {
        $jar = new rCookies();
        $jar->set('cookie=' . rawurlencode('one.test|********')
            . '&cookie=' . rawurlencode('two.test|auth=********')
            . '&cookie=' . rawurlencode('fresh.test|sid=fresh'));
        $saved = rCookies::load();
        $this->assertSame(array('sid' => 'first-secret'), $saved->getCookiesForHost('one.test'),
            'unchanged placeholder retains the existing server-side value');
        $this->assertSame(array('auth' => '********'), $saved->getCookiesForHost('two.test'),
            'literal masked-looking cookie value replaces an old value without colliding with the placeholder');
        $this->assertSame(array('sid' => 'fresh'), $saved->getCookiesForHost('fresh.test'),
            'a new host can be added');
        $this->assertSame(array(), $saved->getCookiesForHost('remove.test'),
            'omitting a host removes it');
        $this->assertSame(array(), $saved->getCookiesForHost('unknown.test'),
            'placeholder alone cannot create a cookie');
        $this->assertTrue(strpos($saved->get(), 'first-secret') === false,
            'post-save bootstrap still omits the preserved value');
    }
}
