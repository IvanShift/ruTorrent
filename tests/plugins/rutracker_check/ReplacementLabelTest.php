<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/DaemonBytesFixture.php');

/** Real daemon-reader and checker paths preserve label and replacement bytes. */
class ReplacementLabelTest extends TestCase
{
    public function testAReplacementKeepsAPlainLabel()
    {
        $this->assertEquals('Plain', $this->replacement('Plain')['label']);
    }

    public function testAReplacementKeepsALabelWithAQuote()
    {
        $this->assertEquals(rawurlencode('Foo"Bar'), $this->replacement('Foo"Bar')['label']);
    }

    public function testAReplacementKeepsALabelWithABackslash()
    {
        $this->assertEquals(rawurlencode('C\\D'), $this->replacement('C\\D')['label']);
    }

    public function testAReplacementKeepsALabelRuTorrentEncoded()
    {
        $this->assertEquals('TV%20Shows', $this->replacement('TV%20Shows')['label']);
    }

    public function testAReplacementKeepsEncodedBackslashQuoteLabel()
    {
        $this->assertEquals('a%5C%22z', $this->replacement('a%5C%22z')['label']);
    }

    public function testAReplacementKeepsADirectoryThrottleAndSeedWithAQuoteAndABackslash()
    {
        $sent = $this->replacement('Plain', 'Fo"o\\dir', 'thr"ot\\tle', 'se"ed\\x');
        $this->assertEquals($sent['root'], $sent['directory']);
        $this->assertEquals('thr"ot\\tle', $sent['throttle']);
        $this->assertEquals('se"ed\\x', $sent['seed']);
    }

    public function testAReplacementKeepsAPlainDirectoryThrottleAndSeed()
    {
        $sent = $this->replacement('Plain', 'plain', 'slow', 'seed');
        $this->assertEquals($sent['root'], $sent['directory']);
        $this->assertEquals('slow', $sent['throttle']);
        $this->assertEquals('seed', $sent['seed']);
    }

    public function testRunIgnoresALabelWithAQuote()
    {
        $this->assertTrue($this->runIgnores('Foo"Bar', ['Foo"Bar']));
    }

    public function testRunIgnoresALabelWithABackslash()
    {
        $this->assertTrue($this->runIgnores('C\\D', ['C\\D']));
    }

    public function testRunIgnoresAPlainLabel()
    {
        $this->assertTrue($this->runIgnores('radarr', ['radarr']));
    }

    public function testRunIgnoresALabelRuTorrentEncodedByItsName()
    {
        $this->assertTrue($this->runIgnores('TV%20Shows', ['TV Shows']));
    }

    public function testRunIgnoresALabelRuTorrentEncodedAsStored()
    {
        $this->assertTrue($this->runIgnores('TV%20Shows', ['TV%20Shows']));
    }

    public function testRunMatchesEncodedBackslashQuoteLabel()
    {
        $this->assertTrue($this->runIgnores('a%5C%22z', ['a\\"z']));
    }

    public function testRunDoesNotIgnoreAnUnlistedLabel()
    {
        $this->assertTrue(!$this->runIgnores('Foo"Bar', ['Foo\\"Bar', 'radarr']), 'a label the list does not name is not ignored');
    }

    public function testUpdateIgnoresALabelWithAQuote()
    {
        $this->assertTrue($this->updateIgnores('Foo"Bar', ['Foo"Bar']));
    }

    public function testUpdateIgnoresALabelWithABackslash()
    {
        $this->assertTrue($this->updateIgnores('C\\D', ['C\\D']));
    }

    public function testUpdateMatchesEncodedBackslashQuoteLabel()
    {
        $this->assertTrue($this->updateIgnores('a%5C%22z', ['a\\"z']));
    }

    public function testUpdateDoesNotIgnoreAnUnlistedLabel()
    {
        $this->assertTrue(!$this->updateIgnores('Foo"Bar', ['Foo\\"Bar', 'radarr']), 'a label the list does not name is not ignored');
    }

    private function replacement($label, $directory = 'plain', $throttle = '', $seed = '')
    {
        return dbWithScene(function ($scene) use ($label, $directory, $throttle, $seed) {
            $scene->root = $scene->downloads . '/' . $directory;
            if (!is_dir($scene->root)) {
                mkdir($scene->root, 0700, true);
            }
            $old = dbMultiTorrent($directory, ['a.mkv']);
            $new = dbMultiTorrent($directory, ['a.mkv', 'b.mkv']);
            file_put_contents($scene->root . '/a.mkv', 'a');
            $scene->seedPredecessor($old, ['custom1' => $label, 'throttle_name' => $throttle, 'connection_seed' => $seed]);
            $scene->start();
            $this->assertSame(['result' => null], $scene->replace($new, $old['hash']), 'the replacement commits');
            $state = $scene->state();
            $this->assertTrue(!isset($state['downloads'][$old['hash']]), 'the old download is erased after replacement');
            $successor = $state['downloads'][$new['hash']] ?? [];
            return ['label' => $successor['custom1'] ?? null, 'directory' => $successor['directory_base'] ?? null,
                'throttle' => $successor['throttle_name'] ?? null, 'seed' => $successor['connection_seed'] ?? null,
                'root' => $scene->root];
        });
    }

    private function runIgnores($label, $ignoreLabels)
    {
        return dbWithScene(function ($scene) use ($label, $ignoreLabels) {
            $old = dbMultiTorrent(DB_SPECIAL, ['a.mkv']);
            file_put_contents($scene->root . '/a.mkv', 'a');
            // A recent in-progress state prevents an unmatched test label from
            // making a network request; the ignore branch still runs first.
            $scene->seedPredecessor($old, ['custom1' => $label,
                'customs' => ['chk-state' => '1', 'chk-time' => (string) time()]]);
            $scene->start();
            $answer = $scene->driver->run('$ignoreLabels = ' . var_export($ignoreLabels, true) . ';'
                . ' rsDone(array("result" => ruTrackerChecker::run(' . var_export($old['hash'], true) . ')));');
            $this->assertSame(['result' => true], $answer, 'the checker completes its guarded dispatch');
            $state = $scene->state()['downloads'][$old['hash']]['customs']['chk-state'] ?? null;
            return $state === '8';
        });
    }

    private function updateIgnores($label, $ignoreLabels)
    {
        return dbWithScene(function ($scene) use ($label, $ignoreLabels) {
            $old = dbMultiTorrent(DB_SPECIAL, ['a.mkv']);
            file_put_contents($scene->root . '/a.mkv', 'a');
            $scene->seedPredecessor($old, ['custom1' => $label, 'views' => ['main', 'seeding'],
                'customs' => ['chk-state' => '3', 'chk-time' => '100']]);
            $scene->start();
            $localId = strtoupper(sha1($old['hash'] . '/1'));
            $values = [$old['hash'], '3', '100', $label, '', '', '',
                'http://bt.t-ru.org/ann?pk=x|1|0|5#', $localId];
            $answer = $scene->driver->run('$ignoreLabels = ' . var_export($ignoreLabels, true) . ';'
                . ' require_once("updatepass.php"); $rows = RuTrackerUpdatePass::parseMulticall('
                . var_export($values, true) . '); rsDone(RuTrackerUpdatePass::run($rows));');
            $this->assertSame([], $answer['checked'] ?? null, 'the live-tracker shortcut avoids a remote request');
            $state = $scene->state()['downloads'][$old['hash']]['customs']['chk-state'] ?? null;
            return $state === '8';
        });
    }
}
