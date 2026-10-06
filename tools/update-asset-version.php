<?php
// Stamp the assembled deployment once, including third-party plugin/theme assets.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

function asset_version_index($contents, $version)
{
    $contents = preg_replace('/([?&]v=)[^"\'<>\s&]+/', '${1}' . $version, $contents);
    return preg_replace('/(var ruTorrentAssetVersion = ")[^"]*(";)/', '${1}' . $version . '${2}', $contents);
}

try {
    $root = dirname(__DIR__);
    $indexPath = $root . '/index.html';
    if (is_link($indexPath)) throw new RuntimeException('index.html must be a regular file');
    $index = file_get_contents($indexPath);
    if ($index === false || substr_count($index, 'var ruTorrentAssetVersion = "') !== 1) {
        throw new RuntimeException('index.html is missing its asset-version marker');
    }
    $files = [];
    foreach (['js', 'css', 'lang', 'images', 'plugins'] as $directory) {
        $path = $root . '/' . $directory;
        if (!is_dir($path)) continue;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile()) continue;
            if (!preg_match('/\.(js|mjs|css|html?|map|png|gif|jpe?g|svg|webp|ico|woff2?|ttf|eot|wasm)$/i', $file->getFilename())) continue;
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $files[$relative] = $file->getPathname();
        }
    }
    ksort($files, SORT_STRING);
    $hash = hash_init('sha256');
    hash_update($hash, "index.html\0" . asset_version_index($index, 'development') . "\0");
    foreach ($files as $relative => $path) {
        hash_update($hash, $relative . "\0");
        if (!hash_update_file($hash, $path)) throw new RuntimeException('Unable to hash asset: ' . $relative);
        hash_update($hash, "\0");
    }
    $version = substr(hash_final($hash), 0, 16);
    $replacement = asset_version_index($index, $version);
    if ($replacement !== $index) {
        $temporary = tempnam($root, '.asset-version-');
        if ($temporary === false) throw new RuntimeException('Unable to create asset-version temporary file');
        try {
            if (file_put_contents($temporary, $replacement) !== strlen($replacement)
                || !chmod($temporary, fileperms($indexPath) & 0777)
                || !rename($temporary, $indexPath)) {
                throw new RuntimeException('Unable to write the asset-version index');
            }
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
    fwrite(STDOUT, $version . "\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Asset deployment failed: ' . $error->getMessage() . "\n");
    exit(1);
}
