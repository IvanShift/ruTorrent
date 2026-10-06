<?php
require_once __DIR__ . '/TestCase.php';

class DeploymentProtectionTest extends TestCase
{
    private function nginxDenies($bytes, $directory)
    {
        $active = preg_replace('/^\s*#.*$/m', '', $bytes);
        preg_match_all('/location\s+~\s+(\S+)\s*\{([^}]*)\}/s', $active, $rules, PREG_SET_ORDER);
        foreach($rules as $rule) {
            if(@preg_match('~'.$rule[1].'~', '/'.$directory.'/proof.php') === 1
                && preg_match('/\bdeny\s+all\s*;/', $rule[2])
                && preg_match('/\breturn\s+403\s*;/', $rule[2])) return true;
        }
        return false;
    }

    public function testCommentedRuleDoesNotProtectTools()
    {
        $bytes = file_get_contents(dirname(__DIR__,2).'/nginx-example.conf');
        $mutant = str_replace('location ~ ^/(share|conf|tests|tools)', 'location ~ ^/(share|conf|tests)', $bytes);
        $this->assertTrue(!$this->nginxDenies($mutant, 'tools'),
            'commented prefix examples cannot mask a missing active tools denial');
        $this->assertTrue($this->nginxDenies($bytes, 'tools'), 'actual root rule denies tools');
    }

    public function testPrivateDirectoryExamplesDenyEveryTrackedTopLevelDirectory()
    {
        $root = dirname(__DIR__, 2);
        $listed = [];
        $status = 0;
        exec('git -C ' . escapeshellarg($root) . ' ls-files 2>/dev/null', $listed, $status);
        $directories = [];
        if ($status === 0 && $listed !== []) {
            foreach ($listed as $path) {
                $parts = explode('/', $path, 2);
                if (count($parts) === 2) $directories[$parts[0]] = true;
            }
        } else {
            foreach (scandir($root) as $path) {
                if (is_dir($root . '/' . $path)) $directories[$path] = true;
            }
            // Local-only state is absent from the archived deployment tested by CI.
            foreach (['tasks', 'docs', 'backup', 'bin', 'node_modules'] as $path) unset($directories[$path]);
        }
        $public = ['css', 'images', 'js', 'lang', 'php', 'plugins'];
        $nginx = file_get_contents($root . '/nginx-example.conf');
        $readme = file_get_contents($root . '/README.md');
        foreach (array_keys($directories) as $path) {
            if ($path[0] === '.' || in_array($path, $public, true)) continue;
            $deny = $root . '/' . $path . '/.htaccess';
            $this->assertTrue(is_file($deny), "$path has an Apache deny file");
            if (is_file($deny)) $this->assertTrue(stripos(file_get_contents($deny), 'require all denied') !== false, "$path Apache denial is explicit");
            $this->assertTrue($this->nginxDenies($nginx, $path), "$path is denied by the active nginx rule");
            $this->assertTrue(strpos($readme, '`' . $path . '/`') !== false, "$path deployment protection is documented");
        }
    }
}
