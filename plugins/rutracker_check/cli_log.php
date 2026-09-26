<?php

/**
 * A classified last-resort log for detached checker CLI processes.
 *
 * Their stderr is discarded by the scheduler/launcher. The PHP engine's raw
 * fatal text can contain a tracker URL or other third-party content, so only
 * known-safe reason tokens and the source location reach the shared log.
 */
class RuTrackerCliLog
{
    private static $installed = false;
    private static $scope = '';
    private static $file = '';

    static private function historicalLogFile()
    {
        return sys_get_temp_dir() . '/errors.log';
    }

    static public function install($scope)
    {
        global $log_file;

        if (self::$installed) return;
        self::$scope = in_array($scope, array('update', 'batch_check'), true)
            ? $scope : 'checker';
        // Core util.php must leave fatal logging to this classified handler
        // throughout profile loading, before useConfiguredLog() can run.
        if (!defined('RUTORRENT_FATAL_LOG_IS_MANAGED'))
            define('RUTORRENT_FATAL_LOG_IS_MANAGED', true);
        // Installed before util.php loads configuration. A bootstrap fatal
        // uses the historical path; a successful load switches to the
        // configured application log through useConfiguredLog().
        self::$file = self::historicalLogFile();
        if (isset($log_file)) self::useConfiguredLog();
        else @ini_set('error_log', self::$file);
        // The engine's verbatim fatal line may contain a remote URL/passkey.
        // The shutdown callback writes one bounded, classified line instead.
        @ini_set('log_errors', '0');
        register_shutdown_function(array(__CLASS__, 'onShutdown'));
        self::$installed = true;
    }

    static public function useConfiguredLog()
    {
        global $log_file;
        $configured = is_string($log_file) ? $log_file : '';
        if (preg_match('~^file://(/.+)$~D', $configured, $match))
            $configured = $match[1];
        // Detached stderr is not a usable fallback. An invalid or disabled
        // app-log setting still leaves fatal errors in the historical log.
        self::$file = $configured !== '' && $configured[0] === '/'
            ? $configured : self::historicalLogFile();
        @ini_set('error_log', self::$file);
        // util.php and other bootstrap code may change the engine setting.
        // Never let it write a second raw fatal beside the classified line.
        @ini_set('log_errors', '0');
    }

    static private function reason($message)
    {
        $message = (string) $message;
        // Only this known local class name is useful to identify the P1
        // bootstrap fault. Arbitrary identifiers can be formed from remote
        // input and never belong in the routine application log.
        if (strpos($message, 'Class "XMLRPCPathResolver" not found') !== false)
            return 'missing-class=XMLRPCPathResolver';
        if (strpos($message, 'Class ') !== false
            && strpos($message, ' not found') !== false)
            return 'missing-class';
        if (strpos($message, 'Call to undefined function ') !== false)
            return 'undefined-function';
        if (strpos($message, 'Allowed memory size') !== false)
            return 'memory-limit';
        if (strpos($message, 'Maximum execution time') !== false)
            return 'time-limit';
        return 'php-fatal';
    }

    static public function onShutdown()
    {
        $error = error_get_last();
        if (!is_array($error) || !in_array($error['type'], array(
            E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR,
            E_USER_ERROR, E_RECOVERABLE_ERROR
        ), true)) return;

        $file = substr(preg_replace('/[^A-Za-z0-9_.-]/', '_',
            basename(isset($error['file']) ? (string) $error['file'] : 'unknown')), 0, 64);
        $line = isset($error['line']) ? max(0, (int) $error['line']) : 0;
        $reason = self::reason(isset($error['message']) ? $error['message'] : '');
        $entry = '[' . date('Y-m-d H:i:s') . '] rutracker_check: '
            . self::$scope . ': cycle died: ' . $reason
            . ' at ' . $file . ':' . $line . "\n";
        if (@error_log($entry, 3, self::$file)) return;

        // Prefer the historical log if the configured app log is unavailable.
        // A second fixed file handles an unwritable historical path; syslog
        // remains the final channel independent of detached stderr.
        $entry = rtrim($entry, "\n") . ' log-target-failed' . "\n";
        $historical = self::historicalLogFile();
        if (self::$file !== $historical && @error_log($entry, 3, $historical)) return;
        $fallback = sys_get_temp_dir() . '/rutorrent-checker-cli-errors.log';
        if (@error_log($entry, 3, $fallback)) return;
        if (function_exists('syslog')) {
            @openlog('rutracker_check', LOG_PID, LOG_USER);
            @syslog(LOG_ERR, rtrim($entry, "\n"));
        }
    }
}
