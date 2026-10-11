<?php

require_once(__DIR__ . '/TestLib.php');

// The storage seam selects the two snapshots at the read/locked-write
// boundary. The backoff mutator itself is the shipped implementation.
class RuTrackerState
{
    public static $readSnapshot;
    public static $lockedSnapshot;

    public static function daemonKey() { return 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'; }
    public static function load($name) { return self::$readSnapshot; }
    public static function update($name, $mutator, &$failure = null)
    {
        self::$lockedSnapshot = $mutator(self::$lockedSnapshot);
        return true;
    }
}

eval(loadClassDefinition(testFindRepoRoot() . '/plugins/rutracker_check/state.php',
    'RuTrackerReplacementBackoff'));

$suite = new StrictTestSuite();
$suite->test('a concurrent clear can retire the whole map before this clear gets its lock', function () {
    $hash = str_repeat('A', 40);
    foreach (array(array(), array('replacement_backoff' => 'damaged'),
        array('replacement_backoff' => array('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => 'damaged')),
        array('replacement_backoff' => array('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb' => array('keep' => true)))) as $newer) {
        RuTrackerState::$readSnapshot = array('replacement_backoff' => array('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => array(
            $hash => array('until' => 1800003600, 'length' => 3600),
        )));
        RuTrackerState::$lockedSnapshot = $newer;
        strictAssertSame(false, RuTrackerReplacementBackoff::clear($hash, 1800000000),
            'no matching refusal remains under the lock');
        strictAssertSame($newer, RuTrackerState::$lockedSnapshot,
            'a newer absent, malformed or unrelated daemon map is preserved');
    }
});

exit($suite->run());
