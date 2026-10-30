<?php

require_once(__DIR__ . '/boundary.php');

/** Shared start-seeding publication for the two create task implementations. */
final class CreateSeeding
{
    public static function publish($torrent, array $request, $profileMask, $origin)
    {
        $fname = FileUtil::getUniqueUploadedFilename($torrent->info['name'] . '.torrent');
        $path_edit = trim($request['path_edit']);
        if (is_dir($path_edit)) $path_edit = FileUtil::addslash($path_edit);
        // action.php checked the tree before the creator started, but links
        // can change while it hashes, and rtorrent is about to serve them.
        $refusal = CreateBoundary::refusal($path_edit, $entry);
        if ($refusal !== null) {
            CreateBoundary::log($origin . ': seeding', $refusal, $entry);
            echo 'Seeding refused (' . $refusal . '): ' . JSON::safeEncode($entry) . "\n";
        } else {
            $path_edit = dirname($path_edit);
            if ($resumed = rTorrent::fastResume($torrent, $path_edit))
                $torrent = $resumed;
            $torrent->save($fname);
            $pendingReceipt = null;
            $load = rTorrent::sendTorrent($torrent, true, true, $path_edit,
                null, true, User::isLocalMode(), true, null, $pendingReceipt, null, true);
            if ($load === null)
                FileUtil::toLog($origin . ': seeding load pending confirmation');
            elseif ($load === false)
                FileUtil::toLog($origin . ': seeding load dispatch failed');
            @chmod($fname, $profileMask & 0666);
        }
    }
}
