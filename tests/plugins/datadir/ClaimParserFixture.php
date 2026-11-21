<?php

/** Shared real-parser harness for guarded datadir claim scenarios. */
trait ClaimParserFixture
{
    private function claimReplies($directory, $name, $basePath, $multi, $files, $label = '', $session = '', $tied = '')
    {
        $claim = ['d.stop_close_claim_state', 'd.stop_close_claim',
            'd.directory.set_if_stop_close_claim', 'd.directory.base.set_if_stop_close_claim',
            'd.start_if_stop_close_claim', 'd.open_if_stop_close_claim', 'd.release_stop_close_claim',
            'd.replay_stop_close_claim', 'd.ack_stop_close_claim'];
        $localId = 'fedcba9876543210fedcba9876543210';
        $receipt = '0123456789abcdef0123456789abcdef:0000000000000001|1|1|1|' . $localId;
        return new FakeRtorrentMethodReplies([
            'system.listMethods' => array_merge(['system.listMethods'], $claim),
            'd.get_custom1' => [$label], 'd.get_custom' => [''],
            'get_session' => [$session], 'session.path' => [$session],
            'd.get_tied_to_file' => [$tied], 'd.tied_to_file' => [$tied],
            'd.stop_close_claim_state' => new FakeRtorrentPerCall([['absent'], ['absent'], ['done|S|' . $receipt]]),
            'd.stop_close_claim' => [$receipt], 'd.is_open' => [1], 'd.local_id' => [$localId],
            'd.directory' => [$directory], 'd.get_name' => [$name], 'd.get_base_path' => [$basePath],
            'd.get_base_filename' => [$name], 'd.is_multi_file' => [$multi ? 1 : 0], 'f.multicall' => $files,
            'd.directory.set_if_stop_close_claim' => [1], 'd.directory.base.set_if_stop_close_claim' => [1],
            'd.start_if_stop_close_claim' => [1], 'd.ack_stop_close_claim' => [1], 'd.hashing' => [0], 'd.state' => [1],
        ]);
    }

    private function claimInPlace($destination, $label = '', $session = '', $tied = '')
    {
        $source = $this->base . '/source';
        if (!is_dir($source)) {
            mkdir($source, 0700, true);
        }
        if (!is_dir($destination)) {
            mkdir($destination, 0700, true);
        }
        $body = '$failure = null; $warning = null; $hash = ' . var_export(self::HASH, true)
            . '; $dest = ' . var_export($destination, true) . ';'
            . ' $refusal = rtDataDirRequestRefusal($hash, $dest);'
            . ' if ($refusal === "") $refusal = rtDataDirClaimRefusal();'
            . ' $applied = $refusal === "" && rtDataDirApplyLocked($hash, $dest, true, false, true, false, false, $failure, $warning);'
            . ' echo json_encode(array("refusal" => $refusal, "applied" => $applied, "failure" => $failure));';
        return $this->claimDrive($this->claimReplies($source, 'download', $source, true, [], $label, $session, $tied), $body);
    }

    private function claimDrive($replies, $body)
    {
        if (!is_dir($this->base . '/profile/settings')) {
            mkdir($this->base . '/profile/settings', 0700, true);
        }
        $this->daemon = new FakeRtorrentDaemon($replies, $this->base . '/calls.log');
        $root = realpath(__DIR__ . '/../../..');
        $plugin = $root . '/plugins/datadir';
        $helper = $this->base . '/rename-helper';
        file_put_contents($helper, "#!/bin/sh\n[ \"\$1\" = --probe ] && exit 0\n"
            . "{ [ -e \"\$2\" ] || [ -L \"\$2\" ]; } && exit 1\nexec mv -T -- \"\$1\" \"\$2\"\n");
        chmod($helper, 0700);
        $driver = $this->base . '/drive-setdir.php';
        file_put_contents($driver, "<?php\n"
            . 'umask(0022); $_SERVER["REMOTE_USER"] = "tester"; $_ENV["RU_PROFILE_PATH"] = '
            . var_export($this->base . '/profile', true) . '; chdir(' . var_export($plugin, true) . ');'
            . ' require_once(' . var_export($root . '/conf/config.php', true) . ');'
            . ' require_once(' . var_export($root . '/php/settings.php', true) . ');'
            . ' $r = new ReflectionClass("rTorrentSettings"); $s = $r->newInstanceWithoutConstructor();'
            . ' $s->iVersion = 0x1018; $s->aliases = array(); $s->directory = "/"; $s->linkExist = true;'
            . ' $p = $r->getProperty("theSettings"); $p->setAccessible(true); $p->setValue(null, $s);'
            . ' $scgi_host = "127.0.0.1"; $scgi_port = ' . $this->daemon->port() . '; $rpcTimeOut = 10; $rpcLogCalls = false;'
            . ' require_once(' . var_export($root . '/php/xmlrpc.php', true) . ');'
            . ' require_once(' . var_export($root . '/plugins/erasedata/filesystem.php', true) . ');'
            . ' class TestDataDirFilesystem extends ErasedataFilesystemOps { public static function renameHelperPath() { return '
            . var_export($helper, true) . '; } }'
            . ' function rtDataDirFilesystem() { static $f = null; if ($f === null) $f = new TestDataDirFilesystem(); return $f; }'
            . ' $source = file_get_contents(' . var_export($plugin . '/util_setdir.php', true) . ');'
            . ' $source = str_replace("function rtDataDirFilesystem()", "function rtDataDirFilesystemShipped()", $source, $seams);'
            . ' $source = str_replace("__DIR__", ' . var_export(var_export($plugin, true), true) . ', $source);'
            . ' if ($seams !== 1) { echo json_encode(array("error" => "seam-moved")); exit; } eval(substr($source, 5));'
            . ' require_once(' . var_export($plugin . '/apply.php', true) . ');' . $body);
        $process = PhpProcess::run(
            array_merge(PhpProcess::sameIni(), ['-d', 'error_reporting=0', $driver]),
            ['env' => ['LC_ALL' => 'C']],
        );
        $out = $process['out'];
        $calls = $this->daemon->calls();
        $bodies = $this->daemon->bodies();
        $this->daemon->stop();
        $this->daemon = null;
        return ['answer' => json_decode($out, true), 'calls' => $calls, 'bodies' => $bodies, 'output' => $out];
    }

}
