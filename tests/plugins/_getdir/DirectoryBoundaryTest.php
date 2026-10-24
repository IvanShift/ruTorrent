<?php
require_once __DIR__.'/../../php/TestCase.php';
require_once __DIR__.'/../../php/PhpProcess.php';

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

    public function testUnavailableCaseFoldDiscoveryKeepsByteNamesSortableWithoutDiagnostics()
    {
        // The comparator's optional discovery must not depend on bootstrap's probes.
        $code = 'require '.var_export(dirname(__DIR__, 3).'/plugins/_getdir/directorylist.php', true).';'
            .'$entries=array("b","A","..",".","raw\\xFF","raw\\xFE");'
            .'usort($entries,array("rDirectoryList","compare"));'
            .'echo json_encode(array_map("base64_encode",$entries));';
        $expected = array_map('base64_encode', array('.', '..', 'A', 'b', "raw\xFE", "raw\xFF"));
        foreach(array('function_exists', 'function_exists,mb_strtolower') as $disabled)
        {
            $result = PhpProcess::run(array_merge(PhpProcess::sameIni(), array('-d', 'display_errors=stderr',
                '-d', 'disable_functions='.$disabled, '-r', $code)));
            $this->assertSame(0, $result['code'], 'unavailable case-fold discovery cannot abort sorting');
            $this->assertSame('', $result['err'], 'PHP 7 disabled-function warnings cannot corrupt directory JSON');
            $this->assertSame($expected, json_decode($result['out'], true),
                'fallback keeps navigation order, case-insensitive ASCII and exact raw byte names');
        }
    }

    private function removeTree($path)
    {
        if (!is_dir($path) || is_link($path)) { unlink($path); return; }
        foreach (new DirectoryIterator($path) as $entry)
            if (!$entry->isDot()) $this->removeTree($entry->getPathname());
        rmdir($path);
    }
    private function probe($script, $request, $fallback = null, $configuredTop = null, $disabled = '', $scanFailure = null, $foldFailure = null)
    {
        $repo = dirname(__DIR__, 3);
        $top = $configuredTop ?: $this->root.'/downloads';
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
            .'$helper = '.var_export($repo.'/plugins/_getdir/directorylist.php', true).'; '
            .'if (is_file($helper)) { '
            .'$helperSource = file_get_contents($helper); '
            .($scanFailure === null ? '' :
                '$helperSource = str_replace("scandir(", "getdirFixtureScandir(", $helperSource); '
                .'function getdirFixtureScandir($path, $flags) { '.
                ($scanFailure === 'throw' ? 'throw new RuntimeException("scan failed");' :
                    'return '.($scanFailure === 'partial' ? 'array("inside", null)' : $scanFailure).';').' } ')
            .($foldFailure === null ? '' :
                '$helperSource = str_replace("mb_strtolower", "getdirFixtureCaseFold", $helperSource); '
                .'function getdirFixtureCaseFold($value) { '.
                ($foldFailure === 'throw' ? 'throw new RuntimeException("case fold failed");' :
                    'return '.$foldFailure.';').' } ')
            .'eval("?>".$helperSource); } '
            .'$source = file_get_contents('.var_export($repo.'/plugins/_getdir/'.$script, true).'); '
            .'$source = preg_replace("~^require_once[^\\n]*|^eval\\(FileUtil::getPluginConf[^\\n]*~m", "", $source); '
            .'$source = str_replace(\'CachedEcho::send(JSON::safeEncode($output),"application/json");\', '
            .'\'echo JSON::safeEncode($output);\', $source); '
            .'eval("?>".$source);';
        $process = PhpProcess::run(array('-d', 'display_errors=stderr', '-d', 'disable_functions='.$disabled, '-r', $code));
        $out = $process['out'];
        $this->assertEquals(0, $process['code'], 'actual directory controller executes: '.$process['err']);
        $this->assertEquals('', $process['err'], 'directory dependency failures do not escape as PHP diagnostics');
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
    public function testInfoFallsBackForArrayParameters()
    {
        $data = $this->probe('info.php', array('mode'=>'dirlist', 'basedir'=>array('unexpected')));
        $this->assertEquals($this->root.'/downloads', $data['basedir'] ?? null,
            'a non-string base directory uses the allowed default');
        $data = $this->probe('info.php', array('mode'=>array('dirlist')));
        $this->assertEquals(array(), $data, 'a non-string mode returns an empty result');
        foreach (array('info.php', 'listdir.php') as $script) {
            $request = $script === 'listdir.php'
                ? array('dir'=>"\0") : array('mode'=>'dirlist', 'basedir'=>"\0");
            $data = $this->probe($script, $request);
            $this->assertEquals($this->root.'/downloads', rtrim($data[$script === 'listdir.php' ? 'path' : 'basedir'] ?? '', '/'),
                'a NUL decoded by the HTTP form parser uses the allowed default in '.$script);
        }
    }
    public function testSymlinkOutsideTopCannotExposeDirectoryOrFileNames()
    {
        symlink($this->root.'/downloads-private', $this->root.'/downloads/escape');
        symlink($this->root.'/downloads-private/secret.txt', $this->root.'/downloads/secret-link.txt');
        foreach (array('listdir.php', 'info.php') as $script) {
            foreach (array('/escape', '') as $suffix) {
            $request = $script === 'listdir.php'
                ? array('dir'=>$this->root.'/downloads'.$suffix, 'withfiles'=>'1')
                : array('mode'=>'dirlist', 'basedir'=>$this->root.'/downloads'.$suffix);
            $data = $this->probe($script, $request);
            $names = $data[$script === 'listdir.php' ? 'directories' : 'dirlist'] ?? array();
            $this->assertTrue(!in_array('secret', $names), 'escaped directory names are refused by '.$script);
            $this->assertTrue(!in_array('escape', $names), 'escaped symlink is hidden by '.$script);
            $this->assertTrue(!in_array('secret-link.txt', $data['files'] ?? array()),
                'escaped file symlink is hidden by '.$script);
            $this->assertTrue(in_array('inside', $names), 'allowed sibling remains visible in '.$script);
            }
        }
    }
    public function testSymlinkWithinTopRemainsBrowsable()
    {
        symlink($this->root.'/downloads/inside', $this->root.'/downloads/alias');
        mkdir($this->root.'/downloads/inside/child', 0700);
        foreach (array('listdir.php', 'info.php') as $script) {
            $request = $script === 'listdir.php'
                ? array('dir'=>$this->root.'/downloads/alias')
                : array('mode'=>'dirlist', 'basedir'=>$this->root.'/downloads/alias');
            $data = $this->probe($script, $request);
            $this->assertTrue(in_array('child', $data[$script === 'listdir.php' ? 'directories' : 'dirlist'] ?? array()),
                'an internal directory alias remains usable in '.$script);
        }
    }
    public function testConfiguredTopSymlinkKeepsItsAllowedContents()
    {
        $alias = $this->root.'/downloads-alias';
        symlink($this->root.'/downloads', $alias);
        mkdir($this->root.'/downloads/inside/child', 0700);
        foreach (array('listdir.php', 'info.php') as $script) {
            foreach (array(''=>'inside', '/inside'=>'child') as $suffix=>$entry) {
                $request = $script === 'listdir.php'
                    ? array('dir'=>$alias.$suffix, 'withfiles'=>'1')
                    : array('mode'=>'dirlist', 'basedir'=>$alias.$suffix);
                $data = $this->probe($script, $request, $alias, $alias);
                $names = $data[$script === 'listdir.php' ? 'directories' : 'dirlist'] ?? array();
                $returned = $data[$script === 'listdir.php' ? 'path' : 'basedir'] ?? null;
                $this->assertEquals($alias.$suffix.($script === 'listdir.php' ? '/' : ''), $returned,
                    'a selected directory keeps the configured top spelling for its consumers in '.$script.$suffix);
                $this->assertTrue(in_array($entry, $names),
                    'a configured top alias keeps its allowed contents in '.$script.$suffix);
                if ($suffix === '' && $script === 'listdir.php')
                    $this->assertTrue(in_array('inside.txt', $data['files'] ?? array()),
                        'a configured top alias keeps its allowed files');
                if ($suffix === '')
                    $this->assertTrue(!in_array('..', $names), 'a configured top alias hides its parent');
            }
        }
    }
    public function testTopBelowSymlinkMapsPhysicalDefaultAndRejectsEscapes()
    {
        $alias = $this->root.'/ancestor-alias';
        symlink($this->root, $alias);
        $top = $alias.'/downloads/';
        symlink($this->root.'/downloads-private', $this->root.'/downloads/escape');
        foreach (array('listdir.php', 'info.php') as $script) {
            foreach (array('', $top.'escape') as $requested) {
                $request = $script === 'listdir.php' ? array('dir'=>$requested)
                    : array('mode'=>'dirlist', 'basedir'=>$requested);
                $data = $this->probe($script, $request, $this->root.'/downloads/inside', $top);
                $expected = ($requested === '' || $script === 'info.php') ? $top.'inside' : rtrim($top, '/');
                if ($script === 'listdir.php') $expected .= '/';
                $this->assertEquals($expected, $data[$script === 'listdir.php' ? 'path' : 'basedir'] ?? null,
                    'a physical default maps to the logical top and an escape returns to that top in '.$script);
                $names = $data[$script === 'listdir.php' ? 'directories' : 'dirlist'] ?? array();
                $this->assertTrue(!in_array('secret', $names) && !in_array('escape', $names),
                    'an aliased top does not expose an escaped directory in '.$script);
            }
        }
    }

    public function testLegacyDirectoryAndFileNamesKeepTheirRawIdentity()
    {
        $base = $this->root.'/downloads';
        mkdir($base."/\xFF", 0700);
        mkdir($base."/\xFF/child", 0700);
        file_put_contents($base."/\xFE.txt", 'legacy');
        mkdir($base.'/\\xFF', 0700);
        foreach (array('listdir.php', 'info.php') as $script) {
            $request = $script === 'listdir.php' ? array('dir'=>$base, 'withfiles'=>'1')
                : array('mode'=>'dirlist', 'basedir'=>$base);
            $data = $this->probe($script, $request);
            $entries = $data[$script === 'listdir.php' ? 'directories' : 'dirlist'] ?? array();
            $legacy = array_values(array_filter($entries, 'is_array'));
            $this->assertEquals(array(array('name'=>'\\xFF', 'pathKey'=>'%FF')), $legacy,
                'legacy bytes have a visible label and a reversible identity in '.$script);
            $this->assertTrue(in_array('\\xFF', $entries, true), 'literal backslash spelling stays a distinct scalar');
            if ($script === 'listdir.php')
                $this->assertTrue(in_array(array('name'=>'\\xFE.txt', 'pathKey'=>'%FE.txt'), $data['files'], true),
                    'file chooser keeps each raw file name');
            parse_str(($script === 'listdir.php' ? 'dir=' : 'mode=dirlist&basedir=').
                rawurlencode($base.'/').'%FF', $navigation);
            $next = $this->probe($script, $navigation);
            $path = $next[$script === 'listdir.php' ? 'path' : 'basedir'] ?? null;
            $raw = $base."/\xFF".($script === 'listdir.php' ? '/' : '');
            $this->assertEquals(array('name'=>str_replace("\xFF", '\\xFF', $raw), 'pathKey'=>rawurlencode($raw)),
                $path, 'returned directory path remains reversible after navigation in '.$script);
            $this->assertTrue(in_array('child', $next[$script === 'listdir.php' ? 'directories' : 'dirlist'], true),
                'navigation opened the raw-byte directory');
        }
    }

    public function testHttpFormDecodesPercentBackslashAndUtf8ExactlyOnce()
    {
        $path = $this->root.'/downloads/雪 %2F\\xFF+&=😀';
        mkdir($path, 0700);
        mkdir($path.'/child', 0700);
        foreach (array('listdir.php', 'info.php') as $script) {
            parse_str(($script === 'listdir.php' ? 'dir=' : 'mode=dirlist&basedir=').rawurlencode($path), $request);
            $data = $this->probe($script, $request);
            $this->assertEquals($path.($script === 'listdir.php' ? '/' : ''),
                $data[$script === 'listdir.php' ? 'path' : 'basedir'] ?? null,
                'valid UTF-8 and literal percent/backslash bytes remain unchanged in '.$script);
            $this->assertTrue(in_array('child', $data[$script === 'listdir.php' ? 'directories' : 'dirlist'], true),
                'the selected literal-percent path opens');
        }
    }

    public function testDirectoryScanFailuresReturnAnObservableEmptyResult()
    {
        foreach (array('listdir.php', 'info.php') as $script) {
            foreach (array('missing', 'disabled', 'false', 'null', 'throw', 'partial') as $failure) {
                $path = $this->root.'/downloads'.($failure === 'missing' ? '/missing' : '');
                $request = $script === 'listdir.php' ? array('dir'=>$path, 'withfiles'=>'1')
                    : array('mode'=>'dirlist', 'basedir'=>$path);
                $data = $this->probe($script, $request, null, null,
                    $failure === 'disabled' ? 'scandir' : '',
                    in_array($failure, array('false', 'null', 'throw', 'partial'), true) ? $failure : null);
                $this->assertEquals('directory-unavailable', $data['error'] ?? null,
                    'failed scan is visible for '.$script.': '.$failure);
                $this->assertEquals(array(), $data[$script === 'listdir.php' ? 'directories' : 'dirlist'] ?? null,
                    'failed scan exposes no directory entries');
                if ($script === 'listdir.php')
                    $this->assertEquals(array(), $data['files'] ?? null, 'failed scan exposes no file entries');
            }
        }
    }

    public function testDirectorySortingWorksWithoutMbstring()
    {
        mkdir($this->root.'/downloads/Alpha', 0700);
        foreach (array('listdir.php', 'info.php') as $script) {
            $request = $script === 'listdir.php' ? array('dir'=>$this->root.'/downloads')
                : array('mode'=>'dirlist', 'basedir'=>$this->root.'/downloads');
            $data = $this->probe($script, $request, null, null, 'mb_strtolower');
            $this->assertTrue(in_array('inside', $data[$script === 'listdir.php' ? 'directories' : 'dirlist'] ?? array(), true),
                'optional case folding does not prevent directory listing in '.$script);
            $entries = $data[$script === 'listdir.php' ? 'directories' : 'dirlist'];
            $this->assertTrue(array_search('Alpha', $entries, true) < array_search('inside', $entries, true),
                'the case-folding fallback still sorts entries');
        }
    }

    public function testFailedCaseFoldingKeepsDirectorySortingUsable()
    {
        mkdir($this->root.'/downloads/Alpha', 0700);
        foreach (array('listdir.php', 'info.php') as $script) {
            foreach (array('false', 'null', 'throw', 'array()') as $failure) {
                $request = $script === 'listdir.php' ? array('dir'=>$this->root.'/downloads')
                    : array('mode'=>'dirlist', 'basedir'=>$this->root.'/downloads');
                $data = $this->probe($script, $request, null, null, '', 'array("inside", "Alpha")', $failure);
                $entries = $data[$script === 'listdir.php' ? 'directories' : 'dirlist'] ?? array();
                $this->assertTrue(in_array('inside', $entries, true) && in_array('Alpha', $entries, true),
                    'failed optional case folding retains every entry in '.$script.': '.$failure);
                $this->assertTrue(array_search('Alpha', $entries, true) < array_search('inside', $entries, true),
                    'failed optional case folding uses the byte-safe fallback');
            }
        }
    }

    public function testCaseInsensitiveOrderingKeepsNavigationEntriesFirst()
    {
        $path = $this->root.'/downloads/inside';
        foreach (array('Zulu', 'alpha', 'Я', 'а') as $name)
            mkdir($path.'/'.$name, 0700);
        foreach (array('listdir.php', 'info.php') as $script) {
            $request = $script === 'listdir.php' ? array('dir'=>$path)
                : array('mode'=>'dirlist', 'basedir'=>$path);
            $data = $this->probe($script, $request);
            $entries = $data[$script === 'listdir.php' ? 'directories' : 'dirlist'] ?? array();
            $this->assertEquals($script === 'listdir.php' ? '..' : '.', $entries[0] ?? null,
                'navigation entries sort before directory names');
            if ($script === 'info.php')
                $this->assertEquals('..', $entries[1] ?? null, 'the current directory precedes the parent directory');
            $this->assertTrue(array_search('alpha', $entries, true) < array_search('Zulu', $entries, true),
                'ASCII directory sorting is case insensitive');
            if (function_exists('mb_strtolower'))
                $this->assertTrue(array_search('а', $entries, true) < array_search('Я', $entries, true),
                    'available Unicode case folding keeps the existing sort order');
        }
    }
}
