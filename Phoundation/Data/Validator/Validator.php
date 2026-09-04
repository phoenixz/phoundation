<?php

/**
 * Class Validator
 *
 * This class validates data from untrusted sources
 *
 * This class is an abstract class and cannot be instantiated directly. Instead, use one of  ArrayValidator, ArgvValidator, GetValidator, PostValidator, or
 * FileValidator classes instead.
 *
 * The ArrayValidator class accepts a source array with untrusted / dirty data when instantiating the class. The ArgvValidator, GetValidator, and PostValidator
 * classes always automatically have the source data supplied from $argv, $_GET, and $_POST respectively.
 *
 * Then, keys can be selected using Validator::select() and a wide variety of validation methods can be applied to each key value. Once every key in the array
 * has been validated, the Validator::validate() method can be called. This will either return an array with the selected keys, all with validated values that
 * are safe for use, or throw a ValidationFailedException. This exception will contain information in the data section on what field(s) failed and why.
 *
 * Data validation for each value is typically executed in the order: exists (optionally default), datatype, size, content.
 *
 * ... (TODO: Write more)
 *
 * @see       https://www.joelonsoftware.com/2003/10/08/the-absolute-minimum-every-software-developer-absolutely-positively-must-know-about-unicode-and-character-sets-no-excuses/
 * @see       https://nulldog.com/utf-8-encoding-in-php-a-complete-guide
 * @see
 *
 * @author    Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @license   http://opensource.org/licenses/GPL-2.0 GNU Public License, Version 2
 * @copyright Copyright © 2025 Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @package   Phoundation\Data
 */


declare(strict_types=1);

namespace Phoundation\Data\Validator;

use PDOStatement;
use Phoundation\Accounts\Users\Password;
use Phoundation\Cli\Cli;
use Phoundation\Core\Core;
use Phoundation\Core\Interfaces\ArrayableInterface;
use Phoundation\Core\Log\Log;
use Phoundation\Data\DataEntries\Interfaces\DataEntryInterface;
use Phoundation\Data\DataEntries\Interfaces\IdentifierInterface;
use Phoundation\Data\Enums\EnumSoftHard;
use Phoundation\Data\Interfaces\IteratorInterface;
use Phoundation\Data\IteratorBase;
use Phoundation\Data\Traits\TraitDataArraySource;
use Phoundation\Data\Traits\TraitDataBooleanDirectMode;
use Phoundation\Data\Traits\TraitDataClassException;
use Phoundation\Data\Traits\TraitDataDataEntry;
use Phoundation\Data\Traits\TraitDataDefinitions;
use Phoundation\Data\Traits\TraitDataIgnoreIterator;
use Phoundation\Data\Traits\TraitDataIntId;
use Phoundation\Data\Traits\TraitDataMaxStringSize;
use Phoundation\Data\Traits\TraitDataMetaColumns;
use Phoundation\Data\Traits\TraitDataMethodPickValidatorInterface;
use Phoundation\Data\Traits\TraitDataPermitValidationFailures;
use Phoundation\Exception\PhoException;
use Phoundation\Filesystem\Traits\TraitDataRestrictions;
use Phoundation\Data\Validator\Exception\KeyAlreadySelectedException;
use Phoundation\Data\Validator\Exception\NoKeySelectedException;
use Phoundation\Data\Validator\Exception\ValidationFailedException;
use Phoundation\Data\Validator\Exception\ValidatorException;
use Phoundation\Data\Validator\Interfaces\ValidatorInterface;
use Phoundation\Databases\Connectors\Interfaces\ConnectorInterface;
use Phoundation\Date\Enums\EnumDateTimeWidth;
use Phoundation\Date\Interfaces\PhoDateTimeInterface;
use Phoundation\Date\PhoDate;
use Phoundation\Date\PhoDateTime;
use Phoundation\Date\PhoDateTimeFormats;
use Phoundation\Date\Exception\UnsupportedDateFormatException;
use Phoundation\Developer\Debug\Debug;
use Phoundation\Exception\ObsoleteException;
use Phoundation\Exception\OutOfBoundsException;
use Phoundation\Filesystem\PhoDirectory;
use Phoundation\Filesystem\PhoFile;
use Phoundation\Filesystem\PhoPath;
use Phoundation\Filesystem\Interfaces\PhoDirectoryInterface;
use Phoundation\Filesystem\Interfaces\PhoPathInterface;
use Phoundation\Filesystem\PhoRestrictions;
use Phoundation\Security\Incidents\EnumSeverity;
use Phoundation\Security\Incidents\Incident;
use Phoundation\Utils\Arrays;
use Phoundation\Utils\Exception\JsonException;
use Phoundation\Utils\Json;
use Phoundation\Utils\Numbers;
use Phoundation\Utils\Strings;
use Phoundation\Web\Html\Enums\EnumDisplayMode;
use Phoundation\Web\Html\Enums\EnumInputType;
use Phoundation\Web\Http\Domains;
use Phoundation\Web\Http\Url;
use Phoundation\Web\Requests\Request;
use Phoundation\Web\Requests\Response;
use Plugins\Medinet\Utils\Exception\InvalidPhnException;
use Plugins\Medinet\Utils\Exception\PhnRequiredException;
use Plugins\Medinet\Utils\Phn;
use ReflectionProperty;
use Stringable;
use Throwable;


abstract class Validator extends IteratorBase implements ValidatorInterface
{
    use TraitDataRestrictions;
    use TraitDataDefinitions {
        setDefinitionsObject as protected __setDefinitions;
    }
    use TraitDataIntId;
    use TraitDataMaxStringSize;
    use TraitDataMetaColumns;
    use TraitDataClassException;
    use TraitDataArraySource;
    use TraitDataIgnoreIterator;
    use TraitDataDataEntry {
        setDataEntryObject as protected __setDataEntry;
    }
    use TraitDataMethodPickValidatorInterface;
    use TraitDataPermitValidationFailures;
    use TraitDataBooleanDirectMode;


    /**
     * If true, all validations will ALWAYS pass
     *
     * @note Still, ONLY validated variables will be available after Validate::validate() has been executed!
     * @var bool $disabled
     */
    protected static bool $disabled = false;

    /**
     * If true, ONLY password validations will ALWAYS pass
     *
     * @note Still, ONLY validated variables will be available after Validate::validate() has been executed!
     * @var bool $password_disabled
     */
    protected static bool $password_disabled = false;

    /**
     * Register for the failures occurred during validations
     *
     * @var array $failures
     */
    protected array $failures = [];

    /**
     * The prefix for field selection
     *
     * @var string|null $field_prefix
     */
    protected ?string $field_prefix = null;

    /**
     * The table that contains the data
     *
     * @var string|null $table
     */
    protected ?string $table = null;

    /**
     * The current field that is being validated
     *
     * @var string|int|null $selected_field
     */
    protected string|int|null $selected_field = null;

    /**
     * The keys that have been selected to be validated. All keys found in the $source array that are not in this array
     * will be removed
     *
     * @var array $selected_fields
     */
    protected array $selected_fields = [];

    /**
     * The value that is selected for testing
     *
     * @var mixed|null $selected_value
     */
    protected mixed $selected_value = null;

    /**
     * If not NULL, the currently selected field may be non-existent or NULL, it will receive this default value
     *
     * @var mixed $selected_optional
     */
    protected mixed $selected_optional = null;

    /**
     * If true, the value is optional
     *
     * @var bool $selected_is_optional
     */
    protected bool $selected_is_optional = false;

    /**
     * The value(s) that actually will be tested. This most of the time will be an array with a single reference to
     * $selected_value, but when ->forEachField() validates a list of values, this will reference that list directly
     *
     * @var array|null $process_values
     */
    protected ?array $process_values = null;

    /**
     * The single key that actually will be tested.
     *
     * @var string|int $process_key
     */
    protected mixed $process_key = null;

    /**
     * The single value that actually will be tested.
     *
     * @var mixed $process_value
     */
    protected mixed $process_value;

    /**
     * Registers when the single value being tested failed during multiple Tests or not
     *
     * @var bool $process_value_failed
     */
    protected bool $process_value_failed = false;

    /**
     * If true, then the current field has the default value
     *
     * @var bool $selected_is_default
     */
    protected bool $selected_is_default = false;

    /**
     * If specified, this is a child element to a parent.
     *
     * The ->validate() call will NOT cause an exception but instead will send the failures list to the parent and then
     * return the parent element
     *
     * @var ValidatorInterface|null
     */
    protected ?ValidatorInterface $_parent = null;

    /**
     * If set, all field failure keys will show the parent field as well
     *
     * @var string|null
     */
    protected ?string $parent_field = null;

    /**
     * Child Validator object for subarray elements. When validating the final result, the results from all the child
     * validators will be added to the result as well
     *
     * @var array $children
     */
    protected array $children = [];

    /**
     * Required to test if selected_optional property is initialized or not
     *
     * @todo Check if we can get rid of this reflectionproperty stuff, its very hacky
     * @var ReflectionProperty $_reflection_selected_optional
     */
    protected ReflectionProperty $_reflection_selected_optional;

    /**
     * Required to test if process_value property is initialized or not
     *
     * @todo Check if we can get rid of this reflectionproperty stuff, its very hacky
     * @var ReflectionProperty $reflection_process_value
     */
    protected ReflectionProperty $reflection_process_value;

    /**
     * If true, failed fields will be cleared on validation
     *
     * @var bool $clear_failed_fields
     */
    protected bool $clear_failed_fields = false;

    /**
     * Tracks the number of tests performed on the currently selected field
     *
     * @var int $test_count
     */
    protected int $test_count = 0;

    /**
     * Tracks the number of content tests performed on the currently selected field
     *
     * @var int $content_count
     */
    protected int $content_test_count = 0;

    /**
     * Tracks if debug should be used or not
     *
     * @var bool $debug
     */
    protected bool $debug = false;

    /**
     * Tracks if datatypes should be validated
     *
     * @var bool $validation_enabled
     */
    protected bool $validation_enabled = true;

    /**
     * Tracks if datatypes should be validated
     *
     * @var bool $datatype_validation_enabled
     */
    protected bool $datatype_validation_enabled = true;

    /**
     * Tracks if content should be validated
     *
     * @var bool $content_validation_enabled
     */
    protected bool $content_validation_enabled = true;

    /**
     * Tracks if the validator has been executed
     *
     * @var bool $has_been_validated
     */
    protected static bool $has_been_validated = false;

    /**
     * Tracks unclean fields that were specified but not validated because they are unknown
     *
     * @var array|null $unclean
     */
    protected static ?array $unclean = null;


    /**
     * Validator constructor
     */
    public function __construct() {
        // Initialize validation configuration
        if (Core::getReady()) {
            $this->setDatatypeValidationEnabled(!config()->getBoolean('security.validation.disabled', false))
                 ->setContentValidationEnabled(!config()->getBoolean('security.validation.content.disabled', false))
                 ->setValidationEnabled($this->getConfigValidationEnabled());
        }
    }


    /**
     * Apply a default value to the specified key if said key is currently not available in the data source
     *
     * @param mixed  $value
     * @param string $key
     *
     * @return Validator
     */
    public function default(mixed $value, string $key): static
    {
        if (array_key_exists($key, $this->source)) {
            if (($this->source[$key] === null) or ($this->source[$key] === '')) {
                $this->source[$key] = $value;
            }

        } else {
            $this->source[$key] = $value;
        }

        return $this;
    }


    /**
     * Returns if all validations are disabled or not
     *
     * @return bool
     */
    public static function disabled(): bool
    {
        return static::$disabled;
    }


    /**
     * Disable all validations
     *
     * @return void
     */
    public static function disable(): void
    {
        static::$disabled = true;
    }


    /**
     * Enable all validations
     *
     * @return void
     */
    public static function enable(): void
    {
        static::$disabled = false;
    }


    /**
     * Disable password validations
     *
     * @return void
     */
    public static function disablePasswords(): void
    {
        static::$password_disabled = true;
    }


    /**
     * Enable password validations
     *
     * @return void
     */
    public static function enablePasswords(): void
    {
        static::$password_disabled = false;
    }


    /**
     * Copies the value of the currently selected key to the specified key
     *
     * @param string|float|int $to_key
     *
     * @return static
     */
    public function copyToKey(string|float|int $to_key): static
    {
        $this->source[$to_key] = $this->selected_value;
        return $this;
    }


    /**
     * Rename the from_key to to_key if it exists
     *
     * @param string|float|int $from_key
     * @param string|float|int $to_key
     * @param bool             $exception
     * @param bool             $overwrite
     *
     * @return static
     */
    public function renameKey(string|float|int $from_key, string|float|int $to_key, bool $exception = true, bool $overwrite = true): static
    {
        if (!array_key_exists($from_key, $this->source)) {
            if ($exception) {
                throw new OutOfBoundsException(tr('Cannot rename ":class" key from ":from" to ":to", the ":original" key does not exist', [
                    ':class'    => static::class,
                    ':from'     => $from_key,
                    ':to'       => $to_key,
                    ':original' => $from_key,
                ]));
            }

            // from_key does not exist, initialize the from_key as a null value
            $this->source[$from_key] = null;
        }

        if (array_key_exists($to_key, $this->source)) {
            // Target already exists, should we overwrite?
            if (!$overwrite) {
                // Do not overwrite, do not change anything
                return $this;
            }
        }

        // Move the from_key to the to_key
        $this->source[$to_key] = $this->source[$from_key];
        unset($this->source[$from_key]);

        return $this;
    }


    /**
     * Requires that the specified keys are available for this key to be valid
     *
     * @note This test does not count as an "executed test" to be able to select the next key. If only this test was
     *       executed for the selected key, and a next key will be selected, an ValidationFailedException exception will
     *       still be thrown
     *
     * @note This test requires that the specified keys have already been tested before, as it does not know what keys
     *       will be tested after, and if they will default or not
     *
     * @note This test will not test for the existence of the specified key, as they SHOULD exist (See previous note) so
     *       it will test if they are not empty. If the specified keys are empty, the field will fail.
     *
     * @note This will only require the specified keys are not empty if the currently selected field is not default,
     *       unless $also_if_selected_is_default is true
     *
     * @param IteratorInterface|array|string $keys
     * @param bool                           $also_if_selected_is_default If true, and the specified column is not empty but contains a default value, it will
     *                                                                    be treated as if it is empty
     *
     * @return static
     */
    public function requiresColumnsEmpty(IteratorInterface|array|string $keys, bool $also_if_selected_is_default = false): static
    {
        $keys = Arrays::force($keys);

        if (empty($keys)) {
            throw new OutOfBoundsException(tr('Cannot test field ":field" for required keys being empty, no keys were specified', [
                ':field' => $this->selected_field
            ]));
        }

        return $this->validateValues(function (&$value) use ($keys, $also_if_selected_is_default) {
            $restricted = [];

            if (!$this->hasOptionalValue($value) or $also_if_selected_is_default) {
                // Ensure we have clean keys to test, test all keys
                foreach ($keys as $key) {
                    $clean_key = Strings::ensureBeginsNotWith($key, '-');
                    $clean_key = str_replace('-', '_', $clean_key);

                    // This key likely WILL exist (because if not specified, it would be defaulted or already failed)
                    // So do not test with array_key_exists() but with empty() instead, NULL or "" or false will be used as
                    // "not specified"
                    if (!empty($this->source[$clean_key])) {
                        $restricted[] = $key;
                    }
                }

                // Any keys missing?
                switch (count($restricted)) {
                    case 0:
                        // All required fields are empty, yay!
                        break;

                    case 1:
                        // 1 field failed
                        $value = [];
                        $this->addSoftFailure(tr('requires that the field ":key" is not specified', [
                            ':key' => current($restricted),
                        ]));

                        break;

                    default:
                        // Multiple fields failed
                        $value = [];
                        $this->addSoftFailure(tr('requires that the fields ":key" are not specified', [
                            ':key' => Strings::force($restricted, ', '),
                        ]));
                }
            }
        });
    }


    /**
     * Requires that the specified keys are available for this key to be valid
     *
     * @note This test does not count as an "executed test" to be able to select the next key. If only this test was
     *       executed for the selected key, and a next key will be selected, an ValidationFailedException exception will
     *       still be thrown
     *
     * @note This test requires that the specified keys have already been tested before, as it does not know what keys
     *       will be tested after, and if they will default or not
     *
     * @note This test will not test for the existence of the specified key, as they SHOULD exist (See previous note) so
     *       it will test if they are not empty. If the specified keys are empty, the field will fail.
     *
     * @note This will only require the specified keys are not empty if the currently selected field is not default,
     *       unless $also_if_selected_is_default is true
     *
     * @param IteratorInterface|array|string $keys
     * @param bool                           $also_if_selected_is_default
     *
     * @return static
     */
    public function requiresColumnsNotEmpty(IteratorInterface|array|string $keys, bool $also_if_selected_is_default = false): static
    {
        $keys = Arrays::force($keys);

        if (empty($keys)) {
            throw new OutOfBoundsException(tr('Cannot test field ":field" for required keys, no keys were specified', [
                ':field' => $this->selected_field
            ]));
        }

        return $this->validateValues(function (&$value) use ($keys, $also_if_selected_is_default) {
            $requires = [];

            if (!$this->hasOptionalValue($value) or $also_if_selected_is_default) {
                // Ensure we have clean keys to test, test all keys
                foreach ($keys as $key) {
                    $clean_key = Strings::ensureBeginsNotWith($key, '-');
                    $clean_key = str_replace('-', '_', $clean_key);

                    // This key likely WILL exist (because if not specified, it would be defaulted or already failed)
                    // So do not test with array_key_exists() but with empty() instead, NULL or "" or false will be used as
                    // "not specified"
                    if (empty($this->source[$clean_key])) {
                        $requires[] = $key;
                    }
                }

                // Any keys missing?
                switch (count($requires)) {
                    case 0:
                        // All required fields are there, yay!
                        break;

                    case 1:
                        // 1 field failed
                        $value = [];
                        $this->addSoftFailure(tr('requires that the field ":key" is specified as well', [
                            ':key' => current($requires),
                        ]));

                        break;

                    default:
                        // Multiple fields failed
                        $value = [];
                        $this->addSoftFailure(tr('requires that the fields ":key" are specified as well', [
                            ':key' => Strings::force($requires, ', '),
                        ]));
                }
            }
        });
    }


