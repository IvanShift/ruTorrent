<?php
require_once(__DIR__.'/TorrentTitlesTest.php');
require_once(__DIR__.'/../../php/utility/labelvalue.php');

class LabelChoiceRequest
{
    public static $labels;
    public $val;
    public $fault = false;
    public function __construct($command) { $this->val = self::$labels; }
    public function run() { return true; }
}

class LabelValuesTest extends TestCase
{
    private function response($rows, $previous = array())
    {
        // Reuse the list's real XML parser/cache fixture, including sparse diffs.
        $response = new ReflectionMethod(TorrentTitlesTest::class, 'response');
        if(PHP_VERSION_ID < 80100) $response->setAccessible(true);
        return $response->invoke(new TorrentTitlesTest(), $rows, false, $previous);
    }

    private function row($label, $comment = '', $message = '')
    {
        $row = array_fill(0, 36, '0');
        $row[4] = 'Release'; $row[14] = $label; $row[25] = '/data/Release';
        $row[29] = $message; $row[30] = $comment;
        return $row;
    }

    public function testValidLabelsAndLiteralEscapeTextKeepTheirExistingIdentity()
    {
        foreach(array('', '0', '映画/Серия', 'raw%FF', 'raw\\xFF', "a!'()*~") as $label)
            $this->assertSame($label, LabelValue::fromEncoded(rawurlencode($label)), 'a valid label stays a scalar with no storage conversion');
        $this->assertSame(array('name'=>'raw\\xFF', 'labelKey'=>'raw%FF'), LabelValue::fromEncoded('raw%ff'), 'unknown bytes are escaped and canonicalized without guessing');
        $this->assertSame('padded', LabelValue::fromEncoded('%20padded%20'), 'surrounding whitespace is trimmed as the WebUI trims labels');
        $this->assertSame(array('name'=>'raw\\xFF', 'labelKey'=>'raw%FF'), LabelValue::fromEncoded('%20raw%FF%20'), 'a byte label is trimmed before its key is built');
    }

    public function testListAddsOpaqueLabelsAfterCachingAndKeepsDistinctLiteralText()
    {
        $hash = str_repeat('A', 40); $literal = str_repeat('B', 40);
        $rows = array($hash=>$this->row('raw%FF'), $literal=>$this->row('raw%5CxFF'));
        $result = $this->response($rows);
        $this->assertSame(array($hash=>array('name'=>'raw\\xFF', 'labelKey'=>'raw%FF')), $result['l'], 'the side map only contains labels whose identity cannot be inferred from display');
        $this->assertSame($rows, TorrentTitleCache::$stored, 'raw cache contents do not inherit display projections');
        $this->assertSame('raw%FF', $result['t'][$hash][14], 'the old numeric custom1 slot is unchanged');
        $wire = json_decode(JSON::safeEncode($result), true);
        $this->assertSame($result['l'], $wire['l'], 'JSON round trips both identity and display');
    }

    public function testSparseListOnlyEmitsChangedLabelIdentity()
    {
        $hash = str_repeat('A', 40); $row = $this->row('raw%FF'); $next = $row; $next[11] = '9';
        $result = $this->response(array($hash=>$next), array($hash=>$row));
        $this->assertSame(false, isset($result['l']), 'unchanged labels retain the browser side map without extra payload');
        $next[14] = 'raw%FE';
        $result = $this->response(array($hash=>$next), array($hash=>$row));
        $this->assertSame(array('name'=>'raw\\xFE','labelKey'=>'raw%FE'), $result['l'][$hash], 'a changed raw label replaces its previous identity');
        $next[14] = 'plain';
        $result = $this->response(array($hash=>$next), array($hash=>$row));
        $this->assertSame(false, isset($result['l']), 'a normal replacement is explicitly present in slot14 and needs no opaque value');
        $this->assertSame('plain', $result['t'][$hash][14], 'the client can clear the old opaque value');
    }

    public function testCommentAndMessageDisplayDoesNotAlterRawCache()
    {
        $hash = str_repeat('A', 40);
        foreach(array("raw\xFF", 'VRS24mrker'.rawurlencode("raw\xFF")) as $comment) {
            $row = $this->row('', $comment, "error\xFE");
            $result = $this->response(array($hash=>$row));
            $this->assertSame($row, TorrentTitleCache::$stored[$hash], 'daemon bytes remain the cache identity');
            $this->assertSame('error\\xFE', $result['t'][$hash][29], 'a raw diagnostic remains distinct and visible');
            $actual = $result['t'][$hash][30];
            if(strpos($actual, 'VRS24mrker') === 0) $actual = rawurldecode(substr($actual, 10));
            $this->assertSame('raw\\xFF', $actual, 'both original and custom2 comments are valid display text');
        }
    }

    public function testDirectoryLabelChoicesKeepBothRawAndLiteralSelectionValues()
    {
        $source = file_get_contents(__DIR__.'/../../plugins/_getdir/info.php');
        $start = strpos($source, '\tif(in_array("labels",$modes))');
        // A literal tab is used in the production endpoint.
        if($start === false) $start = strpos($source, "\tif(in_array(\"labels\",\$modes))");
        $branch = substr($source, $start, strpos($source, "\tif(in_array(\"dirlist\",\$modes))", $start)-$start);
        $branch = str_replace('new rXMLRPCRequest(', 'new LabelChoiceRequest(', $branch);
        LabelChoiceRequest::$labels = array('raw%FF', 'raw%5CxFF', '%E6%98%A0%E7%94%BB', '', 'raw%ff', '%20padded%20');
        $modes = array('labels'); $output = array(); eval($branch);
        $this->assertSame(4, count($output['labels']), 'duplicate raw keys collapse; equal display strings remain separate choices');
        $this->assertSame(true, in_array(array('name'=>'raw\\xFF','labelKey'=>'raw%FF'), $output['labels'], true), 'the legacy choice carries its exact raw query value');
        $this->assertSame(true, in_array('raw\\xFF', $output['labels'], true), 'the literal escape text remains an independent choice');
        $this->assertSame(true, in_array('映画', $output['labels'], true), 'valid Unicode stays backward-compatible');
    }

    public function testMissingByteCodecsLeaveTheLabelToTheBrowser()
    {
        $loader = 'require '.var_export(__DIR__.'/../../php/utility/utf.php', true).';'
            .'require '.var_export(__DIR__.'/../../php/utility/labelvalue.php', true).';';
        foreach(array('rawurldecode', 'rawurlencode', 'trim') as $disabled) {
            $code = $loader.'exit(LabelValue::fromEncoded("raw%FF") === null ? 0 : 1);';
            $result = PhpProcess::run(array_merge(PhpProcess::sameIni(), array('-d', 'xdebug.mode=off', '-d', 'disable_functions='.$disabled, '-r', $code)));
            $this->assertSame(0, $result['code'], 'PHP NULL and Error both give no label value: '.$disabled);
            $this->assertSame('', $result['out'].$result['err'], 'the missing codec prints nothing: '.$disabled);
        }
    }
}
