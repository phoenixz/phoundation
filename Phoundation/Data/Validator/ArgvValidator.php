<?php

/**
 * ArgvValidator class
 *
 * This class validates data from untrusted $argv
 *
 * @author    Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @license   http://opensource.org/licenses/GPL-2.0 GNU Public License, Version 2
 * @copyright Copyright © 2025 Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @package   Phoundation\Data
 */


declare(strict_types=1);

namespace Phoundation\Data\Validator;

use Phoundation\Cli\CliCommand;
use Phoundation\Cli\Exception\CliArgumentsException;
use Phoundation\Cli\Exception\CliInvalidArgumentsException;
use Phoundation\Core\Core;
use Phoundation\Core\Log\Log;
use Phoundation\Data\Traits\TraitDataStaticArrayBackup;
use Phoundation\Data\Traits\TraitDataStaticArrayUnclean;
use Phoundation\Data\Traits\TraitStaticMethodNew;
use Phoundation\Data\Validator\Exception\KeyAlreadySelectedException;
use Phoundation\Data\Validator\Exception\MissingArgumentValueException;
use Phoundation\Data\Validator\Exception\ValidationFailedException;
use Phoundation\Data\Validator\Exception\ValidatorException;
use Phoundation\Data\Validator\Interfaces\ArgvValidatorInterface;
use Phoundation\Exception\OutOfBoundsException;
use Phoundation\Utils\Arrays;
use Phoundation\Utils\Strings;
use Phoundation\Web\Requests\Request;
use Stringable;


class ArgvValidator extends Validator implements ArgvValidatorInterface
{
    use TraitDataStaticArrayBackup;
    use TraitStaticMethodNew;
    use TraitDataStaticArrayUnclean;


    /**
     * Internal $argv array until validation has been completed
     *
     * @var array $argv
     */
    protected static array $argv = [];

    /**
     * Tracks if for selecting the current value, we have to take the current argument or the next
     *
     * @var bool $next
     */
    protected bool $next = false;

    /**
     * The fields that were originally selected as they might be expected on the CLI, like "-u,users"
     *
     * @var string|null $cli_fields
     */
    protected ?string $cli_fields = null;

    /**
     * Tracks test mode where selected variables will NOT be removed from the source array
     *
     * @var bool $test
     */
    protected bool $test = false;

    /**
     * Tracks if ArgvValidator::selectAll() has been used
     *
     * @var bool $select_all
     */
    protected static bool $select_all = false;


    /**
     * Validator constructor.
     *
     * @note This class will purge the $_REQUEST array as this array contains a mix of $_GET and $_POST variables which
     *       should never be used
     *
     * @note Keys that do not exist in $data that are validated will automatically be created
     * @note Keys in $data that are not validated will automatically be removed
     */
    public function __construct()
    {
        parent::__construct();

        // ArgValidator does NOT pass $argv to the parent, the $argv values are manually copied to the object source.
        $this->construct();
    }


    /**
     * Returns if the ArgvValidator object is in test mode or not
     *
     * @warning When test mode is set, ArgvValidator will NOT remove validated keys from its source!
     *
     * @return bool
     */
    public function getTest(): bool
    {
        return $this->test;
    }


    /**
     * Sets if the ArgvValidator object is in test mode or not
     *
     * @warning When test mode is set, ArgvValidator will NOT remove validated keys from its source!
     *
     * @param bool $test
     *
     * @return static
     */
    public function setTest(bool $test): static
    {
        $this->test = $test;
        return $this;
    }


    /**
     * Sets the specified ARGV submit data value
     *
     * @param array|string                $value
     * @param Stringable|string|float|int $key
     * @param bool                        $skip_null_values
     *
     * @return static
     */
    public function set(mixed $value, Stringable|string|float|int $key, bool $skip_null_values = true): static
    {
        static::$argv[$key] = $value;
        return $this;
    }