    /**
     * Returns the currently selected value
     *
     * @return mixed
     */
    public function getSelectedValue(): mixed
    {
        return $this->selected_value;
    }


    /**
     * Sets the currently selected value
     *
     * @param mixed $value
     *
     * @return static
     */
    public function setSelectedValue(mixed $value): static
    {
        $this->selected_value = $value;
        return $this;
    }


    /**
     * Returns the currently selected field
     *
     * @return mixed
     */
    public function getSelectedField(bool $strip_prefix = true): string
    {
        if ($strip_prefix) {
            return Strings::from($this->selected_field, $this->field_prefix);
        }

        return $this->selected_field;
    }


    /**
     * Allow the validator to check each element in a list of values.
     *
     * Basically, each method will expect to process a list always and ->select() will put the selected value in an
     * artificial array because of this. ->Each() actually will have a list of values, so puts that list directly into
     * $this->process_values
     *
     * @return static
     * @see DataValidator::select()
     * @see DataValidator::self()
     */
    public function forEachField(): static
    {
        // This very obviously only works on arrays
        $this->isArray();

        if (!$this->process_value_failed) {
            // Unset process_values first to ensure the byref link is broken
            unset($this->process_values);
            $this->process_values = &$this->selected_value;
        }

        return $this;
    }


    /**
     * Checks if the specified field exists in the currently selected array value
     *
     * @param string $field
     *
     * @return static
     * @see DataValidator::select()
     * @see DataValidator::self()
     */
    public function hasField(string $field): static
    {
        // This very obviously only works on arrays
        $this->isArray();

        if (!$this->process_value_failed) {
            // Check if the specified field exists
            if (!array_key_exists($field, $this->selected_value)) {
                $this->addFailure(tr('field ":field" is missing', [
                    ':field' => $field
                ]));
            }
        }

        return $this;
    }


    /**
     * This method will allow the currently selected key to pass without performing any validation Tests
     *
     * @return static
     */
    public function doNotValidate(): static
    {
        if ($this->test_count > 0) {
            // Cannot NOT validate, validation Tests have already been executed on it.
            throw new OutOfBoundsException(tr('Cannot skip validation tests on key ":key", there have already been ":count" validation tests executed on it', [
                ':key'   => $this->selected_field,
                ':count' => $this->test_count
            ]));
        }

        $this->test_count         = PHP_INT_MIN;
        $this->content_test_count = PHP_INT_MIN;
        return $this;
    }


    /**
     * This method will allow the currently selected key to pass without performing any validation Tests
     *
     * @return static
     */
    public function doNotValidateContent(): static
    {
        if ($this->content_test_count > 0) {
            // Cannot NOT validate, validation Tests have already been executed on it.
            throw new OutOfBoundsException(tr('Cannot skip validation tests on key ":key", there have already been ":count" content validation tests executed on it', [
                ':key'   => $this->selected_field,
                ':count' => $this->test_count
            ]));
        }

        $this->content_test_count = PHP_INT_MIN;
        return $this;
    }


    /**
     * This method will allow skipping validation of data at the cost of an Incident report
     *
     * @return static
     */
    public function skipValidation(): static
    {
        Incident::new()
                ->setSeverity(EnumSeverity::medium)
                ->setType('validation')
                ->setTitle(tr('Validation was skipped'))
                ->setBody(tr('Validation was requested to be skipped for the key ":key" during validation (with DataEntry object ":object")', [
                    ':key'    => $this->selected_field,
                    ':object' => get_class_or_datatype($this->getDataEntryObject())
                ]))
                ->setDetails([
                    'url' => Request::getUrl()
                ])
                ->setNotifyRoles('developer')
                ->save();

        $this->test_count         = PHP_INT_MIN;
        $this->content_test_count = PHP_INT_MIN;
        return $this;
    }


    /**
     * Sets the specified default value for the specified column if it was not received from the user
     *
     * @param mixed  $value
     * @param string $column
     *
     * @return void
     */
    public function setColumnDefault(mixed $value, string $column): void
    {
        if (!array_key_exists($column, $this->source)) {
            $this->source[$column] = $value;
        }
    }


    /**
     * Link the specified DataEntry to this validator
     *
     * @param DataEntryInterface|null $_data_entry
     *
     * @return static
     */
    public function setDataEntryObject(?DataEntryInterface $_data_entry): static {
        return $this->__setDataEntry($_data_entry)
                    ->setId($_data_entry?->getId(false));
    }


    /**
     * Sets the integer id for this object or null
     *
     * @param int|null $id
     *
     * @return static
     */
    public function setId(?int $id): static
    {
        $this->id = $id;

        return $this;
    }


    /**
     * Returns true if the specified key exists
     *
     * @param string $key
     *
     * @return bool
     */
    public function sourceKeyExists(string $key): bool
    {
        return array_key_exists($key, $this->source);
    }


    /**
     * Manually set one of the internal fields to the specified value
     *
     * @param string                           $key
     * @param array|string|int|float|bool|null $value
     *
     * @return static
     */
    public function setField(string $key, array|string|int|float|bool|null $value): static
    {
        $this->source[$key] = $value;

        return $this;
    }


    /**
     * Returns if failed fields will be cleared on validation
     *
     * @return bool
     */
    public function getClearFailedFields(): bool
    {
        return $this->clear_failed_fields;
    }


    /**
     * Sets if failed fields will be cleared on validation
     *
     * @param bool $clear_failed_fields
     *
     * @return static
     */
    public function setClearFailedFields(bool $clear_failed_fields): static
    {
        $this->clear_failed_fields = $clear_failed_fields;

        return $this;
    }


    /**
     * Returns the parent field with the specified name
     *
     * @return string|null
     */
    public function getParentField(): ?string
    {
        return $this->parent_field;
    }


    /**
     * Sets the parent field with the specified name
     *
     * @param string|null $field
     *
     * @return void
     */
    public function setParentField(?string $field): void
    {
        $this->parent_field = $field;
    }


    /**
     * Returns debug mode
     *
     * @return bool
     */
    public function getDebug(): bool
    {
        return $this->debug;
    }


    /**
     * Sets debug mode
     *
     * @param bool $debug
     *
     * @return static
     */
    public function setDebug(bool $debug): static
    {
        $this->debug = $debug;

        return $this;
    }


    /**
     * Copy the current value to the specified field
     *
     * @param string $field
     *
     * @return static
     */
    public function copyTo(string $field): static
    {
        $this->source[$field] = $this->selected_value;

        return $this;
    }


