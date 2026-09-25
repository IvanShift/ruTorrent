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
        // Reject delimiters before parsing: PHP 7.4 discards empty query/fragment
        // components, and parse_url() rewrites control characters in host names.
        if (preg_match('/[\x00-\x20\x7f?#]/', $origin) || strpos($origin, '\\') !== false) {
            $this->configurationError = 'invalid-characters';
            return;
        }
        $parts = @parse_url($origin);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            $this->configurationError = 'invalid-url';
            return;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            $this->configurationError = 'bad-scheme';
            return;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            $this->configurationError = 'has-credentials';
            return;
        }
        if (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/') {
            $this->configurationError = 'has-path';
            return;
        }
        // Some PHP versions parse ':443x' as port 443 and discard the suffix.
        if (!preg_match('~^[a-z][a-z0-9+.-]*://(?:\[[^\]]+\]|[^:/]+)(?::[0-9]+)?/?$~i', $origin)) {
            $this->configurationError = 'invalid-url';
            return;
        }
        if (isset($parts['port']) && $parts['port'] < 1) {
            $this->configurationError = 'bad-port';
            return;
        }
        $host = $parts['host'];
        $ipv6 = substr($host, 0, 1) === '[' && substr($host, -1) === ']'
            && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (!$ipv6 && filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            $this->configurationError = 'bad-host';
            return;
        }
        $this->url = 'https://' . UrlHost::of($origin) . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $this->configurationError = '';
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
        if (strcasecmp((string) @parse_url($url, PHP_URL_PATH), '/engine/download_torrent') !== 0
            || preg_match('/(^|&)id=/', (string) @parse_url($url, PHP_URL_QUERY)) !== 1) {
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