    /**
     * Move all $argv data to internal array to ensure developers cannot access them until validation has been completed
     *
     * @param array $argv
     *
     * @return void
     */
    public static function hideData(array &$argv): void
    {
        // Remove anything before "./pho"
        if (!empty($argv)) {
            foreach ($argv as $value) {
                if (str_ends_with($value, '/pho')) {
                    // This is the ./pho command. Strip it and continue
                    array_shift($argv);
                    break;
                }

                // Whatever this is, remove it.
                array_shift($argv);
            }
        }

        // Expand "single dash + multiple letters" entries to individual "single dash + single letter" entries
        $argv = static::expandSingleDashMultipleLetters($argv);

        // Copy $argv data and reset the global $argv
        static::$argv    = $argv;
        static::$_backup = $argv;

        $argv = null;
    }


    /**
     * Expand "single dash + multiple letters" entries to individual "single dash + single letter" entries
     *
     * @param array $argv
     *
     * @return array
     */
    protected static function expandSingleDashMultipleLetters(array $argv): array
    {
        $return = [];

        foreach ($argv as $value) {
            if (preg_match('/^-[a-z]+$/i', $value)) {
                // Expand
                $length = strlen($value);

                for ($i = 1; $i < $length; $i++) {
                    $return[] = '-' . $value[$i];
                }

            } else {
                $return[] = $value;
            }
        }

        return $return;
    }


    /**
     * Recovers an untouched backup of the command line arguments to the internal $argv array
     *
     * @return void
     */
    public static function recoverBackupSource(): void
    {
        static::$argv = static::$_backup;
    }


    /**
     * Returns the number of command line command arguments still available.
     *
     * @note Modifier arguments start with - or --. - only allows a letter whereas -- allows one or multiple words
     *       separated by a -. Modifier arguments may have or not have values accompanying them.
     * @note Methods are arguments NOT starting with - or --
     * @note As soon as non-command arguments start we can no longer discern if a value like "system" is actually a
     *       command or a value linked to an argument. Because of this, as soon as modifier arguments start, commands
     *       may no longer be specified. An exception to this are system modifier arguments because system modifier
     *       arguments are filtered out BEFORE commands are processed.
     *
     * @return int
     */
    public static function getCommandCount(): int
    {
        $count = 0;

        foreach (static::$argv as $argument) {
            if (!trim($argument)) {
                // Ignore empty items
                continue;
            }

            if (str_starts_with($argument, '-')) {
                break;
            }

            $count++;
        }

        return $count;
    }


    /**
     * Returns an array of command line commands
     *
     * @return array
     */
    public static function getCommands(): array
    {
        $commands = [];

        // Scan all arguments until named parameters start
        foreach (static::$argv as $argument) {
            $argument = trim($argument);

            if (!$argument) {
                // Ignore empty items
                continue;
            }

            if (str_starts_with($argument, '-')) {
                // This is a modifier argument, not a command
                break;
            }

            $commands[] = $argument;
        }

        return $commands;
    }


    /**
     * Returns an array of all UNVALIDATED command line arguments that are left
     *
     * @return array
     */
    public static function getArguments(): array
    {
        return array_values(static::$argv);
    }


    /**
     * Remove the specified method from the arguments list
     *
     * @param string $method
     *
     * @return void
     */
    public static function removeCommand(string $method): void
    {
        $key = array_search($method, static::$argv, true);

        if ($key === false) {
            throw new ValidatorException(tr('Cannot remove method ":method", it does not exist', [
                ':method' => $method,
            ]));
        }

        unset(static::$argv[$key]);

        // Renumber the $argv array so that key gaps are gone
        static::$argv = array_values(static::$argv);
    }


