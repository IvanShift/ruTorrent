<?php

/** A stable reason for refusing a fetched torrent without logging remote text or URL secrets. */
class TorrentFetch
{
	public static function rejectionReason($client, $fetched)
	{
		if(is_object($client) && isset($client->error)
			&& $client->error === Snoopy::CREDENTIAL_REDIRECT_REFUSED)
			return Snoopy::CREDENTIAL_REDIRECT_REFUSED;
		if(is_object($client) && isset($client->status)
			&& is_numeric($client->status) && $client->status >= 100 && $client->status <= 599
			&& !Snoopy::isSuccessfulResponse($client))
			return 'http-status-'.(int)$client->status;
		return $fetched && Snoopy::isSuccessfulResponse($client)
			? 'invalid-torrent-response' : 'fetch-failed';
	}
}
