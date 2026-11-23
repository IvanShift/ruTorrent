<?php

require_once(__DIR__ . '/../../php/TestCase.php');
require_once(__DIR__ . '/DaemonBytesFixture.php');
require_once(__DIR__ . '/../../php/ScriptedCurlFixture.php');
require_once(__DIR__ . '/../../../plugins/rutracker_check/forumindex.php');

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

    public function testOutsideDirectoryIsRefusedBeforeStop()
    {
        foreach (['outside', 'home', 'long', 'zero', 'symlink'] as $variant) {
            dbWithScene(function ($scene) use ($variant) {
                $outside = $scene->base . '/outside';
                mkdir($outside);
                symlink($outside, $scene->downloads . '/escape');
                $directory = [
                    'outside' => $outside,
                    'home' => '~/same',
                    'long' => $scene->downloads . '/' . str_repeat('a', 256),
                    'zero' => '0',
                    'symlink' => $scene->downloads . '/escape',
                ][$variant];
                $old = dbMultiTorrent('old', ['a.mkv']);
                $new = dbMultiTorrent('old', ['a.mkv', 'b.mkv']);
                $scene->seedPredecessor($old, ['directory' => $directory, 'directory_base' => $directory]);
                $scene->start();
                $result = $scene->driver->run('$t = ruTrackerChecker::parseMetainfo(base64_decode('
                    . var_export(base64_encode($new['bytes']), true) . '));'
                    . ' rsDone(array("result" => ruTrackerChecker::createTorrent($t, '
                    . var_export($old['hash'], true) . ')));', ['topDirectory' => $scene->downloads]);
                $state = $scene->state();
                $this->assertSame(6, $result['result'], 'directory refusal is reported');
                $this->assertTrue($state['lifecycle'] === [], 'no stop or erase is sent');
                $this->assertTrue(strpos((string) @file_get_contents($scene->driver->appLog), 'directory-not-reusable: ' . $old['hash']) !== false, 'the classified refusal identifies the predecessor');
                $this->assertTrue(isset($state['downloads'][$old['hash']]), 'predecessor remains loaded');
                $this->assertEquals(1, $state['downloads'][$old['hash']]['state'], 'predecessor remains started');
                $this->assertTrue(!isset($state['downloads'][$new['hash']]), 'successor is not loaded');
            });
        }
    }

    public function testReuseAlsoRefusesAnOutsideDirectoryBeforeStop()
    {
        dbWithScene(function ($scene) {
            $outside = $scene->base . '/outside';
            mkdir($outside);
            $old = dbMultiTorrent('old', ['a.mkv']);
            $new = dbMultiTorrent('old', ['a.mkv', 'b.mkv']);
            $meta = MiniBencode::decode($new['bytes']);
            $meta['value']['comment'] = 'https://example.invalid/topic/1';
            $new['bytes'] = dbBencode($meta['value']);
            $scene->seedPredecessor($old, ['directory' => $outside, 'directory_base' => $outside]);
            $scene->seedPredecessor($new);
            $scene->start();
            $result = $scene->driver->run('$t = ruTrackerChecker::parseMetainfo(base64_decode('
                . var_export(base64_encode($new['bytes']), true) . '));'
                . ' rsDone(array("result" => ruTrackerChecker::createTorrent($t, '
                . var_export($old['hash'], true) . ')));', ['topDirectory' => $scene->downloads]);
            $this->assertSame(6, $result['result'], 'directory refusal is reported');
            $this->assertTrue($scene->state()['lifecycle'] === [], 'reuse does not stop the predecessor');
            $this->assertTrue(strpos(
                (string) @file_get_contents($scene->driver->appLog),
                'directory-not-reusable: ' . $old['hash'],
            ) !== false, 'reuse reaches the directory refusal');
        });
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
                'trackers' => [['url' => 'http://tr2.torrent4me.com.invalid/ann?pk=x', 'enabled' => 1, 'failed' => 0, 'success' => 5]],
                'customs' => ['chk-state' => '3', 'chk-time' => (string) time()]]);
            $scene->start();
            $curl = new ScriptedCurlFixture($scene->base);
            $curl->plan([ScriptedCurlFixture::reply('GET', RuTrackerForumIndex::FEED_URL, 304)]);
            $curl->install();
            try {
                $answer = $scene->driver->run('$ignoreLabels = ' . var_export($ignoreLabels, true) . ';'
                    . ' $pathToExternals["curl"] = ' . var_export($scene->base . '/curl', true) . ';'
                    . ' require "update.php"; rsDone(array("finished" => true));');
                $this->assertSame(['finished' => true], $answer, 'the scheduler finishes');
                $requests = $curl->requests();
                $this->assertSame(1, count($requests), 'only the offline feed request is made');
                $this->assertSame('GET', $requests[0]['method'] ?? null, 'the feed uses GET');
                $this->assertSame(RuTrackerForumIndex::FEED_URL, $requests[0]['url'] ?? null, 'the scripted feed is reached');
            } finally {
                $curl->restore();
            }
            $state = $scene->state()['downloads'][$old['hash']]['customs']['chk-state'] ?? null;
            $this->assertTrue(in_array($state, ['5', '8'], true), 'the offline tracker is unreachable or the label is ignored');
            return $state === '8';
        });
    }
}
