<?php

// Two unmodified result rows per anonymous live search (2026-09-28).
// Full response SHA-256: Nyaa 7ce6f661494ef8b2cba7034c344595a4801770e3cfb542bbe4a2b74a8619d387;
// Sukebei 6833a38ea2e1fcd063140f597302b25090eeafb1898b072268a437ec8ef2f839.
$_ENV['RU_LOG_FILE'] = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/extsearch-nyaa-' . getmypid() . '.log';
require_once(__DIR__ . '/../../php/TestCase.php');
require_once(dirname(__DIR__, 3) . '/plugins/extsearch/engines.php');
require_once(dirname(__DIR__, 3) . '/plugins/extsearch/engines/Nyaa.php');
require_once(dirname(__DIR__, 3) . '/plugins/extsearch/engines/NyaaSukebe.php');

trait NyaaCapturedFetch
{
    public $bodies = array();
    public $requests = array();

    public function fetch($url, $encode = 1, $method = 'GET', $content_type = '', $body = '')
    {
        $this->requests[] = $url;
        $next = array_shift($this->bodies);
        return $next === null || $next === false ? false : (object) array('results' => $next);
    }
}

class CapturedNyaaEngine extends NyaaEngine { use NyaaCapturedFetch; }
class CapturedSukebeiEngine extends NyaaSukebeEngine { use NyaaCapturedFetch; }

class NyaaFamilyCaptureTest extends TestCase
{
    public function setUp()
    {
        set_error_handler(function ($errno, $message) {
            if (error_reporting() & $errno) throw new ErrorException($message, 0, $errno);
            return false;
        });
    }

    public function tearDown()
    {
        restore_error_handler();
    }

    private function cases()
    {
        return array(
            array('nyaa', new CapturedNyaaEngine(), 'https://nyaa.si'),
            array('sukebei', new CapturedSukebeiEngine(), 'https://sukebei.nyaa.si'),
        );
    }

    private function capture($name)
    {
        return file_get_contents(__DIR__ . '/fixtures/' . $name . '-live-2026-09-28.html');
    }

    public function testLiveRowsKeepParsedIdentityAndFields()
    {
        $expected = array(
            'nyaa' => array(
                array('662993', '393507dc6d47ad0fa3008e49cc946bbaca5e46926f1dfc10ed4fd244f499ebf3',
                    '[CG] Mawaru Penguindrum Linux Desktop [B791DF7F].mkv', 'Anime - English-translated',
                    1425659580, 151.3 * 1048576, 1, 0),
                array('662994', 'da9b9fd045601b86821d5403773807598f3184be06833106527206b5e8d13de3',
                    '[CG] Mawaru Penguindrum Linux Desktop [AF0C2336].mp4', 'Anime - English-translated',
                    1425659580, 99.4 * 1048576, 1, 0),
            ),
            'sukebei' => array(
                array('4271950', '8d622ba79cc998e2441f1ad9f04db41b4de78d6ad1246a599c5835b63cbb6f1b',
                    '[ScrewThisNoise][Circle2Labs] PassionEye Early Access BetterRepack R0.2 LINUX (v0.1.15.0)',
                    'Art - Games', 1742014035, 2.6 * 1073741824, 11, 0),
                array('4681503', 'cd19de34f36549c183ae46e478f755bdee24846d1654dbe424f4e91d64b2316a',
                    '(同人アニメ)[260812][LinuxDx] Mona’s video', 'Art - Anime',
                    1786650172, 1.2 * 1073741824, 5, 2),
            ),
        );
        foreach ($this->cases() as list($name, $engine, $url)) {
            $engine->bodies = array($this->capture($name));
            $results = array();
            $engine->action('linux', 'all', $results, 10, true);
            $this->assertSame(2, count($results), $name . ' must parse both live rows');
            $this->assertSame(array(
                $url . '/?c=0_0&q=linux&s=seeders&o=desc&p=1',
                $url . '/?c=0_0&q=linux&s=seeders&o=desc&p=2',
            ), $engine->requests, $name . ' must stop after its first failed page');
            if (count($results) !== 2) continue;
            $links = array_keys($results);
            $items = array_values($results);
            foreach ($expected[$name] as $i => $row) {
                list($id, $linkHash, $title, $category, $time, $size, $seeds, $peers) = $row;
                $this->assertSame($linkHash, hash('sha256', $links[$i]), $name . ' magnet link ' . $id);
                $this->assertSame($url . '/view/' . $id, $items[$i]['desc'], $name . ' detail URL ' . $id);
                $this->assertSame($title, $items[$i]['name'], $name . ' title ' . $id);
                $this->assertSame($category, $items[$i]['cat'], $name . ' category ' . $id);
                $this->assertSame($time, $items[$i]['time'], $name . ' time ' . $id);
                $this->assertTrue(abs($size - $items[$i]['size']) < 1, $name . ' size ' . $id);
                $this->assertSame($seeds, $items[$i]['seeds'], $name . ' seeders ' . $id);
                $this->assertSame($peers, $items[$i]['peers'], $name . ' peers ' . $id);
            }
        }
    }

    public function testMissingOrInvalidTimestampUsesVisibleDate()
    {
        foreach (array('', '01', '999999999999999999999999999') as $value) {
            $html = $this->capture('sukebei');
            $replacement = $value === '' ? '' : 'data-timestamp="' . $value . '"';
            $html = str_replace('data-timestamp="1742014035"', $replacement, $html);
            $engine = new CapturedSukebeiEngine();
            $engine->bodies = array($html);
            $results = array();
            $engine->action('linux', 'all', $results, 1, true);
            $this->assertSame(1, count($results), 'fallback row must still parse');
            $this->assertSame(1742014020, array_values($results)[0]['time'],
                'missing or invalid exact timestamp must use the visible UTC date');
        }
    }

    public function testUnknownSiteCategoryUsesAllCategoryQuery()
    {
        foreach ($this->cases() as list($name, $engine, $url)) {
            $engine->bodies = array($this->capture($name));
            $results = array();
            $engine->action('linux', 'unknown-category', $results, 1, false);
            $this->assertSame($url . '/?c=0_0&q=linux&s=seeders&o=desc&p=1', $engine->requests[0],
                $name . ' unknown category must not omit c=0_0');
            $this->assertSame(1, count($results), $name . ' limit one');
        }
    }

    public function testSeparateGlobalCategoriesAndPageCeiling()
    {
        foreach (array(
            array('nyaa', new CapturedNyaaEngine(), 'games', '6_2'),
            array('sukebei', new CapturedSukebeiEngine(), 'art', '1_0'),
        ) as list($name, $engine, $category, $value)) {
            $engine->bodies = array_fill(0, 11, $this->capture($name));
            $results = array();
            $engine->action('linux', $category, $results, 100, true);
            $this->assertSame(10, count($engine->requests), $name . ' page ceiling');
            $this->assertTrue(strpos($engine->requests[0], '?c=' . $value . '&') !== false,
                $name . ' global category');
            $this->assertSame(2, count($results), $name . ' duplicate links across pages');
        }
    }
}
