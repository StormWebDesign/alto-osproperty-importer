<?php
// /public_html/cli/alto-sync/Logger.php

namespace AltoSync;

class Logger {
    private static $logFile = null;

    /**
     * Initializes the logger with a specific log file path.
     * @param string $filePath The full path to the log file.
     */
    public static function init($filePath) {
        self::$logFile = $filePath;
        // Ensure the directory exists
        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    /**
     * Logs a message to the configured log file.
     * @param string $message The message to log.
     * @param string $level The log level (e.g., INFO, WARNING, ERROR, CRITICAL).
     */
    public static function log($message, $level = 'INFO') {
        if (self::$logFile === null) {
            // Fallback to PHP's error_log if init was not called or failed
            // This happens if the Logger::init() call itself failed or wasn't reached.
            error_log("[NO LOG FILE SET] [" . $level . "] " . $message);
            return;
        }

        $timestamp = date('Y-m-d H:i:s e'); // e for timezone name
        $logEntry = "[" . $timestamp . "] [" . $level . "] " . $message . PHP_EOL;

        // Append to the log file
        // Use FILE_APPEND and LOCK_EX to ensure atomic writes for cron jobs
        if (file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX) === false) {
            // If writing to the custom log file fails, fall back to PHP's default error log
            error_log("[FAILED CUSTOM LOG] [" . $level . "] " . $message);
        }
    }
}