    /**
     * Find the specified method, basically any argument without - or --
     *
     * The result will be removed from $argv, but will remain stored in a static
     * variable which will return the same result every subsequent function call
     *
     * @param int|null    $index   The method number that is requested. 0 (default) is the first method, 1 the second,
     *                             etc.
     * @param string|null $default The value to be returned if no method was found
     *
     * @return string              The results of the executed SSH commands in an array, each entry containing one line
     *                             of the output
     *
     * @see cli_arguments()
     * @see CliCommand::argument()
     */
    protected static function method(?int $index = null, ?string $default = null): string
    {
        global $argv;
        static $method = [];

        if (isset($method[$index])) {
            $reappeared = array_search($method[$index], $argv, true);

            if (is_numeric($reappeared)) {
                // The argument has been re-added to $argv. This is very likely happened by safe_exec() that included
                // the specified script into itself, and had to reset the arguments array
                unset($argv[$reappeared]);
            }

            return $method[$index];
        }

        foreach ($argv as $key => $value) {
            if (!str_starts_with($value, '-')) {
                unset($argv[$key]);
                $method[$index] = $value;

                return $value;
            }
        }

        return $default;
    }


    /**
     * Check if selecting is allowed
     *
     * @param bool $select_all
     *
     * @return void
     */
    protected function checkSelectAllowed(bool $select_all): void
    {
        if (static::$select_all) {
            throw new ValidatorException(tr('Cannot select another cli argument again after using ArgvValidator::selectAll() as that method has selected all arguments, there is nothing left to select'));
        }

        // Once ArgvValidator::selectAll() has been executed once, we cannot ever select anything else!
        static::$select_all = $select_all;
    }


    /**
     * Initializes a select() request
     *
     * @param string|int  $fields
     * @param string|bool $next
     *
     * @return string
     */
    protected function initSelect(string|int $fields, string|bool $next = false): string
    {
        if ($this->source === null) {
            throw new OutOfBoundsException(tr('Cannot select fields ":fields", no source array specified', [
                ':fields' => $fields,
            ]));
        }

        if (!$fields) {
            throw new OutOfBoundsException(tr('No field specified'));
        }

        if ($this->cli_fields and !$this->test_count) {
            throw new ValidationFailedException(tr('Cannot select field(s) ":fields", the previously selected field ":previous" has no validations performed yet', [
                ':fields'   => $fields,
                ':previous' => $this->selected_field,
            ]));
        }

        // Make sure the field value does not have any extras like "-e,--email EMAIL" <<< The EMAIL part is extra
        $fields = Strings::until($fields, ' ');

        // Unset various values first to ensure the byref link is broken
        unset($this->process_values);
        unset($this->process_value);
        unset($this->selected_value);

        $this->test_count           = 0;
        $this->process_value_failed = false;
        $this->selected_is_optional = false;
        $this->selected_is_default  = false;
        $this->cli_fields           = $fields;
        $this->next                 = $next;

        return $fields;
    }


