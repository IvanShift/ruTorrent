<?php

require_once(__DIR__ . '/TestCase.php');

class PluginInfoParserTest extends TestCase
{
    private function fixture($contents)
    {
        $root = sys_get_temp_dir() . '/rutorrent-plugin-info-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($root, 0700);
        mkdir($root . '/php', 0700);
        mkdir($root . '/plugins', 0700);
        mkdir($root . '/plugins/sample', 0700);
        file_put_contents($root . '/plugins/sample/plugin.info', $contents);
        return($root);
    }

    private function removeFixture($root)
    {
        @unlink($root . '/plugins/sample/plugin.info');
        @rmdir($root . '/plugins/sample');
        @rmdir($root . '/plugins');
        @rmdir($root . '/php');
        @rmdir($root);
    }

    private function functionSource($path, $name)
    {
        $tokens = token_get_all(file_get_contents($path));
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) continue;
            $next = $index + 1;
            while (isset($tokens[$next]) && is_array($tokens[$next]) &&
                $tokens[$next][0] === T_WHITESPACE) $next++;
            if (!isset($tokens[$next]) || !is_array($tokens[$next]) ||
                $tokens[$next][1] !== $name) continue;
            $source = '';
            $depth = 0;
            $opened = false;
            for ($i = $index; isset($tokens[$i]); $i++) {
                $part = is_array($tokens[$i]) ? $tokens[$i][1] : $tokens[$i];
                $source .= $part;
                if ($part === '{') { $depth++; $opened = true; }
                if ($part === '}') $depth--;
                if ($opened && $depth === 0) return($source);
            }
        }
        throw new RuntimeException('Missing function ' . $name . ' in ' . $path);
    }

    private function entrypointInfo($loader, $root, $permissions)
    {
        $php = dirname(__DIR__, 2) . '/php';
        $helper = $php . '/plugininfo.php';
        $code = 'function getFlag($permissions,$name,$flag) {' .
            'return array_key_exists($flag,$permissions) ? $permissions[$flag] : true; }' .
            (is_file($helper) ? 'require_once ' . var_export($helper, true) . ';' : '') .
            $this->functionSource($php . '/' . $loader, 'getPluginInfo') .
            'echo json_encode(getPluginInfo("sample", ' .
            var_export($permissions, true) . '));';
        $process = proc_open(array(PHP_BINARY, '-d', 'display_errors=0', '-r', $code),
            array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes,
            $root . '/php');
        if (!is_resource($process)) throw new RuntimeException('Could not run plugin loader');
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        if ($status !== 0) throw new RuntimeException($loader . ': ' . $err);
        return(json_decode($out, true));
    }

    public function testCommonFieldsAndLegacyAliasesUseOneParser()
    {
        $helper = dirname(__DIR__, 2) . '/php/plugininfo.php';
        $this->assertTrue(is_file($helper), 'both loaders need one plugin.info parser');
        if (!is_file($helper)) return;
        require_once($helper);
        $defaults = array('plugin.runlevel' => 10.0,
            'rtorrent.version' => 0x802, 'php.version' => 0x50000,
            'plugin.dependencies' => array(), 'plugin.may_be_launched' => 1);
        $parsed = PluginInfo::parse(array(
            "version: 1.25\n", "runlevel: 13.5\n",
            "rtorrent.version: 0.16.24\n", "php.version: 8.1.2\n",
            "plugin.dependencies: alpha,beta\n", "plugin.may_be_launched: 0\n",
        ), $defaults);
        $this->assertSame(1.25, $parsed['plugin.version']);
        $this->assertSame(13.5, $parsed['plugin.runlevel']);
        $this->assertSame((0 << 16) + (16 << 8) + 24, $parsed['rtorrent.version']);
        $this->assertSame('0.16.24', $parsed['rtorrent.version.readable']);
        $this->assertSame((8 << 16) + (1 << 8) + 2, $parsed['php.version']);
        $this->assertSame(array('alpha', 'beta'), $parsed['plugin.dependencies']);
        $this->assertSame(0, $parsed['plugin.may_be_launched']);
        $this->assertSame(-1, PluginInfo::compare(10, 'zeta', 11, 'alpha'));
        $this->assertTrue(PluginInfo::compare(10, 'alpha', 10, 'zeta') < 0);
    }

    public function testBothEntrypointsKeepCommonFieldsAndDifferentDefaults()
    {
        $root = $this->fixture("version: 1.25\nrunlevel: 13.5\n" .
            "rtorrent.version: 0.16.24\nphp.version: 8.1.2\n" .
            "plugin.dependencies: alpha,beta\nplugin.may_be_launched: 0\n" .
            "author: Legacy Author\ndescription: Legacy Description\n" .
            "remote: remote-alias\nneed_rtorrent: 0\n");
        try {
            $web = $this->entrypointInfo('getplugins.php', $root, array());
            $cli = $this->entrypointInfo('initplugins.php', $root, array());
            foreach (array('plugin.version', 'plugin.runlevel', 'rtorrent.version',
                'rtorrent.version.readable', 'php.version', 'php.version.readable',
                'plugin.dependencies', 'plugin.may_be_launched') as $field) {
                $this->assertSame($web[$field], $cli[$field], $field . ' agrees across loaders');
            }
            $this->assertSame('Legacy Author', $web['plugin.author']);
            $this->assertSame('Legacy Description', $web['plugin.description']);
            $this->assertSame('remote-alias', $web['rtorrent.remote']);
            $this->assertSame(0, $web['rtorrent.need']);
            $this->assertSame('', $web['plugin.help']);
            $this->assertTrue(!array_key_exists('rtorrent.remote', $cli) &&
                !array_key_exists('rtorrent.need', $cli) &&
                !array_key_exists('plugin.help', $cli),
                'CLI keeps web-only aliases and defaults out of its record');
            $this->assertTrue(!array_key_exists('plugin.author', $cli) &&
                !array_key_exists('plugin.description', $cli),
                'CLI keeps its smaller metadata/default surface');
            $this->assertSame(0, $web['perms']);
            $this->assertTrue(!array_key_exists('perms', $cli), 'CLI does not compute web permissions');
            $this->assertSame(false, $this->entrypointInfo('getplugins.php', $root,
                array('enabled' => false)));
            $this->assertSame(false, $this->entrypointInfo('initplugins.php', $root,
                array('enabled' => false)));
        } finally {
            $this->removeFixture($root);
        }
    }
}
