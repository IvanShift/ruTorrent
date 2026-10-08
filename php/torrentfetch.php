<?php

/** A stable reason for refusing a fetched torrent without logging remote text or URL secrets. */
class TorrentFetch
{
	public static function rejectionReason($client, $fetched)
	{
		if(is_object($client) && isset($client->error)
			&& $client->error === Snoopy::CREDENTIAL_REDIRECT_REFUSED)
			return Snoopy::CREDENTIAL_REDIRECT_REFUSED;
		$status = (is_object($client) && isset($client->status) && is_numeric($client->status))
			? (int)$client->status : 0;
		$error = (is_object($client) && isset($client->error)) ? (string)$client->error : '';
		// Snoopy reports a transport failure in the field that otherwise holds
		// the HTTP status: a socket that did not connect leaves its errno there
		// (ECONNREFUSED is 111), with Snoopy::connect()'s "connection failed (N)"
		// as the error, and a failed curl run leaves its exit code -- any code,
		// 100 and up included (CURLE_TOO_LARGE, and the shell's 126 and 127 when
		// curl cannot be run) -- with one of Snoopy's curl messages. Neither
		// came from the server, so neither is an http-status.
		if(preg_match('/^connection failed \((-?[0-9]+)\)$/D', $error, $match))
			return 'connect-failed errno='.$match[1];
		if(($status > 0) && ((strpos($error, 'Error: cURL ') === 0) || ($error === 'oversized-response')))
			return 'curl-failed exit='.$status;
		if(($status >= 100) && ($status <= 599) && !Snoopy::isSuccessfulResponse($client))
			return 'http-status-'.$status;
		return $fetched && Snoopy::isSuccessfulResponse($client)
			? 'invalid-torrent-response' : 'fetch-failed';
	}
}