    /**
     * Selects the specified key within the command line arguments that we are validating
     *
     * @param string|int  $fields The array key (or HTML form field) that needs to be validated / sanitized
     * @param string|bool $next
     *
     * @return static
     */
    public function select(string|int $fields, string|bool $next = false): static
    {
        // Check if selecting is allowed
        static::checkSelectAllowed(false);

        // Initialize select
        $fields      = $this->initSelect($fields, $next);
        $clean_field = null;
        $field       = null;

        // Determine the correct clean field name for the specified argument field
        foreach (Arrays::force($fields, ',') as $field) {
            // Clean the field by stripping parameter information
            $field       = trim($field);
            $clean_field = Strings::until($field, ' ');

            if (str_starts_with($clean_field, '--')) {
                // This is the long form argument
                $clean_field = Strings::ensureBeginsNotWith($clean_field, '-');
                $clean_field = str_replace('-', '_', $clean_field);
                break;
            }

            if (str_starts_with($clean_field, '-')) {
                // This is the short form argument, will not be a variable name unless there is no alternative
                $clean_field = Strings::ensureBeginsNotWith($clean_field, '-');
                $clean_field = str_replace('-', '_', $clean_field);
                continue;
            }

            // This is not a modifier field but a command or value argument instead. Do not modify the field name
            // Do change the field value to NULL, which will cause ArgvValidator::argument() to return the next
            // available argument
            $clean_field = $fields;
            $fields      = null;
        }

        if (!$clean_field) {
            throw new ValidatorException(tr('Failed to determine clean field name for ":field"', [
                ':field' => $field,
            ]));
        }

        // Get the value from the argument list
        try {
            $value = $this->argument($fields, $next, $this->test);

        } catch (OutOfBoundsException) {
            // The field was not specified
            $value = null;
        }

        if (in_array($clean_field, $this->selected_fields)) {
            throw new KeyAlreadySelectedException(tr('The specified key ":key" has already been selected before', [
                ':key' => $clean_field,
            ]));
        }

        if (!$field and str_starts_with((string) $value, '-')) {
            // TODO Improve argument handling here. We should be able to mix "--modifier modifiervalue value" with "value --modifier modifiervalue" but with this design we currently cannot
            // We are looking not for a modifier, but for a command or value. This is a modifier, so do not use it. Put
            // the value back on the arguments list
            $this->source[] = $value;
            $value          = null;
        }

        // Add the cleaned field to the source array
        $this->source[$clean_field] = $value;

        // Select the field.
        $this->selected_field    =   $clean_field;
        $this->selected_fields[] =   $clean_field;
        $this->selected_value    =  &$this->source[$clean_field];
        $this->process_values    = [&$this->selected_value];
        $this->selected_optional =   null;

        return $this;
    }


    /**
     * Selects all arguments
     *
     * @param string|int $fields The array key (or HTML form field) that needs to be validated / sanitized
     *
     * @return static
     */
    public function selectAll(string|int $fields): static
    {
        // Check if we can select
        static::checkSelectAllowed(true);

        // Initialize select
        $fields      = static::initSelect($fields);
        $clean_field = null;

        // Determine the correct clean field name for the specified argument field
        foreach (Arrays::force($fields, ',') as $field) {
            // Clean the field by stripping parameter information
            $field       = trim($field);
            $clean_field = Strings::until($field, ' ');

            if (str_starts_with($clean_field, '--')) {
                // This is the long form argument
                $clean_field = Strings::ensureBeginsNotWith($clean_field, '-');
                $clean_field = str_replace('-', '_', $clean_field);
                break;
            }

            if (str_starts_with($clean_field, '-')) {
                // This is the short form argument, will not be a variable name unless there is no alternative
                $clean_field = Strings::ensureBeginsNotWith($clean_field, '-');
                $clean_field = str_replace('-', '_', $clean_field);
                continue;
            }

            // This is not a modifier field but a command or value argument instead. Do not modify the field name
            // Do change the field value to NULL, which will cause ArgvValidator::argument() to return the next
            // available argument
            $clean_field = $fields;
            $fields      = null;
        }

        if (!$clean_field) {
            throw new ValidatorException(tr('Failed to determine clean field name for ":field"', [
                ':field' => $field,
            ]));
        }

        // Add the cleaned field values to the source array
        $this->source[$clean_field] = static::$argv;
        static::$argv = [];

        if (in_array($clean_field, $this->selected_fields)) {
            throw new KeyAlreadySelectedException(tr('The specified key ":key" has already been selected before', [
                ':key' => $clean_field,
            ]));
        }

        // Select the field.
        $this->selected_field    =  $clean_field;
        $this->selected_fields[] =  $clean_field;
        $this->selected_value    =  &$this->source[$clean_field];
        $this->process_values    = [&$this->selected_value];
        $this->selected_optional = null;

        return $this;
    }


