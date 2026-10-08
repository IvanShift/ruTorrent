<?php

// Linux helpers are optional installation artifacts, but their ABI and source
// names are declared once. Missing or malformed metadata must never select a
// different executable or silently bypass process/filesystem identity checks.
class NativeHelpers
{
    public static function manifest($path = null)
    {
        $path = $path === null ? dirname(__DIR__).'/tools/native-helpers.list' : $path;
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) throw new RuntimeException('native-helper-manifest-unreadable');
        $version = null; $flags = null; $helpers = array();
        foreach ($lines as $line) {
            if ($line === '' || $line[0] === '#') continue;
            $fields = explode("\t", $line);
            if (count($fields) !== 2) throw new RuntimeException('native-helper-manifest-invalid');
            list($name, $value) = $fields;
            if ($name === 'version') {
                if ($version !== null || $value !== '1') throw new RuntimeException('native-helper-manifest-version');
                $version = $value;
            } elseif ($name === 'flags') {
                if ($flags !== null || $value === '') throw new RuntimeException('native-helper-manifest-flags');
                $flags = explode(' ', $value);
                foreach ($flags as $flag) {
                    if (!preg_match('/^-[A-Za-z0-9_=.+-]+$/D', $flag))
                        throw new RuntimeException('native-helper-manifest-flags');
                }
            } else {
                if (!preg_match('/^rutorrent-[a-z-]+$/D', $name) || isset($helpers[$name])
                    || !preg_match('~^[A-Za-z0-9_./-]+\.c$~D', $value)
                    || $value[0] === '/' || in_array('..', explode('/', $value), true))
                    throw new RuntimeException('native-helper-manifest-entry');
                $helpers[$name] = $value;
            }
        }
        if ($version === null || $flags === null || !$helpers)
            throw new RuntimeException('native-helper-manifest-incomplete');
        return array('flags'=>$flags, 'helpers'=>$helpers);
    }

    public static function path($name)
    {
        $manifest = self::manifest();
        if (!isset($manifest['helpers'][$name])) throw new RuntimeException('native-helper-unknown');
        return '/usr/local/bin/'.$name;
    }

    public static function optionalPath($name)
    {
        try { return self::path($name); }
        catch (RuntimeException $error) {
            // Availability can disable a dependent operation, never the entire
            // plugin bootstrap or the identity checks that operation requires.
            error_log('native-helper: '.$error->getMessage().' name='.$name
                .'; helper operations disabled; install tools/native-helpers.list');
            return '';
        }
    }

    public static function source($name)
    {
        $manifest = self::manifest();
        if (!isset($manifest['helpers'][$name])) throw new RuntimeException('native-helper-unknown');
        return dirname(__DIR__).'/'.$manifest['helpers'][$name];
    }

    public static function compilerCommand($name, $output, $extra = array())
    {
        $manifest = self::manifest();
        if (!isset($manifest['helpers'][$name])) throw new RuntimeException('native-helper-unknown');
        return array_merge(array('cc'), $manifest['flags'], $extra,
            array('-o', $output, dirname(__DIR__).'/'.$manifest['helpers'][$name]));
    }
}
