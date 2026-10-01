<?php
require_once __DIR__.'/../../php/TestCase.php';

class DirectoryBoundaryTest extends TestCase
{
    private $root;
    public function setUp()
    {
        $this->root = sys_get_temp_dir().'/getdir-'.getmypid().'-'.uniqid();
        mkdir($this->root.'/downloads/inside', 0700, true);
        mkdir($this->root.'/downloads-private/secret', 0700, true);
        file_put_contents($this->root.'/downloads/inside.txt', 'allowed');
        file_put_contents($this->root.'/downloads-private/secret.txt', 'private');
    }
    public function tearDown()
    {
        $this->removeTree($this->root);
    }
    private function removeTree($path)
    {
        if (!is_dir($path) || is_link($path)) { unlink($path); return; }
        foreach (new DirectoryIterator($path) as $entry)
            if (!$entry->isDot()) $this->removeTree($entry->getPathname());
        rmdir($path);
    }
    private function probe($script, $request, $fallback = null)
    {
        $repo = dirname(__DIR__, 3);
        $top = $this->root.'/downloads';
        $code = 'chdir('.var_export($repo.'/php', true).'); '
            .'$_ENV["RU_PROFILE_PATH"] = '.var_export($this->root.'/profiles', true).'; '
            .'$_ENV["RU_PROFILE_MASK"] = "0700"; '
            .'require '.var_export($repo.'/php/settings.php', true).'; '
            .'$topDirectory = '.var_export($top, true).'; $checkUserPermissions = false; '
            .'$settings = (new ReflectionClass("rTorrentSettings"))->newInstanceWithoutConstructor(); '
            .'$settings->directory = '.var_export($fallback ?: $top, true).'; $settings->uid = -1; '
            .'$property = new ReflectionProperty("rTorrentSettings", "theSettings"); '
            .'if(PHP_VERSION_ID < 80100) $property->setAccessible(true); $property->setValue(null, $settings); '
            .'$_REQUEST = '.var_export($request, true).'; '
            .'$source = file_get_contents('.var_export($repo.'/plugins/_getdir/'.$script, true).'); '
            .'$source = preg_replace("~^require_once[^\\n]*|^eval\\(FileUtil::getPluginConf[^\\n]*~m", "", $source); '
            .'$source = str_replace(\'CachedEcho::send(JSON::safeEncode($output),"application/json");\', '
            .'\'echo JSON::safeEncode($output);\', $source); '
            .'eval("?>".$source);';
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=stderr', '-r', $code),
            array(1=>array('pipe','w'), 2=>array('pipe','w')), $pipes);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertEquals(0, proc_close($process), 'actual directory controller executes: '.$err);
        $data = json_decode($out, true);
        $this->assertTrue(is_array($data), 'directory controller returns JSON: '.$out);
        return $data ?: array();
    }
    public function testListRejectsSiblingPrefixAndHidesRootParent()
    {
        $data = $this->probe('listdir.php', array('dir'=>$this->root.'/downloads-private'));
        $this->assertEquals($this->root.'/downloads/', $data['path'] ?? null, 'sibling prefix returns to top');
        $this->assertTrue(!in_array('secret', $data['directories'] ?? array()), 'sibling directory names stay private');
        $this->assertTrue(!in_array('..', $data['directories'] ?? array()), 'parent is hidden at slashless top');
    }
    public function testFilesAreReturnedOnlyWhenRequested()
    {
        $request = array('dir'=>$this->root.'/downloads');
        $data = $this->probe('listdir.php', $request);
        $this->assertEquals(array(), $data['files'] ?? null, 'directory-only dialog receives no file names');
        $request['withfiles'] = '1';
        $data = $this->probe('listdir.php', $request);
        $this->assertEquals(array('inside.txt'), $data['files'] ?? null, 'file dialog receives allowed file names');
    }
    public function testInfoRejectsSiblingAndNormalizesFallback()
    {
        $data = $this->probe('info.php', array('mode'=>'dirlist', 'basedir'=>$this->root.'/downloads-private'),
            $this->root.'/downloads-private');
        $this->assertEquals($this->root.'/downloads', $data['basedir'] ?? null, 'unsafe fallback returns to top');
        $this->assertTrue(!in_array('secret', $data['dirlist'] ?? array()), 'info lists no sibling names');
        $this->assertTrue(!in_array('..', $data['dirlist'] ?? array()), 'info hides root parent');
    }
}
