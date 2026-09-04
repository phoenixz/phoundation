<?php

/**
 * Log class
 *
 * This class is the main event logger class
 *
 * @author    Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @license   http://opensource.org/licenses/GPL-2.0 GNU Public License, Version 2
 * @copyright Copyright © 2025 Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @package   Phoundation\Core
 */


declare(strict_types=1);

namespace Phoundation\Core\Log;

use JetBrains\PhpStorm\ExpectedValues;
use PDOStatement;
use Phoundation\Accounts\Config\Exception\ConfigParseFailedException;
use Phoundation\Cli\CliColor;
use Phoundation\Core\Core;
use Phoundation\Core\Interfaces\ArrayableInterface;
use Phoundation\Core\Libraries\Library;
use Phoundation\Core\Log\Exception\LogException;
use Phoundation\Core\Log\Interfaces\LogInterface;
use Phoundation\Data\DataEntries\Interfaces\DataEntryInterface;
use Phoundation\Data\DataEntries\Interfaces\DataIteratorInterface;
use Phoundation\Data\Traits\TraitDataStaticBoolQuiet;
use Phoundation\Data\Traits\TraitDataStaticBoolVerbose;
use Phoundation\Data\Validator\Exception\ValidationFailedException;
use Phoundation\Databases\Sql\QueryBuilder\QueryBuilder;
use Phoundation\Date\PhoDateTime;
use Phoundation\Developer\Debug\Debug;
use Phoundation\Exception\OutOfBoundsException;
use Phoundation\Exception\PhoException;
use Phoundation\Filesystem\Enums\EnumFileOpenMode;
use Phoundation\Filesystem\Exception\FilesystemException;
use Phoundation\Filesystem\Interfaces\PhoFileInterface;
use Phoundation\Filesystem\Interfaces\PhoRestrictionsInterface;
use Phoundation\Filesystem\PhoDirectory;
use Phoundation\Filesystem\PhoFile;
use Phoundation\Filesystem\PhoRestrictions;
use Phoundation\Os\Processes\Commands\Find;
use Phoundation\Utils\Arrays;
use Phoundation\Utils\Json;
use Phoundation\Utils\Numbers;
use Phoundation\Utils\Strings;
use Phoundation\Web\Requests\Request;
use Stringable;
use Throwable;


class Log implements LogInterface
{
    use TraitDataStaticBoolVerbose;
    use TraitDataStaticBoolQuiet;


    /**
     * Used to display only classes and functions in backtraces
     */
    public const int BACKTRACE_DISPLAY_FUNCTION = 1;

    /**
     * Used to display only files and line numbers in backtraces
     */
    public const int BACKTRACE_DISPLAY_FILE = 2;

    /**
     * Used to display both classes and function and files and line numbers in backtraces
     */
    public const int BACKTRACE_DISPLAY_BOTH = 3;

    /**
     * Singleton variable
     *
     * @var Log|null $instance
     */
    protected static ?Log $instance = null;

    /**
     * Sets if logging is enabled or disabled
     *
     * @var bool $enabled
     */
    protected static bool $enabled = true;

    /**
     * Sets if logging to a file is enabled or disabled
     *
     * @var bool $file_enabled
     */
    protected static bool $file_enabled = true;

    /**
     * Sets if logging to a screen is enabled or disabled
     *
     * @var bool $screen_enabled
     */
    protected static bool $screen_enabled = true;

    /**
     * Keeps track of what log files we are logging to
     */
    protected static array $streams = [];

    /**
     * Keeps track of the LOG FAILURE status
     */
    protected static bool $failed = false;

    /**
     * Keeps track of the LOG object being ready
     */
    protected static bool $ready = false;

    /**
     * The current threshold level of the log class. The higher this value, the less will be logged
     *
     * @var int $threshold
     */
    protected static int $threshold;

    /**
     * If true, log messages will have a prefix
     *
     * @var bool $echo_prefix
     */
    protected static string|bool $echo_prefix = false;

    /**
     * The current file where the log class will write to.
     *
     * @var string|null $file
     */
    protected static ?string $file = null;

    /**
     * The current backtrace display configuration
     *
     * @var int $display
     */
    protected static int $display = self::BACKTRACE_DISPLAY_BOTH;

    /**
     * Keeps track of if the static object has been initialized or not
     *
     * @var bool $init
     */
    protected static bool $init = false;

    /**
     * The last message that was logged.
     *
     * @var mixed $last_message
     */
    protected static mixed $last_message = null;

    /**
     * Lock the Log class from writing in case it is busy to avoid race conditions
     *
     * @var bool $lock
     */
    protected static bool|array $lock = false;

    /**
     * If true, double log messages will be filtered out (not recommended, this might hide issues)
     *
     * @var bool $filter_double
     */
    protected static bool $filter_double = false;

    /**
     * Log file access restrictions
     *
     * @var PhoRestrictionsInterface $restrictions
     */
    protected static PhoRestrictionsInterface $restrictions;

    /**
     * Tracks whether the syslog filter ini setting has been applied
     *
     * @var bool $syslog_filter_applied
     */
    protected static bool $syslog_filter_applied = false;

    /**
     * Tracks whether the syslog is open or not
     *
     * @var bool $syslog_open
     */
    protected static bool $syslog_open = false;

    /**
     * Tracks if a newline was the last character, so a prefix will be printed
     *
     * @var bool $newline_done
     */
    protected static bool $newline_done = true;

    /**
     * Tracks the time fraction to use for log entries. Must be one of "v" (milliseconds, default), or "u" (microseconds), or "none" for no timestamp
     *
     * @var string
     */
    protected static string $precision;


    /**
     * Log constructor
     */
    protected function __construct()
    {
        // Ensure that the log class  has not been initialized yet
        if (static::$init) {
            return;
        }

        static::$init = true;

        // Apply configuration
        try {
            // Determine log threshold
            if (!isset(static::$threshold)) {
                if (Debug::isEnabled()) {
                    // Debug shows a bit more
                    $threshold = config()->getInteger('log.threshold', Core::errorState() ? 1 : 3);

                } else {
                    $threshold = config()->getInteger('log.threshold', Core::errorState() ? 1 : 5);
                }

                if ($threshold === 1) {
                    // Threshold is at lowest, this will log a LOT
                    if (Core::isState('boot')) {
                        // Boot time logging should not be too much
                        // TODO How will this be set back to 1 again? That should be commented at the very least
                        $threshold = 5;
                    }
                }

                static::setThreshold($threshold);
            }

            static::$restrictions = PhoRestrictions::newWritable(DIRECTORY_DATA . 'log/');
            static::setFile(config()->get('log.file', DIRECTORY_ROOT . 'data/log/syslog'));
            static::setBacktraceDisplay(config()->get('log.backtrace-display', self::BACKTRACE_DISPLAY_BOTH));

        } catch (Throwable $e) {
            // Likely configuration read failed. Set defaults
            static::$restrictions = PhoRestrictions::new(DIRECTORY_DATA . 'log/', true, 'Log');
            static::setThreshold(1); // Since somehting went wrong, log everything
            static::setFile(DIRECTORY_ROOT . 'data/log/syslog');
            static::setBacktraceDisplay(self::BACKTRACE_DISPLAY_BOTH);
        }

        static::$init = false;
    }


    /**
     * Returns if logging is enabled or not
     *
     * @return bool
     */
    public static function getEnabled(): bool
    {
        return static::$enabled;
    }


