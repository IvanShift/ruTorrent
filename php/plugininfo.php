<?php

/** Shared parsing for plugin.info; each loader supplies its own accepted defaults. */
final class PluginInfo
{
    public static function parse(array $lines, array $info)
    {
        $aliases = array(
            'author' => 'plugin.author',
            'description' => 'plugin.description',
            'remote' => 'rtorrent.remote',
            'need_rtorrent' => 'rtorrent.need',
            'version' => 'plugin.version',
            'runlevel' => 'plugin.runlevel',
        );
        $strings = array('plugin.help', 'plugin.author', 'plugin.description',
            'rtorrent.remote');
        $integers = array('plugin.may_be_shutdowned', 'plugin.may_be_launched',
            'rtorrent.need');
        $floats = array('plugin.version', 'plugin.runlevel');
        $versions = array('rtorrent.version', 'php.version');
        $lists = array('plugin.dependencies', 'rtorrent.external.warning',
            'rtorrent.external.error', 'rtorrent.script.error',
            'rtorrent.php.error', 'web.external.warning', 'web.external.error',
            'php.extensions.warning', 'php.extensions.error');

        foreach ($lines as $line) {
            $fields = explode(':', $line, 2);
            if (count($fields) !== 2) continue;
            $field = trim($fields[0]);
            if (isset($aliases[$field])) $field = $aliases[$field];
            if ($field !== 'plugin.version' && !array_key_exists($field, $info)) continue;
            $value = addcslashes(trim($fields[1]), "\\'\"\n\r\t");
            if (in_array($field, $strings, true)) {
                $info[$field] = $value;
            } elseif (in_array($field, $integers, true)) {
                $info[$field] = intval($value);
            } elseif (in_array($field, $floats, true)) {
                $info[$field] = floatval($value);
            } elseif (in_array($field, $versions, true)) {
                $parts = explode('.', $value);
                $info[$field] = (intval($parts[0]) << 16) +
                    (intval(isset($parts[1]) ? $parts[1] : 0) << 8) +
                    intval(isset($parts[2]) ? $parts[2] : 0);
                $info[$field . '.readable'] = $value;
            } elseif (in_array($field, $lists, true)) {
                $info[$field] = explode(',', $value);
            }
        }
        return($info);
    }

    public static function compare($leftLevel, $leftName, $rightLevel, $rightName)
    {
        $leftLevel = (float)$leftLevel;
        $rightLevel = (float)$rightLevel;
        if ($leftLevel > $rightLevel) return(1);
        if ($leftLevel < $rightLevel) return(-1);
        return(strcmp($leftName, $rightName));
    }
}
