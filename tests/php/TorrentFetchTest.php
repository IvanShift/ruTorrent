<?php

require_once(__DIR__ . '/TestCase.php');
require_once(__DIR__ . '/../../php/Snoopy.class.inc');
require_once(__DIR__ . '/../../php/torrentfetch.php');

final class TorrentFetchTest extends TestCase
{
	public function testRejectionReasonsAreClassifiedWithoutRemoteText()
	{
		$client = new Snoopy();
		$client->status = 200;
		$client->results = '<html>Login required</html>';
		$this->assertEquals('invalid-torrent-response', TorrentFetch::rejectionReason($client, true),
			'2xx HTML is classified as an invalid torrent response');

		$client->status = 503;
		$this->assertEquals('http-status-503', TorrentFetch::rejectionReason($client, true),
			'HTTP error status is recorded without response body');

		$client->status = 200;
		$client->error = Snoopy::CREDENTIAL_REDIRECT_REFUSED;
		$this->assertEquals(Snoopy::CREDENTIAL_REDIRECT_REFUSED,
			TorrentFetch::rejectionReason($client, false),
			'credential redirect refusal keeps its existing classification');

		$client->status = 0;
		$client->error = 'secret token in transport error';
		$this->assertEquals('fetch-failed', TorrentFetch::rejectionReason($client, false),
			'transport failure does not expose remote diagnostic text');
	}
}
