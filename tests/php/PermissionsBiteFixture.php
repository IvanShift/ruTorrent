<?php

/**
 * Do file permissions actually stop THIS process?
 *
 * Several suites prove a failure path by taking a permission away -- chmod 0555
 * on a directory a write must fail in, 0000 on one a scan must fail to read --
 * and asserting the code under test reports the failure instead of pretending
 * success. Run as root those chmods change nothing that root has to obey: the
 * write succeeds, the code correctly reports success, and the assertion fails
 * while naming a defect that is not there. The suite then reads as broken on a
 * machine where nothing is broken, and a real regression in the same file is
 * hidden behind noise nobody trusts.
 *
 * The question is answered by DOING it, not by asking who we are. posix is not
 * loaded in the shipped Alpine image, so posix_geteuid() is not available to
 * ask with; and the answer is not only about root anyway -- a filesystem
 * mounted without permission enforcement gives the same result for a different
 * reason. Making a directory and trying to use it answers the question that
 * actually matters, whatever the reason behind the answer.
 *
 * Both halves are probed because the suites need both: a write-denied directory
 * that still accepts a write, and a read-denied one that still lists. Either
 * one not biting is enough to make those assertions wrong, so this reports
 * "permissions bite" only when both do.
 */
function testPermissionsBite()
{
    static $bites = null;
    if ($bites !== null) return $bites;

    $root = sys_get_temp_dir() . '/permissions-bite-' . getmypid() . '-' . uniqid('', true);
    if (!@mkdir($root, 0700, true)) {
        // Cannot answer the question. Say the permissions DO bite: that leaves
        // every caller running its assertions, which is the behaviour this
        // project had before the probe existed. A silent skip on an unreadable
        // answer would be the one outcome worse than a noisy failure.
        return $bites = true;
    }

    $unwritable = $root . '/unwritable';
    $unreadable = $root . '/unreadable';
    @mkdir($unwritable, 0700);
    @mkdir($unreadable, 0700);
    @chmod($unwritable, 0555);
    @chmod($unreadable, 0000);

    $writeBites = @file_put_contents($unwritable . '/probe', 'x') === false;
    $readBites = @scandir($unreadable) === false;

    @chmod($unwritable, 0700);
    @chmod($unreadable, 0700);
    @unlink($unwritable . '/probe');
    @rmdir($unwritable);
    @rmdir($unreadable);
    @rmdir($root);

    return $bites = ($writeBites && $readBites);
}

/**
 * True when the caller must skip the permission-denial assertions it was about
 * to make, having said out loud what went unchecked.
 *
 * The message is the point. A guard that quietly disappears on the machine that
 * runs it most is worse than one that fails there: the run stays green and
 * nobody learns that the failure path was never exercised. $what should name
 * the behaviour, not the mechanism -- what a reader of the log needs to know
 * they still have to check somewhere else.
 */
function testSkipUnlessPermissionsBite($what)
{
    if (testPermissionsBite()) return false;
    echo 'NOT CHECKED (file permissions do not stop this process -- running as root, '
        . 'or on a filesystem that does not enforce them): ' . $what . "\n";
    return true;
}
