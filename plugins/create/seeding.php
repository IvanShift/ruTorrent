<?php

/** Shared start-seeding publication for the two create task implementations. */
final class CreateSeeding
{
    public static function publish($torrent, array $request, $profileMask, $origin)
    {
        $fname = FileUtil::getUniqueUploadedFilename($torrent->info['name'] . '.torrent');
        $path_edit = trim($request['path_edit']);
        if (is_dir($path_edit)) $path_edit = FileUtil::addslash($path_edit);
        if (rTorrentSettings::get()->correctDirectory($path_edit)) {
            $path_edit = dirname($path_edit);
            if ($resumed = rTorrent::fastResume($torrent, $path_edit))
                $torrent = $resumed;
            $torrent->save($fname);
            $load = rTorrent::sendTorrent($torrent, true, true, $path_edit,
                null, true, User::isLocalMode());
            if ($load === null)
                FileUtil::toLog($origin . ': seeding load pending confirmation');
            elseif ($load === false)
                FileUtil::toLog($origin . ': seeding load dispatch failed');
            @chmod($fname, $profileMask & 0666);
        }
    }
}
