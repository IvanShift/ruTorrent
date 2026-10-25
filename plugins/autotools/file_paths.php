<?php

/** Physical file names belong to the loaded daemon, not the metainfo's display aliases. */
final class AutoToolsFilePaths
{
    private static function validate(array $paths)
    {
        $seen = array();
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || strpos($path, "\0") !== false)
                throw new RuntimeException('manifest-path-invalid');
            try { $parts = @explode('/', $path); }
            catch (Throwable $error) { throw new RuntimeException('manifest-path-invalid', 0, $error); }
            if (!is_array($parts) || !$parts) throw new RuntimeException('manifest-path-invalid');
            foreach ($parts as $part)
                if ($part === '' || $part === '.' || $part === '..')
                    throw new RuntimeException('manifest-path-invalid');
            $key = "p\0" . $path;
            if (isset($seen[$key])) throw new RuntimeException('manifest-path-invalid');
            $seen[$key] = true;
        }
        return $paths;
    }

    private static function single($baseName)
    {
        $paths = self::validate(array($baseName));
        if (strpos($baseName, '/') !== false) throw new RuntimeException('manifest-path-invalid');
        return $paths;
    }

    public static function read($hash, $info, $multi, $baseName)
    {
        // d.base_filename is already the daemon's selected single-file name.
        if (!$multi) return self::single($baseName);
        if (!isset($info['files']) || !is_array($info['files']) || !$info['files'])
            throw new RuntimeException('manifest-empty');
        $request = new rXMLRPCRequest(new rXMLRPCCommand('f.multicall',
            array($hash, '', getCmd('f.get_path='))));
        $request->important = false;
        if (!$request->success() || $request->fault)
            throw new RuntimeException('file-list-unconfirmed');
        return self::fromTable($request->stringTable(1), $info);
    }

    /** Consume a table whose XML row/cell provenance was checked by rXMLRPCRequest. */
    public static function fromTable($rows, $info)
    {
        if (!isset($info['files']) || !is_array($info['files']) || !$info['files'])
            throw new RuntimeException('manifest-empty');
        if (!is_array($rows) || count($rows) !== count($info['files']))
            throw new RuntimeException('file-list-malformed');
        $paths = array();
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== array(0))
                throw new RuntimeException('file-list-malformed');
            $paths[] = $row[0];
        }
        return self::validate($paths);
    }

    /** A synchronous daemon callback cannot issue RPC while the daemon waits for it. */
    public static function forSynchronousHook($info, $multi, $baseName)
    {
        if (!$multi) return self::single($baseName);
        if (!isset($info['files']) || !is_array($info['files']) || !$info['files'])
            throw new RuntimeException('manifest-empty');
        $paths = array();
        foreach ($info['files'] as $file) {
            if (!is_array($file) || !isset($file['path']) || !is_array($file['path']))
                throw new RuntimeException('manifest-path-invalid');
            $hasAlias = @array_key_exists('path.utf-8', $file);
            if (!is_bool($hasAlias)) throw new RuntimeException('manifest-path-invalid');
            if ($hasAlias && $file['path.utf-8'] !== $file['path'])
                throw new RuntimeException('file-list-needs-daemon');
            foreach ($file['path'] as $part)
                if (!is_string($part) || strpos($part, '/') !== false)
                    throw new RuntimeException('manifest-path-invalid');
            $paths[] = implode('/', $file['path']);
        }
        return self::validate($paths);
    }
}
