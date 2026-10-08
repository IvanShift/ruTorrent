<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/utility/hashfault.php');

/**
 * HashFault::isMissing(): the one rule for "this RPC fault proves the torrent
 * is not loaded", shared by rTorrent::pendingLoadStatus(), erasedata's
 * presence probe and the retrackers worker. Those three used to disagree:
 * code and text, five case-folded texts without a code, and code, type and
 * text per daemon family.
 */
class HashFaultTest extends TestCase
{
	// Raw SCGI answers to a call on a hash the daemon does not hold, captured
	// from rTorrent 0.9.8 (xmlrpc-c) and 0.16.21 (TinyXML2); the same bytes
	// pin the retrackers codec in UpdateTest. rTorrent 0.16.25 answered d.hash
	// with the same -500 pair in the L3 lab (2026-10-08).
	const CAPTURED = array(
		'0.9.8' => 'U3RhdHVzOiAyMDAgT0sNCkNvbnRlbnQtVHlwZTogdGV4dC94bWwNCkNvbnRlbnQtTGVuZ3RoOiAzMDUNCg0KPD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4NCjxtZXRob2RSZXNwb25zZT4NCjxmYXVsdD4NCjx2YWx1ZT48c3RydWN0Pg0KPG1lbWJlcj48bmFtZT5mYXVsdENvZGU8L25hbWU+DQo8dmFsdWU+PGk0Pi01MDE8L2k0PjwvdmFsdWU+PC9tZW1iZXI+DQo8bWVtYmVyPjxuYW1lPmZhdWx0U3RyaW5nPC9uYW1lPg0KPHZhbHVlPjxzdHJpbmc+Q291bGQgbm90IGZpbmQgaW5mby1oYXNoLjwvc3RyaW5nPjwvdmFsdWU+PC9tZW1iZXI+DQo8L3N0cnVjdD48L3ZhbHVlPg0KPC9mYXVsdD4NCjwvbWV0aG9kUmVzcG9uc2U+DQo=',
		'0.16.21' => 'U3RhdHVzOiAyMDAgT0sNCkNvbnRlbnQtVHlwZTogdGV4dC94bWwNCkNvbnRlbnQtTGVuZ3RoOiAyODANCg0KPD94bWwgdmVyc2lvbj0iMS4wIj8+PG1ldGhvZFJlc3BvbnNlPjxmYXVsdD48dmFsdWU+PHN0cnVjdD48bWVtYmVyPjxuYW1lPmZhdWx0Q29kZTwvbmFtZT48dmFsdWU+PGk4Pi01MDA8L2k4PjwvdmFsdWU+PC9tZW1iZXI+PG1lbWJlcj48bmFtZT5mYXVsdFN0cmluZzwvbmFtZT48dmFsdWU+PHN0cmluZz5pbnZhbGlkIHBhcmFtZXRlcnM6IGluZm8taGFzaCBub3QgZm91bmQ8L3N0cmluZz48L3ZhbHVlPjwvbWVtYmVyPjwvc3RydWN0PjwvdmFsdWU+PC9mYXVsdD48L21ldGhvZFJlc3BvbnNlPg==',
	);

	// d.hash on a missing hash from upstream master 728790a2 (0.16.25) built
	// with configure --with-xmlrpc-c (L3 capture, 2026-10-08, R4-RT-02).
	const CAPTURED_XMLRPC_C_CURRENT = 'U3RhdHVzOiAyMDAgT0sNCkNvbnRlbnQtVHlwZTogdGV4dC94bWwNCkNvbnRlbnQtTGVuZ3RoOiAzMTkNCg0KPD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz4NCjxtZXRob2RSZXNwb25zZT4NCjxmYXVsdD4NCjx2YWx1ZT48c3RydWN0Pg0KPG1lbWJlcj48bmFtZT5mYXVsdENvZGU8L25hbWU+DQo8dmFsdWU+PGk0Pi01MDM8L2k0PjwvdmFsdWU+PC9tZW1iZXI+DQo8bWVtYmVyPjxuYW1lPmZhdWx0U3RyaW5nPC9uYW1lPg0KPHZhbHVlPjxzdHJpbmc+aW52YWxpZCBwYXJhbWV0ZXJzOiBpbmZvLWhhc2ggbm90IGZvdW5kPC9zdHJpbmc+PC92YWx1ZT48L21lbWJlcj4NCjwvc3RydWN0PjwvdmFsdWU+DQo8L2ZhdWx0Pg0KPC9tZXRob2RSZXNwb25zZT4NCg==';

	public function testTheCapturedDaemonAnswersProveAbsence()
	{
		foreach (self::CAPTURED as $version => $encoded) {
			$raw = base64_decode($encoded, true);
			$parsed = preg_match('~<name>faultCode</name>\s*<value><i[48]>(-?[0-9]+)</i[48]></value>.*?' .
				'<name>faultString</name>\s*<value><string>([^<]*)</string>~s', $raw, $fault) === 1;
			$this->assertTrue($parsed && HashFault::isMissing($fault[1], $fault[2]) &&
				HashFault::isMissing($fault[1], $fault[2], $version === '0.9.8'),
				'the captured ' . $version . ' answer proves absence, in its own generation');
			$this->assertTrue($parsed && !HashFault::isMissing($fault[1], $fault[2], $version !== '0.9.8'),
				'the captured ' . $version . ' answer is not the other generation\'s');
		}
	}

