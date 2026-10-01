<?php
require_once __DIR__ . '/TestCase.php';

class AssetVersionTest extends TestCase
{
    private $tree;

    public function setUp()
    {
        $this->tree = sys_get_temp_dir() . '/rutorrent-assets-' . getmypid();
        mkdir($this->tree, 0700);
        foreach (['tools', 'js', 'css', 'lang', 'plugins/example'] as $path) {
            mkdir($this->tree . '/' . $path, 0700, true);
        }
        file_put_contents($this->tree . '/index.html', '<script>var ruTorrentAssetVersion = "development";</script>'
            . '<script src="./js/core.js?v=5315"></script><link href="./css/style.css?v=5315-trackername">');
        file_put_contents($this->tree . '/js/core.js', 'var version="5.3.15";');
        file_put_contents($this->tree . '/css/style.css', 'body {color:black}');
        file_put_contents($this->tree . '/lang/en.js', 'var text="one";');
        file_put_contents($this->tree . '/plugins/example/style.css', 'a {color:red}');
    }

    public function tearDown()
    {
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tree,
            FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tree);
    }

    private function stamp()
    {
        $tool = dirname(__DIR__, 2) . '/tools/update-asset-version.php';
        $this->assertTrue(is_file($tool), 'asset deployment tool exists');
        if (!is_file($tool)) return null;
        copy($tool, $this->tree . '/tools/update-asset-version.php');
        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($this->tree . '/tools/update-asset-version.php') . ' 2>&1', $output, $status);
        $this->assertEquals(0, $status, 'asset deployment succeeds: ' . implode("\n", $output));
        if ($status !== 0) return null;
        $index = file_get_contents($this->tree . '/index.html');
        preg_match('/ruTorrentAssetVersion = "([a-f0-9]{16})"/', $index, $match);
        $this->assertTrue(isset($match[1]), 'index receives a content-derived asset key');
        if (!isset($match[1])) return null;
        preg_match_all('/[?&]v=([^"<> ]+)/', $index, $keys);
        $this->assertEquals([$match[1], $match[1]], $keys[1], 'every core URL receives the same build key');
        return $match[1];
    }

    public function testStampIsIdempotentAndIgnoresUnservedState()
    {
        $first = $this->stamp();
        if ($first === null) return;
        $index = file_get_contents($this->tree . '/index.html');
        $this->assertEquals($first, $this->stamp(), 'stamping unchanged assets is idempotent');
        $this->assertEquals($index, file_get_contents($this->tree . '/index.html'), 'unchanged HTML remains byte identical');
        mkdir($this->tree . '/share');
        file_put_contents($this->tree . '/share/settings.dat', 'operator state');
        $this->assertEquals($first, $this->stamp(), 'operator state cannot change the deployment key');
    }

    public function testSameDisplayVersionDifferentStaticBytesChangesKey()
    {
        $first = $this->stamp();
        if ($first === null) return;
        file_put_contents($this->tree . '/js/core.js', 'var version="5.3.15"; var changed=true;');
        $core = $this->stamp();
        $this->assertTrue($first !== $core, 'new core bytes with the same display version invalidate cached URLs');
        file_put_contents($this->tree . '/plugins/example/style.css', 'a {color:green}');
        $plugin = $this->stamp();
        $this->assertTrue($core !== $plugin, 'plugin static changes invalidate dynamic injection URLs');
        file_put_contents($this->tree . '/lang/en.js', 'var text="two";');
        $this->assertTrue($plugin !== $this->stamp(), 'language changes invalidate language URLs');
    }

    public function testNewFilesAndHtmlChangesAreHashedAndInvalidIndexIsPreserved()
    {
        $first = $this->stamp();
        if ($first === null) return;
        chmod($this->tree . '/index.html', 0640);
        file_put_contents($this->tree . '/js/new.js', 'var added=true;');
        $added = $this->stamp();
        $this->assertTrue($first !== $added, 'adding a served asset changes the deployment key');
        clearstatcache();
        $this->assertEquals(0640, fileperms($this->tree . '/index.html') & 0777, 'deployment preserves the HTML mode');
        file_put_contents($this->tree . '/index.html', file_get_contents($this->tree . '/index.html') . '<div>new template</div>');
        $this->assertTrue($added !== $this->stamp(), 'template changes invalidate the asset key');
        $invalid = '<html>missing marker</html>';
        file_put_contents($this->tree . '/index.html', $invalid);
        $output = [];
        exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($this->tree . '/tools/update-asset-version.php') . ' 2>&1', $output, $status);
        $this->assertEquals(1, $status, 'invalid deployment index fails explicitly');
        $this->assertEquals($invalid, file_get_contents($this->tree . '/index.html'), 'failed deployment preserves original HTML');
        $this->assertEquals([], glob($this->tree . '/.asset-version-*'), 'failed deployment leaves no temporary files');
    }

}
