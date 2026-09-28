<?php
require_once dirname(__DIR__, 2) . '/plugins/rutracker_check/TestLib.php';
require_once dirname(__DIR__, 3) . '/php/utility/fileutil.php';
$source = getenv('AUTOTOOLS_CLASS_SOURCE') ?: dirname(__DIR__, 3) . '/plugins/autotools/autotools.php';
eval(loadClassDefinition($source, 'rAutoTools'));

$suite = new StrictTestSuite();
$suite->test('destination stays under the configured finished root', function () {
    $root = (getenv('TMPDIR') ?: sys_get_temp_dir()) . '/autotools-boundary-' . bin2hex(random_bytes(8));
    mkdir($root);
    mkdir($root . '/finished');
    mkdir($root . '/outside');
    symlink($root . '/outside', $root . '/finished/link');
    try {
        strictAssertSame(true, rAutoTools::destinationWithinRoot($root . '/finished',
            $root . '/finished/Series/Season'), 'normal nested labels remain supported');
        strictAssertSame(false, rAutoTools::destinationWithinRoot($root . '/finished',
            $root . '/finished/../outside'), 'raw custom1 dot-dot cannot leave finished root');
        strictAssertSame(false, rAutoTools::destinationWithinRoot($root . '/finished',
            $root . '/finished/link/Season'), 'an existing link outside the root is refused');
    } finally {
        unlink($root . '/finished/link');
        rmdir($root . '/outside');
        rmdir($root . '/finished');
        rmdir($root);
    }
});
exit($suite->run());
