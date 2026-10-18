<?php
require_once dirname(__DIR__, 2) . '/php/TestCase.php';

class ExternalConfigurationTest extends TestCase
{
    private function configurationNamespace($present)
    {
        $namespace = 'CreateConfig' . bin2hex(random_bytes(4));
        eval('namespace ' . $namespace . '; function is_executable($path) { return '
            . ($present ? 'true' : 'false') . ' && $path === "/usr/local/bin/mktorrent"; }');
        $source = preg_replace('/^<\?php/', '', file_get_contents(dirname(__DIR__, 3) . '/plugins/create/external.php'), 1);
        eval('namespace ' . $namespace . '; ' . $source);
        // externals.php (upstream ca354ca9) maps a backend to its program name.
        $source = preg_replace('/^<\?php/', '', file_get_contents(dirname(__DIR__, 3) . '/plugins/create/externals.php'), 1);
        eval('namespace ' . $namespace . '; ' . $source);
        return $namespace;
    }

    public function testChangingOnlyTheCreatorDoesNotInheritThePackagedBinaryPath()
    {
        $source = preg_replace('/^<\?php/', '', file_get_contents(dirname(__DIR__, 3) . '/plugins/create/conf.php'), 1);
        $namespace = 'CreateOverride' . bin2hex(random_bytes(4));
        eval('namespace ' . $namespace . '; function is_executable($path) { return $path === "/usr/local/bin/mktorrent"; }');
        foreach (array('torrenttools', 'buildtorrent', false) as $override) {
            eval('namespace ' . $namespace . '; ' . $source);
            $useExternal = $override;
            $this->assertEquals('', $pathToCreatetorrent,
                'A partial creator override leaves its program path available for resolution');
        }
    }

    public function testTheFinalCreatorAndAnExplicitPathControlThePackagedDefault()
    {
        foreach (array(false, true) as $present) {
            $namespace = $this->configurationNamespace($present);
            $resolver = $namespace . '\\CreateExternalConfiguration';
            $this->assertEquals($present ? '/usr/local/bin/mktorrent' : '', $resolver::path('mktorrent', ''),
                'Only a present packaged mktorrent receives the absolute FPM default');
            foreach (array('torrenttools', 'buildtorrent', false) as $creator)
                $this->assertEquals('', $resolver::path($creator, ''),
                    'Other creator choices retain their empty configured path');
            $this->assertEquals('/operator/custom-creator', $resolver::path('mktorrent', '/operator/custom-creator'),
                'An explicit operator path retains priority over the packaged binary');
        }
    }

    public function testInitDisablesAnUnavailablePartialOverrideInsteadOfAcceptingMktorrent()
    {
        $namespace = $this->configurationNamespace(true);
        eval('namespace ' . $namespace . '; class FileUtil { public static $conf; '
            . 'public static function getPluginConf($plugin) { return self::$conf; } } '
            . 'class CreateSettings { public $registered = false; '
            . 'public function registerPlugin($name, $perms) { $this->registered = true; } } '
            . 'function findEXE($name) { return false; }');
        $fileUtil = $namespace . '\\FileUtil';
        $settingsClass = $namespace . '\\CreateSettings';
        $conf = preg_replace('/^<\?php/', '', file_get_contents(dirname(__DIR__, 3) . '/plugins/create/conf.php'), 1);
        $init = preg_replace('/^<\?php/', '', file_get_contents(dirname(__DIR__, 3) . '/plugins/create/init.php'), 1);
        $init = str_replace("require_once(__DIR__.'/external.php');", '', $init, $replaced);
        $this->assertEquals(1, $replaced, 'The fixture supplies the real resolver before the real init body');
        $init = str_replace("require_once( dirname(__FILE__).'/externals.php' );", '', $init, $replaced);
        $this->assertEquals(1, $replaced, 'The fixture supplies the real program-name map before the real init body');
        foreach (array('torrenttools', 'mktorrent', false) as $creator) {
            $fileUtil::$conf = 'namespace ' . $namespace . '; ' . $conf . '$useExternal = ' . var_export($creator, true) . ';';
            $plugin = array('name'=>'create');
            $pInfo = array('perms'=>array());
            $theSettings = new $settingsClass();
            $do_diagnostic = true;
            $jResult = '';
            eval('namespace ' . $namespace . '; ' . $init);
            $this->assertEquals($creator !== 'torrenttools', $theSettings->registered,
                'Init registers only an available creator or the internal implementation');
            $this->assertEquals($creator === 'torrenttools', strpos($jResult, 'plugin.disable()') !== false,
                'An unavailable creator-only override is visibly disabled');
        }
    }

    public function testActionResolvesTheFinalProgramAfterAllConfigurationOverrides()
    {
        $namespace = $this->configurationNamespace(true);
        eval('namespace ' . $namespace . '; class FileUtil { public static $conf; '
            . 'public static function getPluginConf($plugin) { return self::$conf; } }');
        $fileUtil = $namespace . '\\FileUtil';
        $conf = preg_replace('/^<\?php/', '', file_get_contents(dirname(__DIR__, 3) . '/plugins/create/conf.php'), 1);
        $action = file_get_contents(dirname(__DIR__, 3) . '/plugins/create/action.php');
        $header = preg_replace('/^<\?php/', '', substr($action, 0, strpos($action, 'class recentTrackers')), 1);
        $header = preg_replace('/^require_once\([^;]+;\s*$/m', '', $header, -1, $replaced);
        $this->assertEquals(5, $replaced, 'The fixture supplies dependencies before the real action configuration');
        $fallback = substr($action, strpos($action, 'if(!$pathToCreatetorrent'));
        $fallback = substr($fallback, 0, strpos($fallback, 'if($useExternal=="mktorrent")'));
        foreach (array(array('mktorrent', '', '/usr/local/bin/mktorrent'),
            array('torrenttools', '', 'torrenttools'), array('buildtorrent', '/custom/buildtorrent', '/custom/buildtorrent')) as $case) {
            $fileUtil::$conf = 'namespace ' . $namespace . '; ' . $conf
                . '$useExternal = ' . var_export($case[0], true) . ';'
                . '$pathToCreatetorrent = ' . var_export($case[1], true) . ';';
            eval('namespace ' . $namespace . '; ' . $header . $fallback);
            $this->assertEquals($case[2], $pathToCreatetorrent,
                'The real action gives the selected wrapper its selected program');
        }
    }
}
