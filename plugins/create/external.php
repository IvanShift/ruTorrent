<?php

class CreateExternalConfiguration
{
    public static function path($creator, $configuredPath)
    {
        // FPM may have no PATH. Apply this default only to the final selection;
        // a creator-only override must not inherit another program's binary.
        if (empty($configuredPath) && $creator === 'mktorrent'
            && is_executable('/usr/local/bin/mktorrent'))
            return '/usr/local/bin/mktorrent';
        return $configuredPath;
    }
}
