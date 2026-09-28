<?php

require_once(__DIR__ . '/../rutracker_check/TestLib.php');

// Stop at fetch: these tests assert only the generated request, never HTML parsing.
class commonEngine
{
    public $requested;

    public function fetch($url, $encode = 1, $method = 'GET', $content_type = '', $body = '')
    {
        $this->requested = $url;
        return false;
    }
}

require_once(testFindRepoRoot() . '/plugins/extsearch/engines/HDTorrents.php');
require_once(testFindRepoRoot() . '/plugins/extsearch/engines/HDTs.php');

function hdtRequest($engine, $category, $global)
{
    $results = array();
    $engine->action('needle', $category, $results, 1, $global);
    strictAssertTrue(is_string($engine->requested), 'the engine did not request a URL');
    $query = parse_url($engine->requested, PHP_URL_QUERY);
    strictAssertTrue(is_string($query), 'the request has no query');
    parse_str($query, $parameters);
    strictAssertSame('needle', $parameters['search'] ?? null, 'the search query changed');
    return $parameters;
}

$suite = new StrictTestSuite();

$suite->test('HDTorrents movie and TV categories remain separate query values', function () {
    $cases = array(
        array('Movie', false, array('1', '2', '5', '3', '63')),
        array('TV Show', false, array('59', '60', '30', '38')),
        array('movies', true, array('1', '2', '5', '3', '63')),
        array('tv', true, array('59', '60', '30', '38')),
    );
    foreach ($cases as $case) {
        $parameters = hdtRequest(new HDTorrentsEngine(), $case[0], $case[1]);
        strictAssertSame('1', $parameters['active'] ?? null, $case[0] . ' changes active');
        strictAssertSame($case[2], $parameters['category'] ?? null,
            $case[0] . ' does not send each selected category');
    }
});

$suite->test('HDTorrents global all does not change the active filter', function () {
    $parameters = hdtRequest(new HDTorrentsEngine(), 'all', true);
    strictAssertSame('1', $parameters['active'] ?? null, 'all appended to active=1');
    strictAssertTrue(!isset($parameters['category']), 'all gained an explicit category');
});

$suite->test('HDTs XXX 1080p category has a query separator', function () {
    $parameters = hdtRequest(new HDTsEngine(), 'XXX/1080p/i', false);
    strictAssertSame('1', $parameters['active'] ?? null, 'category appended to active=1');
    strictAssertSame(array('48'), $parameters['category'] ?? null,
        'XXX/1080p/i does not send category 48');
});

$suite->test('every HDT category choice keeps a well-formed query', function () {
    foreach (array('HDTorrentsEngine', 'HDTsEngine') as $type) {
        $globalLabels = $type === 'HDTorrentsEngine'
            ? array('all', 'movies', 'tv', 'music') : array('all');
        $choices = array(
            array(array_keys((new $type())->categories), false),
            array($globalLabels, true),
        );
        foreach ($choices as $choice) {
            foreach ($choice[0] as $label) {
                $parameters = hdtRequest(new $type(), $label, $choice[1]);
                strictAssertSame('1', $parameters['active'] ?? null,
                    $type . ' ' . $label . ' changes active');
                foreach ($parameters['category'] ?? array() as $value) {
                    strictAssertTrue(ctype_digit($value),
                        $type . ' ' . $label . ' sends a malformed category: ' . $value);
                }
            }
        }
    }
});

exit($suite->run());