    /**
     * This method will make sure that either this field OR the other specified field will have a value
     *
     * @param string $field
     * @param bool   $rename
     *
     * @return static
     *
     * @see Validator::isOptional()
     * @see Validator::orColumn()
     */
    public function xorColumn(string $field, bool $rename = false): static
    {
        if (!str_starts_with($field, (string) $this->field_prefix)) {
            $field = $this->field_prefix . $field;
        }

        if ($this->selected_field === $field) {
            throw new ValidatorException(tr('Cannot validate XOR field ":field" with itself', [':field' => $field]));
        }

        if (isset_get($this->source[$this->selected_field])) {
            // The currently selected field exists, the specified field cannot exist
            if (isset_get($this->source[$field])) {
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
            if (!isset_get($this->source[$field])) {
                $this->addSoftFailure(tr('nor ":field" were set, where either one of them is required', [
                    ':field' => $field,
                ]));

            } else {
                // Yay, the alternate field exists, so this one can be made optional.
                $this->isOptional();
            }
        }

        return $this;
    }


    /**
     * This method will make the selected field optional IF the specified test equals false (loose comparison) and use
     * the specified $default instead
     *
     * @param mixed  $test
     * @param string $field
     * @param bool   $rename
     *
     * @return static
     */
    public function xorColumnIfTrue(mixed $test, string $field, bool $rename = false): static
    {
        if ($test) {
            return $this->xorColumn($field, $rename);
        }

        return $this;
    }


    /**
     * Add the specified failure message to the failure list
     *
     * @param string      $failure
     * @param string|null $field
     *
     * @return static
     */
    public function addSoftFailure(string $failure, ?string $field = null): static
    {
        return $this->doAddFailure($failure, $field, false);
    }


    /**
     * Add the specified failure message to the failure list
     *
     * @param string      $failure
     * @param string|null $field
     *
     * @return static
     */
    public function addFailure(string $failure, ?string $field = null): static
    {
        return $this->doAddFailure($failure, $field, true);
    }


    /**
     * Add the specified failure message to the failure list
     *
     * @param string      $failure
     * @param string|null $field
     * @param bool        $hard
     *
     * @return static
     */
    protected function doAddFailure(string $failure, ?string $field, bool $hard): static
    {
        if (static::$disabled) {
            return $this;
        }

        // Detect field name to store this failure
        if ($field) {
            $selected_field = $field;

        } else {
            $field          = $this->selected_field;
            $selected_field = $this->selected_field;
        }

//        // Detect field name to store this failure
//        if ($field) {
//            $selected_field = $field;
//
//        } else {
//            $selected_field = $this->selected_field;
//
//            if ($this->parent_field) {
//                $field = $this->parent_field . '>' . $selected_field;
//
//            } else {
//                if (!$selected_field) {
//                    throw OutOfBoundsException::new(tr('No field specified or selected for validation failure ":failure"', [
//                        ':failure' => $failure,
//                    ]));
//                }
//
//                $field = $selected_field;
//            }
//
//            if ($this->process_key) {
//                $field .= '>' . $this->process_key;
//            }
//        }

        $failure = trim($failure);

        if (Debug::isEnabled()) {
            if ($this->_definitions?->getDataEntryObject()) {
                Log::write(ts('Validation failed for ":class" DataEntry field ":field" with value ":value" because :failure', [
                    ':class'   => get_class($this->_definitions->getDataEntryObject()),
                    ':field'   => ($this->parent_field ?? '-') . ' / ' . $selected_field . ' / ' . ($this->process_key ?? '-'),
                    ':failure' => $failure,
                    ':value'   => $this->source[$selected_field],
                ]), 'debug', 6);

            } else {
                Log::write(ts('Validation failed for non DataEntry field ":field" with value ":value" because :failure', [
                    ':field'   => ($this->parent_field ?? '-') . ' / ' . $selected_field . ' / ' . ($this->process_key ?? '-'),
                    ':failure' => $failure,
                    ':value'   => $this->source[$selected_field],
                ]), 'debug', 6);
            }

            Log::write('Validation failed on value below:', 'debug', 6);

            // Try to display the value nicely
            if (is_object($this->selected_value)) {
                if ($this->selected_value instanceof ArrayableInterface) {
                    Log::printr($this->selected_value->__toArray(), 6, echo_header: false);

                } elseif ($this->selected_value instanceof Stringable) {
                    Log::printr($this->selected_value->__toString(), 6, echo_header: false);

                } else {
                    Log::printr(get_class_or_datatype($this->selected_value), 6, echo_header: false);
                }

            } else {
                Log::printr($this->selected_value, 6, echo_header: false);
            }

            Log::write('Validation backtrace:', 'debug', 6);
            Log::backtrace(threshold: 6);
            Log::write('Validation data source:', 'debug', 6);
            Log::write(get_null($this->source) ? print_r($this->source, true) : '-', 'debug', 6, clean: false);
        }

        // Build up the failure string
        if (is_numeric($this->process_key)) {
            if (is_numeric($selected_field)) {
                if ($this->parent_field) {
                    $failure = tr('The ":key" field in ":field" in ":parent" ', [
                        ':key'    => Strings::ordinalIndicator($this->process_key),
                        ':field'  => Strings::ordinalIndicator($selected_field),
                        ':parent' => $this->parent_field,
                    ]) . $failure;

                } else {
                    $failure = tr('The ":key" field in ":field" ', [
                        ':key'   => Strings::ordinalIndicator($this->process_key),
                        ':field' => Strings::ordinalIndicator($selected_field),
                    ]) . $failure;
                }

            } elseif ($this->parent_field) {
                $failure = tr('The ":key" field in ":field" in ":parent" ', [
                    ':key'    => Strings::ordinalIndicator($this->process_key),
                    ':field'  => $selected_field,
                    ':parent' => $this->parent_field,
                ]) . $failure;

            } else {
                $failure = tr('The ":key" field in ":field" ', [
                    ':key'   => Strings::ordinalIndicator($this->process_key),
                    ':field' => $selected_field,
                ]) . $failure;
            }

        } elseif (is_numeric($selected_field)) {
            if ($this->parent_field) {
                $failure = tr('The ":key" field in ":parent" ', [
                    ':count'  => Strings::ordinalIndicator($selected_field),
                    ':parent' => $this->parent_field,
                ]) . $failure;

            } else {
                $failure = tr('The ":key" field ', [
                    ':count' => Strings::ordinalIndicator($selected_field)
                ]) . $failure;
            }

        } elseif ($this->parent_field) {
            $failure = tr('The ":field" field in ":parent" ', [
                ':parent' => $this->parent_field,
                ':field'  => $selected_field,
            ]) . $failure;

        } else {
            $failure = tr('The ":field" field ', [
                ':field' => $selected_field
            ]) . $failure;
        }

        $failure = [
            'hard'    => $hard,
            'label'   => $field,
            'column'  => $field,
            'value'   => $this->selected_value,
            'message' => $failure
        ];

        if ($this->getDefinitionsObject()?->keyExists($field)) {
            $failure['required_datatype']  = $this->getDefinitionsObject()->get($field)->getDatatype();
            $failure['required_maxlength'] = $this->getDefinitionsObject()->get($field)->getMaxLength();
        }

        // Store the failure
        $this->process_value_failed = true;
        $this->failures[$field]     = $failure;

        if ($this->direct_mode) {
            // Do not gather multiple failures, fail directly on the first encountered failure
            $this->processFailures();
        }

        return $this;
    }


    /**
     * Renames the current field to the specified field name
     *
     * @param string $field_name
     *
     * @return static
     */
    public function rename(string $field_name): static
    {
        $this->source[$field_name] = $this->source[$this->selected_field];
        unset($this->source[$this->selected_field]);
        $this->selected_field = $field_name;

        return $this;
    }


    /**
     * This method will make the selected field optional IF the specified test equals false (loose comparison) and use
     * the specified $default instead
     *
     * @param mixed $test
     * @param mixed|null $default
     * @return static
     */
    public function isOptionalIfTrue(mixed $test, mixed $default = null): static
    {
        if ($test) {
            return $this->isOptional($default);
        }

        return $this;
    }


    /**
     * This method will make the selected field optional and use the specified $default instead
     *
     * This means that either it may not exist, or it is contents may be NULL
     *
     * @param mixed $default
     *
     * @return static
     *
     * @see Validator::xorColumn()
     * @see Validator::orColumn()
     */
    public function isOptional(mixed $default = null): static
    {
        $this->selected_is_optional = true;
        $this->selected_optional    = $default;

        return $this;
    }


    /**
     * This method will validate that the specified key is set as well, in case the current key is not the default
     *
     * @param int|string $field
     *
     * @return static
     */
    public function requiresField(int|string $field): static
    {
        if (!$this->selected_is_default) {
            if (empty($this->source[$field])) {
                $this->addSoftFailure(tr('requires field ":field" to have a valid value as well', [
                    ':field' => $field,
                ]));
            }
        }

        return $this;
    }


    /**
     * This method will validate that the specified key is NOT set
     *
     * @param int|string $field
     *
     * @return static
     */
    public function requiresNotField(int|string $field): static
    {
        if (!$this->selected_is_default) {
            if (empty($this->source[$field])) {
                $this->addSoftFailure(tr('cannot be combined with field ":field"', [
                    ':field' => $field,
                ]));
            }
        }

        return $this;
    }


    /**
     * This method will make sure that either this field OR the other specified field optionally will have a value
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
        // Ensure that the specified field has the field prefix added if required so
        if (!str_starts_with($field, (string) $this->field_prefix)) {
            $field = $this->field_prefix . $field;
        }

        if ($this->selected_field === $field) {
            throw new ValidatorException(tr('Cannot validate OR field ":field" with itself', [
                ':field' => $field,
            ]));
        }

        // If the specified OR field does not exist, this field will be required
        if (!isset_get($this->source[$this->selected_field])) {
            if (!$this->selected_is_optional) {
                // The currently selected field is required but does not exist, so the other must exist
                if (!isset_get($this->source[$field])) {
                    $this->addSoftFailure(tr('nor ":field" field were set, where at least one of them is required', [
                        ':field' => $field,
                    ]));

                } else {
                    // The other field exists, making this one optional
                    $this->isOptional();
                }
            }
        }

        return $this;
    }


    /**
     * Allows the currently selected column to validate against a different set of rules if the first set failed
     *
     * @return static
     */
    public function or(): static
    {
        if ($this->process_value_failed) {
            // This field failed. Remove the failure data so that we can perform the next set of tests
            $this->process_value_failed = false;
            unset($this->failures[$this->selected_field]);

        } else {
            // This field has not failed so far, so the OR does not have to check the rest. To do this, mark this field
            // as having a default value, even though it (possibly) does not, this way any future checks will be skipped
            $this->selected_is_default = true;
        }

        return $this;
    }


    /**
     * Will validate that the specified argument was not specified
     *
     * @param string $argument
     * @param mixed  $value                The value of said argument.
     * @param mixed  $required_equivalence The value of said argument.
     * @param bool   $strict
     *
     * @return static
     * @see Validator::isOptional()
     */
    public function xorArgument(string $argument, mixed $value, mixed $required_equivalence = null, bool $strict = false): static
    {
        return $this->validateValues(function ($selected_value) use ($argument, $value, $required_equivalence, $strict) {
            if ($this->process_value_failed) {
                // Validation already failed, do not test anything more
                return '';
            }

            if ($selected_value) {
                if ($strict) {
                    if ($value !== $required_equivalence) {
                        $failed = true;
                    }

                } else {
                    if ($value != $required_equivalence) {
                        $failed = true;
                    }
                }
            }

            if (isset($failed)) {
                $this->addSoftFailure(tr('cannot be used with argument ":argument"', [
                    ':argument' => Cli::validateAndSanitizeArgument($argument, false)
                ]));
            }

            return $selected_value;
        });
    }


    /**
     * Will validate that the value of this field matches the value for the specified field
     *
     * @param string $field
     * @param bool   $strict If true will execute a strict comparison where the datatype must match as well (so 1 would
     *                       not be the same as "1") for example
     *
     * @return static
     * @see Validator::isOptional()
     */
    public function isEqualTo(string $field, bool $strict = false): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function ($value) use ($field, $strict) {
            if ($this->process_value_failed) {
                // Validation already failed, do not test anything more
                return '';
            }

            if ($strict) {
                if ($value !== $this->source[$field]) {
                    $this->addSoftFailure(tr('must contain exactly the same value as the field ":field"', [':field' => $field]));
                }

            } else {
                if ($value != $this->source[$field]) {
                    $this->addSoftFailure(tr('must contain the same value as the field ":field"', [':field' => $field]));
                }
            }

            return $value;
        });
    }


    /**
     * Recurse into a subarray and return another validator object for that subarray
     *
     * @return static
     */
    public function recurse(): static
    {
        $this->ensureSelected();

        // Create a new Validator object from the current value. If the current value is not an array (oopsie) then just
        // send in an empty array so that the Validation chain will not break
        if (!is_array($this->selected_value)) {
            $array = [];
            $child = new static($array, $this);

        } else {
            $child = new static($this->selected_value, $this);
        }

        $child->setParentField(($this->parent_field ? $this->parent_field . ' > ' : '') . $this->selected_field);
        $this->children[$this->selected_field] = $child;

        return $child;
    }


    /**
     * Ensure that a key has been selected
     *
     * @return void
     */
    protected function ensureSelected(): void
    {
        if (empty($this->selected_field)) {
            throw new NoKeySelectedException(tr('Cannot execute validations, no field has been selected', [
                ':key' => $this->selected_field,
            ]));
        }
    }


    /**
     * This will add the specified fields to the ignore list
     *
     * @param IteratorInterface|ArrayableInterface|array|string|null $fields
     *
     * @return static
     */
    public function ignoreFields(IteratorInterface|ArrayableInterface|array|string|null $fields): static
    {
        $fields = Arrays::force($fields);

        foreach ($fields as $field) {
            $this->getIgnoreObject(true)->append(true, $field);
        }

        return $this;
    }


    /**
     * Called at the end of defining all validation rules.
     *
     * This method will check the "failures" array and if any failures were registered, it will throw an exception
     *
     * @param bool $require_clean_source [true] If true, requires that this validation will select and validate ALL values in the validator source. If any
     *                                          variables are left when Validator::validate() is called, a ValidationFailedException will be thrown
     * @param bool $exception            [true] If true, and validation failed, will throw a ValidationFailedException. If false, will log the failure, and
     *                                          return the data as if validation was successful. THIS IS ONLY ALLOWED ON DEBUG PLATFORMS! This variable will
     *                                          cause a ValidatorException if this variable is false on production platforms
     *
     * @return array
     */
    public function validate(bool $require_clean_source = true, bool $exception = true): array
    {
        // Check if there is still a field selected that has no test applied.
        // If so, fail, because all fields must be tested
        try {
            if (!$this->process_value_failed and !$this->selected_is_default) {
                if ($this->selected_field and empty($this->test_count)) {
                    if ($this->getDatatypeValidationEnabled()) {
                        throw new ValidatorException(tr('Cannot validate because the last selected field ":field" has no validations performed yet', [
                            ':field' => $this->selected_field,
                        ]));
                    }

                    Log::error(ts('WARNING: SKIPPED VALIDATION DUE TO security.validation.disabled = false CONFIGURATION! SYSTEM MAY BE IN UNKNOWN STATE!'));
                }

                if ($this->selected_field and empty($this->content_test_count)) {
                    if ($this->getContentValidationEnabled()) {
                        throw new ValidatorException(tr('Cannot validate because the last selected field ":field" has no content validations performed yet', [
                            ':field' => $this->selected_field,
                        ]));
                    }

                    Log::error(ts('WARNING: SKIPPED CONTENT VALIDATION DUE TO security.validation.content.disabled = false CONFIGURATION! SYSTEM MAY BE IN UNKNOWN STATE!'));
                }
            }

            $unclean = $this->getExtraFields($require_clean_source);

            $this->processFailures();

            if (isset($unclean)) {
                $this->processUnclean($unclean);
            }

        } catch (ValidationFailedException $e) {
            if ($this->getValidationEnabled() and $exception) {
                throw $e;
            }

            if (Core::isProductionEnvironment()) {
                throw new ValidatorException(tr('Validation failed but throwing ValidationFailedException has been disabled either by configuration path ":path" or by the $exception variable passed to this ":method" method while on a production platform, which is prohibited', [
                    ':path'   => 'security.validation.enabled',
                    ':method' => static::class . '::validate()'
                ]));
            }

            Log::error(ts('WARNING: SKIPPED VALIDATION DUE TO CONFIGURATION PATH "security.validation.disabled" BEING false! SYSTEM MAY BE IN UNKNOWN STATE!'));
        }

        static::$has_been_validated = true;

        return Arrays::extract($this->source, $this->selected_fields);
    }


    /**
     * Returns true if the data in this validator has been completely validated
     *
     * @param bool $require_clean_source
     *
     * @return bool
     */
    public function hasBeenValidated(bool $require_clean_source = true): bool
    {
        if (static::$has_been_validated) {
            if (!$require_clean_source or !static::$unclean) {
                return true;
            }
        }

        return false;
    }


    /**
     * Throws a ValidatorException if the data in this validator has not yet been completely validated
     *
     * @param bool $require_clean_source
     *
     * @return static
     * @throws ValidatorException
     */
    public function checkHasBeenValidated(bool $require_clean_source = true): static
    {
        if ($this->hasBeenValidated($require_clean_source)) {
            return $this;
        }

        throw ValidatorException::new(tr('The ":class" data has not been completely validated. Check the executed program ":program" to see if it executes validation correctly and completely', [
            ':class'   => Strings::from(static::class, '\\'),
            ':program' => Request::getExecutedFile(),
        ]))->setData([
            'require_clean_source' => Strings::fromBoolean($require_clean_source),
            'has_been_validated'   => Strings::fromBoolean(static::$has_been_validated),
            'unclean'              => static::$unclean,
            'executed_program'     => Request::getExecutedFile(),
            'validator_source'     => $this->source,
        ]);
    }


    /**
     * Processes unclean data
     *
     * @param array $unclean
     *
     * @return void
     */
    protected function processUnclean(array $unclean): void
    {
        if ($this->getValidationEnabled()) {
            throw ValidationFailedException::new(tr('Data validation failed because of the following unknown fields'))
                                           ->addData(['failures' => $unclean])
                                           ->makeWarning();
        }

        Log::error('WARNING: SKIPPED DATA CLEAN VALIDATION DUE TO security.validation.disabled = false CONFIGURATION! SYSTEM DATA MAY BE IN UNKNOWN STATE!');
    }


    /**
     * Processes fields that failed to validate
     *
     * @return void
     */
    protected function processFailures(): void
    {
        if ($this->failures) {
            $values = Arrays::keepKeys($this->source, array_keys($this->failures));

            if (Core::inBootState() or $this->getValidationEnabled()) {
                $permit          = $this->getPermitValidationFailures();
                $this->exception = ValidationFailedException::new(tr('Data validation failed with the following issues:'))
                                                            ->setDataEntryObject($this->_definitions?->getDataEntryObject())
                                                            ->addData([
                                                                'class'    => $this->_data_entry ? $this->_data_entry::class : 'N/A',
                                                                'failures' => $this->failures,
                                                                'values'   => $values
                                                            ]);

                switch ($permit) {
                    case EnumSoftHard::hard:
                        // All validation failures are permitted
                        // no break

                    case EnumSoftHard::soft:
                        // Only soft validation failures are permitted
                        $hard_fail = $this->preProcessSoftHardFailures($permit, $failures);

                        if (!$hard_fail) {
                            $this->processSoftHardFailures($failures);
                            break;
                        }

                        // We are allowing only soft failures, and a hard failure was detected
                        // no break

                    case EnumSoftHard::none:
                        throw $this->exception;
                }

            } else {
                Log::error('WARNING: SKIPPED FIELDS VALIDATION DUE TO "security.validation.enabled" = false CONFIGURATION! SYSTEM DATA MAY BE IN UNKNOWN STATE!');
            }
        }
    }


    /**
     * Process all soft failures
     *
     * @param array $failures
     *
     * @return void
     */
    protected function processSoftHardFailures(array $failures): void
    {
        // Modify to fix all values that have validation issues. SOFT failures require no
        // modifications, HARD failures do
        foreach ($failures as $column => $failure) {
            $_definition = $this->getDataEntryObject()?->getDefinitionsObject()?->get($column);

            if ($failure['hard']) {
                if (array_key_exists('required_datatype', $failure)) {
                    // Force datatype
                    switch ($failure['required_datatype']) {
                        case 'string':
                            $this->source[$column] = (string) $this->source[$column];

                            if (array_key_exists('required_maxlength', $failure)) {
                                // Force maxlength
                                $this->source[$column] = substr((string) $this->source[$column], 0, $failure['required_maxlength'] - 1);
                            }

                            break;

                        case 'int':
                            $this->source[$column] = (int) $this->source[$column];

                            // We are forcing numbers to be 0 but that might cause problems with database id's
                            if (empty($this->source[$column]) and $_definition->hasRealInputType(EnumInputType::dbid)) {
                                // Database ID's CANNOT be zero
                                $this->source[$column] = null;
                            }

                            break;

                        case 'float':
                            $this->source[$column] = (float) $this->source[$column];
                            break;

                        case 'bool':
                            $this->source[$column] = (bool) $this->source[$column];
                            break;

                        case 'array':
                            $this->source[$column] = (array) $this->source[$column];
                            break;
                    }
                }
            }
        }
    }


    /**
     * Checks all failures for hard or soft failures, and builds up a list of failures to process
     *
     * @param EnumSoftHard $permit
     * @param array|null   $failures
     *
     * @return bool
     */
    protected function preProcessSoftHardFailures(EnumSoftHard $permit, ?array &$failures): bool
    {
        $hard_fail = false;
        $failures  = [];

        foreach ($this->failures as $column => $failure) {
            if ($failure['hard']) {
                if ($permit === EnumSoftHard::soft) {
                    // Oops, this is a hard failure, not allowed
                    $hard_fail = true;
                    break;
                }
            }

            $failures[$column] = $failure;
        }

        return $hard_fail;
    }


    /**
     * Processes parent validators
     *
     * @deprecated This method will be removed
     *
     * @return void
     */
    protected function validateProcessParent(): void
    {
        if ($this->_parent) {
throw new ObsoleteException();
//            // Copy failures from the child to the parent and return the parent to continue
//            foreach ($this->failures as $field => $failure) {
//                $this->_parent->addSoftFailure($failure, $field);
//            }
//
//            // Clear the contents of this object to avoid stuck references
//            $this->clear();
//
//            // TODO Fix parent support
//            return $this->_parent->validate();
        }
    }


    /**
     * Process possible extra fields in the validator source
     *
     * @param bool $require_clean_source
     *
     * @return array|null
     */
    protected function getExtraFields(bool $require_clean_source = true): ?array
    {
        $unclean = [];

        // Remove all unselected and all failed fields
        foreach ($this->source as $field => $value) {
            // Unprocessed fields
            if ($require_clean_source) {
                // Ignore fields?
                if ($this->ignore?->getCount()) {
                    if ($this->ignore->keyExists($field)) {
                        if (in_array($field, $this->selected_fields)) {
                            // These fields were specified to be skipped but also selected!
                            throw new ValidatorException(tr('Cannot validate because the field ":field" was specified to be ignored but it was also selected for validation', [
                                ':field' => $field,
                            ]));
                        }

                        // This field should be ignored
                        unset($this->source[$field]);
                        continue;
                    }
                }

                switch ($field) {
                    case '__csrf':
                        // no break

                    case '__display_mode':
                        // no break

                    case 'submit-button':
                        // These fields are always ignored
                        break;

                    default:
                        if (!in_array($field, $this->selected_fields)) {
                            // TODO Does this still hold true? meta columns should NEVER be submitted!!!
                            // These fields were never selected, so we do not know them. Are they meta-columns? If so, ignore
                            // them because they will have been set manually (DataEntry::apply() will ignore meta columns)
                            if (empty($this->meta_columns) or !in_array($field, $this->meta_columns)) {
                                $unclean[$field] = [
                                    'hard'      => false,
                                    'label'     => $field,
                                    'column'    => $field,
                                    'value'     => $value,
                                    'message'   => tr('The field ":field" with value ":value" is unknown', [
                                        ':field' => $field,
                                        ':value' => $value,
                                    ])
                                ];

                                unset($this->source[$field]);
                                continue 2;
                            }
                        }
                }
            }

            // Failed fields
            if (array_key_exists($field, $this->failures)) {
                if ($this->clear_failed_fields) {
                    unset($this->source[$field]);
                }
            }
        }

        return static::$unclean = get_null($unclean);
    }


    /**
     * Clears all the internal content for this object
     *
     * @return static
     */
    public function clear(): static
    {
        unset($this->selected_fields);
        unset($this->selected_value);
        unset($this->process_values);
        unset($this->process_value);
        unset($this->source);

        $this->selected_fields = [];
        $this->selected_value  = null;
        $this->process_values  = null;

        return parent::clear();
    }


    /**
     * Returns the list of failures found during validation
     *
     * @return array
     */
    public function getFailures(): array
    {
        return $this->failures;
    }


    /**
     * Returns if the currently selected field failed or not
     *
     * @return bool
     */
    public function getSelectedFieldHasFailed(): bool
    {
        return $this->fieldHasFailed($this->selected_field);
    }


    /**
     * Returns true if the specified field has failed
     *
     * @param string $field
     *
     * @return bool
     */
    public function fieldHasFailed(string $field): bool
    {
        if (!array_key_exists($field, $this->source)) {
            throw new OutOfBoundsException(tr('The specified field ":field" does not exist', [
                ':field' => $field,
            ]));
        }

        return array_key_exists($field, $this->failures);
    }


    /**
     * Return true if this field was empty and now has the specified optional value and does not require validation
     *
     * @note This process will set the static::process_value_failed to true when the optional value is applied to stop
     *       further testing.
     *
     * @param mixed $value The value to test
     *
     * @return bool
     */
    protected function hasOptionalValue(mixed &$value): bool
    {
        if ($this->process_value_failed) {
            // Value processing already failed anyway, so always fail
            return true;
        }

        // DEBUG CODE: In case of errors with validation, it is very useful to have these debugged here
        // show($this->selected_field);
        // show($value);
        // show($this->selected_is_optional);

        if (!$value) {
            // Value 0 IS CONSIDERED A VALUE
            if (!is_numeric($value)) {
                if (!$this->selected_is_optional) {
                    // At this point we know we MUST have a value, so we are bad here
                    $this->addFailure(tr('is required'));
                    return true;
                }

                // If the value is set or not does not matter, it is okay
                $value                      = $this->selected_optional;
                $this->selected_is_default  = true;
                $this->process_value_failed = true;
                return true;
            }
        }

        // The field has a value, we are okay
        return false;
    }


    /**
     * Apply the specified anonymous function on a single or all of the process_values for the selected field
     *
     * @param callable $function
     *
     * @return static
     */
    protected function validateValues(callable $function): static
    {
        if ($this->reflection_process_value->isInitialized($this)) {
            // A single value was selected, test only this value
            $function($this->process_value);

        } else {
            $this->ensureSelected();

            if ($this->process_value_failed or $this->selected_is_default) {
                // In the span of multiple Tests on one value, one test failed, do not execute the rest of the Tests
                return $this;
            }

            foreach ($this->process_values as $key => &$value) {
                // Process all process_values
                $this->process_key   = $key;
                $this->process_value = &$value;
// TODO TEST THIS! IF next line is enabled then multiple Tests after each other will continue, even if the previous failed!!
//                $this->process_value_failed = false;
                $this->selected_is_default = false;

                $function($this->process_value);
            }

            // Clear up work data
            unset($value);
            unset($this->process_value);

            $this->process_key = null;
        }

        return $this;
    }


    /**
     * Will let the validator treat the value as a single variable
     *
     * Basically each method will expect to process a list always and ->select() will put the selected value in an
     * artificial array because of this. ->forEachField() actually will have a list of values, so puts that list directly into
     * $this->process_values
     *
     * @return static
     * @see DataValidator::select()
     * @see DataValidator::eachField()
     */
    public function single(): static
    {
        $this->process_values = [&$this->selected_value];

        return $this;
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is an array
     *
     * @return static
     */
    public function isArray(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            if (!$this->hasOptionalValue($value)) {
                if (!is_array($value)) {
                    if ($value !== null) {
                        $this->addFailure(tr('must have an array value'));
                    }

                    $value = [];
                }
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is a boolean
     *
     * @param bool|null $string tristate variable, false means MUST be boolean, true means MUST be string
     *                          ("true", "false"), NULL means MAY be either string or boolean
     *
     * @return static
     */
    public function isBoolean(?bool $string = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($string) {
            if ($this->hasOptionalValue($value)) {
                if (!is_bool($this->selected_optional)) {
                    if ($this->selected_optional !== null) {
                        throw new OutOfBoundsException(tr('Invalid default data ":data" specified for field ":selected", it must be boolean', [
                            ':data'     => $this->selected_optional,
                            ':selected' => $this->selected_field,
                        ]));
                    }

                    $this->selected_optional = false;
                }

                $value = $this->selected_optional;

            } else {
                $fail = true;

                if (($string === true) or ($string === null)) {
                    $value = Strings::toBoolean($value, false);
                    $fail  = ($value === null);
                }

                if ($fail and (($string === false) or ($string === null))) {
                    $fail = is_bool($value);
                }

                if ($fail) {
                    $this->addFailure(tr('must have a boolean value'));
                }
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is an integer
     *
     * @return static
     */
    public function isInteger(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            if (!$this->hasOptionalValue($value)) {
                if (!is_integer($value)) {
                    if (((int) $value) == $value) {
                        // This integer value was specified as a numeric string
                        $value = (int) $value;

                    } else {
                        $this->addFailure(tr('must have an integer value'));
                    }
                }
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is positive
     *
     * @param bool $allow_zero
     *
     * @return static
     */
    public function isPositive(bool $allow_zero = true): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($allow_zero) {
            $this->isNumeric();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (($allow_zero and ($value < 0)) or (!$allow_zero and ($value <= 0))) {
                $this->addSoftFailure(tr('must have a positive value'));
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is negative
     *
     * @param bool $allow_zero
     *
     * @return static
     */
    public function isNegative(bool $allow_zero = false): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($allow_zero) {
            $this->isNumeric();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if ($value > ($allow_zero ? 0 : 1)) {
                $this->addSoftFailure(tr('must have a negative value'));
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is a valid natural number (integer, 1 and above)
     *
     * @param bool $allow_zero
     *
     * @return static
     */
    public function isNatural(bool $allow_zero = true): static
    {
        $this->test_count++;

        $this->isInteger();

        if ($this->process_value_failed or $this->selected_is_default) {
            // Validation already failed or defaulted, do not test anything more
            return $this;
        }

        return $this->isPositive($allow_zero);
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is numeric
     *
     * @return static
     */
    public function isNumeric(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            if (!$this->hasOptionalValue($value)) {
                if (!is_numeric($value)) {
                    if ($value !== null) {
                        $this->addSoftFailure(tr('must have a numeric value'));
                    }

                    $value = 0;

                } else {
                    // Yay, the value is numeric, but is it a float or an integer? Detect and convert here.
                    $original = $value;
                    $value    = (int) $value;

                    if ($original == $value) {
                        // It looks like value was an int, keep it
                        return;
                    }

                    $value = (float) $original;
                }
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is an float
     *
     * @return static
     */
    public function isFloat(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            if (!$this->hasOptionalValue($value)) {
                if (!is_float($value)) {
                    if (is_string($value) and ((float) $value == $value)) {
                        // This float value was specified as a numeric string
                        // TODO Test this! There may be slight inaccuracies here due to how floats work, so maybe we should check within a range?
                        $value = (float) $value;

                    } else {
                        if ($value !== null) {
                            $this->addFailure(tr('must have a float value'));
                        }

                        $value = 0.0;
                    }
                }
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is a string
     *
     * @param int|false|null $max_characters
     *
     * @return static
     */
    public function isString(int|false|null $max_characters = null): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($max_characters) {
            if (!$this->hasOptionalValue($value)) {
                if (!is_string($value)) {
                    if ($value instanceof Stringable) {
                        // Force object to be string from here
                        $value = (string) $value;

                    } elseif (is_enum($value)) {
                        // Force enum to be string from here
                        $value = (string) $value->value;

                    } elseif (!is_numeric($value)) {
                        if ($value !== null) {
                            $this->addFailure(tr('must have a string value'));
                        }

                        $value = '';

                    } else {
                        // A number is allowed to be interpreted as a string
                        $value = (string) $value;
                    }
                }

                // Ensure this string is smaller than the maximum supported string size
                if ($max_characters !== false) {
                    $this->hasMaxCharacters($max_characters, false);
                }
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is a scalar value
     *
     * @return static
     */
    public function isScalar(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            if (!$this->hasOptionalValue($value)) {
                if (!is_scalar($value)) {
                    if ($value !== null) {
                        $this->addFailure(tr('must have a scalar value'));
                    }

                    $value = '';
                }
            }
        });
    }


    /**
     * Validates that the specified value is either an integer number, or a valid number of bytes
     *
     * 1KB    = 1000
     * 1MB    = 1000000
     * 1GB    = 1000000000
     * 1GiB   = 1073741824
     * 1.5GiB = 1610612736
     * etc...
     *
     * @return static
     * @see trim()
     */
    public function isBytes(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->sanitizeTrim()->hasMaxCharacters(32);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!is_numeric_integer($value)) {
                try {
                    $value = Numbers::fromBytes($value);

                } catch (Throwable) {
                    $this->addSoftFailure(tr('must have a valid byte size, like 1000, 1000kb, 1000MiB, etc'));
                }
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is between the two specified amounts
     *
     * @param int|float $minimum
     * @param int|float $maximum
     * @param bool      $equal
     *
     * @return static
     */
    public function isBetween(int|float $minimum, int|float $maximum, bool $equal = true): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($minimum, $maximum, $equal) {
            $this->isNumeric();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if ($equal) {
                if (($value < $minimum) or ($value > $maximum)) {
                    $this->addSoftFailure(tr('must be between ":minimum" and ":maximum"', [
                        ':minimum' => $minimum,
                        ':maximum' => $maximum,
                    ]));
                }

            } else {
                if (($value <= $minimum) or ($value >= $maximum)) {
                    $this->addSoftFailure(tr('must be between ":minimum" and ":maximum"', [
                        ':minimum' => $minimum,
                        ':maximum' => $maximum,
                    ]));
                }
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is a valid latitude coordinate
     *
     * @return static
     */
    public function isLatitude(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            $this->isFloat();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return $this;
            }

            return $this->isBetween(-90, 90);
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is a valid longitude coordinate
     *
     * @return static
     */
    public function isLongitude(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            $this->isFloat();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return $this;
            }

            return $this->isBetween(-180, 180);
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is a valid database id (integer, 1 and above)
     *
     * @param bool $allow_zero
     * @param bool $allow_negative
     *
     * @return static
     */
    public function isDbId(bool $allow_zero = false, bool $allow_negative = false): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($allow_zero, $allow_negative) {
            $this->isNatural();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return $this;
            }

            if ($allow_negative) {
                if ($allow_zero) {
                    return $this;
                }

                return $this->isNotValue(0);
            }

            return $this->isPositive($allow_zero);
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is a valid database id (integer, 1 and above)
     *
     * @param string|null $failure_message [null] If specified, will use this string as a validation error message instead of the default text
     * @param string|null $column          [null] The column in which this value should exist
     * @param string|null $table           [null] The table in which this value should exist
     *
     * @return static
     */
    public function existsInDatabase(?string $failure_message = null, ?string $column = null, ?string $table = null): static
    {
        if (empty($this->content_test_count)) {
            throw new ValidatorException(ts('Cannot validate if value exists in table ":table" column ":column", the value has not yet had its contents checked', [
                ':table'  => $table,
                ':column' => $column,
            ]));
        }

        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($table, $column, $failure_message) {
            // The value must at least have 1 character to work with!
            $this->isScalar()->hasMinCharacters(1, false);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $table  = $table  ?? $this->table;
            $column = $column ?? $this->selected_field;

            if (empty($table)) {
                throw new ValidatorException(tr('Cannot validate if database id exists, no table configured for this validator, and no table specified'));
            }

            $exists = sql()->getColumn('SELECT `' . $column . '` FROM `' . $table . '` WHERE `' . $column . '` = :' . $column, [
                ':' . $column => $value,
            ]);

            if (!$exists) {
                $this->addSoftFailure($failure_message ?? tr('does not exist in the database'));
            }
        });
    }


    /**
     * Validates that the selected field is the specified value
     *
     * @param mixed $validate_value The value that should not be matched
     * @param bool  $strict         If true, will perform a strict check
     * @param bool  $secret         If true, the $validate_value will not be shown
     * @param bool  $ignore_case    If true, a case-insensitive comparison will be performed
     *
     * @return static
     * @todo Change these individual flag parameters to one bit flag parameter
     */
    public function isNotValue(mixed $validate_value, bool $strict = false, bool $secret = false, bool $ignore_case = true): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($validate_value, $strict, $secret, $ignore_case) {
            if ($strict) {
                // Strict validation
                if ($value === $validate_value) {
                    if ($secret) {
                        $this->addSoftFailure(tr('must not be exactly value ":value"', [':value' => $value]));

                    } else {
                        $this->addSoftFailure(tr('has an incorrect value'));
                    }
                }

            } else {
                $this->isScalar();

                if ($this->process_value_failed or $this->selected_is_default) {
                    // Validation already failed or defaulted, do not test anything more
                    return;
                }

                if ($ignore_case) {
                    $compare_value  = strtolower((string) $value);
                    $validate_value = strtolower((string) $validate_value);

                } else {
                    $compare_value = $value;
                }

                if ($compare_value == $validate_value) {
                    if ($secret) {
                        $this->addSoftFailure(tr('must not be value ":value"', [':value' => $value]));

                    } else {
                        $this->addSoftFailure(tr('has an incorrect value'));
                    }
                }
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is a valid code
     *
     * @param string|null       $until
     * @param int|null          $max_characters
     * @param int|null          $min_characters
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isCode(?string $until = null, ?int $max_characters = 64, ?int $min_characters = 1, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($until, $min_characters, $max_characters, $encoding) {
            $this->hasEncoding($encoding, true, $max_characters, $min_characters);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if ($until) {
                // Truncate the code at one of the specified characters
                $value = Strings::until($value, $until);
                $value = trim($value);
            }

            $this->isPrintable();
        });
    }


    /**
     * Validates that the selected field is equal or larger than the specified number of characters
     *
     * @param int  $characters
     * @param bool $check_datatype
     *
     * @return static
     */
    public function hasMinCharacters(int $characters, bool $check_datatype = true): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($characters, $check_datatype) {
            if ($check_datatype) {
                $this->isString();
            }

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (strlen((string) $value) < $characters) {
                $this->addSoftFailure(tr('must have ":count" characters or more', [':count' => $characters]));
            }
        });
    }


    /**
     * Validates that the selected field is equal or shorter than the specified number of characters
     *
     * @param int|null $max_characters
     * @param bool     $check_datatype
     *
     * @return static
     */
    public function hasMaxCharacters(?int $max_characters = null, bool $check_datatype = true): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($max_characters, $check_datatype) {
            if ($check_datatype) {
                $this->isString(false);
            }

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // Validate the maximum number of characters
            $max_characters = $this->getMaxStringSize($max_characters);

            if ($max_characters <= 0) {
                if (!$max_characters) {
                    throw new ValidatorException(tr('Cannot check max characters, the number of maximum characters specified is 0'));
                }

                throw new ValidatorException(tr('Cannot check max characters, the specified number of maximum characters ":characters" is negative', [
                    ':characters' => $max_characters,
                ]));
            }

            if (strlen((string) $value) > $max_characters) {
                $this->addSoftFailure(tr('must have ":count" characters or less', [':count' => $max_characters]));
            }
        });
    }


    /**
     * Validates that the selected field has exactly the specified value
     *
     * @param mixed $value
     * @param bool  $strict
     *
     * @return static
     */
    public function hasValue(mixed $value = null, bool $strict = true): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$test_value) use ($value, $strict) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if ($strict) {
                if ($value !== $test_value) {
                    $this->addSoftFailure(tr('has an incorrect value'));
                }

            } else {
                if ($value != $test_value) {
                    $this->addSoftFailure(tr('has an incorrect value'));
                }
            }
        });
    }


    /**
     * Sanitize the selected value by trimming whitespace
     *
     * @param string $characters
     *
     * @return static
     * @see trim()
     */
    public function sanitizeTrim(string $characters = " \t\n\r\0\x0B"): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($characters) {
            $this->hasMaxCharacters();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $value = trim((string) $value, $characters);
        });
    }


    /**
     * Sanitizes the selected value by converting human-readable bytes to a positive integer number
     *
     * 1KB    = 1000
     * 1MB    = 1000000
     * 1GB    = 1000000000
     * 1GiB   = 1073741824
     * 1.5GiB = 1610612736
     * etc...
     *
     * @return static
     * @see trim()
     */
    public function sanitizeBytes(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->sanitizeTrim()->hasMaxCharacters(32);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                $value = Numbers::fromBytes($value);

            } catch (Throwable) {
                $this->addSoftFailure(tr('must have a valid byte size, like 1000, 1000kb, 1000MiB, etc'));
            }
        });
    }


    /**
     * Validates that the selected field contains only printable characters (including blanks)
     *
     * @return static
     */
    public function isPrintable(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!preg_match('/^[\p{L}\p{N}\p{P}\p{M}\p{S}\p{Z}\t\r\n]+$/u', $value)) {
                $this->addSoftFailure(tr('must contain only printable characters'));
            }
        });
    }


    /**
     * Strips HTML from the value
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function sanitizeStripHtml(string|false|null $encoding = null): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $value = strip_tags($value);
        });
    }


    /**
     * Validates that the selected field contains HTML
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function containsHtml(string|false|null $encoding = null): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($encoding){
            $this->hasEncoding($encoding);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!containsHtml($value)) {
                $this->addSoftFailure(tr('must contain HTML'));
            }
        });
    }


    /**
     * Validates that the selected field contains no HTML
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function containsNoHtml(string|false|null $encoding = null): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (containsHtml($value)) {
                $this->addSoftFailure(tr('must not contain HTML'));
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is positive
     *
     * @param int|float $amount
     * @param bool      $equal If true, it is more than or equal to, instead of only more than
     *
     * @return static
     */
    public function isMoreThan(int|float $amount, bool $equal = false): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($amount, $equal) {
            $this->isNumeric();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if ($equal) {
                if ($value < $amount) {
                    $this->addSoftFailure(tr('must be more or equal than ":amount"', [':amount' => $amount]));
                }

            } else {
                if ($value <= $amount) {
                    $this->addSoftFailure(tr('must be more than ":amount"', [':amount' => $amount]));
                }
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is positive
     *
     * @param int|float $amount
     * @param bool      $equal If true, it is less than or equal to, instead of only less than
     *
     * @return static
     */
    public function isLessThan(int|float $amount, bool $equal = false): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($amount, $equal) {
            $this->isNumeric();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if ($equal) {
                if ($value > $amount) {
                    $this->addSoftFailure(tr('must be less or equal than ":amount"', [':amount' => $amount]));
                }

            } else {
                if ($value >= $amount) {
                    $this->addSoftFailure(tr('must be less than ":amount"', [':amount' => $amount]));
                }
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key contains a currency value
     *
     * @return static
     */
    public function isCurrency(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->isFloat();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!preg_match('^[\$£¤€₠₱]?(((\d{1,3})(,?\d{1,3})*)|(\d+))(\.\d{2})?$', $value)) {
                if (!preg_match('^[\$£¤€₠₱]?(((\d{1,3})(\.?\d{1,3})*)|(\d+))(,\d{2})?$', $value)) {
                    $this->addSoftFailure(tr('must have a currency value'));
                }
            }
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is a scalar value
     *
     * @param string $enum
     *
     * @return static
     */
    public function isInEnum(string $enum): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($enum) {
            // This value must be scalar, and not too long. What is too long? Longer than the longest allowed item
            $this->isScalar();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!in_enum($value, $enum)) {
                $this->addSoftFailure(tr('must be one of ":list"', [':list' => $enum]));
            }
        });
    }


    /**
     * This will set the specified column to have the value from the given callback
     *
     * @param string   $column              The column to be updated
     * @param callable $callback            The callback that will generate the value for the column
     * @param bool     $fail_on_null [true] If true, the validation will fail if the callback returns NULL
     *
     * @return static
     */
    public function setColumnFromCallback(string $column, callable $callback, bool $fail_on_null = true): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($column, $callback, $fail_on_null) {
            // This value must be scalar, and not too long. What is too long? Longer than the longest allowed item
            $this->isScalar();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $result = $callback($value, $this->source, $this);

            if (!$result and $fail_on_null) {
                $this->addSoftFailure(
                    Strings::plural(count(Arrays::force($value)),
                    tr('value ":values" does not exist', [
                        ':values' => implode(', ', Arrays::force($value))
                    ]),
                    tr('values ":values" do not exist', [':values' => implode(', ', Arrays::force($value))]))
                );
            }

            $this->source[$this->field_prefix . $column] = $result;

            // Mark the column entry for forced processing, in case it was marked as not rendering to avoid validation issues
            $this->_definitions?->get($column)->setForceValidations(true);
        });
    }


    /**
     * Sets the specified column to the results of the executed query
     *
     * This method ensures that the specified key is the same as the column value in the specified query
     *
     * @param string                  $column
     * @param PDOStatement|string     $query
     * @param array|null              $execute
     * @param bool                    $ignore_case
     * @param bool                    $fail_on_null = true
     * @param ConnectorInterface|null $connector
     *
     * @return static
     */
    public function setColumnFromQuery(string $column, PDOStatement|string $query, ?array $execute = null, bool $ignore_case = false, bool $fail_on_null = true, ?ConnectorInterface $connector = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($column, $query, $execute, $ignore_case, $fail_on_null, $connector) {
            // This value must be scalar, and not too long. What is too long? Longer than the longest allowed item
            $this->isScalar();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $execute = $this->applyExecuteVariables($execute);
            $result  = sql($connector)->setDebug($this->debug)->getColumn($query, $execute);

            if (!$result and $fail_on_null) {
                $this->addSoftFailure(Strings::plural(count($execute), tr('value ":values" does not exist', [':values' => implode(', ', $execute)]), tr('values ":values" do not exist', [':values' => implode(', ', $execute)])));
            }

            $this->source[$this->field_prefix . $column] = $result;

            // Mark the column entry for forced processing, in case it was marked as not rendering to avoid validation issues
            $this->_definitions?->get($column)->setForceValidations(true);
        });
    }


    /**
     * Sets the current column to the results of the executed query
     *
     * This method ensures that the specified key is the same as the column value in the specified query
     *
     * @param PDOStatement|string     $query
     * @param array|null              $execute
     * @param bool                    $ignore_case
     * @param bool                    $fail_on_null = true
     * @param ConnectorInterface|null $connector
     *
     * @return static
     */
    public function setFromQuery(PDOStatement|string $query, ?array $execute = null, bool $ignore_case = false, bool $fail_on_null = true, ?ConnectorInterface $connector = null): static
    {
        return $this->setColumnFromQuery($this->getSelectedField(), $query, $execute, $ignore_case, $fail_on_null, $connector);
    }


    /**
     * Go over the specified SQL execute array and apply any variable
     *
     * @param array|null $execute
     *
     * @return array|null
     */
    protected function applyExecuteVariables(?array $execute): ?array
    {
        foreach ($execute as &$value) {
            if (is_string($value)) {
                if (str_starts_with($value, '$')) {
                    // Fix field names with field prefix
                    $value = $this->field_prefix . substr($value, 1);

                    if (!array_key_exists($value, $this->source)) {
                        throw OutOfBoundsException::new(tr('Specified execution variable ":value" does not exist in the specified source', [
                            ':value' => $value,
                        ]))->addData([
                            'source'         => $this->source,
                            'selected_field' => $this->selected_field,
                        ]);
                    }

                    // Replace this value with key from the array
                    $value = $this->source[$value];
                }
            }
        }

        unset($value);

        return $execute;
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified key value contains the column value in the specified query
     *
     * @param PDOStatement|string     $query
     * @param array|null              $execute
     * @param ConnectorInterface|null $connector
     *
     * @return static
     */
    public function containsQueryColumn(PDOStatement|string $query, ?array $execute = null, ?ConnectorInterface $connector = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($query, $execute, $connector) {
            // This value must be scalar, and not too long. What is too long? Longer than the longest allowed item
            $this->isScalar();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $execute = $this->applyExecuteVariables($execute);
            $column  = sql($connector)->setDebug($this->debug)->getColumn($query, $execute);

            $this->contains($column);
        });
    }


    /**
     * Ensures that the value has the specified string
     *
     * This method ensures that the specified array key contains the specified string
     *
     * @param string      $string
     * @param bool        $regex
     * @param bool        $not
     * @param string|null $message
     *
     * @return static
     */
    public function contains(string $string, bool $regex = false, bool $not = false, ?string $message = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($string, $regex, $not, $message) {
            // This value must be scalar
            $this->isScalar();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if ($regex) {
                try {
                    if ($not xor preg_match($string, (string) $value)) {
                        return;
                    }

                    $this->addSoftFailure($message ?? tr('must match pattern ":value"', [':value' => $string]));

                } catch (Throwable $e) {
                    if (str_contains($e->getMessage(), 'preg_match')) {
                        throw new ValidatorException(tr('Specified regular expression pattern ":regex" is invalid', [
                            ':regex' => $string
                        ]), $e);
                    }

                    throw new ValidatorException(tr('Failed validation'), $e);
                }

            } else {
                if ($not xor !str_contains((string) $value, $string)) {
                    $this->addSoftFailure($message ?? tr('must contain ":value"', [':value' => $string]));
                }
            }
        });
    }


    /**
     * Ensures that the value has the specified string
     *
     * This method ensures that the specified array key contains the specified string
     *
     * @param string $string
     * @param bool   $regex
     * @param bool   $not
     *
     * @return static
     */
    public function containsNot(string $string, bool $regex = false, bool $not = false): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($string, $regex, $not) {
            // This value must be scalar
            $this->isScalar();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if ($regex) {
                if ($not xor preg_match($string, $value)) {
                    $this->addSoftFailure(tr('must not contain ":value"', [':value' => $string]));
                }

            } else {
                if ($not xor str_contains($value, $string)) {
                    $this->addSoftFailure(tr('must not contain ":value"', [':value' => $string]));
                }
            }
        });
    }


    /**
     * Validates that the selected field matches the specified regex
     *
     * @param string      $regex
     * @param bool        $not
     * @param string|null $message
     *
     * @return static
     */
    public function matchesRegex(string $regex, bool $not = false, ?string $message = null): static
    {
        return $this->contains($regex, true, $not, $message);
    }


    /**
     * Validates that the selected field NOT matches the specified regex
     *
     * @param string $regex
     * @param bool   $not
     *
     * @return static
     */
    public function matchesNotRegex(string $regex, bool $not = false): static
    {
        return $this->containsNot($regex, true, $not);
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the value is in the results from the specified query
     *
     * @param PDOStatement|string     $query
     * @param array|null              $execute
     * @param ConnectorInterface|null $connector
     *
     * @return static
     */
    public function inQueryResultArray(PDOStatement|string $query, ?array $execute = null, ?ConnectorInterface $connector = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($query, $execute, $connector) {
            // This value must be scalar, and not too long. What is too long? Longer than the longest allowed item
            $this->isScalar();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $execute = $this->applyExecuteVariables($execute);
            $results = sql($connector)->setDebug($this->debug)->list($query, $execute);

            $this->isInArray($results);
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified array key is a scalar value
     *
     * @param IteratorInterface|array $array
     * @param bool                    $strict
     * @param string|false|null       $encoding
     *
     * @return static
     */
    public function isInArray(IteratorInterface|array $array, bool $strict = true, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($array, $strict, $encoding) {
            // This value must be scalar, and not too long. What is too long? Longer than the longest allowed item
            $this->isScalar();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if ($array instanceof IteratorInterface) {
                $array = $array->getSource();
            }

            $this->hasEncoding($encoding, true, Arrays::getLongestValueLength($array));

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                $this->addSoftFailure(tr('must be one of ":list"', [':list' => $array]));
                return;
            }

            $failed = !in_array($value, $array, $strict);

            if ($failed) {
                $this->addSoftFailure(tr('must be one of ":list"', [':list' => $array]));
            }
        });
    }


    /**
     * Validates that the selected field is equal or larger than the specified number of characters
     *
     * @param int $characters
     *
     * @return static
     */
    public function hasCharacters(int $characters): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($characters) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (strlen($value) != $characters) {
                $this->addSoftFailure(tr('must have exactly ":count" characters', [':count' => $characters]));
            }
        });
    }


    /**
     * Validates that the selected field starts with the specified string
     *
     * @param string $string
     *
     * @return static
     */
    public function startsWith(string $string): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($string) {
            // This value must be scalar
            $this->isScalar();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!str_starts_with((string) $value, $string)) {
                $this->addSoftFailure(tr('must start with ":value"', [':value' => $string]));
            }
        });
    }


    /**
     * Validates that the selected field ends with the specified string
     *
     * @param string $string
     *
     * @return static
     */
    public function endsWith(string $string): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($string) {
            // This value must be scalar
            $this->isScalar();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!str_ends_with((string) $value, $string)) {
                $this->addSoftFailure(tr('must end with ":value"', [':value' => $string]));
            }
        });
    }


    /**
     * Validates that the selected field contains only alphabet characters
     *
     * @return static
     */
    public function isAlpha(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!ctype_alpha($value)) {
                $this->addSoftFailure(tr('must contain only letters'));
            }
        });
    }


    /**
     * Validates that the selected field contains only lowercase letters
     *
     * @return static
     */
    public function isLowercase(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!ctype_lower($value)) {
                $this->addSoftFailure(tr('must contain only lowercase letters'));
            }
        });
    }


    /**
     * Validates that the selected field contains only uppercase letters
     *
     * @return static
     */
    public function isUppercase(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!ctype_upper($value)) {
                $this->addSoftFailure(tr('must contain only uppercase letters'));
            }
        });
    }


    /**
     * Validates that the selected field contains only characters that are printable, but neither letter, digit nor
     * blank
     *
     * @return static
     */
    public function isPunct(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!ctype_punct($value)) {
                $this->addSoftFailure(tr('must contain only uppercase letters'));
            }
        });
    }


    /**
     * Validates that the selected field contains only printable characters (NO blanks)
     *
     * @return static
     */
    public function isGraph(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!ctype_graph($value)) {
                $this->addSoftFailure(tr('must contain only visible characters'));
            }
        });
    }


    /**
     * Validates that the selected field contains only whitespace characters
     *
     * @return static
     */
    public function isWhitespace(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!ctype_space($value)) {
                $this->addSoftFailure(tr('must contain only whitespace characters'));
            }
        });
    }


    /**
     * Validates that the selected field contains only octal numbers
     *
     * @return static
     */
    public function isOctal(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!preg_match('/^0-7*$/', $value)) {
                $this->addSoftFailure(tr('must contain only octal numbers'));
            }
        });
    }


    /**
     * Validates that the selected field is the specified value
     *
     * @param mixed $validate_value
     * @param bool  $strict If true, will perform a strict check
     * @param bool  $secret If specified, the $validate_value will not be shown
     * @param bool  $ignore_case
     *
     * @return static
     * @todo Change these individual flag parameters to one bit flag parameter
     */
    public function isValue(mixed $validate_value, bool $strict = false, bool $secret = false, bool $ignore_case = true): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($validate_value, $strict, $secret, $ignore_case) {
            if ($strict) {
                // Strict validation
                if ($value !== $validate_value) {
                    if ($secret) {
                        $this->addSoftFailure(tr('must be exactly value ":value"', [':value' => $value]));

                    } else {
                        $this->addSoftFailure(tr('has an incorrect value'));
                    }
                }

            } else {
                $this->isScalar();

                if ($this->process_value_failed or $this->selected_is_default) {
                    // Validation already failed or defaulted, do not test anything more
                    return;
                }

                if ($ignore_case) {
                    $compare_value  = strtolower((string) $value);
                    $validate_value = strtolower((string) $validate_value);

                } else {
                    $compare_value = $value;
                }

                if ($compare_value != $validate_value) {
                    if ($secret) {
                        $this->addSoftFailure(tr('must be value ":value"', [':value' => $value]));

                    } else {
                        $this->addSoftFailure(tr('has an incorrect value'));
                    }
                }
            }
        });
    }


    /**
     * Validates that the selected field is a date
     *
     * @note Regex taken from https://code.oursky.com/regex-date-currency-and-time-accurate-data-extraction/
     *
     * @param array|string|null $formats
     *
     * @return static
     * @todo Either remove $formats or implement it
     * @todo Add locale support instead , see https://www.php.net/manual/en/book.intl.php and
     *       https://stackoverflow.com/questions/8827514/get-date-format-according-to-the-locale-in-php (INTL section)
     */
    public function isDate(array|string|null $formats = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($formats) {
            // Sort-of arbitrary max size, just to ensure Date class will not receive a 2MB string
            $this->sanitizeTrim()->hasMinCharacters(4)->hasMaxCharacters(32);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $this->validateDate($value);
        });
    }


    /**
     * Validates that the selected field is a date range
     *
     * @note Regex taken from https://code.oursky.com/regex-date-currency-and-time-accurate-data-extraction/
     *
     * @param array|string|null $formats
     *
     * @return static
     * @todo Add locale support instead , see https://www.php.net/manual/en/book.intl.php and
     *       https://stackoverflow.com/questions/8827514/get-date-format-according-to-the-locale-in-php (INTL section)
     */
    public function isDateRange(array|string|null $formats = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($formats) {
            // Sort-of arbitrary max size, just to ensure Date class will not receive a 2MB string
            $this->sanitizeTrim()->hasMinCharacters(4)->hasMaxCharacters(32);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // TODO Expand on this to allow user locale formatted dates in ranges!
            // First check if the date range format is correct, then we can check the content
            $range_formats = [
                '/^(\d{2}\s*[-\/]?\s*\d{2}\s*[-\/]?\s*\d{4})\s*-?\s*(\d{2}\s*[-\/]?\s*\d{2}\s*[-\/]?\s*\d{4})$/',
                '/^(\d{4}\s*[-\/]?\s*\d{2}\s*[-\/]?\s*\d{2})\s*-?\s*(\d{4}\s*[-\/]?\s*\d{2}\s*[-\/]?\s*\d{2})$/',
            ];

            foreach ($range_formats as $range_format) {
                $match = preg_match_all($range_format, $value, $matches);

                if ($match) {
                    break;
                }
            }

            if ($match) {
                // Now check content. Get the start and stop, and check them individually
                $dates = [
                    'start' => $matches[1][0],
                    'stop'  => $matches[2][0]
                ];

                foreach ($dates as $date) {
                    $this->validateDate($date);
                }

                // Yay, valid!
                return;
            }

            $this->addSoftFailure(tr('must be a valid date range'));
        });
    }


    /**
     * Returns true if the specified date is valid
     *
     * @param string $date
     *
     * @return bool
     */
    protected function validateDate(string $date): bool
    {
        // Ensure we have formats to work with, default to a number of acceptable formats
        $date = PhoDateTimeFormats::normalizeDate($date);

        try {
            PhoDateTime::new($date);
            return true;

        } catch (Throwable) {
            $this->addSoftFailure(tr('must be a valid date'));
        }

        return false;
    }


    /**
     * Returns the given date sanitized if the specified date matches any of the specified formats, NULL otherwise
     *
     * @param string $date
     * @param array  $formats
     *
     * @return string|null
     */
    protected static function dateMatchesFormats(string $date, array $formats): ?string
    {
        // We must be able to create a date object using the given formats without failure, and the resulting date
        // must be the same as the specified date
        $given = PhoDateTimeFormats::normalizeDate($date);

        foreach ($formats as $format) {
            try {
                // Create DateTime object
                $format = PhoDateTimeFormats::normalizeDateFormat($format);
                $value  = PhoDateTime::createFromFormat($format, $given);

                if ($value) {
                    // DateTime object created successfully! Now get a dateformat, and normalize it
                    $test = PhoDateTimeFormats::normalizeDate($value->format($format));

                    // Test the normalized test DateTime against the specified normalized date time string
                    if ($test === $given) {
                        return $given;
                    }
                }

                // Yeah, this is not a valid date, try again
            } catch (UnsupportedDateFormatException $e) {
                // The specified date format is invalid
                throw new ValidatorException($e->getMessage(), $e);

            } catch (Throwable) {
                // Yeah, this is not a valid date, try again
            }
        }

        // Nothing matched
        return null;
    }


    /**
     * Validates that the selected field is a timestamp
     *
     * @param bool $force_integer
     *
     * @return static
     */
    public function isTimestamp(bool $force_integer = false): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($force_integer) {
            $this->isNumeric()->isPositive();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if ($value > 8_640_000_000_000) {
                $this->addSoftFailure(tr('must be a valid timestamp'));
            }

            if ($force_integer) {
                $value = (int) $value;
            }
        });
    }


    /**
     * Validates that the selected field is a date
     *
     * @note Regex taken from https://code.oursky.com/regex-date-currency-and-time-accurate-data-extraction/
     *
     * @param array|string|null $formats
     *
     * @return static
     */
    public function isTime(array|string|null $formats = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($formats) {
            $this->sanitizeTrim()->hasMinCharacters(3)->hasMaxCharacters(18); // 00:00:00.000000 AM

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // Ensure we have formats to work with
            if (!$formats) {
                // Default to a number of acceptable formats
                $formats = config()->get('locale.formats.time', [
                    'h:i a',
                    'H:i',
                    'h:i:s a',
                    'H:i:s',
                ]);
            }

            $formats = Arrays::force($formats, null);

            // Validate the user time against all allowed formats
            foreach ($formats as $format) {
                if (is_object(PhoDateTime::createFromFormat($format, $value))) {
                    // The specified time matches one of the allowed formats
                    return;
                }
            }

            $this->addSoftFailure(tr('must be a valid time'));
        });
    }


    /**
     * Validates that the selected field is a date time field
     *
     * @note Regex taken from https://code.oursky.com/regex-date-currency-and-time-accurate-data-extraction/
     * @todo Add locale support, see https://www.php.net/manual/en/book.intl.php and
     *       https://stackoverflow.com/questions/8827514/get-date-format-according-to-the-locale-in-php (INTL section)
     *
     * @param EnumDateTimeWidth $width
     *
     * @return static
     */
    public function isDateTime(EnumDateTimeWidth $width = EnumDateTimeWidth::default): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($width) {
            // Sort-of arbitrary max size, just to ensure Date class will not receive a 2MB string
            $this->sanitizeTrim()
                 ->hasMaxCharacters(32);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // Ensure we have formats to work with
            // Default to a number of acceptable formats
            $formats = PhoDateTimeFormats::getSupportedPhp($width);
            $formats = Arrays::force($formats, null);

            // If the datetime has milliseconds or microseconds, then remove those
            if (preg_match('/\.\d+$/', $value)) {
                $value = Strings::untilReverse($value, '.');
            }

            // We must be able to create a date object using the given formats without failure, and the resulting date
            // must be the same as the specified date
            if (!static::dateMatchesFormats($value, $formats)) {
                $this->addSoftFailure(tr('must be a valid date time'));
            }
        });
    }


    /**
     * Validates that the selected field is in the past
     *
     * @param PhoDateTimeInterface|null $before
     * @param bool                      $equal
     *
     * @return static
     */
    public function isBefore(?PhoDateTimeInterface $before, bool $equal = false): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($before, $equal) {
            // Sort-of arbitrary max size, just to ensure Date class will not receive a 2MB string
            $this->sanitizeTrim()
                 ->hasMaxCharacters(32);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $value = new PhoDateTime($value);

            if ($equal) {
                if ($value > $before) {
                    $this->addSoftFailure(tr('must be a valid date on or before ":date"', [
                        ':date' => $before->getHumanReadableDateTime(),
                    ]));
                }

            } elseif ($value >= $before) {
                $this->addSoftFailure(tr('must be a valid date before ":date"', [
                    ':date' => $before->getHumanReadableDateTime(),
                ]));
            }
        });
    }


    /**
     * Validates that the selected field is in the past
     *
     * @param PhoDateTimeInterface|null $after
     * @param bool                      $equal
     *
     * @return static
     */
    public function isAfter(?PhoDateTimeInterface $after, bool $equal = false): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($after, $equal) {
            // Sort-of arbitrary max size, just to ensure Date class will not receive a 2MB string
            $this->sanitizeTrim()->hasMaxCharacters(32);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $value = new PhoDateTime($value);

            if ($equal) {
                if ($value < $after) {
                    $this->addSoftFailure(tr('must be a valid date on or after ":date"', [
                        ':date' => $after->getHumanReadableDateTime(),
                    ]));
                }

            } elseif ($value <= $after) {
                $this->addSoftFailure(tr('must be a valid date after ":date"', [
                    ':date' => $after->getHumanReadableDateTime(),
                ]));
            }
        });
    }


    /**
     * Validates that the selected field is a credit card
     *
     * @todo Add car number CRC checking as well
     * @note Card regexes taken from https://code.oursky.com/regex-date-currency-and-time-accurate-data-extraction/
     * @note From the site: A huge disclaimer: Never depend your code on card regex. The reason behind is simple: Card
     *       issuers carry on adding new card number patterns or removing old ones. You are likely to end up with
     *       maintaining/debugging the regular expressions that way. It’s still fine to use them for visual effects,
     *       like for identifying the card type on the screen.
     * @return static
     */
    public function isCreditCard(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            // Sort-of arbitrary max size, just to ensure regex will not receive a 2MB string
            $this->sanitizeTrim()->hasMaxCharacters(32);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $_cards = [
                'Amex Card'          => '^3[47][0-9]{13}$',
                'BCGlobal'           => '^(6541|6556)[0-9]{12}$',
                'Carte Blanche Card' => '^389[0-9]{11}$',
                'Diners Club Card'   => '^3(?:0[0-5]|[68][0-9])[0-9]{11}$',
                'Discover Card'      => '^65[4-9][0-9]{13}|64[4-9][0-9]{13}|6011[0-9]{12}|(622(?:12[6-9]|1[3-9][0-9]|[2-8][0-9][0-9]|9[01][0-9]|92[0-5])[0-9]{10})$',
                'Insta Payment Card' => '^63[7-9][0-9]{13}$',
                'JCB Card'           => '^(?:2131|1800|35d{3})d{11}$',
                'KoreanLocalCard'    => '^9[0-9]{15}$',
                'Laser Card'         => '^(6304|6706|6709|6771)[0-9]{12,15}$',
                'Maestro Card'       => '^(5018|5020|5038|6304|6759|6761|6763)[0-9]{8,15}$',
                'Mastercard'         => '^(5[1-5][0-9]{14}|2(22[1-9][0-9]{12}|2[3-9][0-9]{13}|[3-6][0-9]{14}|7[0-1][0-9]{13}|720[0-9]{12}))$',
                'Solo Card'          => '^(6334|6767)[0-9]{12}|(6334|6767)[0-9]{14}|(6334|6767)[0-9]{15}$',
                'Switch Card'        => '^(4903|4905|4911|4936|6333|6759)[0-9]{12}|(4903|4905|4911|4936|6333|6759)[0-9]{14}|(4903|4905|4911|4936|6333|6759)[0-9]{15}|564182[0-9]{10}|564182[0-9]{12}|564182[0-9]{13}|633110[0-9]{10}|633110[0-9]{12}|633110[0-9]{13}$',
                'Union Pay Card'     => '^(62[0-9]{14,17})$',
                'Visa Card'          => '^4[0-9]{12}(?:[0-9]{3})?$',
                'Visa Master Card'   => '^(?:4[0-9]{12}(?:[0-9]{3})?|5[1-5][0-9]{14})$',
            ];

            foreach ($_cards as $regex) {
                if (preg_match($regex, $value)) {
                    return;
                }
            }

            $this->addSoftFailure(tr('must be a valid credit card'));
        });
    }


    /**
     * Validates that the selected field is a valid display mode
     *
     * @return static
     */
    public function isDisplayMode(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!($value instanceof EnumDisplayMode)) {
                if (is_string($value)) {
                    // Maybe a string representation of a backed enum?
                    $test = EnumDisplayMode::tryFrom($value);

                    if ($test) {
                        $value = $test->value;

                    } else {
                        $this->addSoftFailure(tr('must be a valid display mode'));
                    }
                }
            }
        });
    }


    /**
     * Validates that the selected field is a timezone
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isTimezone(string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            // Sort-of arbitrary max size, just to ensure Date class will not receive a 2MB string
            $this->hasEncoding($encoding, true, 64);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $this->isQueryResult('SELECT `id` FROM `geo_timezones` WHERE `name` = :name', [':name' => $value]);
        });
    }


    /**
     * Validates the datatype for the selected field
     *
     * This method ensures that the specified key is the same as the column value in the specified query
     *
     * @param PDOStatement|string     $query
     * @param array|null              $execute
     * @param bool                    $ignore_case
     * @param ConnectorInterface|null $connector
     *
     * @return static
     */
    public function isQueryResult(PDOStatement|string $query, ?array $execute = null, bool $ignore_case = false, ?ConnectorInterface $connector = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($query, $execute, $ignore_case, $connector) {
            // This value must be scalar, and not too long. What is too long? Longer than the longest allowed item
            $this->isScalar();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $execute        = $this->applyExecuteVariables($execute);
            $validate_value = sql($connector)->setDebug($this->debug)->getColumn($query, $execute);

            if ($ignore_case) {
                $compare_value  = strtolower((string) $value);
                $validate_value = strtolower((string) $validate_value);

            } else {
                $compare_value = $value;
            }

            if ($compare_value != $validate_value) {
                $this->addSoftFailure(tr(' has a non existing identifier value'));
            }
        });
    }


    /**
     * Validates that the selected field array has a minimal number of elements
     *
     * @param int $count
     *
     * @return static
     */
    public function hasElements(int $count): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($count) {
            $this->isArray();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (count($value) != $count) {
                $this->addSoftFailure(tr('must have exactly ":count" elements', [':count' => $count]));
            }
        });
    }


    /**
     * Validates that the selected field array has a minimal number of elements
     *
     * @param int $count
     *
     * @return static
     */
    public function hasMinimumElements(int $count): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($count) {
            $this->isArray();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (count($value) < $count) {
                $this->addSoftFailure(tr('must have ":count" elements or more', [':count' => $count]));
            }
        });
    }


    /**
     * Validates that the selected field array has a maximum number of elements
     *
     * @param int $count
     *
     * @return static
     */
    public function hasMaximumElements(int $count): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($count) {
            $this->isArray();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (count($value) > $count) {
                $this->addSoftFailure(tr('must have ":count" elements or less', [':count' => $count]));
            }
        });
    }


    /**
     * Validates if the selected field is a valid email address
     *
     * @return static
     */
    public function isHttpMethod(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->sanitizeTrim()->hasMinCharacters(3)->hasMaxCharacters(128);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $value = mb_strtoupper($value);

            // Check against the HTTP methods that are considered valid
            switch ($value) {
                case 'GET':
                    // no break

                case 'HEAD':
                    // no break

                case 'POST':
                    // no break

                case 'PUT':
                    // no break

                case 'DELETE':
                    // no break

                case 'CONNECT':
                    // no break

                case 'OPTIONS':
                    // no break

                case 'TRACE':
                    // no break

                case 'PATCH':
                    break;

                default:
                    $this->addSoftFailure(tr('must contain a valid HTTP method'));
            }
        });
    }


    /**
     * Validates if the selected field is a valid multiple phones field
     *
     * @param string $separator
     *
     * @return static
     */
    public function isPhoneNumbers(string $separator = ','): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($separator) {
            $this->sanitizeTrim()->hasMinCharacters(10)->hasMaxCharacters(64);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $separator = Strings::escapeForRegex($separator);

            $this->matchesRegex('/[0-9- ' . $separator . '].+?/');
        });
    }


    /**
     * Validates if the selected field is a valid gender
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isGender(string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding, true, 16, 2);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $this->isPrintable();
        });
    }


    /**
     * Validates if the selected field is a valid name
     *
     * @param int|null          $max_characters
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isName(?int $max_characters = 128, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($max_characters, $encoding) {
            $this->hasEncoding($encoding, true, $max_characters, 1);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $this->isPrintable();
        });
    }


    /**
     * Validates that the selected field is not a number
     *
     * @return static
     */
    public function isNotNumeric(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (is_numeric($value)) {
                $this->addSoftFailure(tr('cannot be a number'));
            }
        });
    }


    /**
     * Validates if the selected field is a valid name
     *
     * @param int|null          $max_characters
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isUsername(?int $max_characters = 64, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($max_characters, $encoding) {
            $this->hasEncoding($encoding, true, $max_characters, 2);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $this->isAlphaNumeric()->isNotNumeric();
        });
    }


    /**
     * Validates that the selected field contains only alphanumeric characters
     *
     * @return static
     */
    public function isAlphaNumeric(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!ctype_alnum($value)) {
                $this->addSoftFailure(tr('must contain only letters and numbers'));
            }
        });
    }


    /**
     * Validates if the selected field is a valid word
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isWord(string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value, $encoding) {
            $this->hasEncoding($encoding, true, 32, 2);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $this->matchesRegex('/^[a-z-]+$/i');
        });
    }


    /**
     * Validates if the selected field is a valid variable
     *
     * @param int|null          $max_characters
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isVariable(?int $max_characters = null, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($max_characters, $encoding) {
            $this->hasEncoding($encoding, true, $max_characters, 1);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $this->matchesRegex('/^[a-z0-9-_.]*$/i');
        });
    }


    /**
     * Validates if the selected field is a valid variable name or label
     *
     * @param int|null          $max_characters
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isVariableName(?int $max_characters = 128, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($max_characters, $encoding) {
            $this->hasEncoding($encoding, true, $max_characters, 2);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $this->matchesRegex('/^[a-z0-9][a-z0-9-_.]*$/i');
        });
    }


    /**
     * Checks if the value is a valid filename
     *
     * @param int|null          $max_characters
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isFilename(?int $max_characters = 2048, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($max_characters, $encoding) {
            $this->hasEncoding($encoding, true, $max_characters, 2);

            $this->matchesRegex('/\//i');
        });
    }


    /**
     * Checks if the specified path exists in one the required directories or not, and if its of the correct type
     *
     * @param string                           $path
     * @param PhoDirectoryInterface|array|null $exists_in_directories
     * @param bool                             $must_be_directory
     * @param bool|null                        $require_exist
     *
     * @return PhoPathInterface
     */
    protected function validatePath(string $path, PhoDirectoryInterface|array|null $exists_in_directories, ?bool $must_be_directory, ?bool $require_exist): PhoPathInterface
    {
        // Determine filetype, if any
        if ($must_be_directory) {
            $type  = 'directory';
            $class = PhoDirectory::class;

        } elseif (is_bool($must_be_directory)) {
            $type = 'file';
            $class = PhoFile::class;

        } else {
            $type  = null;
            $class = PhoPath::class;
        }

        // Was a path specified? A path is required here!
        if (!$path) {
            $this->addSoftFailure(tr('must contain a path'));
            return new $class($path, $this->_restrictions);
        }

        // Check each specified directory if the file exists there.
        if ($exists_in_directories) {
            foreach (Arrays::force($exists_in_directories) as $exists_in_directory) {
                if (!$exists_in_directory instanceof PhoDirectoryInterface) {
                    throw new OutOfBoundsException(tr('Cannot validate if path ":path", the specified "$exists_in_directory" value ":value" must be an PhoDirectoryInterface object or an array with PhoDirectoryInterface objects', [
                        ':path'  => $path,
                        ':value' => $exists_in_directory
                    ]));
                }

                $exists_in_directory->makeAbsolute(must_exist: false)
                                    ->checkRestrictions(false);

                // The path should be a PhoPath object with restrictions from the specified directory that is tested
                // Get the absolute "path", "file" does not need to exist here, that can be checked later
                $test = $class::new($path, $exists_in_directory->getRestrictionsObject())
                              ->makeReal($exists_in_directory);

                if ($test->isInDirectory($exists_in_directory)) {
                    $does_exist = true;
                    break;
                }
            }

        } else {
            // Okay, we should not check if it exists IN a directly but does it exists at all?
            $does_exist = file_exists($path);
            $test       = $class::new($path, PhoRestrictions::new($path));
        }

        if (empty($does_exist)) {
            // The file, whatever it is, does not exist
            if ($require_exist) {
                // File does not exist, but should exist
                if ($type) {
                    $this->addSoftFailure(tr('must be an existing ":type" in paths ":paths"', [
                        ':type'  => $type,
                        ':paths' => Strings::force($exists_in_directories, ', ')
                    ]));

                } else {
                    $this->addSoftFailure(tr('must exist in paths ":paths"', [
                        ':paths' => $exists_in_directories
                    ]));
                }
            }

        } else {
            // The file, whatever it is, does exist
            if ($require_exist === false) {
                // The file exists, but should not exist
                $this->addSoftFailure(tr('must not exist'));
            }

            // The file exists, but that is okay, yay!
            if ($must_be_directory) {
                // The file should be a directory
                if (!$test->isDirectory()) {
                    $this->addSoftFailure(tr('must be a directory'));
                }

            } elseif (is_bool($must_be_directory)) {
                // The file should not be a directory
                if ($test->isDirectory()) {
                    $this->addSoftFailure(tr('cannot be a directory'));
                }
            }
        }

        return $test;
    }


    /**
     * Validates if the selected field is a valid file path
     *
     * @param PhoDirectoryInterface|array|null $exists_in_directories
     * @param bool|null                        $require_exists
     * @param string|false|null                $encoding
     *
     * @return static
     */
    public function isPath(PhoDirectoryInterface|array|null $exists_in_directories = null, ?bool $require_exists = true, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($exists_in_directories, $require_exists, $encoding) {
            $this->hasEncoding($encoding, true, 2048, 1);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // Check the path
            $this->validatePath($value, $exists_in_directories, null, $require_exists);
        });
    }


    /**
     * Validates if the selected field is a valid directory
     *
     * @param PhoDirectoryInterface|array|null $exists_in_directories
     * @param bool|null                        $require_exists
     * @param string|false|null                $encoding
     *
     * @return static
     */
    public function isDirectory(PhoDirectoryInterface|array|null $exists_in_directories = null, ?bool $require_exists = true, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($exists_in_directories, $require_exists, $encoding) {
            $this->hasEncoding($encoding, true, 2048, 1);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // Check the directory
            $this->validatePath($value, $exists_in_directories, true, $require_exists);
        });
    }


    /**
     * Validates if the selected field is a valid file
     *
     * @param PhoDirectoryInterface|array|null $exists_in_directories
     * @param bool|null                        $require_exists
     * @param string|false|null                $encoding
     *
     * @return static
     */
    public function isFile(PhoDirectoryInterface|array|null $exists_in_directories = null, ?bool $require_exists = true, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($exists_in_directories, $require_exists, $encoding) {
            $this->hasEncoding($encoding, true, 2048, 1);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // Check the file
            $this->validatePath($value, $exists_in_directories, false, $require_exists);
        });
    }


    /**
     * Validates if the selected field is a valid file path and converts the value into PhoPath object
     *
     * @param PhoDirectoryInterface|array|null $exists_in_directories
     * @param bool|null                        $require_exists
     * @param string|false|null                $encoding
     *
     * @return static
     */
    public function sanitizePath(PhoDirectoryInterface|array|null $exists_in_directories = null, ?bool $require_exists = true, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($exists_in_directories, $require_exists, $encoding) {
            $this->hasEncoding($encoding, true, 2048, 1);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // Check the path and convert into PhoPath object
            $value = $this->validatePath($value, $exists_in_directories, null, $require_exists);
        });
    }


    /**
     * Validates if the selected field is a valid directory and converts the value into PhoDirectory object
     *
     * @param PhoDirectoryInterface|array|null $exists_in_directories
     * @param bool|null                        $require_exists
     * @param string|false|null                $encoding
     *
     * @return static
     */
    public function sanitizeDirectory(PhoDirectoryInterface|array|null $exists_in_directories = null, ?bool $require_exists = true, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($exists_in_directories, $require_exists, $encoding) {
            $this->hasEncoding($encoding, true, 2048, 1);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // Check the directory and convert into PhoDirectory object
            $value = $this->validatePath($value, $exists_in_directories, true, $require_exists);
        });
    }


    /**
     * Validates if the selected field is a valid file and converts the value into an PhoFile object
     *
     * @param PhoDirectoryInterface|array|null $exists_in_directories
     * @param bool|null                        $require_exists
     * @param string|false|null                $encoding
     *
     * @return static
     */
    public function sanitizeFile(PhoDirectoryInterface|array|null $exists_in_directories = null, ?bool $require_exists = true, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($exists_in_directories, $require_exists, $encoding) {
            $this->hasEncoding($encoding, true, 2048, 1);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // Check the file and convert into PhoFile object
            $value = $this->validatePath($value, $exists_in_directories, false, $require_exists);
        });
    }


    /**
     * Validates if the selected field is a valid description
     *
     * @param int|null          $max_characters
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isDescription(?int $max_characters = 16_777_200, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($max_characters, $encoding) {
            $this->hasEncoding($encoding, true, $max_characters);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $this->isPrintable()->containsNoHtml();
        });
    }


    /**
     * Validates if the selected field is a valid password
     *
     * @return static
     */
    public function isPassword(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            if (static::passwordsDisabled()) {
                // Do not test passwords
                return;
            }

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                $value = Password::testSecurity((string) $value);

            } catch (ValidationFailedException $e) {
                $this->addSoftFailure(tr('failed because ":e"', [':e' => $e->getMessage()]));
            }
        });
    }


    /**
     * Returns if all validations are disabled or not
     *
     * @return bool
     */
    public static function passwordsDisabled(): bool
    {
        return static::$password_disabled;
    }


    /**
     * Validates if the selected field is a valid and strong enough password
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isStrongPassword(string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding, true, 128, 10);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // TODO Implement
        });
    }


    /**
     * Validates if the selected field is a valid email address
     *
     * @param int|null $max_characters
     *
     * @return static
     */
    public function isColor(?int $max_characters = 6): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($max_characters) {
            $this->sanitizeTrim()->hasMinCharacters(3)->hasMaxCharacters($max_characters);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // Color (for the moment) is only accepted in hexadecimal format
            $this->isHexadecimal();
        });
    }


    /**
     * Validates that the selected field contains only hexadecimal characters
     *
     * @return static
     */
    public function isHexadecimal(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!ctype_xdigit($value)) {
                $this->addSoftFailure(tr('must contain only hexadecimal characters'));
            }
        });
    }


    /**
     * Validates if the selected field is a valid email address
     *
     * @param int|null          $max_characters
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isEmail(?int $max_characters = 2048, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($max_characters, $encoding) {
            $this->hasEncoding($encoding, true, $max_characters, 3);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->addSoftFailure(tr('must contain a valid email'));
            }
        });
    }


    /**
     * Validates if the selected field is a valid email address
     *
     * @param string|null       $domain
     * @param int|null          $max_characters
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isUrl(?string $domain = null, ?int $max_characters = 2048, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($max_characters, $domain, $encoding) {
            $this->hasEncoding($encoding, true, $max_characters, 3);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $url = Url::new($value);

            if (!$url->isValid()) {
                if (str_contains($value, ' ')) {
                    // Spaces in URL's are common but will make the URL fail, auto replace with + and retry
                    $value = str_replace(' ', '+', $value);
                    $url = Url::new($value);
                }
            }

            if ($url->isValid()) {
                if ($url->hasHost($domain)) {
                    // Now we are good!
                    return;

                } else {
                    $this->addSoftFailure(tr('must match the specified domain ":domain"', [
                        ':domain' => $domain
                    ]));

                    return;
                }
            }

            $this->addSoftFailure(tr('must contain a valid URL'));

        });
    }


    /**
     * Validates if the selected field matches the current project domain
     *
     * @param int|null $max_characters
     *
     * @return static
     */
    public function hasCurrentDomain(?int $max_characters = 2048): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($max_characters) {
            $this->isUrl(Domains::getCurrent(), $max_characters);
        });
    }


    /**
     * Validates if the selected field is a valid email address
     *
     * @param string|null $domain
     * @param int|null    $max_characters
     *
     * @return static
     */
    public function sanitizeMakeUrlObject(?string $domain = null, ?int $max_characters = 2048): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($max_characters, $domain) {
            $this->isUrl($domain, $max_characters);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $value = Url::new($value);
        });
    }


    /**
     * Validates if the selected field is a valid domain name
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isDomain(string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding, true, 128, 3);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!filter_var($value, FILTER_VALIDATE_DOMAIN)) {
                $this->addSoftFailure(tr('must contain a valid domain'));
            }
        });
    }


    /**
     * Validates if the selected field is a valid IP address
     *
     * @return static
     */
    public function isIpAddress(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->sanitizeTrim()->hasMinCharacters(3)->hasMaxCharacters(48);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!filter_var($value, FILTER_VALIDATE_IP)) {
                $this->addSoftFailure(tr('must contain a valid IP address'));
            }
        });
    }


    /**
     * Validates if the selected field is a valid domain name or IP address
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     */
    public function isDomainOrIp(string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding, true, 128, 3);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!filter_var($value, FILTER_VALIDATE_DOMAIN)) {
                if (!filter_var($value, FILTER_VALIDATE_IP)) {
                    $this->addSoftFailure(tr('must contain a valid domain or IP address'));
                }
            }
        });
    }


    /**
     * Validates if the selected field is a valid formatted UUID
     *
     * @return static
     */
    public function isUuid(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->sanitizeTrim()->hasMinCharacters(3)->hasMaxCharacters(48);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-5][0-9a-f]{3}-[089ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
                $this->addSoftFailure(tr('must contain a valid UUID string'));
            }
        });
    }


    /**
     * Validates if the selected field is a valid JSON string
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     * @copyright The used JSON regex validation taken from a twitter post by @Fish_CTO
     * @see       static::isCsv()
     * @see       static::isBase58()
     * @see       static::isBase64()
     * @see       static::isSerialized()
     * @see       static::sanitizeDecodeJson()
     */
    public function isJson(string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding, true, null, 3);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            // Try by regex. If that fails, try JSON decode
            @json_decode($value);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->addSoftFailure(tr('must contain a valid JSON string'));
            }
        });
    }


    /**
     * Validates if the selected field is a valid CSV string
     *
     * @param string            $separator The separation character, defaults to comma
     * @param string            $enclosure
     * @param string            $escape
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     * @see static::isBase58()
     * @see static::isBase64()
     * @see static::isSerialized()
     * @see static::sanitizeDecodeCsv()
     */
    public function isCsv(string $separator = ',', string $enclosure = "\"", string $escape = "\\", string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($separator, $enclosure, $escape, $encoding) {
            $this->hasEncoding($encoding, true, null, 3);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                str_getcsv($value, $separator, $enclosure, $escape);

            } catch (Throwable) {
                $this->addSoftFailure(tr('must contain a valid ":separator" separated string', [
                    ':separator' => $separator,
                ]));
            }
        });
    }


    /**
     * Validates if the selected field is a serialized string
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     * @see static::isCsv()
     * @see static::isBase58()
     * @see static::isBase64()
     * @see static::isSerialized()
     * @see static::sanitizeDecodeSerialized()
     */
    public function isSerialized(string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding, true, null, 3);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                unserialize($value);

            } catch (Throwable) {
                $this->addSoftFailure(tr('must contain a valid serialized string'));
            }
        });
    }


    /**
     * Validates if the selected field is a base58 string
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     * @see static::isCsv()
     * @see static::isBase64()
     * @see static::isSerialized()
     * @see static::sanitizeDecodeBase58()
     */
    public function isBase58(string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding, true, null, 3);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!Strings::isBase58($value)) {
                $this->addSoftFailure(tr('must contain a valid Base58 encoded string'));
            }
        });
    }


    /**
     * Validates if the selected field is a base64 string
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     * @see static::isCsv()
     * @see static::isBase58()
     * @see static::isSerialized()
     * @see static::sanitizeDecodeBase64()
     */
    public function isBase64(string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding, true, null, 3);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!Strings::isBase64($value)) {
                $this->addSoftFailure(tr('must contain a valid Base64 encoded string'));
            }
        });
    }


    /**
     * Validates if the selected field is a valid version number
     *
     * @see    https://semver.org/
     *
     * @param  int|null $max_characters
     *
     * @return static
     */
    public function isVersion(?int $max_characters = 11): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($max_characters) {
            $this->sanitizeTrim()->hasMinCharacters(3)->hasMaxCharacters($max_characters);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!Strings::isVersion($value)) {
                $this->addSoftFailure(tr('must contain a valid version number'));
            }
        });
    }


    /**
     * Validates if the specified function returns TRUE for this value
     *
     * @param callable|bool $value_or_function
     * @param string        $failure
     *
     * @return static
     */
    public function isTrue(callable|bool $value_or_function, string $failure): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($value_or_function, $failure) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (is_callable($value_or_function)) {
                if (!$value_or_function($value, $this->source)) {
                    $this->addSoftFailure($failure);
                }

            } else {
                if (!$value_or_function) {
                    $this->addSoftFailure($failure);
                }
            }
        });
    }


    /**
     * Validates if the specified function returns FALSE for this value
     *
     * @param callable|bool $value_or_function
     * @param string        $failure
     *
     * @return static
     */
    public function isFalse(callable|bool $value_or_function, string $failure): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($value_or_function, $failure) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (is_callable($value_or_function)) {
                if ($value_or_function($value, $this->source)) {
                    $this->addSoftFailure($failure);
                }

            } else {
                if ($value_or_function) {
                    $this->addSoftFailure($failure);
                }
            }
        });
    }


    /**
     * Validates the value is unique in the table
     *
     * @note This requires Validator::$id to be set with an entry id through Validator::setId()
     * @note This requires Validator::setTable() to be set with a valid, existing table
     *
     * @param string|null             $failure
     * @param ConnectorInterface|null $_connector
     *
     * @return static
     */
    public function isUnique(?string $failure = null, ?ConnectorInterface $_connector = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($failure, $_connector) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $_data_entry = $this->_definitions?->getDataEntryObject();
            $field        = Strings::from($this->selected_field, $this->field_prefix);

            if ($_data_entry) {
                // TODO Add support for connector passing here
                if (($_data_entry::class)::exists([$field => $value], $this->id)) {
                    $this->addSoftFailure($failure ?? tr('already exists'));
                }

            } else {
                // Not a DataEntry object, use manual query
                if (sql($_connector)->setDebug($this->debug)->exists($this->table, $field, $value, $this->id)) {
                    $this->addSoftFailure($failure ?? tr('already exists'));
                }
            }
        });
    }


    /**
     * Sanitize the selected value by applying htmlspecialchars()
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     * @see trim()
     */
    public function sanitizeHtmlSpecialChars(string|false|null $encoding = null): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (is_string($value)) {
                $value = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8', true);
            }
        });
    }


    /**
     * Makes the current field a boolean value
     *
     * This method ensures that the specified array key is a boolean
     *
     * @return static
     */
    public function sanitizeToBoolean(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            if (!$this->hasOptionalValue($value)) {
                $value = (bool) $value;
            }
        });
    }


    /**
     * Makes the current field either "male", "female", "other", or null
     *
     * This method ensures that the specified array key is a boolean
     *
     * @return static
     */
    public function sanitizeGender(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            if (!$this->hasOptionalValue($value)) {
                $value = match ((string) $value) {
                    'm'      => 'male',
                    'M'      => 'male',
                    'Male'   => 'male',
                    'male'   => 'male',
                    'f'      => 'female',
                    'F'      => 'female',
                    'female' => 'female',
                    'Female' => 'female',
                    ''       => null,
                    default  => 'other'
                };
            }
        });
    }


    /**
     * Makes the current field a boolean value
     *
     * This method ensures that the specified array key is a boolean
     *
     * @return static
     */
    public function sanitizeToDateTime(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            if (!$this->hasOptionalValue($value)) {
                $this->isDate();
// TODO Change this to isDateTime() when the PhoDate class is ready
//                $this->isDateTime();

                if ($this->process_value_failed or $this->selected_is_default) {
                    // Validation already failed or defaulted, do not test anything more
                    return;
                }

                $value = PhoDateTime::new($value);
            }
        });
    }


    /**
     * Makes the current field a boolean value
     *
     * This method ensures that the specified array key is a boolean
     *
     * @return static
     */
    public function sanitizeToDate(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            if (!$this->hasOptionalValue($value)) {
                $this->isDate();
// TODO Change this to isDateTime() when the PhoDate class is ready
//                $this->isDateTime();

                if ($this->process_value_failed or $this->selected_is_default) {
                    // Validation already failed or defaulted, do not test anything more
                    return;
                }

                $value = PhoDate::new($value);
            }
        });
    }


    /**
     * Makes the current field a boolean value
     *
     * This method ensures that the specified array key is a boolean
     *
     * @return static
     */
    public function sanitizeToTime(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            if (!$this->hasOptionalValue($value)) {
                $this->isTime();

                if ($this->process_value_failed or $this->selected_is_default) {
                    // Validation already failed or defaulted, do not test anything more
                    return;
                }

                $value = PhoDateTime::new($value);
            }
        });
    }


    /**
     * Makes the current field a DataEntry object of the specified class, loaded with the specified identifier.
     * The object MUST exist.
     *
     * @param string                                    $class
     * @param IdentifierInterface|array|string|int|false|null $identifier
     * @param string                                    $method
     *
     * @return static
     * @todo replace method datatype string with an Enum containing only all the possible DataEntry load methods
     */
    public function sanitizeMakeDataEntry(string $class, IdentifierInterface|array|string|int|false|null $identifier, string $method = 'load'): static
    {
        $this->test_count++;

        if (empty($this->content_test_count)) {
            if ($this->getContentValidationEnabled()) {
                throw new ValidatorException(tr('Cannot sanitize column ":column" to DataEntry, no content tests have been executed yet', [
                    ':column' => $this->selected_field,
                ]));
            }
        }

        return $this->validateValues(function (&$value) use ($class, $identifier, $method) {
            if (!$this->hasOptionalValue($value)) {
                // Since we cannot know what identifier column value we may expect, we do not know if it should be a
                // database id, a code, a name, etcetera, so no prior validations are possible here
                $value = $class::new()->$method($identifier);
            }
        });
    }


    /**
     * Updates the current field value by passing it to the specified function
     *
     * @param callable $function
     *
     * @return static
     */
    public function sanitizeCallback(callable $function): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($function) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!$this->hasOptionalValue($value)) {
                $value = $function($value);
            }
        });
    }


    /**
     * Sanitize the selected value by applying htmlentities()
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     * @see trim()
     */
    public function sanitizeHtmlEntities(string|false|null $encoding = null): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (is_string($value)) {
                $value = htmlentities($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8', true);
            }
        });
    }


    /**
     * Sanitize the selected value by starting the value from the specified needle
     *
     * @param string $needle
     *
     * @return static
     * @see String::from()
     * @see Validator::sanitizeUntil()
     * @see Validator::sanitizeFromReverse()
     */
    public function sanitizeFrom(string $needle): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($needle) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $value = Strings::from($value, $needle);
        });
    }


    /**
     * Sanitize the selected value by ending the value at the specified needle
     *
     * @param string $needle
     *
     * @return static
     * @see String::until()
     * @see Validator::sanitizeFrom()
     * @see Validator::sanitizeUntilReverse()
     */
    public function sanitizeUntil(string $needle): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($needle) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $value = Strings::until($value, $needle);
        });
    }


    /**
     * Sanitize the selected value by starting the value from the specified needle, but starting search from the end of
     * the string
     *
     * @param string $needle
     *
     * @return static
     * @see String::fromReverse()
     * @see Validator::sanitizeFrom()
     * @see Validator::sanitizeUntilReverse()
     */
    public function sanitizeFromReverse(string $needle): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($needle) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $value = Strings::fromReverse($value, $needle);
        });
    }


    /**
     * Sanitize the selected value by ending the value at the specified needle, but starting search from the end of the
     * string
     *
     * @param string $needle
     *
     * @return static
     * @see String::untilReverse()
     * @see Validator::sanitizeUntil()
     * @see Validator::sanitizeFromReverse()
     */
    public function sanitizeUntilReverse(string $needle): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($needle) {
            $this->isString();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $value = Strings::untilReverse($value, $needle);
        });
    }


    /**
     * Sanitize the selected value by making the entire string uppercase
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     * @see static::sanitizeTrim()
     * @see static::sanitizeLowercase()
     */
    public function sanitizeUppercase(string|false|null $encoding = null): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                if (!$this->selected_is_default or ($value !== null)) {
                    $value = mb_strtoupper($value);
                }

            } catch (Throwable) {
                $this->addSoftFailure(tr('must contain a valid string'));
            }
        });
    }


    /**
     * Sanitize the selected value by making the entire string lowercase
     *
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     * @see static::sanitizeTrim()
     * @see static::sanitizeUppercase()
     */
    public function sanitizeLowercase(string|false|null $encoding = null): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($encoding) {
            $this->hasEncoding($encoding);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                if (!$this->selected_is_default or ($value !== null)) {
                    $value = mb_strtolower($value);
                }

            } catch (Throwable) {
                $this->addSoftFailure(tr('must contain a valid string'));
            }
        });
    }


    /**
     * Sanitize the selected value with a search / replace
     *
     * @param array             $replace A key => value map of all items that should be searched / replaced
     * @param bool              $regex   If true, all keys in the $replace array will be treated as a regex instead of a
     *                                   normal string This is slower and more memory intensive, but more flexible as well.
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     * @see trim()
     */
    public function sanitizeSearchReplace(array $replace, bool $regex = false, string|false|null $encoding = null): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($replace, $regex, $encoding) {
            $this->hasEncoding($encoding);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if ($regex) {
                // Regex search / replace, each key will be treated as a regex instead of a normal string
                $value = preg_replace(array_keys($replace), array_values($replace), $value);

            } else {
                // Standard string search / replace
                $value = str_replace(array_keys($replace), array_values($replace), $value);
            }
        });
    }


    /**
     * Sanitize the selected value by decoding the JSON
     *
     * @param bool              $array    If true, will return the data in associative arrays instead of generic objects
     * @param string|false|null $encoding The encoding that this string must use
     *
     * @return static
     * @see static::isJson()
     * @see static::sanitizeDecodeCsv()
     * @see static::sanitizeDecodeSerialized()
     * @see static::sanitizeForceString()
     */
    public function sanitizeDecodeJson(bool $array = true, string|false|null $encoding = null): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($array, $encoding) {
            try {
                if (is_string($value)) {
                    $this->hasEncoding($encoding, true, null, 2);

                    if ($this->process_value_failed or $this->selected_is_default) {
                        // Validation already failed or defaulted, do not test anything more
                        return;
                    }

                    $value = Json::decode($value);
                }

            } catch (JsonException) {
                $this->addSoftFailure(tr('must contain a valid JSON string'));
            }
        });
    }


    /**
     * Sanitize the selected value by encoding the data to JSON
     *
     * @return static
     * @see static::isJson()
     * @see static::sanitizeDecodeJson()
     */
    public function sanitizeEncodeJson(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                if (is_string($value)) {
                    $this->isJson();

                } else {
                    $value = Json::encode($value);
                }

            } catch (JsonException) {
                $this->addSoftFailure(tr('could not be processed'));
            }
        });
    }


    /**
     * Sanitize the selected value by decoding the specified CSV
     *
     * @param string $separator The separation character, defaults to comma
     * @param string $enclosure
     * @param string $escape
     *
     * @return static
     * @see static::isCsv()
     * @see static::sanitizeDecodeBase58()
     * @see static::sanitizeDecodeBase64()
     * @see static::sanitizeDecodeJson()
     * @see static::sanitizeDecodeSerialized()
     * @see static::sanitizeDecodeUrl()
     * @see static::sanitizeForceString()
     */
    public function sanitizeDecodeCsv(string $separator = ',', string $enclosure = "\"", string $escape = "\\"): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) use ($separator, $enclosure, $escape) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                $value = str_getcsv($value, $separator, $enclosure, $escape);

            } catch (Throwable) {
                $this->addSoftFailure(tr('must contain a valid ":separator" separated string', [
                    ':separator' => $separator,
                ]));
            }
        });
    }


    /**
     * Sanitize the selected value by decoding the specified CSV
     *
     * @return static
     * @see static::sanitizeDecodeBase58()
     * @see static::sanitizeDecodeBase64()
     * @see static::sanitizeDecodeCsv()
     * @see static::sanitizeDecodeJson()
     * @see static::sanitizeDecodeUrl()
     * @see static::sanitizeForceString()
     */
    public function sanitizeDecodeSerialized(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                $value = unserialize($value);

            } catch (Throwable $e) {
                $this->addSoftFailure(tr('must contain a valid serialized string'));
            }
        });
    }


    /**
     * Sanitize the selected value by decoding the specified CSV
     *
     * @return static
     * @see static::sanitizeDecodeSerialized()
     */
    public function sanitizeEncodeSerialized(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                $value = serialize($value);

            } catch (Throwable $e) {
                $this->addSoftFailure(tr('could not be processed'));
            }
        });
    }


    /**
     * Sanitize the selected value by converting it to an array
     *
     * @param string $characters
     *
     * @return static
     * @see trim()
     * @see static::sanitizeForceString()
     */
    public function sanitizeForceArray(string $characters = ','): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($characters) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }
            try {
                $value = Arrays::force($value, $characters);

            } catch (Throwable) {
                $this->addSoftFailure(tr('cannot be processed'));
            }
        });
    }


    /**
     * Sanitize the selected value by decoding the specified CSV
     *
     * @return static
     * @see static::sanitizeDecodeBase64()
     * @see static::sanitizeDecodeCsv()
     * @see static::sanitizeDecodeJson()
     * @see static::sanitizeDecodeSerialized()
     * @see static::sanitizeDecodeUrl()
     * @see static::sanitizeForceString()
     */
    public function sanitizeDecodeBase58(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                $value = base58_decode($value);

            } catch (Throwable) {
                $this->addSoftFailure(tr('must contain a valid base58 encoded string'));
            }
        });
    }


    /**
     * Sanitize the selected value by decoding the specified CSV
     *
     * @return static
     * @see static::sanitizeDecodeBase58()
     * @see static::sanitizeDecodeCsv()
     * @see static::sanitizeDecodeJson()
     * @see static::sanitizeDecodeSerialized()
     * @see static::sanitizeDecodeUrl()
     * @see static::sanitizeForceString()
     */
    public function sanitizeDecodeBase64(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                $value = base64_decode($value);

            } catch (Throwable) {
                $this->addSoftFailure(tr('must contain a valid base64 encoded string'));
            }
        });
    }


    /**
     * Sanitize the selected value by decoding the specified CSV
     *
     * @return static
     * @see static::sanitizeDecodeBase58()
     * @see static::sanitizeDecodeBase64()
     * @see static::sanitizeDecodeCsv()
     * @see static::sanitizeDecodeJson()
     * @see static::sanitizeDecodeSerialized()
     * @see static::sanitizeForceString()
     */
    public function sanitizeDecodeUrl(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->isUrl();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                $value = urldecode($value);

            } catch (Throwable) {
                $this->addSoftFailure(tr('must contain a valid url string'));
            }
        });
    }


    /**
     * Sanitize the selected value by making it a string
     *
     * @param string $characters
     *
     * @return static
     * @todo KNOWN BUG: THIS DOESNT WORK
     * @see  static::sanitizeDecodeBase58()
     * @see  static::sanitizeDecodeBase64()
     * @see  static::sanitizeDecodeCsv()
     * @see  static::sanitizeDecodeJson()
     * @see  static::sanitizeDecodeSerialized()
     * @see  static::sanitizeDecodeUrl()
     * @see  static::sanitizeForceArray()
     */
    public function sanitizeForceString(string $characters = ','): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($characters) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            try {
                $value = Strings::force($value, $characters);

            } catch (Throwable) {
                $this->addSoftFailure(tr('cannot be processed'));
            }
        });
    }


    /**
     * Sanitize the selected value by decoding the specified CSV
     *
     * @param string|null $pre
     * @param string|null $post
     *
     * @return static
     */
    public function sanitizePrePost(?string $pre, ?string $post): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($pre, $post) {
            if ($pre or $post) {
                if ($this->process_value_failed or $this->selected_is_default) {
                    if (!$this->selected_is_default) {
                        // Validation already failed or defaulted, do not test anything more
                        return $this;
                    }

                    // This field contains the default
                }

                if (!is_scalar($this->selected_value)) {
                    throw new ValidatorException(tr('Cannot sanitize pre / post string data for field ":field", the field contains a non scalar value', [
                        ':field' => $this->selected_field,
                    ]));
                }

                $value = $pre . $value . $post;
            }

            return $this;
        });
    }


    /**
     * Sanitize the selected value by applying the specified transformation callback
     *
     * @param callable $callback
     *
     * @return static
     */
    public function sanitizeTransform(callable $callback): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($callback) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return $this;
            }

            $value = $callback($value, $this->source, $this);

            return $this;
        });
    }


    /**
     * Requires the selected value not be NULL
     *
     * @return static
     */
    public function isNotNull(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if ($value === null) {
                $this->addSoftFailure(tr('is not valid'));
            }
        });
    }


    /**
     * Sanitize the selected value by executing the specified callback over it, but the results may NOT be NULL
     *
     * @note The callback should accept values mixed $value and array $source
     *
     * @param callback $callback
     *
     * @return static
     */
    public function sanitizeCallbackNoNull(callable $callback): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($callback) {
            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $results = $callback($value, $this->source);

            if ($results === null) {
                $this->addSoftFailure(tr('is not valid'));

            } else {
                $value = $results;
            }
        });
    }


    /**
     * Sanitize the phone number in the selected value
     *
     * @return static
     * @see trim()
     */
    public function sanitizePhoneNumber(): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) {
            $this->isPhoneNumber();

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $value = Sanitize::new($value)->phoneNumber()->getSource();
        });
    }


    /**
     * Validates if the selected field is a valid phone number
     *
     * @return static
     */
    public function isPhoneNumber(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            $this->sanitizeTrim()->hasMinCharacters(10)->hasMaxCharacters(30);

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            $this->matchesRegex('/^\+?[0-9-#\*\(\) ].+?$/');
        });
    }


    /**
     * Returns the field prefix value
     *
     * @return string|null
     */
    public function getPrefix(): ?string
    {
        return $this->field_prefix;
    }


    /**
     * Sets the field prefix value
     *
     * @param string|null $field_prefix
     *
     * @return static
     */
    public function setPrefix(?string $field_prefix): static
    {
        $this->field_prefix = $field_prefix;

        return $this;
    }


    /**
     * Returns the value for config path "security.validation.enabled"
     *
     * @return bool
     */
    public function getConfigValidationEnabled(): bool
    {
        return config()->getBoolean('security.validation.enabled', true);
    }


    /**
     * Returns if validation has been disabled
     *
     * @return bool
     */
    public function getValidationEnabled(): bool
    {
        return $this->validation_enabled;
    }


    /**
     * Sets if validation has been disabled
     *
     * @param bool $value
     *
     * @return static
     */
    public function setValidationEnabled(bool $value): static
    {
        $this->validation_enabled = $value;
        return $this;
    }


    /**
     * Returns the field datatype_validation_enabled property value
     *
     * @return bool
     */
    public function getDatatypeValidationEnabled(): bool
    {
        return $this->datatype_validation_enabled;
    }


    /**
     * Sets the field datatype_validation_enabled property value
     *
     * @param bool $value
     *
     * @return static
     */
    public function setDatatypeValidationEnabled(bool $value): static
    {
        $this->datatype_validation_enabled = $value;
        return $this;
    }


    /**
     * Returns the field content_validation_enabled property value
     *
     * @return bool
     */
    public function getContentValidationEnabled(): bool
    {
        return $this->content_validation_enabled;
    }


    /**
     * Sets the field content_validation_enabled property value
     *
     * @param bool $value
     *
     * @return static
     */
    public function setContentValidationEnabled(bool $value): static
    {
        $this->content_validation_enabled = $value;
        return $this;
    }


    /**
     * Returns the table value
     *
     * @return string|null
     */
    public function getTable(): ?string
    {
        return $this->table;
    }


    /**
     * Sets the table value
     *
     * @param string|null $table
     *
     * @return static
     */
    public function setTable(?string $table): static
    {
        $this->table = $table;

        return $this;
    }


    /**
     * Returns the number of Tests performed on the current column
     *
     * @return int
     */
    public function getTestCountForSelectedColumn(): int
    {
        return $this->test_count;
    }


    /**
     * Returns the number of Tests performed on the current column
     *
     * @return int
     */
    public function getContentTestCountForSelectedColumn(): int
    {
        return $this->content_test_count;
    }


    /**
     * Sets the content test as done
     *
     * @return static
     */
    public function setContentTestDone(): static
    {
        $this->content_test_count++;
        return $this;
    }


    /**
     * Increases the test counter by the specified amount
     *
     * @param int $count
     *
     * @return static
     */
    public function increaseTestCount(int $count = 1): static
    {
        $this->test_count += $count;
        return $this;
    }


    /**
     * Selects the specified key within the array that we are validating
     *
     * @param string|int $field The array key (or HTML form field) that needs to be validated / sanitized
     *
     * @return static
     */
    public function standardSelect(string|int $field): static
    {
        if (!$field) {
            throw new OutOfBoundsException(tr('No field specified'));
        }

        if (!$this->selected_field or (!$this->process_value_failed and !$this->selected_is_default)) {
            if ($this->selected_field and empty($this->test_count)) {
                if ($this->getDatatypeValidationEnabled()) {
                    throw new ValidatorException(tr('Cannot select field ":field" for object ":object", the previously selected field ":previous" has no validations performed yet', [
                        ':object'   => ($this->_definitions?->getDataEntryObject() ? get_class($this->_definitions->getDataEntryObject()) : '-'),
                        ':field'    => $field,
                        ':previous' => $this->selected_field,
                    ]));
                }

                Log::error(ts('WARNING: SKIPPED VALIDATION DUE TO security.validation.disabled = false CONFIGURATION! SYSTEM MAY BE IN UNKNOWN STATE!'));
            }

            if ($this->selected_field and empty($this->content_test_count)) {
                if ($this->getContentValidationEnabled()) {
                    throw new ValidatorException(tr('Cannot select field ":field" for class ":class", the previously selected field ":previous" has no content validations performed yet', [
                        ':class'    => ($this->_definitions?->getDataEntryObject() ? get_class($this->_definitions->getDataEntryObject()) : 'N/A'),
                        ':field'    => $field,
                        ':previous' => $this->selected_field,
                    ]));
                }

                Log::error(ts('WARNING: SKIPPED CONTENT VALIDATION DUE TO security.validation.content.disabled = false CONFIGURATION! SYSTEM MAY BE IN UNKNOWN STATE!'));
            }
        }

        // Unset various values first to ensure the byref link is broken
        unset($this->process_value);
        unset($this->process_values);
        unset($this->selected_value);

        $this->process_value_failed = false;
        $this->selected_is_default  = false;
        $this->selected_is_optional = false;

        // Add the field prefix to the field name
        $field = $this->field_prefix . $field;

        if (in_array($field, $this->selected_fields)) {
            throw new KeyAlreadySelectedException(tr('The specified key ":key" has already been selected before', [
                ':key' => $field,
            ]));
        }

        // Does the field exist in the source? If not, initialize it with NULL to be able to process it
        if (!array_key_exists($field, $this->source)) {
            $this->source[$field] = null;
        }

        // Select the field.
        $this->test_count         =  0;
        $this->content_test_count =  0;
        $this->selected_field     =  $field;
        $this->selected_fields[]  =  $field;
        $this->selected_value     =  &$this->source[$field];
        $this->process_values     = [&$this->selected_value];
        $this->selected_optional  =  null;

        return $this;
    }


    /**
     * Constructor for all validator types
     *
     * @param ValidatorInterface|null $parent
     * @param array                   $source
     *
     * @return void
     */
    protected function construct(?ValidatorInterface $parent = null, array &$source = []): void
    {
        $this->source = &$source;
        $this->_parent = $parent;

        $this->_reflection_selected_optional = new ReflectionProperty($this, 'selected_optional');
        $this->reflection_process_value       = new ReflectionProperty($this, 'process_value');
    }


    /**
     * Forces all empty strings ('') to be converted to null.
     * If the recursive parameter is true, sub-arrays will have their empty string values set to null as well.
     *
     * @param bool $recursive
     *
     * @return static
     */
    public function forceEmptyStringsToNull(bool $recursive = true): static
    {
        return $this->replaceEmptyStringsToNull($this->source, $recursive);
    }


    /**
     * Replaces all instances of empty strings ('') with null
     *
     * @param array $source
     * @param bool  $recursive
     *
     * @return static
     */
    protected function replaceEmptyStringsToNull(array &$source, bool $recursive): static
    {
        foreach ($source as &$value) {
            if (is_array($value) and $recursive) {
                $this->replaceEmptyStringsToNull($value, true);

            } elseif ($value === '') {
                $value = null;
            }
        }

        return $this;
    }


    /**
     * Requires this value to be a valid Canadian PHN
     *
     * @returns static
     */
    public function isPhn(): static
    {
        $this->test_count++;
        $this->content_test_count++;

        return $this->validateValues(function (&$value) {
            if (!$this->hasOptionalValue($value)) {
                try {
                    $value = Phn::checkSanitizeAndValidate($value);

                } catch (InvalidPhnException | PhnRequiredException) {
                    $this->addSoftFailure(tr('must be a valid PHN'));
                }
            }
        });
    }


    /**
     * Validates that the string has the correct encoding
     *
     * @param string|false|null $encoding The encoding that this string must use
     * @param bool              $trim
     * @param int|false|null    $max_characters
     * @param int|false|null    $min_characters
     *
     * @return static
     */
    public function hasEncoding(string|false|null $encoding = null, bool $trim = true, int|false|null $max_characters = null, int|false|null $min_characters = null): static
    {
        static $enabled = false;

        $this->test_count++;

        // Default in encoding to what the Response will be
        $encoding = $encoding ?? Response::getEncoding();
        $enabled  = config()->getBoolean('security.validation.encoding.enabled', true);

        return $this->validateValues(function (&$value) use ($encoding, $trim, $min_characters, $max_characters, $enabled) {
            if ($trim) {
                $this->sanitizeTrim();
            }

            if ($max_characters !== false) {
                // If trim was executed, it already checked for $max_characters NULL. Only check numeric max_characters
                if (!$trim or is_numeric($max_characters)) {
                    // !trim because Validator::sanitizeTrim() already checks datatype
                    $this->hasMaxCharacters($max_characters, !$trim);
                }
            }

            if ($min_characters) {
                // !trim because Validator::sanitizeTrim() already checks datatype
                $this->hasMinCharacters($min_characters, !$trim);
            }

            if (!$enabled or !$encoding) {
                // Do not check encoding
                return;
            }

            if ($this->process_value_failed or $this->selected_is_default) {
                // Validation already failed or defaulted, do not test anything more
                return;
            }

            if (!mb_check_encoding($value, $encoding)) {
                $this->addSoftFailure(tr('does not have valid :encoding encoding', [
                    ':encoding' => $encoding,
                ]));
            }
        });
    }


    /**
     * Requires that the specified value is empty
     *
     * @param mixed  $value
     * @param string $failure
     *
     * @return static
     */
    public function requiresValueEmpty(mixed $value, string $failure): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$selected_value) use ($value, $failure) {
            if (!$this->hasOptionalValue($value)) {
                if ($value) {
                    $this->addSoftFailure($failure);
                }
            }
        });
    }


    /**
     * Requires that the specified value is NOT empty
     *
     * @param mixed  $value
     * @param string $failure
     *
     * @return static
     */
    public function requiresValueNotEmpty(mixed $value, string $failure): static
    {
        $this->test_count++;

        return $this->validateValues(function (&$value) use ($failure) {
            if (!$this->hasOptionalValue($value)) {
                if (empty($value)) {
                    $this->addSoftFailure($failure);
                }
            }
        });
    }
}