    /**
     * Set the local id parameter.
     *
     * 1 BACKTRACE_DISPLAY_FUNCTION
     * 2 BACKTRACE_DISPLAY_FILE
     * 3 BACKTRACE_DISPLAY_BOTH
     *
     * @note This method also allows $display defined as their string names (for easy configuration purposes)
     *
     * @param string|int $display The new display configuration
     *
     * @return int The previous value
     */
    public static function setBacktraceDisplay(#[ExpectedValues(values: [
        'BACKTRACE_DISPLAY_FUNCTION',
        'BACKTRACE_DISPLAY_FILE',
        'BACKTRACE_DISPLAY_BOTH',
        Log::BACKTRACE_DISPLAY_FUNCTION,
        Log::BACKTRACE_DISPLAY_FILE,
        Log::BACKTRACE_DISPLAY_BOTH,
    ])] string|int $display): int
    {
        switch ($display) {
            case 'BACKTRACE_DISPLAY_FUNCTION':
                // no break

            case self::BACKTRACE_DISPLAY_FUNCTION:
                $display = self::BACKTRACE_DISPLAY_FUNCTION;
                break;

            case 'BACKTRACE_DISPLAY_FILE':
                // no break

            case self::BACKTRACE_DISPLAY_FILE:
                $display = self::BACKTRACE_DISPLAY_FILE;
                break;

            case 'BACKTRACE_DISPLAY_BOTH':
                // no break

            case self::BACKTRACE_DISPLAY_BOTH:
                $display = self::BACKTRACE_DISPLAY_BOTH;
                break;

            default:
                throw new OutOfBoundsException(tr('Invalid backtrace display value ":display" specified. Please ensure it is one of Log::BACKTRACE_DISPLAY_FUNCTION, Log::BACKTRACE_DISPLAY_FILE, or Log::BACKTRACE_DISPLAY_BOTH', [
                    ':display' => $display,
                ]));
        }

        $return          = static::$display;
        static::$display = $display;

        return $return;
    }


    /**
     * Log to PHP error console
     *
     * @param ArrayableInterface|array|string $messages
     * @param int                             $message_type
     * @param string|null                     $destination
     * @param string|null                     $additional_headers
     *
     * @return void
     * @todo Improve handling of logging that does not go through syslog
     *
     */
    public static function toAlternateLog(ArrayableInterface|array|string $messages, int $message_type = 4, ?string $destination = null, ?string $additional_headers = null): void
    {
        if (!static::$syslog_filter_applied) {
            ini_set('syslog.filter', 'any');
            static::$syslog_filter_applied = true;
        }

        $additional_headers = $additional_headers ?? config()->get('log.headers', '');

        if ($messages instanceof ArrayableInterface) {
            $messages = $messages->__toArray();
        }

        if (is_array($messages)) {
            foreach ($messages as $message) {
                static::toAlternateLog(Strings::force($message, PHP_EOL));
            }

        } else {
            error_log($messages, $message_type, $destination, $additional_headers);
        }

        if (php_sapi_name() !== 'cli') {
            flush();
        }
    }


    /**
     * Log to PHP syslog
     *
     * @todo Under construction
     *
     * @param string $message
     * @param int    $priority_flags
     * @param int    $open_flags
     * @param int    $facility
     *
     * $priority_flags can be a mix of the following flags:
     *
     * LOG_EMERG   system is unusable
     * LOG_ALERT   action must be taken immediately
     * LOG_CRIT    critical conditions
     * LOG_ERR     error conditions
     * LOG_WARNING warning conditions
     * LOG_NOTICE  normal, but significant, condition
     * LOG_INFO    informational message
     * LOG_DEBUG   debug-level message
     *
     * $open_flags can be a mix of the following flags:
     * LOG_CONS    if there is an error while sending data to the system logger, write directly to the system console
     * LOG_NDELAY  open the connection to the logger immediately
     * LOG_ODELAY  (default) delay opening the connection until the first message is logged
     * LOG_PERROR  print log message also to standard error
     * LOG_PID     include PID with each message
     *
     * $faciltiy
     * LOG_AUTH    security/authorization messages (use LOG_AUTHPRIV instead in systems where that constant is defined)
     * LOG_AUTHPRIV    security/authorization messages (private)
     * LOG_CRON    clock daemon (cron and at)
     * LOG_DAEMON    other system daemons
     * LOG_KERN    kernel messages
     * LOG_LOCAL0 ... LOG_LOCAL7    reserved for local use, these are not available in Windows
     * LOG_LPR    line printer subsystem
     * LOG_MAIL    mail subsystem
     * LOG_NEWS    USENET news subsystem
     * LOG_SYSLOG    messages generated internally by syslogd
     * LOG_USER    generic user-level messages
     * LOG_UUCP    UUCP subsystem
     *
     * @return void
     */
    protected static function sysLog(string $message, int $priority_flags = LOG_INFO, int $open_flags = LOG_CONS | LOG_NDELAY | LOG_ODELAY | LOG_PERROR | LOG_PID, int $facility = LOG_USER): void
    {
        if (!static::$syslog_filter_applied) {
            ini_set('syslog.filter', 'all');
            static::$syslog_filter_applied = true;
        }

        static::$syslog_open = true;
        openlog(PROJECT, $priority_flags, $facility);

        if (static::getScreenEnabled()) {
            syslog($priority_flags, $message);
        }

        if (php_sapi_name() !== 'cli') {
            flush();
        }
    }


    /**
     * Returns true if the Log object is ready for logging operations.
     *
     * @note The Log class CAN already log before its ready (output would go to the system log file instead)
     *
     * @return bool
     */
    public static function isReady(): bool
    {
        return static::$ready;
    }


    /**
     * Returns true if the log is in failed mode and only logging to Log::errorLog()
     *
     * @return bool
     */
    public static function getFailed(): bool
    {
        return static::$failed;
    }


    /**
     * Sets the log into failed mode
     *
     * @return void
     */
    public static function setFailed(): void
    {
        static::$failed = true;
    }


    /**
     * Returns if the static Log object has been initialized or not. This SHOULD always return true.
     *
     * @return bool
     */
    public static function getInit(): bool
    {
        return static::$init;
    }


    /**
     * Returns the last message that was logged
     *
     * @return ?string
     */
    public static function getLastMessage(): ?string
    {
        return static::$last_message;
    }


    /**
     * Returns if log messages have a prefix or not
     *
     * @return bool
     */
    public static function getEchoPrefix(): bool
    {
        return static::$echo_prefix;
    }


    /**
     * Sets if log messages have a prefix or not
     *
     * @param string|bool $echo_prefix
     *
     * @return void
     *
     * @throws OutOfBoundsException if the specified threshold is invalid.
     */
    public static function setEchoPrefix(bool $echo_prefix): void
    {
        static::$echo_prefix = $echo_prefix;
    }


    /**
     * Returns the log threshold on which log messages will pass to log files
     *
     * @return int
     */
    public static function getThreshold(): int
    {
        return static::$threshold;
    }


    /**
     * Returns true if the log threshold is the specified value
     *
     * @param int $threshold
     *
     * @return bool
     */
    public static function hasThreshold(int $threshold): bool
    {
        return static::$threshold === $threshold;
    }


    /**
     * Returns true if the log threshold is passed
     *
     * @param int $threshold
     *
     * @return bool
     */
    public static function passesThreshold(int $threshold): bool
    {
        static::ensureInstance();

        // Get the real level and check if we passed the threshold. If $threshold was negative, the same message may be
        // logged multiple times
        $real_threshold = abs($threshold);

        // Validate the specified log level
        if ($real_threshold > 9) {
            // This is an "always log!" message, which only are displayed if we are running in debug mode
            if (Debug::isEnabled()) {
                if ($real_threshold > 10) {
                    // Yeah, this is not okay
                    static::warning(tr('Invalid log level ":level" specified for the following log message. This level should be set to 1-10', [
                        ':level' => $threshold,
                    ]), 10);
                }
            }
        }

        return $real_threshold >= static::$threshold;
    }


    /**
     * Sets the log threshold level to the newly specified level and will return the previous level.
     *
     * @param int $threshold
     *
     * @return int
     * @throws OutOfBoundsException if the specified threshold is invalid.
     */
    public static function setThreshold(int $threshold): int
    {
        if (!is_numeric($threshold) or ($threshold < 1) or ($threshold > 10)) {
            throw OutOfBoundsException::new(tr('The specified log threshold level ":level" is invalid. Please ensure the level is between 1 and 10', [
                ':level' => $threshold,
            ]))->makeWarning();
        }

        static::$threshold = $threshold;

        return $threshold;
    }


    /**
     * Enables logging
     *
     * @return void
     */
    public static function enable(): void
    {
        static::$enabled = true;
    }


    /**
     * Disables logging
     *
     * @return void
     */
    public static function disable(): void
    {
        static::$enabled = false;
    }


    /**
     * Enables to file logging
     *
     * @return void
     */
    public static function enableFile(): void
    {
        static::$file_enabled = true;
    }


    /**
     * Disables to file logging
     *
     * @return void
     */
    public static function disableFile(): void
    {
        static::$file_enabled = false;
    }


    /**
     * Returns if logging to file is enabled or not
     *
     * @return bool
     */
    public static function getFileEnabled(): bool
    {
        return static::$enabled and static::$file_enabled and PhoFile::getWriteEnabled();
    }


    /**
     * Enables to screen logging
     *
     * @return void
     */
    public static function enableScreen(): void
    {
        static::$screen_enabled = true;
    }


    /**
     * Disables to screen logging
     *
     * @return void
     */
    public static function disableScreen(): void
    {
        static::$screen_enabled = false;
    }


    /**
     * Returns if logging to screen is enabled or not
     *
     * @return bool
     */
    public static function getScreenEnabled(): bool
    {
        return static::$enabled and static::$screen_enabled;
    }


    /**
     * Returns if double messages should be filtered or not
     *
     * @return bool
     */
    public static function getFilterDouble(): bool
    {
        return static::$filter_double;
    }


    /**
     * Sets if double messages shoudl be filtered or not
     *
     * @param bool $filter_double
     */
    public static function setFilterDouble(bool $filter_double): void
    {
        static::$filter_double = $filter_double;
    }


    /**
     * Close the specified log file
     *
     * @param string|null $file
     *
     * @return void
     */
    public static function closeFile(?string $file = null): void
    {
        if ($file === null) {
            // Default log file is always the syslog
            $file = DIRECTORY_ROOT . 'data/log/syslog';
        }

        if (empty(static::$streams[$file])) {
            throw new FilesystemException(tr('Cannot close log file ":file", it was never opened', [':file' => $file]));
        }

        static::$streams[$file]->close();
    }


    /**
     * Returns the backtrace display configuration
     *
     * 1 BACKTRACE_DISPLAY_FUNCTION
     * 2 BACKTRACE_DISPLAY_FILE
     * 3 BACKTRACE_DISPLAY_BOTH
     *
     * @return int
     */
    public static function getBacktraceDisplay(): int
    {
        return static::$display;
    }


    /**
     * Write a success message in the log file
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */
    public static function success(mixed $messages = null, int $threshold = 6, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        return Log::write($messages, 'success', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Write the specified log message to the current log file for this instance
     *
     * @param mixed       $messages     The messages that are to be logged
     * @param string|null $class        The class of message that will be logged. Different classes will show in
     *                                  different colors
     * @param int         $threshold    The threshold level for this message. If the level is lower than the threshold,
     *                                  the message will be dropped and not appear in the log files to avoid clutter
     * @param bool        $clean        If true, the data will be cleaned before written to log. This will avoid (for
     *                                  example) binary data from corrupting the log file
     * @param bool        $echo_newline If true, a newline will be appended at the end of the log line
     * @param string|bool $echo_prefix  If true (default), all log lines will be prefixed with a string containing
     *                                  date-time, local process id, and global process id
     * @param bool        $echo_screen  If true (default), on CLI, the log line will be printed (without prefix) on the
     *                                  command line as well
     *
     * @return bool                     True if the line was written, false if it was dropped
     * @todo Refactor this method, its become too cluttered over time
     */
    public static function write(mixed $messages = null, ?string $class = null, int $threshold = 10, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        if (!static::$enabled) {
            // Logging has been disabled, do not do anything
            return false;
        }

        if (static::$init) {
            // Do not log anything while locked, initializing, or while dealing with a Log internal failure
            // Check if we passed the log threshold. If not, discard the message
            if (!static::passesThreshold($threshold)) {
                return false;
            }

            if (static::$screen_enabled and static::$file_enabled) {
                foreach (Arrays::force($messages, null) as $message) {
                    if ($message instanceof Throwable) {
                        static::toAlternateLog('Phoundation: exception class    : ' . get_class($message));
                        static::toAlternateLog('Phoundation: exception message  : ' . $message->getMessage());
                        static::toAlternateLog('Phoundation: exception location : ' . $message->getFile() . '@' . $message->getLine());

                        $trace = Debug::formatBackTrace($message->getTrace());

                        foreach ($trace as $step) {
                            static::toAlternateLog('Phoundation: exception trace    : ' . $step);
                        }

                        if ($message instanceof PhoException) {
                            static::toAlternateLog('Phoundation: exception data     : ' . Strings::force($message->getData()));
                        }

                        if ($message->getPrevious()) {
                            static::toAlternateLog('Phoundation: previous exception : ');
                            Log::write($message->getPrevious());
                        }

                    } else {
                        static::toAlternateLog('Phoundation: ' . Strings::force($message));
                    }
                }
            }

            return false;
        }

        static::ensureInstance();

        try {
            if (static::$failed) {
                Log::toAlternateLog($messages);
                return false;
            }

            // Do we have a log file setup?
            if (empty(static::$file)) {
                if (static::getFileEnabled()) {
                    throw new LogException(tr('Cannot log, no log file specified'));
                }

                // Log file has not been set, but file logging is disabled, so continue
            }

            // If we received an array, then log each line separately
            if (is_array($messages)) {
                $success = true;

                foreach ($messages as $message) {
                    $success = ($success and Log::write($message, $class, $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen));
                }

                static::$lock = false;

                return $success;

            }

            if (is_object($messages)) {
                // If the message to be logged is an object, then extract the log information from there
                return static::object($messages, $class, $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
            }

            if (static::$lock) {
                static::toAlternateLog(tr('Rejecting next log message to avoid endless loops because Log->write() is locked for another log entry. Check backtrace for Log-> calls within Log->write()'));
                static::toAlternateLog(Strings::force($messages, PHP_EOL));
                static::toAlternateLog(Strings::force(print_r(Debug::getBacktrace(), true), PHP_EOL));

                return false;
            }

            static::$lock = true;

            // Check if we passed the log threshold. If not, discard the message
            if (!static::passesThreshold($threshold)) {
                static::$lock = false;
                return false;
            }

            // Make sure the log message is clean and readable.
            // Do not truncate as we might have huge log messages!
            // If no or an empty class was specified, we do not clean
            if ($class and $clean) {
                $messages = Strings::log($messages, 0);
            }

            // Do not log the same message twice in a row
            if (($threshold > 0) and (static::$last_message === $messages) and (static::$filter_double)) {
                static::$lock = false;
                return false;
            }

            static::$last_message = $messages;

            // If logging to the standard log output failed, or we are initializing the log, then write to the system log
            if (static::$failed) {
                static::toAlternateLog(Strings::force($messages));
                static::$lock = false;

                return true;
            }

            // Build the message to be logged, clean it and log
            // The log line format is DATE LEVEL PID GLOBALID/LOCALID MESSAGE EOL
            if ($clean) {
                $messages = Strings::cleanWhiteSpace((string) $messages);
            }

            if (!$messages) {
                if (!is_numeric($messages)) {
                    // Do not log empty messages
                    static::$lock = false;

                    if (Debug::isEnabled()) {
                        // Log where this empty log message came from
                        Log::warning(ts('Encountered an empty log message at ":call"', [
                            ':call' => Debug::getCall(null, Log::class)->getLocation()
                        ]));
                    }
                }

                // This is 0 or 0.0
                $messages = (string) $messages;
            }

            // Add coloring for easier reading
            $messages  = CliColor::apply((string) $messages, $class);
            $messages .= ($echo_newline ? PHP_EOL : null);

            if (!static::$newline_done) {
                $echo_prefix = false;
            }

            // Build message prefix
            // TODO Check max process id in /proc/sys/kernel/pid_max and use that as max length instead of static 7
            if (is_bool($echo_prefix)) {
                $prefix = Log::getPrefix($threshold);

            } else {
                $prefix = $echo_prefix;
            }

            // Write the log message to screen and file
            Log::writeMessage($prefix, $messages, $echo_prefix, $echo_screen, $threshold);

            static::$lock         = false;
            static::$newline_done = $echo_newline;

            return true;

        } catch (Throwable $e) {
            return Log::writeExceptionHandler($e, $messages, $threshold);
        }
    }


    /**
     * Returns a prefix for a log line
     *
     * @param int         $threshold The threshold used to log this line (The number is added into the log prefix line)
     * @param string|null $precision The log time stamp precision. One of "u" (microseconds), v (milliseconds), or "none" (just seconds)
     *
     * @return string
     */
    public static function getPrefix(int $threshold, ?string $precision = null): string
    {
        $precision = $precision ?? Log::getPrecision();

        return match ($precision) {
            'none'  => ($threshold === 10 ? 10 : ' ' . $threshold) . ' ' .
                       Strings::size(getmypid(), 7, ' ', true) . ' ' .
                       Core::getGlobalId() . ' ' . (PLATFORM_CLI ? 'C' : 'W') . ' ' . Core::getLocalId() . (Core::isStateShutdown() ? '#' : ' '),

            default => PhoDateTime::new(null, 'server')
                                  ->format('Y-m-d H:i:s.' . $precision) . ' ' .
                       ($threshold === 10 ? 10 : ' ' . $threshold) . ' ' .
                       Strings::size(getmypid(), 7, ' ', true) . ' ' .
                       Core::getGlobalId() . ' ' . (PLATFORM_CLI ? 'C' : 'W') . ' ' . Core::getLocalId() . (Core::isStateShutdown() ? '#' : ' '),
        };
    }


    /**
     * Returns the time fraction to use for log entry timestamps. Must be one of "v" (milliseconds, default), or "u" (microseconds", or "none" for no timestamp
     *
     * @return string
     */
    #[ExpectedValues(values: ['u', 'v', 'none'])] public static function getPrecision(): string
    {
        if (empty(static::$precision)) {
            static::$precision = config()->getInArray('log.timestamps.precision', ['u', 'v', 'none'], 'v');
        }

        return static::$precision;
    }


    /**
     * Writes the log message to screen and file
     *
     * @param string $prefix
     * @param string $message
     * @param bool   $echo_prefix
     * @param bool   $echo_screen
     * @param int    $threshold
     *
     * @return void
     */
    protected static function writeMessage(string $prefix, string $message, bool $echo_prefix, bool $echo_screen, int $threshold): void
    {
        // Write the message to screen
        if ($echo_screen and (PHP_SAPI === 'cli') and static::getScreenEnabled()) {
            // Only show CLI messages on screen at threshold level in VERBOSE mode, or threshold 10
            if (static::$verbose or (abs($threshold) === 10) or Core::getErrorState()) {
                if (static::$echo_prefix and $echo_prefix) {
                    echo $prefix, $message;

                } else {
                    echo $message;
                }
            }
        }

        // Write the message to the log file
        if (static::getFileEnabled()) {
            if ($echo_prefix) {
                // Write the message to the log file
                static::$streams[static::$file]->write($prefix . $message);

            } else {
                // Write the message to the log file
                static::$streams[static::$file]->write($message);
            }
        }
    }


    /**
     * Singleton, ensure to always return the same Log object.
     *
     * @return void
     */
    protected static function ensureInstance(): void
    {
        try {
            if (!isset(static::$instance)) {
                static::$instance = new static();

                // Log class startup message
                if (Debug::isEnabled() and static::$verbose) {
                    static::information(tr('Logger started, threshold set to ":threshold"', [
                        ':threshold' => static::$threshold,
                    ]), 3);
                }

                static::$ready = true;
            }

        } catch (Throwable $e) {
            // Crap, we could not get a Log instance
            static::$failed = true;
        }
    }


    /**
     * Write an information message in the log file
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */
    public static function information(mixed $messages = null, int $threshold = 7, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        return Log::write($messages, 'information', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Write a warning message in the log file
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */
    public static function warning(mixed $messages = null, int $threshold = 7, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        return Log::write($messages, 'warning', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Write a developer message in the log file
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */
    public static function developer(mixed $messages = null, int $threshold = 7, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        return Log::write($messages, 'warning', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Logs an object in the log file
     *
     * @param object      $object
     * @param string|null $class
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */
    public static function object(object $object, ?string $class = null, int $threshold = 10, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        if ($object instanceof Throwable) {
            // Log exception
            return static::exception($object, $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
        }

        if ($object instanceof ArrayableInterface) {
            // Convert to array
            $message = $object->__toArray();

        } elseif ($object instanceof Stringable) {
            // Convert to string
            $message = (string) $object;

        } else {
            // No idea what to do with this object, so log the class name
            $message = 'Object {' . get_class($object) . '}';
        }

        return Log::write($message, $class, $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Logs current memory usage
     *
     * @param int         $threshold
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */

    public static function memoryUsage(int $threshold = 10, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        return Log::printr(tr('Memory usage :usage :location', [
            ':usage'     => Numbers::getHumanReadableAndPreciseBytes(memory_get_usage()),
            ':location'  => static::getSourceCodeLocationText(include_class_and_file: false)
        ]), $threshold, $echo_prefix, $echo_screen, false);
    }


    /**
     * Logs peak memory usage
     *
     * @param int         $threshold
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */

    public static function memoryUsagePeak(int $threshold = 10, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        return Log::printr(tr('Peak memory usage :usage :location', [
            ':usage'     => Numbers::getHumanReadableAndPreciseBytes(memory_get_peak_usage()),
            ':location'  => static::getSourceCodeLocationText(include_class_and_file: false)
        ]), $threshold, $echo_prefix, $echo_screen, false);
    }


    /**
     * Logs an exception object in the log file
     *
     * @param Throwable|null $exception
     * @param int            $threshold
     * @param bool           $clean
     * @param bool           $echo_newline
     * @param string|bool    $echo_prefix
     * @param bool           $echo_screen
     *
     * @return bool
     */
    public static function exception(?Throwable $exception, int $threshold = 9, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        if ($exception) {
            // This is an exception object, log the warning or error  message data. PHP exceptions have
            // $e->getMessage() and Phoundation exceptions can have multiple messages using $e->getMessages()
            // Redetermine the log class
            if ($exception instanceof PhoException) {
                if ($exception->hasBeenLogged()) {
                    // This exception has already been logged, do not log again
                    return false;
                }

                if ($exception->isWarning()) {
                    // This is a warning exception, which can be displayed to user (usually this is caused by user
                    // data validation issues, etc.
                    $class = 'warning';

                } else {
                    // This is an error exception, which is more severe
                    $class = 'error';
                }

            } else {
                // This is a PHP error, which is always a hard error
                $class = 'error';
            }

            // Log the initial exception message
            Log::write(tr('Message   : '), 'information', $threshold, false, false, echo_screen: $echo_screen);
            Log::write('[E' . ($exception->getCode() ?? 'N/A') . '] ' . $exception->getMessage(), $class, $threshold, false, true, false, $echo_screen);
            Log::exceptionMessages($exception, $class, $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
            Log::write(tr('Location  : '), 'information', $threshold, false, false, echo_screen: $echo_screen);
            Log::write(Strings::from($exception->getFile(), DIRECTORY_ROOT) . '@' . $exception->getLine(), $class, $threshold, true, true, false, $echo_screen);
            Log::write(tr('Exception : '), 'information', $threshold, false, false, echo_screen: $echo_screen);
            Log::write(get_class($exception), $class, $threshold, true, true, false, $echo_screen);
            Log::write(tr('Command   : '), 'information', $threshold, false, false, echo_screen: $echo_screen);

            $has_logged = Log::write(Request::getExecutedPath(true), $class, $threshold, true, true, false, $echo_screen);

            // Log the exception data, the trace, and previous exception, if any.
            Log::exceptionTrace($exception, $class, $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
            Log::exceptionData($exception, $threshold, $clean, $echo_newline, $echo_screen);
            Log::previousException($exception, $class, $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);

            if ($exception instanceof PhoException) {
                $exception->hasBeenLogged($has_logged);
            }

        } else {
            // NULL exception
            Log::write(tr('Exception : '), 'information', $threshold, false, false, echo_screen: $echo_screen);
            Log::write('NULL (a.k.a. There is no exception)', 'error', $threshold, true, true, false, $echo_screen);
        }

        return true;
    }


    /**
     * Logs additional exception messages, if available
     *
     * @param Throwable|null $exception
     * @param string|null    $class
     * @param int            $threshold
     * @param bool           $clean
     * @param bool           $echo_newline
     * @param string|bool    $echo_prefix
     * @param bool           $echo_screen
     *
     * @return bool
     */
    protected static function exceptionMessages(?Throwable $exception, ?string $class = null, int $threshold = 9, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        if ($exception instanceof PhoException) {
            $messages = $exception->getMessages();

            if ($messages) {
                foreach ($messages as $message) {
                    Log::write($message, $class, $threshold, false, true, true, $echo_screen);
                }
            }

            return true;
        }

        return false;
    }


    /**
     * Returns the file to which log messages will be written
     *
     * @return PhoFileInterface
     */
    public static function getFile(): PhoFileInterface
    {
        return new PhoFile(static::$file);
    }


    /**
     * Sets the log threshold level to the newly specified level and will return the previous level. Once a log file has
     * been opened, it will remain open until closed with the Log::closeFile() method
     *
     * @param string|null $file
     *
     * @return string|null
     * @throws LogException if the specified threshold is invalid.
     */
    public static function setFile(?string $file = null): ?string
    {
        if (!static::getFileEnabled()) {
            // Logging to file is disabled, do not set a file
            return static::$file;
        }

        if (static::$failed) {
            // If the log is in failed mode, we cannot switch to a different file
            static::toAlternateLog(tr('Not switching log file to ":file", log is running in failed mode', [
                ':file' => $file,
            ]));

            return static::$file;
        }

        try {
            $return = static::$file;

            if ($file === null) {
                // Default log file is always the syslog
                $file = DIRECTORY_ROOT . 'data/log/syslog';
            }

            // Log file is already open? Close so re-open will ensure that the file exists
            if (isset(static::$streams[$file])) {
                static::$streams[$file]->close(true);
            }

            // Open the specified log file
            static::$streams[$file] = PhoFile::new($file, static::$restrictions)
                                             ->ensureWritable(0640) // Log file should always be 0640
                                             ->setForceAccess(true) // Log file must always be accessible
                                             ->open(EnumFileOpenMode::writeOnlyAppend);

            // Set the class file to the specified file and return the old value and
            static::$file = $file;

        } catch (Throwable $e) {
            // Something went wrong trying to open the log file. Log the error but do continue
            static::$failed = true;
            static::error(tr('Failed to open log file ":file" because of exception ":e"', [
                ':file' => $file,
                ':e'    => $e->getMessage(),
            ]));
        }

        return $return;
    }


    /**
     * Logs the data section of an exception
     *
     * @param Throwable $exception
     * @param int       $threshold
     * @param bool      $clean
     * @param bool      $echo_newline
     * @param bool      $echo_screen
     *
     * @return void
     */
    protected static function exceptionData(Throwable $exception, int $threshold = 10, bool $clean = true, bool $echo_newline = true, bool $echo_screen = true): void
    {
        if ($exception instanceof PhoException) {
            $data = $exception->getData();

            if ($data) {
                Log::write(tr('Data      : '), 'information', $threshold, false, echo_screen: $echo_screen);

                if ($exception->isWarning()) {
                    if (($exception instanceof ValidationFailedException) or !static::$verbose) {
                        // Log warning data as individual lines for easier read
                        foreach (Arrays::force($data, null) as $line) {
                            if (is_array($line)) {
                                $columns = [];

                                foreach ($line as $column) {
                                    if (is_array($column)) {
                                        $columns[] = array_get_safe($column, 'message');
                                    }
                                }

                                $line = implode(', ', $columns);
                            }

                            if ($line) {
                                static::warning($line, $threshold, false, $echo_newline, false, $echo_screen);
                            }
                        }

                    } else {
                        // Log warning data as individual lines for easier read
                        foreach (Arrays::force($data, null) as $line) {
                            Log::write(get_null(var_export($line, true)) ?? '-', 'debug', $threshold, false, $echo_newline, false, $echo_screen);
                        }
                    }

                } else {
                    foreach ($data as $key => $value) {
                        if (is_object($value)) {
                            if ($value instanceof DataEntryInterface) {
                                $value = [
                                    'datatype' => 'object (DataEntryInterface)',
                                    'class'    => $value::class,
                                    'data'     => $value->getSource(),
                                ];
                            }
                        }

                        Log::write($key . ': '                                , 'error', $threshold, false, false, false, $echo_screen);
                        Log::write((get_null(var_export($value, true)) ?? '-'), 'debug', $threshold, false, true , false, $echo_screen);
                    }
                }

            } else {
                Log::write(tr('Data      : '), 'information', $threshold, false, false        , false, $echo_screen);
                Log::write('-'               , 'debug'      , $threshold, false, $echo_newline, false, $echo_screen);
            }
        }
    }


    /**
     * Logs the trace section of an exception
     *
     * @param Throwable   $exception
     * @param string|null $class
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return void
     */
    protected static function exceptionTrace(Throwable $exception, ?string $class = null, int $threshold = 10, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): void
    {
        // Warning exceptions do not need to show the extra messages, trace, or data or previous exception
        if ($class == 'error') {
            // Log the backtrace
            Log::write(tr('Backtrace :'), 'information', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);

            if ($exception->getTrace()) {
                Log::writeTrace($exception->getTrace(), $threshold, class: $class, echo_screen: $echo_screen);

            } else {
                Log::write('-', 'debug', $threshold, false, $echo_newline, $echo_prefix, $echo_screen);
            }
        }
    }




    /**
     * Logs the previous exception from the specified exception, if any
     *
     * @param Throwable   $exception
     * @param string|null $class
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return void
     */
    protected static function previousException(Throwable $exception, ?string $class = null, int $threshold = 10, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): void
    {
        // Log all previous exceptions as well
        $previous = $exception->getPrevious();

        if ($previous) {
            if ($previous instanceof PhoException) {
                // Previous exceptions are always shown
                $previous->hasBeenLogged(false);
            }

            Log::write('Previous exception: ', 'information', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
            static::exception($previous, $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
        }
    }


    /**
     * Dump the specified backtrace data
     *
     * @param array       $backtrace The backtrace data
     * @param int         $threshold The log level for this backtrace data
     * @param int|null    $display   How to display the backtrace. Must be one of Log::BACKTRACE_DISPLAY_FILE,
     *                               Log::BACKTRACE_DISPLAY_FUNCTION or Log::BACKTRACE_DISPLAY_BOTH.
     * @param string      $class
     * @param bool        $echo_screen
     * @param bool        $from_script
     * @param string|bool $echo_prefix
     *
     * @return int The number of lines that were logged. -1 in case of an exception while trying to log the backtrace.
     */
    protected static function writeTrace(array $backtrace, int $threshold = 9, ?int $display = null, string $class = 'debug', bool $echo_screen = true, bool $from_script = true, string|bool $echo_prefix = true): int
    {
        try {
            $lines = Debug::formatBackTrace($backtrace);

            if ($from_script) {
                // Filter out all entries before the script start
                $copy  = $lines;
                $lines = [];

                foreach ($copy as $line) {
                    if (str_contains($line, 'functions.php') and str_contains($line, 'include()')) {
                        break;
                    }

                    $lines[] = $line;
                }
            }

            foreach ($lines as $line) {
                Log::write($line, $class, $threshold, false, echo_prefix: $echo_prefix, echo_screen: $echo_screen);
            }

            return count($lines);

        } catch (Throwable $e) {
            // Do not crash the process because of this, log it and return -1 to indicate an exception
            Log::exception($e);
            Log::error(tr('Failed to write backtrace to log because of exception ":e" cause by backtrace specified below', [
                ':e' => $e->getMessage(),
            ]));
            Log::printr($backtrace);

            return -1;
        }
    }


    /**
     * Write an error message in the log file
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */
    public static function error(mixed $messages = null, int $threshold = 7, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        return Log::write($messages, 'error', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Handles log write exceptions
     *
     * @param Throwable  $e
     * @param mixed|null $messages
     * @param int        $threshold
     *
     * @return bool
     */
    protected static function writeExceptionHandler(Throwable $e, mixed $messages = null, int $threshold = 10): bool
    {
        // Do not ever let the system crash because of a log issue, so we catch all possible exceptions
        static::$lock = false;

        try {
            if (!static::$failed) {
                $message = $threshold . ' ' . getmypid() . ' ' . Core::getGlobalId() . '/' . Core::getLocalId() . ' Failed to log message to internal log files because "' . $e->getMessage() . '" in "' . $e->getFile() . '@' . $e->getLine() . '"';
                static::toAlternateLog($message);
            }

            static::$failed = true;

            try {
                foreach (Arrays::force($messages, null) as $message) {
                    $message = CliColor::strip((string) $message);
                    $message = $threshold . ' ' . getmypid() . ' ' . Core::getGlobalId() . '/' . Core::getLocalId() . ' ' . $message;
                    static::toAlternateLog($message);
                }

            } catch (Throwable $g) {
                // Okay, this is messed up, we cannot even log to system logs.
                static::toAlternateLog('Failed to log message because: ' . $g->getMessage());
            }

        } catch (ConfigParseFailedException $f) {
            // If configuration parsing failed, just throw that exception as adding that logging failed would just confuse people about the issue
            throw $e;

        } catch (Throwable $f) {
            // Okay WT actual F is going on here? We cannot log to our own files, we cannot log to system files. THIS
            // we will not stand for!
            throw LogException::new('Failed to write to ANY log (Failed to write to both local log files and system log files', $e)
                              ->addData(['original exception' => $e]);
        }

        // We did NOT log
        return false;
    }


    /**
     * Write a notice message in the log file
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */
    public static function notice(mixed $messages = null, int $threshold = 3, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        return Log::write($messages, 'notice', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Write a command line interface message in the log file and to the screen
     *
     * @param mixed       $messages
     * @param string|null $class
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */
    public static function cli(mixed $messages = null, ?string $class = 'cli', int $threshold = 10, bool $clean = false, bool $echo_newline = true, bool $echo_prefix = false, bool $echo_screen = true): bool
    {
        if (is_empty($messages) and !is_numeric($messages)) {
            $messages = ' ';
        }

        switch (OUTPUT) {
            case 'normal':
                if (!is_data_scalar($messages)) {
                    $messages = print_r($messages, true);
                }

                return Log::write($messages, $class, $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);

            case 'json':
                return static::json($messages, $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);

            default:
                throw new OutOfBoundsException(tr('Unknown output type ":output" set, should be one of "normal", or "json"', [
                    ':output' => OUTPUT
                ]));
        }
    }


    /**
     * Write data in JSON format to the log file and the screen
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */
    public static function json(mixed $messages = null, int $threshold = 10, bool $clean = false, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        $messages = Json::encode($messages, JSON_PRETTY_PRINT|JSON_BIGINT_AS_STRING);

        return Log::write($messages, 'notice', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Write a debug message in the log file
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     * @param bool        $echo_header
     *
     * @return bool
     */
    public static function debug(mixed $messages = null, int $threshold = 10, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true, bool $echo_header = true): bool
    {
        $type = gettype($messages);

        switch ($type) {
            case 'array':
                $size = count($messages);
                break;

            case 'boolean':
                $size     = '-';
                $messages = strtoupper(Strings::fromBoolean($messages));
                break;

            case 'string':
                $size = strlen($messages);
                break;

            default:
                // For all other types size does not matter
                $size = '-';
        }

        if (!is_scalar($messages)) {
            if (is_object($messages) and $messages instanceof Throwable) {
                // Convert exception in readable message
                if ($messages instanceof PhoException) {
                    $messages = [
                        'exception' => get_class($messages),
                        'code'      => $messages->getCode(),
                        'messages'  => $messages->getMessages(),
                        'data'      => $messages->getData(),
                    ];

                } else {
                    $messages = [
                        'exception' => get_class($messages),
                        'code'      => $messages->getCode(),
                        'message'   => $messages->getMessage(),
                    ];
                }
            }

        } else {
            // Build the message
            $messages = strtoupper($type) . ' [' . $size . '] ' . $messages;
        }

        if ($echo_header) {
            Log::logDebugHeader('DEBUG', get_class_or_datatype($messages), 1, $threshold, echo_screen: $echo_screen);
        }

        if (empty($messages) and (!is_numeric($messages))) {
            $messages = '-';
        }

        return Log::write(Strings::log($messages, ensure_visible: true), 'debug', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Write a debug message in the log file
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     * @param bool        $echo_header
     *
     * @return bool
     */
    public static function dump(mixed $messages = null, int $threshold = 10, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true, bool $echo_header = true): bool
    {
        if ($echo_header) {
            Log::logDebugHeader('DEBUG', get_class_or_datatype($messages), 1, $threshold, echo_screen: $echo_screen);
        }

        if (empty($messages) and (!is_numeric($messages))) {
            $messages = '-';
        }

        return Log::write(Strings::log($messages, ensure_visible: true), 'debug', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Write a debug message containing the hash and size of the specified message
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     * @param bool        $echo_header
     *
     * @return bool
     */
    public static function hash(mixed $messages = null, int $threshold = 10, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true, bool $echo_header = true): bool
    {
        return Log::debug(hash('sha256', Strings::force($messages), false) . ' / ' . strlen($messages), $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen, $echo_header);
    }


    /**
     * Write a debug header message in the log file
     *
     * @param string      $keyword
     * @param string      $datatype
     * @param int         $trace
     * @param int         $threshold
     * @param bool        $echo_screen
     * @param string|bool $echo_prefix
     *
     * @return bool
     */
    protected static function logDebugHeader(string $keyword, string $datatype, int $trace = 4, int $threshold = 10, bool $echo_screen = true, string|bool $echo_prefix = true): bool
    {
        if (QUIET) {
            // Not logging headers at all!
            return false;
        }

        return Log::write(tr('Showing debug ":datatype" data with ":keyword" at :location', [
            ':keyword'  => $keyword,
            ':location' => static::getSourceCodeLocationText($trace - 1),
            ':datatype' => $datatype,
        ]), 'debug', $threshold, echo_prefix: $echo_prefix, echo_screen: $echo_screen);
    }


    /**
     * Returns a text indicating location in code
     *
     * @param int  $trace
     * @param bool $include_class_and_file
     *
     * @return string
     */
    protected static function getSourceCodeLocationText(int $trace = 1, bool $include_class_and_file = true): string
    {
        // Get the class, method, file and line data.
        $file = Debug::currentFile($trace);
        $file = Strings::from($file, DIRECTORY_ROOT);
        $file = Strings::from($file, DIRECTORY_WEB);
        $file = Strings::from($file, DIRECTORY_COMMANDS);
        $file = Strings::from($file, DIRECTORY_PLUGINS);
        $line = Debug::currentLine($trace);

        if ($include_class_and_file) {
            $class    = Debug::currentClass($trace);
            $function = Debug::currentFunction($trace);

            if ($class) {
                // Add class - method separator
                $class .= '::';
            }

            return tr('":class:function()" in ":file@:line"', [
                ':class'    => $class,
                ':function' => $function,
                ':file'     => $file,
                ':line'     => $line,
            ]);
        }

        return tr('at ":file@:line"', [
            ':file' => $file,
            ':line' => $line,
        ]);
    }


    /**
     * Write a "FUNCTION IS DEPRECATED" message in the log file
     *
     * @param int  $threshold
     * @param bool $echo_screen
     *
     * @return bool
     */
    public static function deprecated(int $threshold = 8, bool $echo_screen = true): bool
    {
        return Log::logDebugHeader('DEPRECATED', 'N/A', 1, $threshold, echo_screen: $echo_screen);
    }


    /**
     * Write a hex encoded message in the log file. All hex codes will be grouped in groups of 2 characters for
     * readability
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     * @param bool        $echo_header
     *
     * @return bool
     */
    public static function hex(mixed $messages = null, int $threshold = 10, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true, bool $echo_header = true): bool
    {
        if ($echo_header) {
            Log::logDebugHeader('HEX', get_class_or_datatype($messages), 1, $threshold, echo_screen: $echo_screen);
        }

        $messages = Strings::force($messages, PHP_EOL);

               Log::write(Strings::interleave($messages, '  ', chunk_size: 1), 'debug', $threshold, false, $echo_newline, $echo_prefix, $echo_screen);
        return Log::write(Strings::interleave(bin2hex($messages), ' ', chunk_size: 2), 'debug', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Write a checkpoint message in the log file.
     *
     * A checkpoint log entry will show when the checkpoint was passed where (class::function in file@line)
     *
     * @param string|float|int|null $messages
     * @param int                   $threshold
     * @param string|bool           $echo_prefix
     * @param bool                  $echo_screen
     *
     * @return bool
     */
    public static function checkpoint(string|float|int|null $messages = null, int $threshold = 10, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        // Get the class, method, file and line data.
        $trace    = 0;
        $messages = Strings::log($messages);
        $file     = Strings::from(Debug::currentFile($trace), DIRECTORY_ROOT);
        $line     = Debug::currentLine($trace);

        return Log::write(tr(':message in :file@:line', [
            ':message'  => trim('CHECKPOINT ' . $messages),
            ':file'     => $file,
            ':line'     => $line,
        ]), 'debug', $threshold, echo_prefix: $echo_prefix, echo_screen: $echo_screen);
    }


    /**
     * Write a debug message trying to format the data in a neat table.
     *
     * @param mixed       $key_value
     * @param int         $indent
     * @param int         $threshold
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */
    public static function table(array $key_value, int $indent = 4, int $threshold = 10, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        return Log::write(Strings::getKeyValueTable($key_value, PHP_EOL, ': ', $indent), 'debug', $threshold, false, false, $echo_prefix, $echo_screen);
    }


    /**
     * Write a debug message using vardump() in the log file
     *
     * @param mixed $messages
     * @param int   $threshold
     * @param bool  $echo_screen
     * @param bool  $echo_header
     *
     * @return bool
     */
    public static function vardump(mixed $messages = null, int $threshold = 10, bool $echo_screen = true, bool $echo_header = true): bool
    {
        if ($echo_header) {
            Log::logDebugHeader('VARDUMP', get_class_or_datatype($messages), 1, $threshold, echo_screen: $echo_screen);
        }

        return Log::write(Debug::dump($messages, 100), 'debug', $threshold, false, echo_screen: $echo_screen);
    }


    /**
     * Write a backtrace message in the log file
     *
     * @note This method has $echo_header default to FALSE as the header contains backtrace information which, well,
     *       this method already displays anyway
     *
     * @param ?int        $display
     * @param array|null  $backtrace
     * @param int         $threshold
     * @param bool        $echo_screen
     * @param bool        $echo_header
     * @param string|bool $echo_prefix
     *
     * @return void
     */
    public static function backtrace(?int $display = null, ?array $backtrace = null, int $threshold = 10, bool $echo_screen = true, bool $echo_header = false, string|bool $echo_prefix = false): void
    {
        if ($backtrace === null) {
            $backtrace = Debug::getBacktrace(1);
        }

        if ($echo_header) {
            Log::logDebugHeader('BACKTRACE', 'N/A', 1, $threshold, echo_screen: $echo_screen, echo_prefix: $echo_prefix);
        }

        Log::writeTrace($backtrace, $threshold, $display, echo_screen: $echo_screen, echo_prefix: $echo_prefix);
    }


    /**
     * Write a debug statistics dump message in the log file
     *
     * @param int $threshold
     *
     * @return bool
     */
    public static function statistics(int $threshold = 10): bool
    {
        // WTH IS THIS? LIBRARY::GETJSON() ???
        return Log::printr(Library::getJson(), $threshold);
    }


    /**
     * Write a debug message using print_r() in the log file
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     * @param bool        $echo_header
     *
     * @return bool
     */
    public static function printr(mixed $messages = null, int $threshold = 10, string|bool $echo_prefix = true, bool $echo_screen = true, bool $echo_header = true): bool
    {
        if ($echo_header) {
            Log::logDebugHeader('PRINTR', get_class_or_datatype($messages), 3, $threshold, echo_screen: $echo_screen);
        }

        if (empty($messages)) {
            if (is_bool($messages)) {
                $messages = Strings::fromBoolean($messages);

            } elseif (($messages !== 0) and ($messages !== 0.0) and ($messages !== '0') and ($messages !== '0.0')) {
                if ($messages === null) {
                    $messages = 'NULL';

                } elseif (!is_array($messages)) {
                    $messages = '>>> EMPTY <<<';
                }
            }

        } elseif (($messages instanceof DataEntryInterface) or ($messages instanceof DataIteratorInterface)) {
            // Make sure log message is not DataEntry type object
            $messages = $messages->getLogData();

        } elseif (is_array($messages)) {
            ksort($messages);

            // Make sure array contents are not DataEntry type objects
            foreach ($messages as &$message) {
                if (($message instanceof DataEntryInterface) or ($message instanceof DataIteratorInterface)) {
                    $message = $message->getLogData();
                }
            }

            unset($message);
        }

        return Log::write(print_r($messages, true), 'debug', $threshold, false, echo_prefix: $echo_prefix, echo_screen: $echo_screen);
    }


    /**
     * Write a debug message using print_r() in the log file
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     * @param bool        $echo_header
     *
     * @return bool
     */
    public static function printJson(mixed $messages = null, int $threshold = 10, string|bool $echo_prefix = true, bool $echo_screen = true, bool $echo_header = true): bool
    {
        if ($echo_header) {
            Log::logDebugHeader('PRINTR', get_class_or_datatype($messages), 1, $threshold, echo_screen: $echo_screen);
        }

        if (empty($messages)) {
            if (is_bool($messages)) {
                $messages = Strings::fromBoolean($messages);

            } elseif (($messages !== 0) and ($messages !== 0.0) and ($messages !== '0') and ($messages !== '0.0')) {
                if ($messages === null) {
                    $messages = 'NULL';

                } elseif (!is_array($messages)) {
                    $messages = '>>> EMPTY <<<';
                }
            }

        } elseif (is_array($messages)) {
            ksort($messages);
        }

        if (!is_scalar($messages)) {
            $messages = Json::encode($messages, JSON_BIGINT_AS_STRING | JSON_PRETTY_PRINT);
        }

        return Log::write($messages, 'debug', $threshold, false, echo_prefix: $echo_prefix, echo_screen: $echo_screen);
    }


    /**
     * Write the specified SQL query as a message in the log file
     *
     * @param string|PDOStatement $query               The query that should be logged
     * @param ?array              $execute      [null] If specified, must contain the execution variables to apply when executing the specified query
     * @param int                 $threshold    [10]   The volume threshold that must be passed to allow this log message to show up in the log files
     * @param bool                $clean        [true] If true, will first clean the log message from double spaces, etc. before writing it to the log files
     * @param bool                $echo_newline [true] If true, will ensure the log message has a newline at the end when writing the message to the log files
     * @param string|bool         $echo_prefix  [true] If true will log the message with the standard log message prefix (datetime, pid, gid, lid, etc.)
     * @param bool                $echo_screen  [true] If true, will log the entry to the screen as well as the log files (only applies on WEB platform)
     *
     * @return bool
     */
    public static function sql(string|PDOStatement $query, ?array $execute = null, int $threshold = 10, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        $query = QueryBuilder::renderQueryString($query, $execute, true);
        $query = Strings::ensureEndsWith($query, ';');

        return Log::write('SQL QUERY: ' . $query, 'debug', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Show a dot on the console each $each call if $each is false, "DONE" will be printed, with next line. Internal
     * counter will reset if a different $each is received.
     *
     * @note While log_console() will log towards the DIRECTORY_ROOT/data/log/ log files, cli_dot() will only log one
     *       single dot even though on the command line multiple dots may be shown
     *
     * @param int|true $each      [10]    How many calls to this method it takes for a single dot to show up in the log files
     * @param string   $color     [green] The color of the log message
     * @param string   $dot       [.]     The character to use for the dot
     * @param int      $threshold [10]    The volume threshold that must be passed for this message to show up in the log files
     * @param string   $ten_color [blue]  The color of the log message if it is the tenth dot
     * @param string   $ten_dot   [:]     The character of the log message if it is the tenth dot
     *
     * @return boolean True if a dot was printed, false if not
     * @example
     * for($i=0; $i < 100; $i++) {
     *     Log::dot();
     * }
     * /code
     *
     * This will return something like
     *
     * ..........
     *
     * @see  Log::write()
     */
    public static function dot(int|true $each = 10, string $color = 'green', string $dot = '.', int $threshold = 10, string $ten_color = 'blue', string $ten_dot = ':'): bool
    {
        static $count = 0, $internal_each = 0, $ten_count = 0;

        $echo_screen = PLATFORM_CLI;

        if (($each === 0) or ($each === true)) {
            if ($count) {
                // Only show "Done" if we've shown any dot at all
                Log::write(ts('Done'), $color, $threshold, false, true, false);
            }

            $internal_each = 0;
            $ten_count     = 0;
            $count         = 0;

            return true;
        }

        $count++;
        $ten_count++;

        if ($internal_each != $each) {
            $internal_each = $each;
            $ten_count     = 0;
            $count         = 0;
        }

        if ($count >= $internal_each) {
            $count = 0;

            if (floor($ten_count / 10) >= $internal_each) {
                $ten_count = 0;
                Log::write($ten_dot, $ten_color, $threshold, false, false, false, echo_screen: $echo_screen);

            } else {
                Log::write($dot, $color, $threshold, false, false, false, echo_screen: $echo_screen);
            }

            return true;
        }

        return false;
    }


    /**
     * Rotates the current log file
     *
     * @return PhoFileInterface
     */
    public static function rotate(): PhoFileInterface
    {
        $current = static::$file;
        $file    = PhoFile::new(static::$file, PhoRestrictions::newWritable(DIRECTORY_DATA . 'log/'));
        $target  = $file->getSource() . '~' . PhoDateTime::new()->format('Ymd');
        $target  = PhoFile::getAvailableVersion($target, '.gz');

        static::action(tr('Rotating to next syslog file'));

        $file = $file->rename($target)->gzip();

        static::setFile($current);
        Log::information(ts('Continuing syslog from file ":file"', [':file' => $file->getSource()]));

        return $file;
    }


    /**
     * Write an action message in the log file
     *
     * @param mixed       $messages
     * @param int         $threshold
     * @param bool        $clean
     * @param bool        $echo_newline
     * @param string|bool $echo_prefix
     * @param bool        $echo_screen
     *
     * @return bool
     */
    public static function action(mixed $messages = null, int $threshold = 5, bool $clean = true, bool $echo_newline = true, string|bool $echo_prefix = true, bool $echo_screen = true): bool
    {
        return Log::write($messages, 'action', $threshold, $clean, $echo_newline, $echo_prefix, $echo_screen);
    }


    /**
     * Clean up old log files
     *
     * @param int|null $age_in_days
     *
     * @return void
     */
    public static function clean(?int $age_in_days): void
    {
        if (!$age_in_days) {
            $age_in_days = config()->getInteger('log.clean.age', 30);
        }

        Log::action(ts('Cleaning log files older than ":age" days', [
            ':age' => $age_in_days,
        ]));

        Find::new(new PhoDirectory(DIRECTORY_DATA . 'log/', PhoRestrictions::newWritable(DIRECTORY_DATA . 'log/')))
            ->setMtime('+' . ($age_in_days * 1440))
            ->setExec('rf {} -rf')
            ->executeNoReturn();
    }


    /**
     * Executes the log callback when the log threshold is passed
     *
     * @param int      $threshold
     * @param callable $callback
     *
     * @return void
     */
    public static function callback(int $threshold, callable $callback): void
    {
        if (static::passesThreshold($threshold)) {
            $callback($threshold);
        }
    }


    /**
     * Returns true if the syslog is open
     *
     * @return bool
     */
    public static function syslogIsOpen(): bool
    {
        return static::$syslog_open;
    }
}
