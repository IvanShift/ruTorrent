<?php

/** Shared tier construction with a separate adapter for encoded edit form values. */
final class TorrentTrackerTiers
{
    public static function fromLines(array $lines)
    {
        $tiers = [];
        $tier = [];
        $count = 0;
        foreach ($lines as $line) {
            $tracker = trim($line);
            if (strlen($tracker)) {
                $tier[] = $tracker;
                $count++;
            } elseif (count($tier)) {
                $tiers[] = $tier;
                $tier = [];
            }
        }
        if (count($tier)) {
            $tiers[] = $tier;
        }
        return(['tiers' => $tiers, 'count' => $count]);
    }

    public static function fromEditFormValues(array $values)
    {
        return(self::fromLines(array_map('rawurldecode', $values)));
    }
}