    /**
     * Returns arguments from the command line
     *
     * This function will REMOVE and then return the argument when its found
     * If the argument is not found, $default will be returned
     *
     * @param array|string|int|null $arguments (NOTE: See $next for what will be returned) If set to a numeric value,
     *                                         the value from $argv[$key] will be selected. If set as a string value,
     *                                         the $argv key where the value is equal to $key will be selected. If set
     *                                         specified as an array, all entries in the specified array will be
     *                                         selected.
     * @param string|bool           $next      When set to true, it REQUIRES that the specified key contains a next
     *                                      argument, and this will be returned. If set to "all", it will return all
     *                                      following arguments. If set to "optional", a next argument will be returned,
     *                                      if available.
     * @param bool                  $test      If true, will not remove the variable from the internal $argv, it will just
     *                                      return the values to test them
     *
     * @return mixed                        If $next is null, it will return a boolean value, true if the specified key
     *                                      exists, false if not. If $next is true or "optional", the next value will be
     *                                      returned as a string. However, if "optional" was used, and the next value
     *                                      was not specified, boolean FALSE will be returned instead. If $next is
     *                                      specified as all, all subsequent values will be returned in an array
     */
    protected function argument(array|string|int|null $arguments = null, string|bool $next = false, bool $test = false): mixed
    {
        if (is_integer($arguments)) {
            // Get arguments by index
            if ($next === 'all') {
                foreach (static::$argv as $argv_key => $argv_value) {
                    if ($argv_key < $arguments) {
                        continue;
                    }

                    if ($argv_key == $arguments) {
                        if (!$test) {
                            unset(static::$argv[$arguments]);
                        }

                        continue;
                    }

                    if (str_starts_with($argv_value, '-')) {
                        // Encountered a new option, stop!
                        break;
                    }

                    // Add this argument to the list
                    $value[] = $argv_value;

                    if (!$test) {
                        unset(static::$argv[$argv_key]);
                    }
                }

                return isset_get($value);
            }

            if (isset(static::$argv[$arguments++])) {
                $argument = static::$argv[$arguments - 1];

                if (!$test) {
                    unset(static::$argv[$arguments - 1]);
                }

                return $argument;
            }

            // No arguments found (except perhaps for test or force)
            return null;
        }

        if ($arguments === null) {
            // Get the next argument?
            if ($test) {
                return static::$argv[array_key_first(static::$argv)];
            }

            return array_shift(static::$argv);
        }

        // Detect multiple key options for the same command, but ensure only one is specified
        if (is_array($arguments) or (is_string($arguments) and str_contains($arguments, ','))) {
            $arguments  = Arrays::force($arguments);
            $return_key = static::getReturnKey($arguments);
            $results    = [];

            foreach ($arguments as $argument) {
                if ($next === 'all') {
                    // We are requesting all values for all specified keys. It will return null in case the specified key
                    // does not exist
                    $value = $this->argument($argument, 'all', $test);

                    if (is_array($value)) {
                        $found   = true;
                        $results = array_merge($results, $value);
                    }

                } else {
                    $value = $this->argument($argument, $next, $test);

                    if ($value) {
                        $results[$return_key] = $value;
                        break;
                    }
                }
            }

            if (($next === 'all') and isset($found)) {
                return $results;
            }

            return match (count($results)) {
                0       => null,
                1       => current($results),
                default => throw CliArgumentsException::new(tr('Multiple related command line arguments ":results" for the same option specified. Please specify only one', [
                    ':results' => $results,
                ]))->makeWarning()
            };
        }

        // Find the location of the specified singular key. If not found, we are done, return null
        if (($argument_array_key = array_search($arguments, static::$argv, true)) === false) {
            return null;
        }

        if ($next) {
            if ($next === 'all') {
                // Return all following arguments, if available, until the next option
                $value = [];

                foreach (static::$argv as $argv_key => $argv_value) {
                    if (empty($start)) {
                        if ($argv_value == $arguments) {
                            $start = true;

                            if (!$test) {
                                unset(static::$argv[$argv_key]);
                            }
                        }

                        continue;
                    }

                    if (str_starts_with($argv_value, '-')) {
                        // Encountered a new option, stop!
                        break;
                    }

                    // Add this argument to the list
                    $value[] = $argv_value;

                    if (!$test) {
                        unset(static::$argv[$argv_key]);
                    }
                }

                return $value;
            }

            try {
                // Return next argument, if available
                $value = Arrays::nextValue(static::$argv, $arguments, !$test);

            } catch (OutOfBoundsException $e) {
                // This argument requires another parameter. Make it a CliArgumentsException
                // The current value was found, but it was at the end of the array
                throw MissingArgumentValueException::new(tr('Argument ":value" does not have a required value specified, see --help or --usage', [
                    ':value' => $arguments,
                ]), $e)->addData($e->getData())
                       ->makeWarning();
            }

            if (str_starts_with((string) $value, '-')) {
                throw CliArgumentsException::new(tr('Argument ":keys" has no assigned value. It is immediately followed by argument ":value"', [
                    ':keys'  => $arguments,
                    ':value' => $value,
                ]))->addData(['keys' => $arguments])
                   ->makeWarning();
            }

            return $value;
        }

        if (!$test) {
            unset(static::$argv[$argument_array_key]);
        }

        return true;
    }


