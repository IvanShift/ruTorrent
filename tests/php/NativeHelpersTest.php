<?php
require_once(__DIR__.'/TestCase.php');
require_once(__DIR__.'/../../php/nativehelpers.php');

class NativeHelpersTest extends TestCase
{
    public function testManifestDeclaresReadableSourcesAndEveryLiteralReference()
    {
        $manifest = NativeHelpers::manifest();
        $this->assertEquals(3, count($manifest['helpers']), 'The current native ABI has three helpers');
        foreach ($manifest['helpers'] as $name=>$relative) {
            $this->assertTrue(is_file(NativeHelpers::source($name)), 'Declared source exists: '.$relative);
            $this->assertEquals('/usr/local/bin/'.$name, NativeHelpers::path($name), 'One declared install path');
        }
        $root = dirname(__DIR__, 2);
        foreach (array('php', 'plugins') as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory,
                FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') continue;
                preg_match_all('~/usr/local/bin/(rutorrent-[A-Za-z0-9_.-]+)~', file_get_contents($file->getPathname()), $matches);
                foreach ($matches[1] as $name)
                    $this->assertTrue(isset($manifest['helpers'][$name]), 'Every referenced native path is declared: '.$name);
            }
        }
    }

    public function testCompilerFlagsAndFixtureHookUseTheSameDeclaredContract()
    {
        $manifest = NativeHelpers::manifest();
        foreach ($manifest['helpers'] as $name=>$relative) {
            $command = NativeHelpers::compilerCommand($name, '/private/output', array('-DTEST_ONLY=1'));
            $this->assertEquals(array_merge(array('cc'), $manifest['flags'], array('-DTEST_ONLY=1', '-o',
                '/private/output', dirname(__DIR__, 2).'/'.$relative)), $command, 'Fixture keeps manifest flags and source');
        }
    }

    public function testMalformedManifestCannotSelectAnotherHelperOrSource()
    {
        $file = tempnam(sys_get_temp_dir(), 'native-manifest-');
        $prefix = "version\t1\nflags\t-std=c11 -O2\n";
        try {
            foreach (array(
                "version\t2\nflags\t-O2\nrutorrent-test\tplugins/test.c\n",
                $prefix."rutorrent-test\t../outside.c\n",
                $prefix."rutorrent-test\t/plugins/test.c\n",
                $prefix."rutorrent-test\tplugins/test.c\nrutorrent-test\tplugins/other.c\n",
                "version\t1\nflags\t-O2 ;touch\nrutorrent-test\tplugins/test.c\n",
                "version\t1\nflags\t-O2\n",
            ) as $bytes) {
                file_put_contents($file, $bytes);
                $refused = false;
                try { NativeHelpers::manifest($file); } catch (RuntimeException $e) { $refused = true; }
                $this->assertTrue($refused, 'Invalid manifest fails closed');
            }
        } finally { unlink($file); }
    }

    public function testCreateDefaultsUseAbsoluteMktorrentOnlyWhenPresent()
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/plugins/create/conf.php');
        $source = preg_replace('/^<\?php/', '', $source, 1);
        $namespace = 'CreateDefaults'.bin2hex(random_bytes(4));
        eval('namespace '.$namespace.'; function is_executable($path) { return $path === "/usr/local/bin/mktorrent" && $GLOBALS["createDefaultBinaryPresent"]; }');
        $external = preg_replace('/^<\?php/', '', file_get_contents(dirname(__DIR__, 2).'/plugins/create/external.php'),1);
        eval('namespace '.$namespace.'; '.$external);
        try {
            foreach (array(false, true) as $present) {
                $GLOBALS['createDefaultBinaryPresent'] = $present;
                eval('namespace '.$namespace.'; '.$source);
                $this->assertEquals($present ? 'mktorrent' : false, $useExternal, 'Standalone defaults reflect actual binary availability');
                $this->assertEquals('', $pathToCreatetorrent, 'Raw defaults never leak a creator binary to an override');
                $resolver = $namespace.'\\CreateExternalConfiguration';
                $this->assertEquals($present ? '/usr/local/bin/mktorrent' : '', $resolver::path($useExternal, $pathToCreatetorrent), 'Final mktorrent choice gets the available absolute binary');
                $this->assertTrue($useInternalHybrid, 'Hybrid torrents retain the internal fallback');
                eval('$useExternal = false; $pathToCreatetorrent = "";');
                $this->assertEquals(false, $useExternal, 'Later operator overrides retain priority');
            }
        } finally { unset($GLOBALS['createDefaultBinaryPresent']); }
    }
    public function testMissingManifestDoesNotKillTaskBootstrap()
    {
        $root = sys_get_temp_dir().'/native-missing-'.uniqid();
        mkdir($root.'/php',0700,true); mkdir($root.'/plugins/_task',0700,true);
        $repo = dirname(__DIR__,2);
        copy($repo.'/php/nativehelpers.php',$root.'/php/nativehelpers.php');
        copy($repo.'/plugins/_task/task.php',$root.'/plugins/_task/task.php');
        file_put_contents($root.'/php/xmlrpc.php','<?php');
        $code = 'require '.var_export($root.'/plugins/_task/task.php',true)
            .'; echo class_exists("rTask") ? "BOOTSTRAP_ALLOWED" : "MISSING";';
        $process = proc_open(array(PHP_BINARY,'-r',$code),array(1=>array('pipe','w'),
            2=>array('pipe','w')),$pipes);
        $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $status = proc_close($process);
        unlink($root.'/php/nativehelpers.php'); unlink($root.'/php/xmlrpc.php');
        unlink($root.'/plugins/_task/task.php'); rmdir($root.'/plugins/_task');
        rmdir($root.'/plugins'); rmdir($root.'/php'); rmdir($root);
        $this->assertEquals(0,$status,'missing optional artifact does not fatally abort core bootstrap: '.$err);
        $this->assertEquals('BOOTSTRAP_ALLOWED',$out,'task class remains available');
        $this->assertTrue(strpos($err,'native-helper-manifest-unreadable')!==false,
            'missing manifest stays visible and helper operations remain refused');
    }

}
