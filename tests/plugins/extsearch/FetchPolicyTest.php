<?php

// Keep downloader policy coverage on the real commonEngine path without HTTP.
$_ENV['RU_LOG_FILE'] = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/extsearch-fetch-' . getmypid() . '.log';
require_once(__DIR__ . '/../../../plugins/extsearch/engines.php');
require_once(__DIR__ . '/../../../plugins/extsearch/engines/KAT.php');
require_once(__DIR__ . '/../../../plugins/extsearch/engines/RARbgTorrentAPI.php');

class FetchPolicyClient extends Snoopy
{
    public $nextError = '';
    public $nextBody = '';
    public $nextReturn = true;

    public function fetchComplex($url, $method = 'GET', $content_type = '', $body = '')
    {
        $this->status = 200;
        $this->error = $this->nextError;
        $this->results = $this->nextBody;
        return $this->nextReturn;
    }
}

class FetchPolicyEngine extends commonEngine
{
    public $client;

    public function makeClient($url)
    {
        return $this->client;
    }
}

class KATFetchPolicyEngine extends KATEngine
{
    public $client;

    public function makeClient($url) { return $this->client; }
}

class RARbgFetchPolicyEngine extends RARbgTorrentAPIEngine
{
    public $client;

    public function makeClient($url) { return $this->client; }
}

function fetchPolicySame($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ': expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true));
    }
}

$failed = 0;
try {
    $engine = new FetchPolicyEngine();
    $engine->client = new FetchPolicyClient();
    $engine->client->nextError = Snoopy::CREDENTIAL_REDIRECT_REFUSED;
    $engine->client->nextBody = '<html>Login required</html>';
    fetchPolicySame(false, $engine->fetch('https://tracker.example/file'),
        '2xx source response with a refused Location must not be accepted');
    fetchPolicySame(false, $engine->getTorrent('https://tracker.example/file'),
        'refused response must not be written as a torrent');
    $engine->client->nextError = '';
    fetchPolicySame(false, $engine->getTorrent('https://tracker.example/file'),
        'ordinary HTML response must not be written as metainfo');
    $engine->client->nextBody = 'd4:infod6:lengthi1e4:name4:test12:piece lengthi1e6:pieces20:abcdefghijklmnopqrstee';
    $engine->client->nextReturn = false;
    fetchPolicySame(false, $engine->fetch('https://tracker.example/file'),
        'a transport failure cannot reuse a previous 2xx status');
    foreach (array(new KATFetchPolicyEngine(), new RARbgFetchPolicyEngine()) as $override) {
        $override->client = new FetchPolicyClient();
        $override->client->nextError = Snoopy::CREDENTIAL_REDIRECT_REFUSED;
        $override->client->nextBody = '<html>Login required</html>';
        fetchPolicySame(false, $override->fetch('https://tracker.example/file'),
            get_class($override) . ' must reject a classified 2xx refusal');
    }
    echo "ok - extsearch refuses credential redirects and non-metainfo bodies\n";
} catch (Throwable $error) {
    $failed = 1;
    echo "not ok - extsearch refuses credential redirects and non-metainfo bodies\n  "
        . $error->getMessage() . "\n";
} finally {
    @unlink($_ENV['RU_LOG_FILE']);
}
echo "1 test, $failed failures\n";
exit($failed);
