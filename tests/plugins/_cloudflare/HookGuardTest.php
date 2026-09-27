<?php

require_once(__DIR__ . '/../../php/TestCase.php');

$cloudflareHookScratch = tempnam(sys_get_temp_dir(), 'cloudflare-hook-');
if ($cloudflareHookScratch === false || !unlink($cloudflareHookScratch) || !mkdir($cloudflareHookScratch)) {
    throw new RuntimeException('Could not create isolated Cloudflare hook test directory');
}
$_ENV['RU_PROFILE_PATH'] = $cloudflareHookScratch . '/profile';
$_ENV['RU_LOG_FILE'] = $cloudflareHookScratch . '/errors.log';
require_once(__DIR__ . '/../../../php/Snoopy.class.inc');

// The executable stands in for the optional Python cloudscraper dependency.
// It records invocation and returns tokens without opening any connection.
$cloudflareHookMock = $cloudflareHookScratch . '/python-mock';
file_put_contents($cloudflareHookMock, <<<'SH'
#!/bin/sh
printf '%s' "$2" > "$CLOUDFLARE_HOOK_CAPTURE"
printf '%s\n' '[{"clearance":"synthetic"},"synthetic-agent"]'
SH
);
chmod($cloudflareHookMock, 0700);
$pathToExternals['python'] = $cloudflareHookMock;
putenv('CLOUDFLARE_HOOK_CAPTURE=' . $cloudflareHookScratch . '/capture');

register_shutdown_function(function () use ($cloudflareHookScratch) {
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($cloudflareHookScratch, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        if ($entry->isDir()) rmdir($entry->getPathname());
        else unlink($entry->getPathname());
    }
    rmdir($cloudflareHookScratch);
});

class CloudflareHookChallengeClient extends Snoopy
{
    public $retries = array();

    public function fetch($URI, $method = 'GET', $content_type = '', $body = '')
    {
        $this->retries[] = $URI;
        return true;
    }
}

class CloudflareHookGuardTest extends TestCase
{
    private $url = 'http://synthetic-public.example/favicon.ico';
    private $settings;

    public function setUpClass()
    {
        // Use the real event registry without its singleton's SCGI discovery.
        $this->settings = (new ReflectionClass('rTorrentSettings'))->newInstanceWithoutConstructor();
        $this->settings->registerEventHook('_cloudflare', 'URLFetched');
    }

    public function setUp()
    {
        global $cloudflareHookScratch;
        @unlink($cloudflareHookScratch . '/capture');
    }

    private function dispatchChallenge($guarded, $status = 503)
    {
        $client = new CloudflareHookChallengeClient();
        $client->block_private = $guarded;
        $client->status = $status;
        $client->results = 'synthetic challenge';
        $client->headers = array('Server: cloudflare');
        $this->settings->pushEvent('URLFetched', array(
            'client' => $client,
            'uri' => $this->url,
            'method' => 'GET',
            'content_type' => '',
            'body' => ''
        ));
        return $client;
    }

    public function testGuardedChallengeDoesNotStartUnpinnedCloudscraperRetry()
    {
        global $cloudflareHookScratch;
        foreach (array(503, 429) as $status) {
            $client = $this->dispatchChallenge(true, $status);
            $this->assertSame(false, file_exists($cloudflareHookScratch . '/capture'),
                'Guarded client started the unpinned cloudscraper process for ' . $status);
            $this->assertSame(array(), $client->retries,
                'Guarded client retried the URL after Cloudflare response ' . $status);
        }
    }

    public function testUnguardedChallengeStillUsesCloudscraperAndRetries()
    {
        global $cloudflareHookScratch;
        $client = $this->dispatchChallenge(false);
        $this->assertSame(true, file_exists($cloudflareHookScratch . '/capture'),
            'Unguarded client did not start cloudscraper');
        $this->assertSame(array($this->url), $client->retries,
            'Unguarded client did not retry the challenged URL');
        $this->assertSame('synthetic', $client->cookies['clearance'] ?? null,
            'Unguarded client did not use cloudscraper tokens');
    }
}
