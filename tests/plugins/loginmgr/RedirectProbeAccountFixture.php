<?php

class RedirectProbeAccount extends commonAccount
{
    public static $loginCalls = 0;
    protected function isOK($client) { return true; }
    protected function login($client, $login, $password, &$url, &$method, &$content_type, &$body, &$is_result_fetched)
    {
        self::$loginCalls++;
        $client->status = 200;
        $client->results = 'authenticated login';
        $client->cookies['loginmgr_marker'] = 'session-value';
        return true;
    }
    public function test($url)
    {
        return UrlHost::urlIsOneOf($url, array('tracker.example'), 'https', '/file');
    }
}