    /**
     * Returns the key that will be used internally as an argument key
     *
     * Keys may be specified by words, letters, or both. If both were specified, prefer the word
     *
     * @param array $keys
     *
     * @return string
     */
    protected static function getReturnKey(array $keys): string
    {
        if (empty($keys)) {
            throw new OutOfBoundsException(tr('No keys specified'));
        }

        $return = '';

        foreach ($keys as $key) {
            if (str_starts_with($key, '--')) {
                // This key MUST have more than one letter!
                if (strlen($key) <= 3) {
                    throw new ValidationFailedException(tr('Specified word argument ":argument" starts with -- and must have more than one alpha-numeric character after!', [
                        ':argument' => $key,
                    ]));
                }

                if (!preg_match('/^--(?:[a-z0-9]+-?)+$/i', $key)) {
                    throw new ValidationFailedException(tr('Specified word argument ":argument" is invalid, it must follow the expression /^--(?:[a-z0-9]+-)+]$/i', [
                        ':argument' => $key,
                    ]));
                }

                // Always use the first word argument that is encountered
                return $key;

            } elseif (str_starts_with($key, '-')) {
                // This key MUST have only one letter!
                if (strlen($key) > 2) {
                    throw new ValidationFailedException(tr('Specified letter argument ":argument" starts with - and must have only one alpha-numeric character after!', [
                        ':argument' => $key,
                    ]));
                }

                if (!preg_match('/^-[a-z0-9]$/i', $key)) {
                    throw new ValidationFailedException(tr('Specified letter argument ":argument" is invalid, it must follow the expression /^-[a-z0-9]$/i', [
                        ':argument' => $key,
                    ]));
                }

                // So far we encountered a letter, if that is all, that is what we will return
                $return = $key;

            } else {
                throw new OutOfBoundsException(tr('Specified key ":key" is invalid, a key must start with - or --', [
                    ':key' => $key,
                ]));
            }
        }

        return $return;
    }


    /**
     * Returns the $argv array
     *
     * @return array
     */
    public function getArgv(): array
    {
        global $argv;
        return $argv;
    }


