<?php

$_ENV['RU_LOG_FILE'] = (getenv('TMPDIR') ?: sys_get_temp_dir())
    . '/loginmgr-redacted-origin-' . getmypid() . '.log';
require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts.php');
require_once(__DIR__ . '/../../../plugins/loginmgr/accounts/Redacted.php');

register_shutdown_function(function () { @unlink($_ENV['RU_LOG_FILE']); });

class RedactedOriginClient
{
    public $status = 200;
    public $results = '';
    public $referer = '';
    public $events = array();
    public $answers = array();

    public function fetch($url, $method = 'GET', $contentType = '', $body = '')
    {
        $this->events[] = array('fetch', $url, $method, $contentType, $body, $this->referer);
        if (!$this->answers)
            throw new RuntimeException('Unexpected request');
        $answer = array_shift($this->answers);
        $this->status = $answer[0];
        $this->results = $answer[1];
        return $answer[2];
    }

    public function setcookies()
    {
        $this->events[] = array('setcookies');
    }
}

class RedactedOriginTest extends TestCase
{
    private function invokeLogin($account, $client, &$fetched)
    {
        $method = self::makeAccessible(new ReflectionMethod('RedactedAccount', 'login'));
        $url = 'https://redacted.sh/torrents.php';
        $httpMethod = 'GET';
        $contentType = '';
        $body = '';
        $fetched = true;
        return $method->invokeArgs($account, array($client, 'name+test', 'secret&value',
            &$url, &$httpMethod, &$contentType, &$body, &$fetched));
    }

    public function setUp()
    {
        @unlink($_ENV['RU_LOG_FILE']);
    }

    public function testOnlyCurrentOriginOwnsRedactedRequests()
    {
        $account = new RedactedAccount();
        $this->assertSame('https://redacted.sh', $account->url, 'The account uses the current Redacted site');
        $this->assertSame(true, $account->test('https://redacted.sh/torrents.php'), 'Current site owns its requests');
        $this->assertSame(false, $account->test('https://redacted.ch/torrents.php'), 'Parked old domain owns no credentials');
    }

    public function testCurrentGuestFormIsNotAuthenticated()
    {
        $account = new RedactedAccount();
        $predicate = self::makeAccessible(new ReflectionMethod('RedactedAccount', 'isOK'));
        $client = new RedactedOriginClient();
        // The current anonymous page's form tag and field names, captured 2026-09-29.
        $client->results = '<form class="auth_form" name="login" id="loginform" method="post" action="login.php">'
            . '<input name="username"><input name="password"></form>';
        $this->assertSame(false, $predicate->invoke($account, $client), 'Current login form is a guest answer');
        $client->results = '<a href="logout.php">Log out</a>';
        $this->assertSame(true, $predicate->invoke($account, $client), 'Authenticated page remains accepted');
    }

    public function testUnexpectedLoginPageNeverReceivesCredentialsAndIsLogged()
    {
        $account = new RedactedAccount();
        $client = new RedactedOriginClient();
        $client->answers = array(array(200,
            '<html><title>redacted.ch</title><p>This domain may be for sale.</p></html>', true));
        $fetched = null;
        $this->assertSame(false, $this->invokeLogin($account, $client, $fetched),
            'Unrecognized login page refuses credential POST');
        $this->assertSame(array(array('fetch', 'https://redacted.sh/login.php', 'GET', '', '', '')),
            $client->events, 'Only an anonymous GET is sent');
        $log = is_file($_ENV['RU_LOG_FILE']) ? file_get_contents($_ENV['RU_LOG_FILE']) : '';
        $this->assertTrue(strpos($log, 'loginmgr: Redacted login refused: unexpected-login-form') !== false,
            'Refusal has a classified log reason');
        $this->assertSame(false, strpos($log, 'secret&value') !== false, 'Log contains no password');
    }

    public function testCurrentFormKeepsLoginTraceAndRejectsFailedStatus()
    {
        $account = new RedactedAccount();
        $form = '<form class="auth_form" name="login" id="loginform" method="post" action="login.php">'
            . '<input name="username"><input name="password"></form>';
        $client = new RedactedOriginClient();
        $client->answers = array(array(200, $form, true), array(200, '<a href="logout.php">Log out</a>', true));
        $fetched = null;
        $this->assertSame(true, $this->invokeLogin($account, $client, $fetched), 'Current login form is submitted');
        $this->assertSame(false, $fetched, 'The caller response still needs fetching');
        $this->assertSame(array(
            array('fetch', 'https://redacted.sh/login.php', 'GET', '', '', ''),
            array('setcookies'),
            array('fetch', 'https://redacted.sh/login.php', 'POST',
                'application/x-www-form-urlencoded',
                'username=name%2Btest&password=secret%26value&keeplogged=1&login=Login',
                'https://redacted.sh/login.php'),
            array('setcookies'),
        ), $client->events, 'GET, cookie, referer, POST, cookie trace remains intact');

        $failed = new RedactedOriginClient();
        $failed->answers = array(array(500, $form, true));
        $this->assertSame(false, $this->invokeLogin($account, $failed, $fetched),
            'An HTTP failure with copied form never receives credentials');
        $this->assertSame(1, count($failed->events), 'Failed GET is the only request');
    }

    public function testCredentialPostHttpRefusalsAreLoggedWithoutSavingCookies()
    {
        $account = new RedactedAccount();
        $form = '<form class="auth_form" name="login" id="loginform" method="post" action="login.php">'
            . '<input name="username"><input name="password"></form>';
        foreach (array(403, 500) as $status) {
            @unlink($_ENV['RU_LOG_FILE']);
            $client = new RedactedOriginClient();
            $client->answers = array(array(200, $form, true), array($status, 'Refused', true));
            $fetched = null;
            $this->assertSame(false, $this->invokeLogin($account, $client, $fetched),
                'HTTP ' . $status . ' POST is a login refusal');
            $this->assertSame(array(
                array('fetch', 'https://redacted.sh/login.php', 'GET', '', '', ''),
                array('setcookies'),
                array('fetch', 'https://redacted.sh/login.php', 'POST',
                    'application/x-www-form-urlencoded',
                    'username=name%2Btest&password=secret%26value&keeplogged=1&login=Login',
                    'https://redacted.sh/login.php'),
            ), $client->events, 'HTTP ' . $status . ' POST saves no response cookies');
            $log = is_file($_ENV['RU_LOG_FILE']) ? file_get_contents($_ENV['RU_LOG_FILE']) : '';
            $this->assertTrue(strpos($log, 'loginmgr: Redacted login refused: credential-post-failed') !== false,
                'HTTP ' . $status . ' POST refusal is classified');
            $this->assertSame(false, strpos($log, 'secret&value') !== false,
                'HTTP ' . $status . ' POST log hides credentials');
        }
    }
}
