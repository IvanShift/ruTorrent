<?php

require_once(__DIR__.'/utility/utf.php');

/** Display legacy metainfo names without changing daemon paths or file indices. */
final class TorrentFileNames
{
    private static function isUtf8($text)
    {
        return UTF::isValid($text);
    }

    public static function needsMetadata($rows)
    {
        foreach($rows as $row)
            if(isset($row[0]) && UTF::isString($row[0]) && !self::isUtf8($row[0])) return true;
        return false;
    }

    public static function needsTitleMetadata($name)
    {
        return UTF::isString($name) && !self::isUtf8($name);
    }

    private static function sourceMatches($source, $hash)
    {
        if(!($source instanceof Torrent)) return false;
        try {
            if($source->errors()) return false;
            $sourceHash = $source->hash_info();
            return is_string($hash) && is_string($sourceHash)
                && preg_match('/\A[0-9A-Fa-f]{40}\z/', $hash) === 1
                && preg_match('/\A[0-9A-Fa-f]{40}\z/', $sourceHash) === 1
                && strcasecmp($sourceHash, $hash) === 0;
        } catch(Throwable $error) {
            return false;
        }
    }

    /** Keep the original wire name for path comparisons; only name is a label. */
    public static function title($name, $hash, $source = false)
    {
        if(!self::needsTitleMetadata($name)) return $name;
        $encoding = null;
        $alias = false;
        if($source instanceof Torrent) {
            try {
                if(@self::sourceMatches($source, $hash)
                    && @is_array($source->info) && isset($source->info['name']) && $source->info['name'] === $name) {
                    $encoding = $source->encoding;
                    if(isset($source->info['name.utf-8']) && @self::component($source->info['name.utf-8']))
                        $alias = $source->info['name.utf-8'];
                }
            } catch(Throwable $error) {
                // An unreadable source cannot remove a live torrent from the list.
            }
        }
        return array('name'=>$alias === false ? self::fallback($name, $encoding) : $alias, 'raw'=>$name);
    }

    private static function component($text)
    {
        return self::isUtf8($text) && $text !== '' && $text !== '.' && $text !== '..'
            && strpos($text, '/') === false && strpos($text, "\0") === false;
    }

    private static function alias($path, $width)
    {
        if(!is_array($path) || count($path) !== $width || array_keys($path) !== range(0, $width-1)) return false;
        foreach($path as $part) if(!self::component($part)) return false;
        return $path;
    }

    private static function escaped($text)
    {
        return UTF::display($text);
    }