    /**
     * This method will make sure that either this field OR the other specified field will have a value
     *
     * @note: This or works slightly different form the ValidatorBasics::xor(). If the specified field data is not found
     *        initially, it will check the $this->source to see if it might be there, ready and waiting. This is because
     *        in the ArgvValidator key values may not be detected until $this->source has passed through
     *        $this->argument(). It first needs to detect one of the requested keys, then the corresponding value
     *        (for example: -u,--users USEREMAIL is detected by first finding --user then the email)
     *
     * @param string $field
     * @param bool   $rename
     *
     * @return static
     *
     * @see Validator::isOptional()
     * @see Validator::orColumn()
     */
    public function xor(string $field, bool $rename = false): static
    {
        if (!str_starts_with($field, (string) $this->field_prefix)) {
            $field = $this->field_prefix . $field;
        }

        if ($this->selected_field === $field) {
            throw new ValidatorException(tr('Cannot validate XOR field ":field" with itself', [':field' => $field]));
        }

        if (isset_get($this->source[$this->selected_field]) and !$this->argument($this->selected_field, $this->next, true)) {
            // The currently selected field exists, the specified field cannot exist
            if (isset_get($this->source[$field]) or $this->argument($this->cli_fields, $this->next, true)) {
                $this->addSoftFailure(tr('Both fields ":field" and ":selected_field" were set, where only either one of them are allowed', [
                    ':field'          => $field,
                    ':selected_field' => $this->selected_field,
                ]));
            }

            if ($rename) {
                // Rename this field to the specified field
                $this->rename($field);
            }

        } else {
            // The currently selected field does not exist, the specified field MUST exist
            if (!isset_get($this->source[$field]) and !$this->argument($this->cli_fields, $this->next, true)) {
                $this->addSoftFailure(tr('neither the field ":current" nor ":field" were set, where either one of them is required', [
                    ':current' => $this->selected_field,
                    ':field'   => $field,
                ]));

            } else {
                // Yay, the alternate field exists, so this one can be made optional.
                $this->isOptional();
            }
        }

        return $this;
    }


    /**
     * This method will make sure that either this field OR the other specified field optionally will have a value
     *
     * @note: This or works slightly different form the ValidatorBasics::or(). If the specified field data is not found
     *        initially, it will check the $this->source to see if it might be there, ready and waiting. This is because
     *        in the ArgvValidator key values may not be detected until static::$argv has passed through
     *        $this->argument(). It first needs to detect one of the requested keys, then the corresponding value
     *        (for example: -u,--users USEREMAIL is detected by first finding --user then the email)
     *
     * @param string $field
     *
     * @return static
     *
     * @see Validator::isOptional()
     * @see Validator::xorColumn()
     */
    public function orColumn(string $field): static
    {
        if (!str_starts_with($field, (string) $this->field_prefix)) {
            $field = $this->field_prefix . $field;
        }

        if ($this->selected_field === $field) {
            throw new ValidatorException(tr('Cannot validate OR field ":field" with itself', [':field' => $field]));
        }

        if (!isset_get($this->source[$this->selected_field])) {
            if (!$this->selected_is_optional) {
                // The currently selected field is required but does not exist, so the other must exist
                if (!isset_get($this->source[$field]) and !$this->argument($this->cli_fields, $this->next, true)) {
                    $this->addSoftFailure(tr('nor ":field" field were set, where at least one of them is required', [
                        ':field' => $field,
                    ]));

                } else {
                    // Yay, the alternate field exists, so this one can be made optional.
                    $this->isOptional();
                }
            }
        }

        return $this;
    }