	public function testTheCodeIsCheckedWheneverTheCallerHasIt()
	{
		foreach (array(array('-501', 'Could not find info-hash.'), array(-501, 'Could not find info-hash.'),
			array(null, 'Could not find info-hash.'), array('-500', 'invalid parameters: info-hash not found'),
			array(-500, 'invalid parameters: info-hash not found'),
			array(null, 'invalid parameters: info-hash not found')) as $case)
			$this->assertTrue(HashFault::isMissing($case[0], $case[1]), 'absent: ' . json_encode($case));
		foreach (array(array('-500', 'Could not find info-hash.'), array('-501', 'invalid parameters: info-hash not found'),
			array('-506', 'invalid parameters: info-hash not found'), array('', 'Could not find info-hash.'),
			array(1.5, 'Could not find info-hash.'), array(array(), 'Could not find info-hash.'),
			array(true, 'Could not find info-hash.')) as $case)
			$this->assertTrue(!HashFault::isMissing($case[0], $case[1]), 'no proof: ' . json_encode($case));
	}

	public function testOnlyTheDaemonTextIsProof()
	{
		// The five texts erasedata used to accept, in any case, and padding
		// that php/xmlrpc.php keeps in rawFaultString.
		foreach (array('info-hash not found', 'info-hash not found.', 'could not find info-hash',
			'could not find info-hash.', 'COULD NOT FIND INFO-HASH.', 'INVALID PARAMETERS: INFO-HASH NOT FOUND',
			' invalid parameters: info-hash not found', "Could not find info-hash.\n",
			'invalid parameters: info-hash not found.', 'Access denied: could not find info-hash.',
			'invalid parameters: info-hash not found in protected view', '', 'Some other fault') as $text) {
			$this->assertTrue(!HashFault::isMissing(null, $text) && !HashFault::isMissing('-500', $text) &&
				!HashFault::isMissing('-501', $text), 'no proof: ' . json_encode($text));
		}
		$this->assertTrue(!HashFault::isMissing(null, null) && !HashFault::isMissing(null, array()),
			'a missing text proves nothing');
	}

	// 0.15+ sends the same text from both backends with different codes.
	public function testAnXmlrpcCBuildOfCurrentRtorrentProvesAbsenceWithItsOwnCode()
	{
		$raw = base64_decode(self::CAPTURED_XMLRPC_C_CURRENT, true);
		$parsed = preg_match('~<name>faultCode</name>\s*<value><i4>(-?[0-9]+)</i4></value>.*?' .
			'<name>faultString</name>\s*<value><string>([^<]*)</string>~s', $raw, $fault) === 1 &&
			strpos($raw, '<?xml version="1.0" encoding="UTF-8"?>') !== false;
		$this->assertTrue($parsed && $fault[1] === '-503' && HashFault::isMissing($fault[1], $fault[2]) &&
			HashFault::isMissing($fault[1], $fault[2], false) && !HashFault::isMissing($fault[1], $fault[2], true),
			'the captured xmlrpc-c answer of 0.16.25 proves absence, in the current generation');
		$this->assertTrue($parsed && HashFault::isMissingFromBackend('xmlrpc-c', $fault[1], $fault[2]) &&
			!HashFault::isMissingFromBackend('tinyxml2', $fault[1], $fault[2]),
			'it is the xmlrpc-c backend\'s answer, never TinyXML2\'s');
		foreach (array(array('xmlrpc-c', '-501', HashFault::LEGACY_MESSAGE), array('xmlrpc-c', -503, HashFault::CURRENT_MESSAGE),
			array('tinyxml2', '-500', HashFault::CURRENT_MESSAGE)) as $case)
			$this->assertTrue(HashFault::isMissingFromBackend($case[0], $case[1], $case[2]), 'absent: ' . json_encode($case));
		foreach (array(array('tinyxml2', '-501', HashFault::LEGACY_MESSAGE), array('tinyxml2', '-503', HashFault::CURRENT_MESSAGE),
			array('xmlrpc-c', '-500', HashFault::CURRENT_MESSAGE), array('xmlrpc-c', '-503', HashFault::LEGACY_MESSAGE),
			array('xmlrpc-c', null, HashFault::CURRENT_MESSAGE), array('lua', '-500', HashFault::CURRENT_MESSAGE),
			array(array(), '-500', HashFault::CURRENT_MESSAGE), array('xmlrpc-c', '-503', ' ' . HashFault::CURRENT_MESSAGE)) as $case)
			$this->assertTrue(!HashFault::isMissingFromBackend($case[0], $case[1], $case[2]), 'no proof: ' . json_encode($case));
		$this->assertTrue(!HashFault::isMissing('-503', HashFault::LEGACY_MESSAGE) &&
			!HashFault::isMissing('-502', HashFault::CURRENT_MESSAGE),
			'-503 does not prove the legacy text, and no other code proves the current one');
	}
}
