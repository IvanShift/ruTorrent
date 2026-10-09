<?php
require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');

class BinaryScalarTest extends TestCase
{
	private function decode($body, &$failure)
	{
		$xml = '<?xml version="1.0"?><methodResponse><params><param><value>' . $body .
			'</value></param></params></methodResponse>';
		return(retrackersDecodeRestrictedRawResponse("Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: " .
			strlen($xml) . "\r\n\r\n" . $xml, retrackersRestrictedPlanDirectScalar('string'), $failure));
	}
	public function testNativeBase64NamesPreserveTheirExactBytes()
	{
		foreach (array("\xcf\xf0\xe8\xe2\xe5\xf2", "a\rb", "a\x01b", "a\0b", 'plain') as $bytes) {
			$failure = null;
			$result = $this->decode('<base64>' . base64_encode($bytes) . '</base64>', $failure);
			$this->assertTrue(is_array($result) && $result['value'] === $bytes,
				'native base64 string preserves byte identity');
		}
	}
	public function testInvalidOrOversizedBase64IsRefused()
	{
		foreach (array('YQ', 'YR==', 'YQ===', 'YQ== ', '!', 'AAAA' . str_repeat('A', 1398100)) as $encoded) {
			$failure = null;
			$result = $this->decode('<base64>' . $encoded . '</base64>', $failure);
			$this->assertTrue($result === false, 'noncanonical or oversized binary scalar is refused');
		}
	}
}
