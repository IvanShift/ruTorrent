<?php

/** The rTorrent command parser's quoting and literal-argument boundary. */
class RtorrentCommandArguments
{
    public static function quote($value)
    {
        // Escape backslashes first so the quote escape is not escaped again.
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    public static function isLiteral($value)
    {
        // The daemon evaluates a leading '$' after removing quotes; NUL ends
        // command text or argv. Neither can represent the caller's literal.
        return is_string($value)
            && (!isset($value[0]) || $value[0] !== '$')
            && strpos($value, "\0") === false;
    }
}