    /** A declared encoding is evidence; detector guesses and lossy conversions are not. */
    private static function converted($text, $encoding)
    {
        try {
            if(!UTF::isString($encoding) || @preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $encoding) !== 1) return false;
        } catch(Throwable $error) {
            return false;
        }
        foreach(array('iconv', 'mb_convert_encoding') as $function) {
            try {
                if(!@function_exists($function)) continue;
                if($function === 'iconv') {
                    $result = @iconv($encoding, 'UTF-8', $text);
                    $reverse = UTF::isString($result) ? @iconv('UTF-8', $encoding, $result) : false;
                } else {
                    $result = @mb_convert_encoding($text, 'UTF-8', $encoding);
                    $reverse = UTF::isString($result) ? @mb_convert_encoding($result, $encoding, 'UTF-8') : false;
                }
                if(@self::component($result) && $reverse === $text) return $result;
            } catch(Throwable $error) {
                // PHP 8 throws for disabled functions and unknown encoding names.
            }
        }
        return false;
    }

    private static function fallback($text, $encoding)
    {
        $converted = self::converted($text, $encoding);
        return $converted === false ? self::escaped($text) : $converted;
    }

    /** Return source rows only after checking the requested torrent's identity. */
    private static function sourceFiles($source, $hash, $count, &$encoding)
    {
        $encoding = null;
        if(!($source instanceof Torrent)) return false;
        try {
            if(!self::sourceMatches($source, $hash)
                || !is_array($source->info)) return false;
            $info = $source->info;
            if(isset($info['files'])) $files = $info['files'];
            else {
                if(!isset($info['name'], $info['length']) || !is_string($info['name'])) return false;
                $files = array(array('path'=>array($info['name']), 'length'=>$info['length']));
                if(isset($info['name.utf-8'])) $files[0]['path.utf-8'] = array($info['name.utf-8']);
            }
            if(!is_array($files) || count($files) !== $count || array_keys($files) !== range(0, $count-1)) return false;
            $encoding = $source->encoding;
            return $files;
        } catch(Throwable $error) {
            return false;
        }
    }

    private static function matchingFile($file, $row, $path)
    {
        if(!is_array($file) || !isset($file['path'], $file['length'], $row[3]) || !is_array($file['path'])
            || $file['path'] !== $path || (!is_int($file['length']) && !is_string($file['length']))
            || (!is_int($row[3]) && !is_string($row[3]))) return false;
        return (string)$file['length'] === (string)$row[3];
    }

    /** A download label for one exact live file; never a replacement disk path. */
    public static function fileName($path, $index, $size, $count, $hash, $source = false)
    {
        $parts = explode('/', $path);
        $leaf = $parts[count($parts)-1];
        if(self::isUtf8($leaf)) return $leaf;
        $fallback = self::escaped($leaf);
        if(!is_int($index) || $index < 0 || (!is_int($count) && !is_string($count))
            || preg_match('/\A[0-9]+\z/', (string)$count) !== 1 || $index >= (int)$count) return $fallback;
        $files = self::sourceFiles($source, $hash, (int)$count, $encoding);
        if($files === false || !isset($files[$index])
            || !self::matchingFile($files[$index], array(3=>$size), $parts)) return $fallback;
        $alias = isset($files[$index]['path.utf-8'])
            ? self::alias($files[$index]['path.utf-8'], count($parts)) : false;
        return $alias === false ? self::fallback($leaf, $encoding) : $alias[count($parts)-1];
    }

    /**
     * Ordinary UTF-8 rows retain their wire format. Only legacy names receive
     * a display envelope; pathKey encodes raw components, never an alias.
     * Source acquisition belongs to rTorrent::getSource() in the caller.
     */
    public static function resolve($rows, $hash, $source = false)
    {
        if(!self::needsMetadata($rows) || !function_exists('rawurlencode')) return $rows;
        $files = self::sourceFiles($source, $hash, count($rows), $encoding);
        $names = array();
        $directories = array();
        foreach($rows as $index=>$row) {
            if(!isset($row[0]) || !is_string($row[0]) || self::isUtf8($row[0])) continue;
            $path = explode('/', $row[0]);
            $alias = false;
            $rowEncoding = null;
            if($files !== false && isset($files[$index]) && self::matchingFile($files[$index], $row, $path)) {
                $rowEncoding = $encoding;
                if(isset($files[$index]['path.utf-8'])) $alias = self::alias($files[$index]['path.utf-8'], count($path));
            }
            $display = array();
            $keys = array();
            foreach($path as $depth=>$part) {
                $key = rawurlencode($part);
                if(!is_string($key)) return $rows;
                $keys[] = $key;
                $label = self::isUtf8($part) ? $part
                    : ($alias !== false ? $alias[$depth] : self::fallback($part, $rowEncoding));
                $display[] = $label;
                if($depth < count($path)-1 && !self::isUtf8($part)) {
                    $prefix = implode('/', $keys);
                    if(!array_key_exists($prefix, $directories)) $directories[$prefix] = $label;
                    else if($directories[$prefix] !== $label) $directories[$prefix] = false;
                }
            }
            $names[$index] = array($path, $display, $keys);
        }
        foreach($names as $index=>$name) {
            list($path, $display, $keys) = $name;
            $prefix = array();
            foreach($path as $depth=>$part) {
                $prefix[] = $keys[$depth];
                if($depth < count($path)-1 && !self::isUtf8($part) && $directories[implode('/', $prefix)] === false)
                    $display[$depth] = self::escaped($part);
            }
            $rows[$index][0] = array('name'=>implode('/', $display), 'pathKey'=>implode('/', $keys));
        }
        return $rows;
    }
}
