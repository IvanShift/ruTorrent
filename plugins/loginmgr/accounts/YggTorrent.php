<?php

class YggTorrentAccount extends commonAccount
{
    public $url = '';
    private static $configurationWarningShown = false;
    private $configurationError = 'missing-origin';

    public function __construct()
    {
        global $yggTorrentOrigin;
        $origin = isset($yggTorrentOrigin) && is_string($yggTorrentOrigin) ? $yggTorrentOrigin : '';
        if ($origin === '') {
            return;
        }
        $this->url = self::configuredOrigin($origin, $error, $this->configurationError);
    }

    public function configurationError()
    {
        return $this->configurationError;
    }

    private function hasOrigin()
    {
        if ($this->url !== '') {
            return true;
        }
        if (!self::$configurationWarningShown) {
            self::$configurationWarningShown = true;
            FileUtil::toLog('loginmgr YggTorrent: invalid-or-missing-origin (' . $this->configurationError . '); set $yggTorrentOrigin to a trusted HTTPS origin with an ASCII host in conf/config.php, conf/users/<user>/config.php, plugins/loginmgr/conf.local.php, or conf/users/<user>/plugins/loginmgr/conf.php (plugin override files load after global config); session downloads and automatic login are disabled');
        }
        return false;
    }

    public function check($client, $login, $password, $auto)
    {
        if ($this->hasOrigin()) {
            parent::check($client, $login, $password, $auto);
        }
    }

    protected function isOK($client)
    {
        return (
            $client->status == 200
            && $client->results !== 'Vous devez vous connecter pour télécharger un torrent'
            && strpos($client->results, "S'identifier</a>") === false
        );
    }

    private function trusts($url)
    {
        $parts = @parse_url((string) $url);
        return $this->hasOrigin() && is_array($parts) && !isset($parts['user']) && !isset($parts['pass'])
            && UrlHost::sameOrigin($this->url, $url);
    }

    // A moving domain is an operator trust decision, never a domain-name pattern.
    public function test($url)
    {
        if (strcasecmp((string) @parse_url($url, PHP_URL_PATH), '/engine/download_torrent') !== 0) {
            return false;
        }
        $id = self::queryValue($url, 'id');
        if ($id === false || $id === '') {
            return false;
        }
        return $this->trusts($url);
    }

    public function fetch($client, $url, $login, $password, $method, $content_type, $body)
    {
        // Direct callers must not load a cached session for an untrusted URL.
        return $this->test($url) && parent::fetch($client, $url, $login, $password, $method, $content_type, $body);
    }

    protected function login($client, $login, $password, &$url, &$method, &$content_type, &$body, &$is_result_fetched)
    {
        $is_result_fetched = false;
        // check() calls login() without account selection. Validate independently.
        if (!$this->trusts($url)) {
            return false;
        }
        // A configurable origin can be stale. Require a successful landing
        // response before posting credentials to its configured host.
        if ($client->fetch($url) && $client->status >= 200 && $client->status < 300) {
            $client->setcookies();
            $client->referer = $url;
            if ($client->fetch($this->url . '/user/login', 'POST', 'application/x-www-form-urlencoded',
                'id=' . rawurlencode($login) . '&pass=' . rawurlencode($password) . '&submit=')) {
                $client->setcookies();
                return true;
            }
        }
        return false;
    }
}