    /**
     * Called at the end of defining all validation rules.
     *
     * This method will check the "failures" array and, if any failures were registered, will throw an exception
     *
     * @note See also the configuration path "security.validation.enabled", if this configuration is false, validate() will also not throw a
     *       ValidationFailedException
     *
     * @param bool $require_clean_source [true] If true, requires that this validation will select and validate ALL values in the validator source. If any
     *                                          variables are left when Validator::validate() is called, a ValidationFailedException will be thrown
     * @param bool $exception            [true] If true, and validation failed, will throw a ValidationFailedException. If false, will log the failure, and
     *                                          return the data as if validation was successful. THIS IS ONLY ALLOWED ON DEBUG PLATFORMS! This variable will
     *                                          cause a ValidatorException if this variable is false on production platforms
     *
     *
     * @return array
     *
     * @throws ValidationFailedException
     * @throws ValidatorException
     */
    public function validate(bool $require_clean_source = true, bool $exception = true): array
    {
        // Check if there is still a field selected that has no test applied.
        // If so, fail, because all fields must be tested
        if ($this->selected_field and !$this->test_count) {
            if (!config()->getBoolean('security.validation.disabled', false) and $exception) {
                throw new ValidatorException(tr('Cannot validate because the last selected field ":field" has no validations performed yet', [
                    ':field' => $this->selected_field,
                ]));
            }

            Log::error('WARNING: SKIPPED VALIDATION DUE TO security.validation.disabled = false CONFIGURATION! SYSTEM MAY BE IN UNKNOWN STATE!');
        }

        if (static::$argv) {
            // Unprocessed fields
            if ($require_clean_source) {
                if ($this->getValidationEnabled() and $exception) {
                    throw ValidationFailedException::new(tr('Data validation failed because of the following unknown fields'))
                                                   ->addData([
                                                       'failures' => static::$argv,
                                                   ])
                                                   ->makeWarning();
                }

                Log::error('WARNING: SKIPPED DATA CLEAN VALIDATION DUE TO security.validation.disabled = false CONFIGURATION! SYSTEM DATA MAY BE IN UNKNOWN STATE!');
            }
        }

        if ($this->failures) {
            $values = Arrays::keepKeys($this->source, array_keys($this->failures));

            if (Core::inBootState() or $this->getValidationEnabled() and $exception) {
                throw ValidationFailedException::new(tr('Data validation failed with the following issues:'))
                                               ->setDataEntryObject($this->_definitions?->getDataEntryObject())
                                               ->makeWarning()
                                               ->addData([
                                                   'class'    => get_class_or_datatype($this->_definitions?->getDataEntryObject()),
                                                   'failures' => $this->failures,
                                                   'values'   => $values
                                               ]);
            }

            Log::error('WARNING: SKIPPED FIELDS VALIDATION DUE TO "security.validation.enabled" = false CONFIGURATION! SYSTEM DATA MAY BE IN UNKNOWN STATE!');
        }

        static::$has_been_validated = true;

        return Arrays::extract($this->source, $this->selected_fields);
    }


    /**
     * Throws an exception if there are still arguments left in the argv source
     *
     * @param bool $apply
     *
     * @return static
     */
    protected function noArgumentsLeft(bool $apply = true): static
    {
        if (!$apply) {
            return $this;
        }

        if (empty($this->source)) {
            return $this;
        }

        throw CliInvalidArgumentsException::new(tr('Invalid command line arguments ":arguments" encountered', [
            ':arguments' => Strings::force($this->source, ', '),
        ]))->makeWarning();
    }


    /**
     * Adds the STDIN stream to the ARGV source with the specified key
     *
     * @note If the key already exists in the internal argv source, then an exception will be thrown
     *
     * @note The specified key will be tested against backup command line arguments to ensure the key cannot be added
     *       from STDIN AND be specified on the command line
     *
     * @param string $key
     *
     * @return static
     */
    public function addStdInStreamAsKey(string $key): static
    {
        if (CliCommand::hasStdInStream()) {
            if (in_array($key, static::$_backup)) {
                throw new ValidationFailedException(tr('Cannot add STDIN stream as key ":key", the key was also specified on the command line', [
                    ':key' => $key,
                ]));
            }

            $this->source[] = $key;
            $this->source[] = CliCommand::getStdInStream();
        }

        return $this;
    }


    /**
     * Returns true if ArgvValidator::selectAll() has been used
     *
     * @return bool
     */
    public function getSelectAll(): bool
    {
        return static::$select_all;
    }


    /**
     * Ensures that any argument with extra dash has that dash removed
     *
     * @return void
     */
    public static function unDoubleDash(): void
    {
        foreach (static::$argv as &$argv) {
            $length = strlen($argv);

            if ($length === 3) {
                if (str_starts_with($argv, '--')) {
                    $argv = substr($argv, 1);
                }

            } elseif ($length > 3) {
                if (str_starts_with($argv, '---')) {
                    $argv = substr($argv, 1);
                }
            }
        }

        unset($argv);
    }
}
