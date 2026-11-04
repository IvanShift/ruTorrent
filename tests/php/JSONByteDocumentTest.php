<?php

require_once(__DIR__.'/TestCase.php');
require_once(__DIR__.'/../../php/utility/json.php');

class JSONByteDocumentTest extends TestCase
{
	public function testOrdinaryDocumentsKeepTheirExactHistoricalEncoding()
	{
		if(!method_exists('JSON','encodeBytes')) { $this->assertTrue(false, 'the explicit byte-document codec exists'); return; }
		$document = ['path'=>'/中文/🙂/literal%FF', 'files'=>array_fill(0,20000,'file "q" \\ %41'), 'integer'=>PHP_INT_MAX];
		foreach([0,JSON_UNESCAPED_SLASHES,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT] as $flags) {
			$json = JSON::encodeBytes($document,$flags);
			$this->assertSame(json_encode($document,$flags),$json,'large valid documents keep the original bytes and flags');
			$this->assertSame($document,JSON::decodeBytes($json),'ordinary percent strings and Unicode remain literal');
		}
	}

	public function testInvalidValuesAndDictionaryKeysRoundTripWithoutCollisions()
	{
		if(!method_exists('JSON','encodeBytes')) { return; }
		$document = ["raw\xFF"=>["\0key\xFE"=>"path\xFF\\%41", '01'=>'literal', 1=>'integer key', ''=>''],
			'types'=>[null,false,true,PHP_INT_MIN,PHP_INT_MAX,1.0,-1.25,0.0],
			'literal'=>['$rutorrent_bytes'=>1,'data'=>['s','literal%2F']]];
		foreach([0,JSON_UNESCAPED_SLASHES,JSON_INVALID_UTF8_SUBSTITUTE,JSON_PARTIAL_OUTPUT_ON_ERROR,JSON_NUMERIC_CHECK,JSON_FORCE_OBJECT] as $flags) {
			$json = JSON::encodeBytes($document,$flags);
			$this->assertTrue(is_string($json),'invalid byte strings get an explicit JSON envelope');
			$this->assertSame($document,JSON::decodeBytes($json),'every value, raw key, key type and array order survives');
			$this->assertSame(1,preg_match('//u',$json),'the persisted representation itself is valid UTF-8');
		}
	}

	public function testNumericEncodingFlagsCannotRewriteBase64Strings()
	{
		$document=['path'=>base64_decode('1234')];
		$this->assertSame($document,JSON::decodeBytes(JSON::encodeBytes($document,JSON_NUMERIC_CHECK)),
			'a numeric-looking base64 string keeps its string type in the tagged representation');
	}

	public function testMalformedEnvelopesAndAmbiguousKeysAreRefused()
	{
		if(!method_exists('JSON','encodeBytes')) { return; }
		$valid = ['$rutorrent_bytes'=>1,'data'=>['s:eA=='=>1]];
		$badNodes = ['not-an-array',['unknown'=>1],['s:%%%'=>1],['s:YQ==\n'=>1],
			['s:eA=='=>'%%%'],['s:eA=='=>"YQ==\n"],['s:MQ=='=>1],['i:01'=>1],
			['i:-0'=>1],['i:999999999999999999999999'=>1],['s:eA=='=>['bad'=>'YQ==']]];
		foreach($badNodes as $node) {
			$document = $valid; $document['data']=$node;
			$this->assertSame(null,JSON::decodeBytes(json_encode($document)),'invalid tags, base64 and noncanonical keys cannot become a journal');
		}
		foreach([['$rutorrent_bytes'=>2,'data'=>$valid['data']],['$rutorrent_bytes'=>1],
			['$rutorrent_bytes'=>1,'data'=>$valid['data'],'extra'=>true]] as $document) {
			$this->assertSame(null,JSON::decodeBytes(json_encode($document)),'unknown versions and wrapper fields are refused'); }
	}

	public function testUnsupportedOrRecursiveInputsNeverProducePartialJournals()
	{
		if(!method_exists('JSON','encodeBytes')) { return; }
		$recursive = ['bad'=>"\xFF"]; $recursive['self']=&$recursive;
		foreach([['bad'=>"\xFF",'value'=>INF],['bad'=>"\xFF",'value'=>new stdClass()],$recursive] as $document) {
			$this->assertSame(false,JSON::encodeBytes($document),'unsupported or recursive input is refused'); }
	}

	public function testUnavailableCodecsRefuseOnlyTheEncodedRepresentation()
	{
		if(!method_exists('JSON','encodeBytes')) { return; }
		$encoded = JSON::encodeBytes(['bad'=>"\xFF"]);
		$script = 'require '.var_export(__DIR__.'/../../php/utility/json.php',true).';'
			.' echo json_encode(array(JSON::encodeBytes(array("plain"=>"%FF")),JSON::decodeBytes("{\"plain\":\"%FF\"}"),'
			.' JSON::encodeBytes(array("bad"=>chr(255))),JSON::decodeBytes('.var_export($encoded,true).')));';
		foreach(['base64_encode','base64_decode','base64_encode,base64_decode'] as $disabled) {
			$output=[];
			exec(escapeshellarg(PHP_BINARY).' -d disable_functions='.escapeshellarg($disabled).' -r '.escapeshellarg($script).' 2>&1',$output,$status);
			$this->assertSame(0,$status,'disabled byte codecs cannot crash a journal owner');
			$this->assertSame(['{"plain":"%FF"}',['plain'=>'%FF'],false,null],json_decode(implode("\n",$output),true),
				'old literal documents remain readable and unrepresentable byte documents fail closed');
		}
	}

	public function testUnavailableStructuralFunctionsCannotExposeTheTaggedEnvelope()
	{
		$encoded=JSON::encodeBytes(['bad'=>"\xFF"]);
		$script='require '.var_export(__DIR__.'/../../php/utility/json.php',true).';'
			.' echo json_encode(array(JSON::encodeBytes(array("bad"=>chr(255))),JSON::decodeBytes('.var_export($encoded,true).')));';
		foreach(['function_exists','is_string','is_array','array_key_exists','array_keys','is_int','is_bool','is_float','is_finite','substr'] as $disabled) {
			$output=[];
			exec(escapeshellarg(PHP_BINARY).' -d disable_functions='.escapeshellarg($disabled).' -r '.escapeshellarg($script).' 2>&1',$output,$status);
			$this->assertSame(0,$status,'an unavailable structural primitive cannot escape the codec boundary');
			$this->assertSame([false,null],json_decode(implode("\n",$output),true),'an unverified tagged identity is refused rather than exposed as a plain journal');
		}
	}
}
