<?php

require_once(__DIR__ . '/RetrackersTestStubs.php');
define('RETRACKERS_IMPORT_ONLY', true);
require_once(__DIR__ . '/../../../plugins/retrackers/update.php');

// One raw SCGI answer through the real restricted codec.
class MissingTargetRawAdapter extends RetrackersDirectRpcAdapter
{
	public $raw;

	protected function send($payload, $plan, &$failure = null, $historical = false)
	{
		$failure = null;
		$decoded = retrackersDecodeRestrictedRawResponse($this->raw, $plan);
		if ($decoded === false) $failure = 'malformed-response';
		return($decoded);
	}
}

/**
 * The worker's missing-target rule is the core HashFault one, held to the
 * generation and integer type of the family that answered.
 */
class MissingTargetFaultTest extends TestCase
{
	// rTorrent 0.9.8 (xmlrpc-c) and 0.16.21 (TinyXML2) on a hash they do not
	// hold; the same captures pin the codec in UpdateTest and HashFaultTest.
	const LEGACY = 'U3RhdHVzOiAyMDAgT0sNCkNvbnRlbnQtVHlwZTogdGV4dC94bWwNCkNvbnRlbnQtTGVuZ3RoOiAzMDUNCg0KPD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4NCjxtZXRob2RSZXNwb25zZT4NCjxmYXVsdD4NCjx2YWx1ZT48c3RydWN0Pg0KPG1lbWJlcj48bmFtZT5mYXVsdENvZGU8L25hbWU+DQo8dmFsdWU+PGk0Pi01MDE8L2k0PjwvdmFsdWU+PC9tZW1iZXI+DQo8bWVtYmVyPjxuYW1lPmZhdWx0U3RyaW5nPC9uYW1lPg0KPHZhbHVlPjxzdHJpbmc+Q291bGQgbm90IGZpbmQgaW5mby1oYXNoLjwvc3RyaW5nPjwvdmFsdWU+PC9tZW1iZXI+DQo8L3N0cnVjdD48L3ZhbHVlPg0KPC9mYXVsdD4NCjwvbWV0aG9kUmVzcG9uc2U+DQo=';
	const CURRENT = 'U3RhdHVzOiAyMDAgT0sNCkNvbnRlbnQtVHlwZTogdGV4dC94bWwNCkNvbnRlbnQtTGVuZ3RoOiAyODANCg0KPD94bWwgdmVyc2lvbj0iMS4wIj8+PG1ldGhvZFJlc3BvbnNlPjxmYXVsdD48dmFsdWU+PHN0cnVjdD48bWVtYmVyPjxuYW1lPmZhdWx0Q29kZTwvbmFtZT48dmFsdWU+PGk4Pi01MDA8L2k4PjwvdmFsdWU+PC9tZW1iZXI+PG1lbWJlcj48bmFtZT5mYXVsdFN0cmluZzwvbmFtZT48dmFsdWU+PHN0cmluZz5pbnZhbGlkIHBhcmFtZXRlcnM6IGluZm8taGFzaCBub3QgZm91bmQ8L3N0cmluZz48L3ZhbHVlPjwvbWVtYmVyPjwvc3RydWN0PjwvdmFsdWU+PC9mYXVsdD48L21ldGhvZFJlc3BvbnNlPg==';

	// rTorrent 0.16.25 (upstream master 728790a2) built --with-xmlrpc-c, d.hash
	// on a missing hash (L3 capture, 2026-10-08): family 1 with the current text.
	const XMLRPC_C_CURRENT = 'U3RhdHVzOiAyMDAgT0sNCkNvbnRlbnQtVHlwZTogdGV4dC94bWwNCkNvbnRlbnQtTGVuZ3RoOiAzMTkNCg0KPD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4NCjxtZXRob2RSZXNwb25zZT4NCjxmYXVsdD4NCjx2YWx1ZT48c3RydWN0Pg0KPG1lbWJlcj48bmFtZT5mYXVsdENvZGU8L25hbWU+DQo8dmFsdWU+PGk0Pi01MDM8L2k0PjwvdmFsdWU+PC9tZW1iZXI+DQo8bWVtYmVyPjxuYW1lPmZhdWx0U3RyaW5nPC9uYW1lPg0KPHZhbHVlPjxzdHJpbmc+aW52YWxpZCBwYXJhbWV0ZXJzOiBpbmZvLWhhc2ggbm90IGZvdW5kPC9zdHJpbmc+PC92YWx1ZT48L21lbWJlcj4NCjwvc3RydWN0PjwvdmFsdWU+DQo8L2ZhdWx0Pg0KPC9tZXRob2RSZXNwb25zZT4NCg==';

	private static function reframed($raw, $from, $to)
	{
		$body = str_replace($from, $to, substr($raw, strpos($raw, "\r\n\r\n") + 4));
		return("Status: 200 OK\r\nContent-Type: text/xml\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
	}

	private function classify($raw)
	{
		$adapter = new MissingTargetRawAdapter();
		$adapter->raw = $raw;
		$failure = null;
		$this->assertTrue($adapter->sourceGenericMapSnapshot(str_repeat('A', 40), $failure) === false,
			'a fault is never a map');
		return($failure);
	}

	public function testEachFamilyAcceptsOnlyItsOwnMissingHashAnswer()
	{
		$legacy = base64_decode(self::LEGACY, true);
		$current = base64_decode(self::CURRENT, true);
		$this->assertEquals('target-absent', $this->classify($legacy), 'the captured 0.9.8 answer');
		$this->assertEquals('target-absent', $this->classify($current), 'the captured 0.16.21 answer');
		// The current text and code on the legacy family, and the reverse,
		// each in that family's own integer type: no daemon answers so.
		$this->assertEquals('rpc-fault', $this->classify(self::reframed($legacy,
			array('<i4>-501</i4>', 'Could not find info-hash.'),
			array('<i4>-500</i4>', 'invalid parameters: info-hash not found'))),
			'0.9.8 never answers with the 0.15+ pair');
		$this->assertEquals('rpc-fault', $this->classify(self::reframed($current,
			array('<i8>-500</i8>', 'invalid parameters: info-hash not found'),
			array('<i8>-501</i8>', 'Could not find info-hash.'))),
			'0.16 never answers with the 0.9.x pair');
	}

	// R4-RT-02: the xmlrpc-c family also serves 0.15+, whose missing hash is -503.
	public function testAnXmlrpcCBuildOfCurrentRtorrentIsAbsentToo()
	{
		$this->assertEquals('target-absent', $this->classify(base64_decode(self::XMLRPC_C_CURRENT, true)),
			'the captured xmlrpc-c answer of 0.16.25');
		$current = base64_decode(self::CURRENT, true);
		$this->assertEquals('rpc-fault', $this->classify(self::reframed($current,
			array('<i8>-500</i8>'), array('<i8>-503</i8>'))), 'TinyXML2 never sends the -503 code for it');
		$this->assertEquals('malformed-response', $this->classify(self::reframed(base64_decode(self::XMLRPC_C_CURRENT, true),
			array('<i4>-503</i4>'), array('<i8>-503</i8>'))), 'an i8 fault code is not an xmlrpc-c answer at all');
	}
}
