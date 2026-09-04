<?php

/**
 * Class IteratorCore
 *
 * This is a slightly extended interface to the default PHP iterator class. This class also requires the following
 * methods:
 *
 * - IteratorCore::getCount() Returns the number of elements contained in this object
 *
 * - IteratorCore::getFirst() Returns the first element contained in this object without changing the internal pointer
 *
 * - IteratorCore::getLast() Returns the last element contained in this object without changing the internal pointer
 *
 * - IteratorCore::clear() Clears all the internal content for this object
 *
 * - IteratorCore::delete() Deletes the specified key
 *
 * @author    Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @license   http://opensource.org/licenses/GPL-2.0 GNU Public License, Version 2
 * @copyright Copyright © 2025 Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @package   Phoundation\Data
 */


declare(strict_types=1);

namespace Phoundation\Data;

use PDOStatement;
use Phoundation\Cli\Cli;
use Phoundation\Content\Documents\Interfaces\SpreadSheetInterface;
use Phoundation\Content\Documents\SpreadSheet;
use Phoundation\Core\Interfaces\ArrayableInterface;
use Phoundation\Core\Log\Log;
use Phoundation\Data\DataEntries\DataIterator;
use Phoundation\Data\DataEntries\Definitions\Interfaces\DefinitionInterface;
use Phoundation\Data\DataEntries\Interfaces\DataEntryInterface;
use Phoundation\Data\Exception\IteratorDataTypeNotAcceptedException;
use Phoundation\Data\Exception\IteratorException;
use Phoundation\Data\Exception\IteratorKeyExistsException;
use Phoundation\Data\Exception\IteratorKeyNotExistsException;
use Phoundation\Data\Exception\IteratorNullKeyException;
use Phoundation\Data\Exception\IteratorValidatorFailedException;
use Phoundation\Data\Interfaces\ArraySourceInterface;
use Phoundation\Data\Interfaces\IteratorInterface;
use Phoundation\Data\Traits\TraitDataArraySource;
use Phoundation\Data\Traits\TraitDataCache;
use Phoundation\Data\Traits\TraitDataCacheKey;
use Phoundation\Data\Traits\TraitDataColumns;
use Phoundation\Data\Traits\TraitDataDisabled;
use Phoundation\Data\Traits\TraitDataFilterForm;
use Phoundation\Data\Traits\TraitDataParent;
use Phoundation\Data\Traits\TraitDataReadonly;
use Phoundation\Filesystem\Traits\TraitDataRestrictions;
use Phoundation\Data\Traits\TraitDataRowCallbacks;
use Phoundation\Data\Traits\TraitDataStringName;
use Phoundation\Databases\Sql\Limit;
use Phoundation\Exception\NotExistsException;
use Phoundation\Exception\OutOfBoundsException;
use Phoundation\Utils\Arrays;
use Phoundation\Utils\Json;
use Phoundation\Utils\Strings;
use Phoundation\Utils\Utils;
use Phoundation\Web\Html\Components\Input\InputSelect;
use Phoundation\Web\Html\Components\Input\Interfaces\InputSelectInterface;
use Phoundation\Web\Html\Components\Interfaces\RenderInterface;
use Phoundation\Web\Html\Components\Tables\HtmlDataTable;
use Phoundation\Web\Html\Components\Tables\HtmlTable;
use Phoundation\Web\Html\Components\Tables\Interfaces\HtmlDataTableInterface;
use Phoundation\Web\Html\Components\Tables\Interfaces\HtmlTableInterface;
use Phoundation\Web\Html\Components\Widgets\Accordion;
use Phoundation\Web\Html\Components\Widgets\Interfaces\AccordionInterface;
use Phoundation\Web\Html\Components\Widgets\Interfaces\TreeViewerInterface;
use Phoundation\Web\Html\Components\Widgets\TreeViewer;
use Phoundation\Web\Html\Enums\EnumTableIdColumn;
use Phoundation\Web\Http\Interfaces\UrlInterface;
use Phoundation\Web\Requests\Request;
use ReturnTypeWillChange;
use Stringable;
use Throwable;


class IteratorCore extends IteratorBase implements IteratorInterface
{
    use TraitDataCache;
    use TraitDataCacheKey;
    use TraitDataColumns {
        getColumns as protected __getColumns;
    }
    use TraitDataFilterForm;
    use TraitDataParent {
        setParentObject as protected __setParentObject;
    }
    use TraitDataRestrictions;
    use TraitDataRowCallbacks; // TODO WHY IS THIS HERE? THESE METHODS ARE FOR TABLES!
    use TraitDataArraySource{
        setSource as protected __setSource;
    }
    use TraitDataStringName {
        getName as protected __getName;
    }
    use TraitDataReadonly {
        setReadonly as protected __setReadonly;
    }
    use TraitDataDisabled {
        setDisabled as protected __setDisabled;
    }


    /**
     * Tracks the datatype required for all elements in this iterator, NULL if none is required
     *
     * @var array|null
     */
    protected ?array $accepted_data_types = null;

    /**
     * Tracks the class used to generate the select input
     *
     * @var string
     */
    protected string $input_select_class = InputSelect::class;

    /**
     * Tracks validators that are required to pass to add values to this Iterator
     *
     * @var IteratorInterface $validators
     */
    protected IteratorInterface $validators;

    /**
     * Tracks if values in this Iterator should be automatically converted into objects, or not
     *
     * @var bool $ensure_objects
     */
    protected bool $ensure_objects = true;

    /**
     * Tracks validators that are required to pass to add values to this Iterator
     *
     * @var IteratorInterface $_validators
     */
    protected IteratorInterface $_validators;


    /**
     * Iterator class constructor
     *
     * @param IteratorInterface|PDOStatement|array|string|null $source
     */
    public function __construct(IteratorInterface|PDOStatement|array|string|null $source = null)
    {
        $this->setAcceptedDataTypes(static::getDefaultContentDataType(), false);

        if ($source) {
            $this->setSource($source);
        }

        $this->setAcceptedDataTypes(static::getDefaultContentDataType());
    }


    /**
     * Returns the name of the items in this Iterator class
     *
     * @return string
     */
    public static function getIteratorName(): string
    {
        return tr('data');
    }


    /**
     * Returns the first data type that is allowed and accepted for this data iterator, considered as the most important
     * one
     *
     * @return string|null
     */
    public function getAcceptedDataType(): ?string
    {
        return array_value_first($this->getAcceptedDataTypes());
    }


    /**
     * Returns the data types that are allowed and accepted for this data iterator
     *
     * @return string|null
     */
    public static function getDefaultContentDataType(): ?string
    {
        return 'mixed';
    }


    /**
     * Sets the source for this Iterator class
     *
     * @param IteratorInterface|PDOStatement|array|string|null $source  [null] The new source for this iterator
     * @param array|null                                       $execute [null] Optional query values in case the specified source is an SQL query
     *
     * @return static
     *
     * @TODO: implement $filter_meta for iterators
     * @TODO: This ::setSource() method should pass the given source through checkDataTypeAndContent() and possibly auto ensureObject() in case the type does not match
     */
    public function setSource(IteratorInterface|PDOStatement|array|string|null $source = null, array|null $execute = null): static
    {
        $this->source = Arrays::extractSourceArray($source, $execute);
        return $this;
    }


    /**
     * Returns the class used to generate the select input
     *
     * @return string
     */
    public function getInputSelectClass(): string
    {
        return $this->input_select_class;
    }


    /**
     * Returns the name of this Iterator
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        if ($this->__getName()) {
            return Strings::fromReverse($this::class, '\\') . '/' . $this->__getName();
        }

        return Strings::fromReverse($this::class, '\\');
    }


    /**
     * Sets the class used to generate the select input
     *
     * @param string $input_select_class
     *
     * @return DataIterator
     */
    public function setComponentClass(string $input_select_class): static
    {
        if (is_a($input_select_class, InputSelectInterface::class, true)) {
            $this->input_select_class = $input_select_class;

            return $this;
        }

        throw new OutOfBoundsException(tr('Cannot use specified class ":class" to generate input select, the class must be an instance of InputSelectInterface', [
            ':class' => $input_select_class,
        ]));
    }


    /**
     * Sets the parent
     *
     * @param DataEntryInterface|RenderInterface|UrlInterface|null $_parent
     *
     * @return static
     * @todo This is a mess, redo parent management. DataEntryInterface is very generic, is ok, RenderInterface is generic, is ok, UrlInterface is very specific, not ok. See Rights::setParentObject()
     */
    public function setParentObject(DataEntryInterface|RenderInterface|UrlInterface|null $_parent): static
    {
        if ($_parent instanceof DataEntryInterface) {
            // Pass readonly flags. If the parent is readonly, then so is this list
            if ($this->getReadonly() !== $_parent->getReadonly()) {
                $this->setReadonly($this->getReadonly() or $_parent->getReadonly());
            }

            // Pass disabled flags. If the parent is disabled, then so is this list
            if ($this->getDisabled() !== $_parent->getDisabled()) {
                $this->setDisabled($this->getDisabled() or $_parent->getDisabled());
            }
        }

        return $this->__setParentObject($_parent);
    }


    /**
     * Sets if this DataIterator (and its entries in its source!) is readonly or not
     *
     * @param bool        $readonly
     * @param bool|null   $set_disabled
     *
     * @return static
     */
    public function setReadonly(bool $readonly, ?bool $set_disabled = null): static
    {
        foreach ($this as $entry) {
            if ($entry instanceof RenderInterface) {
                $entry->setReadonly($readonly, $set_disabled);
            }
        }

        return $this->__setReadonly($readonly, $set_disabled);
    }


    /**
     * Sets if this DataIterator (and its entries in its source!) is disabled or not
     *
     * @param bool      $disabled
     * @param bool|null $set_readonly
     *
     * @return static
     */
    public function setDisabled(bool $disabled, ?bool $set_readonly = null): static
    {
        foreach ($this as $entry) {
            if ($entry instanceof RenderInterface) {
                $entry->setDisabled($disabled, $set_readonly);
            }
        }

        return $this->__setDisabled($disabled, $set_readonly);
    }


    /**
     * Returns if values in this Iterator should be automatically converted into objects, or not
     *
     * @return bool
     */
    public function getEnsureObjects(): bool
    {
        return $this->ensure_objects;
    }


    /**
     * Sets if values in this Iterator should be automatically converted into objects, or not
     *
     * @param bool $ensure_objects
     *
     * @return static
     */
    public function setEnsureObjects(bool $ensure_objects): static
    {
        $this->ensure_objects = $ensure_objects;
        return $this;
    }


    /**
     * Returns the columns for the data in this Iterator.
     *
     * If columns have not been set already, it will automatically detect the columns from the first entry in the
     * Iterator
     *
     * @return array|null
     */
    public function getColumns(): ?array
    {
        $columns = $this->__getColumns();

        if (empty($columns)) {
            $columns = array_value_first($this->source);
            $columns = Arrays::force($columns);
            $columns = array_keys($columns);
        }

        return $columns;
    }


    /**
     * Returns the current entry
     *
     * @note overrides the IteratorBase::current() method which does not support the protected Iterator||ensureobject()
     *       method
     *
     * @return mixed
     */
    #[ReturnTypeWillChange] public function current(): mixed
    {
        return $this->ensureObject(key($this->source));
    }


    /**
     * Add the specified data entry to the end of the source list
     *
     * @note if no key was specified, the entry will be assigned as-if a new array entry
     *
     * @param mixed                            $value                   The value to add
     * @param Stringable|string|float|int|null $key              [null] The key under which to store the value. If NULL, the key is determined automatically
     * @param bool                             $skip_null_values [true] If true, will skipp adding the value if it is NULL
     * @param bool                             $exception        [true] If true, will throw an exception if the DataEntry object already exists in this list
     *
     * @return static
     */
    public function add(mixed $value, Stringable|string|float|int|null $key = null, bool $skip_null_values = true, bool $exception = true): static
    {
        return $this->append($value, $key, $skip_null_values, $exception);
    }


     /**
      * Appends the specified value to the Iterator source using an optional key
      *
      * @note if no key was specified, the entry will be assigned as-if a new array entry
      *
     * @param mixed                            $value                   The value to add
     * @param Stringable|string|float|int|null $key              [null] The key under which to store the value. If NULL, the key is determined automatically
     * @param bool                             $skip_null_values [true] If true, will skipp adding the value if it is NULL
     * @param bool                             $exception        [true] If true, will throw an exception if the DataEntry object already exists in this list
     *
     * @return static
     *
     * @throws IteratorKeyExistsException
     */
   public function append(mixed $value, Stringable|string|float|int|null $key = null, bool $skip_null_values = true, bool $exception = true): static
    {
        // Skip NULL values?
        if ($value === null) {
            if ($skip_null_values) {
                // TODO Check why $this->source[$key] would be unset if the $value is NULL. IF there was a value, it should just remain, no?
                unset($this->source[$key]);
                return $this;
            }
        }

        $value = $this->checkDataTypeAndContent($value, $key);

        // NULL keys will be added as numerical "next" entries
        if ($key === null) {
            $key = $this->fetchKeyFromValue($value);
        }

        if ($key === null) {
            $this->source[] = $value;

        } else {
            if (array_key_exists($key, $this->source) and ($exception ?? $this->exception_on_get)) {
                throw new IteratorKeyExistsException(tr('Cannot add key ":key" to Iterator class ":class" object because the key already exists', [
                    ':key'   => $key,
                    ':class' => static::class,
                ]));
            }

            $this->source[$key] = $value;
        }

        return $this;
    }


    /**
     * Prepends the specified data entry to the beginning of this Iterator
     *
     * @note if no key was specified, the entry will be assigned as-if a new array entry
     *
     * @param mixed                            $value                   The value to add
     * @param Stringable|string|float|int|null $key              [null] The key under which to store the value. If NULL, the key is determined automatically
     * @param bool                             $skip_null_values [true] If true, will skipp adding the value if it is NULL
     * @param bool                             $exception        [true] If true, will throw an exception if the DataEntry object already exists in this list
     *
     * @return static
     */
    public function prepend(mixed $value, Stringable|string|float|int|null $key = null, bool $skip_null_values = true, bool $exception = true): static
    {
        // Skip NULL values?
        if ($value === null) {
            if ($skip_null_values) {
                // TODO Check why $this->source[$key] would be unset if the $value is NULL. IF there was a value, it should just remain, no?
                unset($this->source[$key]);
                return $this;
            }
        }

        $value = $this->checkDataTypeAndContent($value, $key);

        // NULL keys will be added as numerical "first" entries
        if ($key === null) {
            array_unshift($this->source, $value);

        } else {
            if (array_key_exists($key, $this->source) and ($exception ?? $this->exception_on_get)) {
                throw new IteratorKeyExistsException(tr('Cannot add key ":key" to Iterator class ":class" object because the key already exists', [
                    ':key'   => $key,
                    ':class' => static::class,
                ]));
            }

            $this->source = array_merge([$key => $value], $this->source);
        }

        return $this;
    }


    /**
     * Prepends the specified value to the Iterator source using an optional key BEFORE the specified "$before" key
     *
     * @note if no key was specified, the entry will be assigned as-if a new array entry
     *
     * @param mixed                            $value            The value to add
     * @param Stringable|string|float|int|null $key              [null] The key under which to store the value. If NULL, the key is determined automatically
     * @param Stringable|string|int|null       $before           [null] The key before which this new value must be inserted. TODO If null, then what happens?
     * @param bool                             $skip_null_values [true] If true, will skipp adding the value if it is NULL
     * @param bool                             $exception        [true] If true, will throw an exception if the DataEntry object already exists in this list
     *
     * @return static
     */
    public function prependBeforeKey(mixed $value, Stringable|string|float|int|null $key = null, Stringable|string|int|null $before = null, bool $skip_null_values = true, bool $exception = true): static
    {
        // Skip NULL values?
        if ($value === null) {
            if ($skip_null_values) {
                // TODO Check why $this->source[$key] would be unset if the $value is NULL. IF there was a value, it should just remain, no?
                unset($this->source[$key]);
                return $this;
            }
        }

        $value = $this->checkDataTypeAndContent($value, $key);

        if ($before === null) {
            throw new IteratorNullKeyException(tr('Cannot prepend key ":key" to Iterator class ":class" object because no "before" key was specified', [
                ':key'   => $key,
                ':class' => static::class,
            ]));
        }

        // Ensure the before key exists
        if (!array_key_exists($before, $this->source)) {
            throw new IteratorKeyNotExistsException(tr('Cannot prepend key ":key" to Iterator class ":class" object before key ":before" because the before key ":before" does not exist', [
                ':key'    => $key,
                ':before' => $before,
                ':class'  => static::class,
            ]));
        }

        // NULL keys will be added as numerical "next" entries
        if (array_key_exists($key, $this->source) and ($exception ?? $this->exception_on_get)) {
            throw new IteratorKeyExistsException(tr('Cannot prepend key ":key" to Iterator class ":class" object before key ":before" because the key ":key" already exists', [
                ':key'    => $key,
                ':before' => $before,
                ':class'  => static::class,
            ]));
        }

        Arrays::spliceByKey($this->source, $before, 0, [$key => $value], false);
        return $this;
    }


    /**
     * Appends the specified value to the Iterator source using an optional key AFTER the specified "$after" key
     *
     * @note if no key was specified, the entry will be assigned as-if a new array entry
     *
     * @param mixed                            $value            The value to add
     * @param Stringable|string|float|int|null $key              [null] The key under which to store the value. If NULL, the key is determined automatically
     * @param Stringable|string|int|null       $after            [null] The key after which this new value must be inserted. TODO If null, then what happens?
     * @param bool                             $skip_null_values [true] If true, will skipp adding the value if it is NULL
     * @param bool                             $exception        [true] If true, will throw an exception if the DataEntry object already exists in this list
     *
     * @return static
     */
    public function appendAfterKey(mixed $value, Stringable|string|float|int|null $key = null, Stringable|string|int|null $after = null, bool $skip_null_values = true, bool $exception = true): static
    {
        // Skip NULL values?
        if ($value === null) {
            if ($skip_null_values) {
                // TODO Check why $this->source[$key] would be unset if the $value is NULL. IF there was a value, it should just remain, no?
                unset($this->source[$key]);
                return $this;
            }
        }

        $value = $this->checkDataTypeAndContent($value, $key);

        if ($after === null) {
            throw new IteratorNullKeyException(tr('Cannot append key ":key" to Iterator class ":class" object because no "after" key was specified', [
                ':key'   => $key,
                ':class' => static::class,
            ]));
        }

        // Ensure the after key exists
        if (!array_key_exists($after, $this->source)) {
            throw new IteratorKeyNotExistsException(tr('Cannot append key ":key" to Iterator class ":class" object after key ":after" because the after key ":after" does not exist', [
                ':key'   => $key,
                ':after' => $after,
                ':class' => static::class,
            ]));
        }

        // NULL keys will be added as numerical "next" entries
        if (array_key_exists($key, $this->source) and ($exception ?? $this->exception_on_get)) {
            throw new IteratorKeyExistsException(tr('Cannot append key ":key" to Iterator class ":class" object after key ":after" because the key ":key" already exists', [
                ':key'   => $key,
                ':after' => $after,
                ':class' => static::class,
            ]));
        }

        Arrays::spliceByKey($this->source, $after, 0, [$key => $value], true);
        return $this;
    }


    /**
     * Prepends the specified value to the Iterator source using an optional key BEFORE the specified "$before" value
     *
     * @note if no key was specified, the entry will be assigned as-if a new array entry
     *
     * @param mixed                            $value            The value to add
     * @param Stringable|string|float|int|null $key              [null]  The key under which to store the value. If NULL, the key is determined automatically
     * @param Stringable|string|int|null       $before           [null]  The value before which this new value must be inserted. TODO If null, then what happens?
     * @param bool                             $strict           [false] If true, the $before value must match with strict (===) comparison. If false, must
     *                                                                   match a loose comparison (==)
     * @param bool                             $skip_null_values [true]  If true, will skipp adding the value if it is NULL
     * @param bool                             $exception        [true]  If true, will throw an exception if the DataEntry object already exists in this list
     *
     * @return static
     */
    public function prependBeforeValue(mixed $value, Stringable|string|float|int|null $key = null, mixed $before = null, bool $strict = false, bool $skip_null_values = true, bool $exception = true): static
    {
        // Skip NULL values?
        if ($value === null) {
            if ($skip_null_values) {
                // TODO Check why $this->source[$key] would be unset if the $value is NULL. IF there was a value, it should just remain, no?
                unset($this->source[$key]);
                return $this;
            }
        }

        $value = $this->checkDataTypeAndContent($value, $key);

        if ($before === null) {
            throw new IteratorNullKeyException(tr('Cannot prepend value ":value" to Iterator class ":class" object because no "before" value was specified', [
                ':value' => $value,
                ':class' => static::class,
            ]));
        }

        // Ensure the before key exists
        $before_key = array_search($before, $this->source, $strict);

        if ($before_key === false) {
            throw new IteratorKeyNotExistsException(tr('Cannot prepend key ":key" to Iterator class ":class" object before value ":before" because the before value ":before" does not exist', [
                ':key'    => $key,
                ':before' => $before,
                ':class'  => static::class,
            ]));
        }

        // NULL keys will be added as numerical "next" entries
        if (array_key_exists($key, $this->source) and ($exception ?? $this->exception_on_get)) {
            throw new IteratorKeyExistsException(tr('Cannot prepend key ":key" to Iterator class ":class" object before key ":before" because the key ":key" already exists', [
                ':key'    => $key,
                ':before' => $before,
                ':class'  => static::class,
            ]));
        }

        Arrays::spliceByKey($this->source, $before_key, 0, [$key => $value], false);
        return $this;
    }


    /**
     * Appends the specified value to the Iterator source using an optional key AFTER the specified "$after" value
     *
     * @note if no key was specified, the entry will be assigned as-if a new array entry
     *
     * @param mixed                            $value            The value to add
     * @param Stringable|string|float|int|null $key              [null]  The key under which to store the value. If NULL, the key is determined automatically
     * @param Stringable|string|int|null       $after            [null]  The value after which this new value must be inserted. TODO If null, then what happens?
     * @param bool                             $strict           [false] If true, the $before value must match with strict (===) comparison. If false, must
     *                                                                   match a loose comparison (==)
     * @param bool                             $skip_null_values [true]  If true, will skipp adding the value if it is NULL
     * @param bool                             $exception        [true]  If true, will throw an exception if the DataEntry object already exists in this list
     *
     * @return static
     */
    public function appendAfterValue(mixed $value, Stringable|string|float|int|null $key = null, mixed $after = null, bool $strict = false, bool $skip_null_values = true, bool $exception = true): static
    {
        // Skip NULL values?
        if ($value === null) {
            if ($skip_null_values) {
                // TODO Check why $this->source[$key] would be unset if the $value is NULL. IF there was a value, it should just remain, no?
                unset($this->source[$key]);
                return $this;
            }
        }

        $value = $this->checkDataTypeAndContent($value, $key);

        if ($after === null) {
            throw new IteratorNullKeyException(tr('Cannot prepend value ":value" to Iterator class ":class" object because no "after" value was specified', [
                ':value' => $value,
                ':class' => static::class,
            ]));
        }

        // Ensure the after value exists
        $after_key = array_search($after, $this->source, $strict);

        if ($after_key === false) {
            throw new IteratorKeyNotExistsException(tr('Cannot add key ":key" to Iterator class ":class" object after value ":after" because the after value ":after" does not exist', [
                ':key'   => $key,
                ':after' => $after,
                ':class' => static::class,
            ]));
        }

        // NULL keys will be added as numerical "next" entries
        if (array_key_exists($key, $this->source) and ($exception ?? $this->exception_on_get)) {
            throw new IteratorKeyExistsException(tr('Cannot add key ":key" to Iterator class ":class" object after key ":after" because the key ":key" already exists', [
                ':key'   => $key,
                ':after' => $after,
                ':class' => static::class,
            ]));
        }

        Arrays::spliceByKey($this->source, $after_key, 0, [$key => $value], true);

        return $this;
    }


    /**
     * Check if the datatype of the given value or Interface (in case of an object) is allowed
     *
     * Throws an OutOfBounds exception if the datatype or Interface is not allowed
     *
     * @param mixed                            $value
     * @param Stringable|string|float|int|null $key
     *
     * @return mixed
     * @throws IteratorDataTypeNotAcceptedException | IteratorValidatorFailedException
     */
    protected function checkDataTypeAndContent(mixed $value, Stringable|string|float|int|null $key = null): mixed
    {
        if ($this->accepted_data_types) {
            if (!is_datatype_or_class($this->accepted_data_types, $value)) {
                // Failed data Tests
                throw new IteratorDataTypeNotAcceptedException(tr('Iterator ":class" value argument is restricted to type(s) ":allowed", value ":value" has datatype ":type"', [
                    ':class'   => static::class,
                    ':value'   => $value,
                    ':type'    => (is_object($value) ? get_class($value) : gettype($value)),
                    ':allowed' => Strings::force($this->accepted_data_types, ', '),
                ]));
            }
        }

        // Apply validators as well? Only if datatype test  has not failed yet
        if (isset($this->validators)) {
            foreach ($this->validators as $name => $_validator) {
                if (!$_validator($value)) {
                    throw IteratorValidatorFailedException::new(tr('Iterator value argument ":key" with value ":value" failed to pass validator ":validator"', [
                        ':key'       => $key ?? tr('N/A'),
                        ':value'     => $value,
                        ':validator' => $name,
                    ]))->addData([
                        'key'       => $key ?? tr('N/A'),
                        'value'     => $value,
                        'validator' => $name,
                        'iterator'  => $this->getName(),
                    ]);
                }
            }
        }

        // Passed both datatype and validator Tests, yay!
        return $value;
    }


    /**
     * Returns an Iterator object that contains callback functions that validates data before adding it to this Iterator
     *
     * @return IteratorInterface
     */
    public function getValidatorsObject(): IteratorInterface
    {
        if (empty($this->validators)) {
            $this->validators = Iterator::new()->setAcceptedDataTypes('closure');
        }

        return $this->validators;
    }


    /**
     * Adds a validator callback that must be passed for data to be added to this Iterator object
     *
     * @param callable    $_validator
     * @param string|null $name
     *
     * @return static
     */
    public function addValidator(callable $_validator, ?string $name = null): static
    {
        $this->getValidatorsObject()->add($_validator, $name);
        return $this;
    }


    /**
     * Forces the specified source to become an Iterator
     *
     * @note DataIterator objects will remain DataIterator objects as those are extended Iterators
     *
     * @param mixed       $source
     * @param string|null $separator
     *
     * @return static
     */
    public static function force(mixed $source, ?string $separator = ','): static
    {
        return static::new(Arrays::force($source, $separator));
    }


    /**
     * Explodes the specified string into an Iterator object and returns it
     *
     * @param Stringable|string $source
     * @param string|null       $separator
     *
     * @return static
     */
    public static function explode(Stringable|string $source, ?string $separator = ','): static
    {
        $source = (string) $source;

        if ($separator) {
            $source = explode($separator, $source);

        } else {
            // We cannot explode with an empty separator, assume that $source is a single item and return it as such
            $source = [$source];
        }

        return static::new()->setSource($source);
    }


    /**
     * Same as Arrays::spliceKey() but for this Iterator
     *
     * @param string                  $key
     * @param int|null                $length
     * @param IteratorInterface|array $replacement
     * @param bool                    $after
     * @param array|null              $spliced
     *
     * @return static
     */
    public function spliceByKey(string $key, ?int $length = null, IteratorInterface|array $replacement = [], bool $after = false, ?array &$spliced = null): static
    {
        try {
            $spliced = Arrays::spliceByKey($this->source, $key, $length, $replacement, $after);

        } catch (OutOfBoundsException $e) {
            throw new OutOfBoundsException(tr('Failed to splice iterator by key ":key", the key does not exist', [
                ':key' => $key,
            ]), $e);
        }

        return $this;
    }


    /**
     * Will remove the entry with the specified key before the $before key
     *
     * @param Stringable|string|float|int|null $key
     * @param Stringable|string|int|null $before
     * @param bool                       $strict
     *
     * @return static
     */
    public function moveBeforeKey(Stringable|string|float|int|null $key, Stringable|string|int|null $before, bool $strict = true): static
    {
        $pos_key    = array_search($key   , array_keys($this->source), $strict);
        $pos_before = array_search($before, array_keys($this->source), $strict);

        if ($pos_key === false) {
            throw new OutOfBoundsException(tr('Specified key ":key" does not exist in this ":class" list', [
                ':key'   => $key,
                ':class' => static::class,
            ]));
        }

        if ($pos_before === false) {
            throw new OutOfBoundsException(tr('Specified before key ":key" does not exist in this ":class" list', [
                ':key'   => $before,
                ':class' => static::class,
            ]));
        }

        $part1 = array_splice($this->source, $pos_key, 1);
        $part2 = array_splice($this->source, 0, $pos_before);

        $this->source = array_merge($part2, $part1, $this->source);

        return $this;
    }


    /**
     * Will remove the entry with the specified key after the $after key
     *
     * @param Stringable|string|float|int|null $key
     * @param Stringable|string|int|null $after
     * @param bool                       $strict
     *
     * @return static
     */
    public function moveAfterKey(Stringable|string|float|int|null $key, Stringable|string|int|null $after, bool $strict = true): static
    {
        $pos_key   = array_search($key  , array_keys($this->source), $strict);
        $pos_after = array_search($after, array_keys($this->source), $strict);

        if ($pos_key === false) {
            throw new OutOfBoundsException(tr('Specified key ":key" does not exist in this ":class" list', [
                ':key'   => $key,
                ':class' => static::class,
            ]));
        }

        if ($pos_after === false) {
            throw new OutOfBoundsException(tr('Specified after key ":key" does not exist in this ":class" list', [
                ':key'   => $after,
                ':class' => static::class,
            ]));
        }

        $part1 = array_splice($this->source, $pos_key, 1);
        $part2 = array_splice($this->source, 0, $pos_after + 1);

        $this->source = array_merge($part2, $part1, $this->source);

        return $this;
    }


    /**
     * Copies the value of the specified $from_key to the specified $to_key
     *
     * Note: $from_key must exist, or an OutOfBoundsException will be thrown
     * Note: If the specified key $to_key already exist, its value will be overwritten
     *
     * @param Stringable|string|int|null $from_key
     * @param Stringable|string|int|null $to_key
     *
     * @return static
     * @throws OutOfBoundsException|Throwable
     */
    public function copyValue(Stringable|string|int|null $from_key, Stringable|string|int|null $to_key): static
    {
        try {
            $this->source[$to_key] = $this->source[$from_key];

        } catch (Throwable $e) {
            if (array_key_exists($from_key, $this->source)) {
                // No idea what went wrong here
                throw $e;
            }

            throw new OutOfBoundsException(tr('The specified from_key ":from_key" does not exist in this ":class" Iterator', [
                ':from_key' => $from_key,
                ':class'    => static::class,
            ]), $e);
        }

        return $this;
    }


    /**
     * Adds the specified source(s) to the internal source
     *
     * @param IteratorInterface|array|string|null $source
     * @param bool                                $clear_keys
     * @param bool                                $exception
     *
     * @return static
     */
    public function addSource(IteratorInterface|array|string|null $source, bool $clear_keys = false, bool $exception = true): static
    {
        if ($source instanceof IteratorInterface) {
            if ($source === $this) {
                throw OutOfBoundsException::new(tr('Cannot add a source Iterator object that is itself, to itself, it would cause an endless loop'))
                                          ->addData([
                                              'this'   => $this,
                                              'source' => $source,
                                          ]);
            }

            $source = $source->getSource();
        }

        // Add each entry
        foreach (Arrays::force($source) as $key => $value) {
            $this->add($value, $clear_keys ? null : $key, exception: ($exception ?? $this->exception_on_get));
        }

        return $this;
    }


    /**
     * Sets the internal source directly, but separating the values in key > values by $separator
     *
     * Any value that is NOT a string will be quietly ignored
     *
     * @param IteratorInterface|PDOStatement|array|string|null $source
     * @param array|null                                       $execute
     * @param string|null                                      $separator
     * @return static
     */
    public function setKeyValueSource(IteratorInterface|PDOStatement|array|string|null $source = null, array|null $execute = null, ?string $separator = null): static
    {
        $source = Arrays::extractSourceArray($source, $execute);

        if ($separator) {
            foreach ($source as $key => &$value) {
                if (is_string($value)) {
                    $key   = trim(Strings::until($value, $separator));
                    $value = trim(Strings::from($value, $separator));

                    $this->source[$key] = $value;
                }
            }

            unset($value);

        } else {
            $this->source = $source;
        }

        return $this;
    }


    /**
     * Append the specified source to the end of this Iterator
     *
     * @param IteratorInterface|array ...$sources
     *
     * @return static
     */
    public function appendSource(IteratorInterface|array ...$sources): static
    {
        foreach ($sources as $source) {
            if (is_object($source)) {
                $source = $source->getSource();
            }

            $this->source = array_merge($this->source, $source);
        }

        return $this;
    }


    /**
     * Prepend the specified source at the beginning of this Iterator
     *
     * @param IteratorInterface|array ...$sources
     *
     * @return static
     */
    public function prependSource(IteratorInterface|array ...$sources): static
    {
        foreach ($sources as $source) {
            if (is_object($source)) {
                $source = $source->getSource();
            }

            $this->source = array_merge($source, $this->source);
        }

        return $this;
    }


    /**
     * Returns the datatype restrictions for all elements in this iterator, NULL if none
     *
     * @return array|null
     */
    public function getAcceptedDataTypes(): ?array
    {
        return $this->accepted_data_types;
    }


    /**
     * Sets the datatype restrictions for all elements in this iterator, NULL if none
     *
     * @param array|string|null $data_types
     * @param bool              $overwrite
     *
     * @return static
     */
    public function setAcceptedDataTypes(array|string|null $data_types, bool $overwrite = true): static
    {
        if ($this->accepted_data_types and !$overwrite) {
            return $this;
        }

        $data_types = Arrays::force($data_types, '|');

        foreach ($data_types as $data_type) {
            if ($data_type) {
                if (!preg_match('/^mixed|bool|int|float|string|array|null|resource|object|closure|(?:(?:(?:(?:[A-Z][a-z0-9]+)+\\\)+)+(?:[A-Z][a-z0-9]+)+)$/', $data_type)) {
                    throw new OutOfBoundsException(tr('Invalid Iterator datatype restriction ":datatype" specified, must be one or multiple of "mixed|bool|int|float|string|array|null|resource|object|closure|Class\Path"', [
                        ':datatype' => $data_type,
                    ]));
                }

            } else {
                // No data type restrictions required
                $data_type = null;
            }
        }

        $this->accepted_data_types = $data_types;

        if ($this->source) {
            // This iterator already contains a data source! Ensure all entries match the accepted datatypes
            foreach ($this->source as $key => &$value) {
                $value = $this->checkDataTypeAndContent($value, $key);
            }
        }

        return $this;
    }


    /**
     * Returns value for the specified key, defaults that key to the specified value if it does not yet exist
     *
     * @param Stringable|string|float|int $key
     * @param mixed                       $value
     *
     * @return mixed
     */
    #[ReturnTypeWillChange] public function getValueOrDefault(Stringable|string|float|int $key, mixed $value): mixed
    {
        if (!array_key_exists($key, $this->source)) {
            $this->source[$key] = $value;
        }

        return $this->source[$key];
    }


    /**
     * Returns true if the specified key exists in the array source
     *
     * @param Stringable|string|float|int|null $key
     *
     * @return bool
     */
    public function hasKey(Stringable|string|float|int|null $key): bool
    {
        return array_key_exists($key, $this->source);
    }


    /**
     * Returns the length of the longest value
     *
     * @return int
     */
    public function getLongestKeyLength(): int
    {
        return Arrays::getLongestKeyLength($this->source);
    }


    /**
     * Returns the length of the shortest value
     *
     * @return int
     */
    public function getShortestKeyLength(): int
    {
        return Arrays::getShortestKeyLength($this->source);
    }


    /**
     * Returns the length of the longest value
     *
     * @param string|null $key
     * @param bool        $exception
     *
     * @return int
     */
    public function getLongestValueLength(?string $key = null, bool $exception = false): int
    {
        return Arrays::getLongestValueLength($this->source, $key, $exception ?? $this->exception_on_get);
    }


    /**
     * Returns the length of the shortest value
     *
     * @param string|null $key
     * @param bool        $exception
     *
     * @return int
     */
    public function getShortestValueLength(?string $key = null, bool $exception = false): int
    {
        return Arrays::getShortestValueLength($this->source, $key, $exception ?? $this->exception_on_get);
    }


    /**
     * Returns a list with all the values that match the specified value
     *
     * @param ArrayableInterface|Stringable|array|string|int|null $needles
     * @param string|null                                         $column
     *
     * @return static
     */
    public function keepMatchingAutocompleteValues(ArrayableInterface|Stringable|array|string|int|null $needles, ?string $column = null): static
    {
        $this->source = Arrays::keepMatchingValuesBeginningWith($this->source, $needles, Utils::MATCH_CASE_INSENSITIVE | Utils::MATCH_ALL | Utils::MATCH_BEGINS_WITH | Utils::SKIP_NULL_NEEDLES, $column);
        return $this;
    }


    /**
     * Remove source keys on the specified needles with the specified match mode
     *
     * @param ArrayableInterface|Stringable|array|string|int|null $needles
     * @param int                                                 $flags
     *
     * @return static
     */
    public function keepMatchingKeys(ArrayableInterface|Stringable|array|string|int|null $needles, int $flags = Utils::MATCH_FULL | Utils::MATCH_REQUIRE): static
    {
        $this->source = Arrays::keepMatchingKeys($this->source, $needles, $flags);
        return $this;
    }


    /**
     * Keep source values on the specified needles with the specified match mode
     *
     * @param ArrayableInterface|Stringable|array|string|int|null $needles
     * @param int                                                 $flags
     * @param string|null                              $column
     *
     * @return static
     */
    public function keepMatchingValues(ArrayableInterface|Stringable|array|string|int|null $needles, int $flags = Utils::MATCH_FULL | Utils::MATCH_REQUIRE, ?string $column = null): static
    {
        $this->source = Arrays::keepMatchingValues($this->source, $needles, $flags, $column);
        return $this;
    }


    /**
     * Remove source keys on the specified needles with the specified match mode
     *
     * @param ArrayableInterface|Stringable|array|string|int|null $needles
     * @param int                                                 $flags
     *
     * @return static
     */
    public function removeMatchingKeys(ArrayableInterface|Stringable|array|string|int|null $needles, int $flags = Utils::MATCH_FULL | Utils::MATCH_REQUIRE): static
    {
        $this->source = Arrays::removeMatchingKeys($this->source, $needles, $flags);
        return $this;
    }


    /**
     * Remove source values on the specified needles with the specified match mode
     *
     * @param ArrayableInterface|Stringable|array|string|int|null $needles
     * @param int                                                 $flags
     * @param string|null                              $column
     *
     * @return static
     */
    public function removeMatchingValues(ArrayableInterface|Stringable|array|string|int|null $needles, int $flags = Utils::MATCH_FULL | Utils::MATCH_REQUIRE, ?string $column = null): static
    {
        $this->source = Arrays::removeMatchingValues($this->source, $needles, $flags, $column);
        return $this;
    }


    /**
     * Returns Iterator with the entries where the keys match the specified needles and flags
     *
     * @param ArrayableInterface|Stringable|array|string|int|null $needles
     * @param int                                                 $flags
     *
     * @return static
     */
    public function getMatchingKeys(ArrayableInterface|Stringable|array|string|int|null $needles, int $flags = Utils::MATCH_FULL | Utils::MATCH_REQUIRE): static
    {
        return new static(Arrays::keepMatchingKeys($this->source, $needles, $flags));
    }


    /**
     * Returns Iterator with the entries where the values match the specified needles and flags
     *
     * @param ArrayableInterface|Stringable|array|string|int|null $needles
     * @param int $flags
     * @param string|null $column
     * @return static
     */
    public function getMatchingValues(ArrayableInterface|Stringable|array|string|int|null $needles, int $flags = Utils::MATCH_FULL | Utils::MATCH_REQUIRE, ?string $column = null): static
    {
        return new static(Arrays::keepMatchingValues($this->source, $needles, $flags, $column));
    }


    /**
     * Returns a list with all the values that match the specified value
     *
     * @param ArrayableInterface|Stringable|array|string|int|null $needles
     * @param int                                                 $flags
     * @param string|null                                         $column
     *
     * @return static
     */
    public function keepMatchingValuesStartingWith(ArrayableInterface|Stringable|array|string|int|null $needles, int $flags = Utils::MATCH_CASE_INSENSITIVE | Utils::MATCH_ALL | Utils::MATCH_BEGINS_WITH, ?string $column = null): static
    {
        return new static(Arrays::keepMatchingValuesBeginningWith($this->source, $needles, $flags, $column));
    }


    /**
     * Returns a list with all the values that match the specified value
     *
     * @param ArrayableInterface|Stringable|array|string|int|null $needles
     * @param int                                                 $flags
     *
     * @return static
     */
    public function keepMatchingKeysStartingWith(ArrayableInterface|Stringable|array|string|int|null $needles, int $flags = Utils::MATCH_CASE_INSENSITIVE | Utils::MATCH_ALL | Utils::MATCH_BEGINS_WITH): static
    {
        return new static(Arrays::keepMatchingKeysBeginningWith($this->source, $needles, $flags));
    }


//    /**
//     * Deletes the entries that have columns with the specified value(s)
//     *
//     * @param Stringable|array|string|int $values
//     * @param string                            $column
//     *
//     * @return static
//     */
//    public function removeMatchingValuesByColumn(Stringable|array|string|int $values, string $column): static
//    {
//        foreach (Arrays::force($values, null) as $value) {
//            foreach ($this->source as $key => $data) {
//                if (is_array($data)) {
//                    if (!array_key_exists($column, $data)) {
//                        throw new OutOfBoundsException(tr('Cannot delete entries by column ":column" value ":value" because entry ":key" does not have the requested column ":column"', [
//                            ':key'    => $key,
//                            ':value'  => $value,
//                            ':column' => $column,
//                        ]));
//                    }
//                    if ($data[$key] === $value) {
//                        unset($this->source[$key]);
//                    }
//                } else {
//                    if (!$data instanceof DataEntry) {
//                        throw new OutOfBoundsException(tr('Cannot delete entries by column ":column" value ":value" because key ":key" is neither array nor DataEntry', [
//                            ':key'    => $key,
//                            ':value'  => $value,
//                            ':column' => $column,
//                        ]));
//                    }
//                    // This entry is not an array but DataEntry object. Compare using DataEntry::get()
//                    if ($data->load($key) === $value) {
//                        unset($this->source[$key]);
//                    }
//                }
//            }
//        }
//
//        return $this;
//    }


    /**
     * Returns the total amounts for all columns together for only the specified columns
     *
     * @param array|string|null $columns
     * @param string|null       $totals_column
     * @param string|null       $totals_label
     *
     * @return array|null
     */
    public function getTotals(array|string|null $columns = null, ?string $totals_column = null, ?string $totals_label = null): ?array
    {
        if (!$this->source) {
            return null;
        }

        if (empty($columns)) {
            $columns = $this->getColumns();

        } elseif (is_string($columns)) {
            $columns = Arrays::force($columns);
        }

        $footers = $this->getColumns();
        $footers = array_flip($footers);
        $footers = Arrays::setValues($footers);

        // Ensure all requested columns exist
        foreach ($columns as $column) {
            if (str_starts_with($column, '-')) {
                $remove = true;
                $column = substr($column, 1);
            }

            if (!array_key_exists($column, $footers)) {
                throw new OutOfBoundsException(tr('Specified totals column ":column" does not exist in the source data', [
                    ':column' => $column,
                ]));
            }
        }

        // Initialize all requested column footers
        foreach($footers as $footer => &$value) {
            if ($footer === $totals_column) {
                // This is the column that will have the "Totals:" label, ensure it will not try to add data from columns!
                $value           = $totals_label ?? tr('Totals');
                $columns = Arrays::removeValues($columns, $totals_column);

            } elseif (array_key_exists($footer, $columns)) {
                $value = 0;
            }
        }

        unset($value);

        // Convert all source entries to array and fetch their values
        foreach ($this->source as $value) {
            $value = Arrays::force($value);

            try {
                foreach ($columns as $column) {
                    $footers[$column] += get_numeric(array_get_safe($value, $column));
                }

            } catch (Throwable $e) {
                throw IteratorException::new(tr('Encountered non numeric row while calculating totals'), $e)
                                       ->addData([
                                           'row' => $value,
                                       ]);
            }
        }

        return $footers;
    }


    /**
     * Displays a message on the command line
     *
     * @param string|null $message
     * @param bool        $header
     *
     * @return static
     */
    public function displayCliMessage(?string $message = null, bool $header = false): static
    {
        if ($header) {
            Log::information($message, echo_prefix: false);

        } else {
            Log::cli($message);
        }

        return $this;
    }


    /**
     * Creates and returns a CLI table for the data in this list
     *
     * @param array|string|null $columns
     * @param array             $filters
     * @param string|null       $id_column
     *
     * @return static
     */
    public function displayCliTable(array|string|null $columns = null, array $filters = [], ?string $id_column = 'id'): static
    {
        Cli::displayTable($this->source, $columns, $id_column);
        return $this;
    }


    /**
     * Creates and returns a CLI key-value table for the data in this list
     *
     * @param string|null $key_header
     * @param string|null $value_header
     * @param int         $offset
     *
     * @return static
     */
    public function displayCliKeyValueTable(?string $key_header = null, ?string $value_header = null, int $offset = 0): static
    {
        Cli::displayForm($this->source, $key_header, $value_header, $offset);
        return $this;
    }


    /**
     * Shift an entry off the beginning of this Iterator
     *
     * @return mixed
     */
    #[ReturnTypeWillChange] public function extractFirstValue(): mixed
    {
        return array_shift($this->source);
    }


    /**
     * Prepend elements to the beginning of an array
     *
     * @return mixed
     */
    public function extractLastValue(mixed ...$values): static
    {
        array_pop($this->source, $values);
        return $this;
    }


    /**
     * Sorts the Iterator source in ascending order
     *
     * @return static
     */
    public function sort(): static
    {
        asort($this->source);
        return $this;
    }


    /**
     * Sorts the Iterator source in descending order
     *
     * @return static
     */
    public function rsort(): static
    {
        arsort($this->source);
        return $this;
    }


    /**
     * Sorts the Iterator source keys in ascending order
     *
     * @return static
     */
    public function ksort(): static
    {
        ksort($this->source);
        return $this;
    }


    /**
     * Sorts the Iterator source keys in descending order
     *
     * @return static
     */
    public function krsort(): static
    {
        krsort($this->source);
        return $this;
    }


    /**
     * Sorts the Iterator source using the specified callback
     *
     * @param callable $callback
     *
     * @return static
     */
    public function uasort(callable $callback): static
    {
        uasort($this->source, $callback);
        return $this;
    }


    /**
     * Sorts the Iterator source keys using the specified callback
     *
     * @param callable $callback
     *
     * @return static
     */
    public function uksort(callable $callback): static
    {
        uksort($this->source, $callback);
        return $this;
    }


    /**
     * Will limit the number of entries in the source of this DataIterator to the
     *
     * @return static
     */
    public function limitAutoComplete(): static
    {
        $this->source = Arrays::limit($this->source, Limit::getShellAutoCompletion());
        return $this;
    }


    /**
     * Returns if all (or optionally any) of the specified entries are in this list
     *
     * @param IteratorInterface|array|string $list
     * @param bool                           $all
     * @param string|null                    $always_match
     *
     * @return bool
     */
    public function containsKeys(IteratorInterface|array|string $list, bool $all = true, ?string $always_match = null): bool
    {
        foreach (Arrays::force($list) as $key) {
            if (!array_key_exists($key, $this->source)) {
                if ($all) {
                    // All need to be in the array, but we found one missing.
                    // Can still match if $always_match is available!
                    if ($always_match and array_key_exists($always_match, $this->source)) {
                        // Okay, this list contains ALL the requested entries due to $always_match
                        return true;
                    }

                    return false;
                }

            } elseif (!$all) {
                // only one needs to be in the array, we found one, we are good!
                return true;
            }
        }

        if ($all) {
            // All were in the array
            return true;
        } else {
            // if a match happened, it would have returned true instantly, meaning there were no matches
            return false;
        }
    }


    /**
     * Returns a list of items that are specified, but not available in this Iterator
     *
     * @param IteratorInterface|array|string $list
     * @param string|null                    $always_match
     *
     * @return array
     * @todo Redo this with array_diff()
     */
    public function getMissingKeys(IteratorInterface|array|string $list, ?string $always_match = null): array
    {
        $return = [];

        foreach (Arrays::force($list) as $key) {
            if (array_key_exists($key, $this->source)) {
                continue;
            }

            // Can still match if $always_match is available!
            if ($always_match and array_key_exists($always_match, $this->source)) {
                // Okay, this list contains ALL the requested entries due to $always_match
                return [];
            }

            $return[] = $key;
        }

        return $return;
    }


    /**
     * Returns multiple column values for a single entry
     *
     * @param Stringable|string|int $key
     * @param array|string          $columns
     * @param bool                  $exception
     *
     * @return static
     */
    #[ReturnTypeWillChange] public function getSingleRowMultipleColumns(Stringable|string|int $key, array|string $columns, bool $exception = true): static
    {
        if (!$columns) {
            throw new OutOfBoundsException(tr('Cannot return source key columns for ":this", no columns specified', [
                ':this' => static::class,
            ]));
        }

        $value = $this->get($key, exception: $exception ?? $this->exception_on_get);
        $value = $this->ensureSourceValueHasColumns($value, $columns);

        return new static(Arrays::keepKeys($value, $columns));
    }


    /**
     * Returns true if the specified key has the specified value
     *
     * @param Stringable|string|float|int $key
     * @param mixed                       $value
     * @param bool                        $strict
     * @param bool                        $exception
     *
     * @return bool
     */
    public function keyHasValue(Stringable|string|float|int $key, mixed $value, bool $strict = true, bool $exception = true): bool
    {
        if ($strict) {
            return $this->get($key, exception: $exception ?? $this->exception_on_get) === $value;
        }

        return $this->get($key, exception: $exception ?? $this->exception_on_get) == $value;
    }


    /**
     * Returns value for the specified key
     *
     * @param Stringable|string|float|int $key       The key in this Iterator object for which to return the value
     * @param mixed                       $default   The default value to return if the specified key does not exist
     * @param bool|null                   $exception If true, will throw a NotExistsException if the specified key does
     *                                               not exist
     *
     * @return mixed
     * @throws NotExistsException
     */
    #[ReturnTypeWillChange] public function get(Stringable|string|float|int $key, mixed $default = null, ?bool $exception = null): mixed
    {
        // Does this entry exist?
        if (array_key_exists($key, $this->source)) {
            return $this->ensureObject($key);
        }

        if ($exception ?? $this->exception_on_get) {
            // The key does not exist
            throw new NotExistsException(tr('The key ":key" does not exist in this ":class" object', [
                ':key'   => $key,
                ':class' => static::class,
            ]));
        }

        return $default;
    }


    /**
     * Sets the value for the specified key
     *
     * @note this is basically a wrapper function for IteratorCore::add($value, $key, false) that always requires a key
     *
     * @param mixed                       $value
     * @param Stringable|string|float|int $key
     * @param bool                        $skip_null_values
     *
     * @return mixed
     */
    public function set(mixed $value, Stringable|string|float|int $key, bool $skip_null_values = true): static
    {
        return $this->append($value, $key, $skip_null_values, false);
    }


    /**
     * Returns a random entry
     *
     * @return mixed
     */
    #[ReturnTypeWillChange] public function getRandom(): mixed
    {
        if (empty($this->source)) {
            return null;
        }

        return $this->ensureObject(array_rand($this->source, 1));
    }


    /**
     * Returns a new static object with multiple random entries
     *
     * @param int $count
     *
     * @return static
     */
    public function getRandomList(int $count = 1): static
    {
        $iterator = new static();

        return $iterator->setSource(Arrays::getRandomValues($this->source, $count));
    }


    /**
     * Checks that the specified value has the requested columns
     *
     * @param mixed        $value
     * @param array|string $columns
     *
     * @return array|null
     *
     * @throws OutOfBoundsException When the specified value is neither an array nor an ArrayableInterface object
     */
    protected function checkSourceValueHasColumns(ArrayableInterface|array $value, array|string $columns): ?array
    {
        // Ensure we have arrays
        if (is_object($value)) {
            if (!$value instanceof ArrayableInterface) {
                throw new OutOfBoundsException(tr('Cannot get source columns for ":this", the source contains non arrayable objects', [
                    ':this' => static::class,
                ]));
            }

            $value = $value->getSource();
        }

        foreach (Arrays::force($columns) as $column) {
            if (!array_key_exists($column, $value)) {
                throw OutOfBoundsException::new(tr('The specified column ":column" does not exist', [
                    ':column' => $column,
                ]))->setData([
                    'column'  => $column,
                    'columns' => Arrays::force($columns),

                ]);
            }
        }

        return $value;
    }


    /**
     * Ensures that the specified value has the requested columns
     *
     * @param mixed        $value
     * @param array|string $columns
     * @param mixed|null   $default
     *
     * @return array|null
     *
     */
    protected function ensureSourceValueHasColumns(ArrayableInterface|array $value, array|string $columns, mixed $default = null): ?array
    {
        // Ensure we have arrays
        if (is_object($value)) {
            if (!$value instanceof ArrayableInterface) {
                throw new OutOfBoundsException(tr('Cannot get source columns for ":this", the source contains non arrayable objects', [
                    ':this' => static::class,
                ]));
            }

            $value = $value->getSource();
        }

        foreach (Arrays::force($columns) as $column) {
            if (!array_key_exists($column, $value)) {
                $value[$column] = $default;
            }
        }

        return $value;
    }


    /**
     * Returns multiple column values for multiple entries
     *
     * @param Stringable|string|int $key
     * @param string                $column
     * @param bool                  $exception
     *
     * @return mixed
     */
    #[ReturnTypeWillChange] public function getSingleRowsSingleColumn(Stringable|string|int $key, string $column, bool $exception = true): mixed
    {
        if (!$column) {
            throw new OutOfBoundsException(tr('Cannot return source key column for ":this", no column specified', [
                ':this' => static::class,
            ]));
        }

        $value = $this->get($key, exception: $exception);
        $value = $this->checkSourceValueHasColumns($value, $column);
        $value = Arrays::keepKeys($value, $column);

        return $value[$column];
    }


    /**
     * Returns an array with each value containing a scalar with only the specified column value
     *
     * @note This only works on sources that contains array / DataEntry object values. Any other value will cause an
     *       OutOfBoundsException
     *
     * @param string $column
     * @param bool   $allow_scalar
     *
     * @return IteratorInterface
     */
    public function getAllRowsSingleColumn(string $column, bool $allow_scalar = false): IteratorInterface
    {
        if (!$column) {
            throw new OutOfBoundsException(tr('Cannot return source column for ":this", no column specified', [
                ':this' => static::class,
            ]));
        }

        $return = [];

        foreach ($this->source as $key => $value) {
            if (is_scalar($value)) {
                if (!$allow_scalar) {
                    throw new OutOfBoundsException(tr('Encountered scalar value for key ":key" where either array or object is required', [
                        ':key' => $key,
                    ]));
                }

                $return[$key] = $value;

            } else {
                $value        = $this->checkSourceValueHasColumns($value, $column);
                $return[$key] = $value[$column];
            }
        }

        return Iterator::new()->setAcceptedDataTypes('string|float|int|bool|null')->setSource($return);
    }


    /**
     * Same as Arrays::splice() but for this Iterator
     *
     * @param int                     $offset
     * @param int|null                $length
     * @param IteratorInterface|array $replacement
     * @param array|null              $spliced
     *
     * @return static
     */
    public function splice(int $offset, ?int $length = null, IteratorInterface|array $replacement = [], ?array &$spliced = null): static
    {
        $spliced = Arrays::splice($this->source, $offset, $length, $replacement);
        return $this;
    }


    /**
     * Renames and returns the specified value
     *
     * @param Stringable|string|int $key
     * @param Stringable|string|int $target
     * @param bool                  $exception
     *
     * @return DefinitionInterface
     */
    #[ReturnTypeWillChange] public function renameKey(Stringable|string|int $key, Stringable|string|int $target, bool $exception = true): mixed
    {
        // First, ensure the target does not exist yet!
        if (array_key_exists($target, $this->source)) {
            throw new IteratorException(tr('Cannot rename key ":key" to target ":target", the target key already exists', [
                ':key'    => $key,
                ':target' => $target,
            ]));
        }

        // Then, get the entry
        $entry = $this->get($key, exception: $exception);

        // Now rename
        $this->source[$target] = $this->source[$key];
        unset($this->source[$key]);

        // Done, return!
        return $entry;
    }


    /**
     * Returns an IteratorInterface with array values containing only the specified columns
     *
     * @note This only works on sources that contain array / DataEntry object values. Any other value will cause an
     *       OutOfBoundsException
     *
     * @note If no columns were specified, then all columns will be assumed and the complete source will be returned
     *
     * @param array|string|null $columns
     *
     * @return static
     */
    public function getAllRowsMultipleColumns(array|string|null $columns): static
    {
        if (!$columns) {
            // Return all columns
            return new Iterator($this->source);
        }

        // Already ensure columns is an array here to avoid Arrays::keep() having to convert all the time, just in case.
        $return  = [];
        $columns = Arrays::force($columns);

        foreach ($this->source as $key => $value) {
            $value        = $this->ensureSourceValueHasColumns($value, $columns);
            $return[$key] = Arrays::keepKeysOrdered($value, $columns);
        }

        return new static($return);
    }


    /**
     * Extracts headers from the specified columns
     *
     * @param array|string|null $columns
     *
     * @return array|null
     */
    protected function prepareHeaders(array|string|null $columns): ?array
    {
        if ($columns) {
            $columns = Arrays::force($columns);
            $return  = [];

            foreach ($columns as $column => $header) {
                if (is_integer($column)) {
                    $column = (string) $header;
                    $header = (string) $header;
                    $header = str_replace(['_', '-'], ' ', $header);
                    $header = Strings::capitalize($header);

                    $return[$column] = $header;

                } else {
                    $return[$column] = $header;
                }
            }

            return $return;
        }

        return null;
    }


    /**
     * ???
     *
     * @param array|string|null $columns
     *
     * @return array|null
     */
    protected function prepareColumns(array|string|null $columns): ?array
    {
        $columns = get_null(Arrays::force($columns ?? $this->columns));

        if ($columns) {
            if (is_array($columns)) {
                return $columns;
            }

            return explode(',', $columns);
        }

        return null;
    }


    /**
     * Returns a cache key for this object
     *
     * @return string|null
     */
    public function getCacheKeySeed(): ?string
    {
        return PROJECT . '#Iterator#' . static::class . '#' . Json::encode([Request::getUrl(), $this->getName(), ($this->_parent ? ($this->_parent::class . '-' . $this->_parent->getId()) : '')]);
    }


    /**
     * Returns a Table object containing the entries from this Iterator object
     *
     * @param array|string|null $columns
     *
     * @return HtmlTableInterface
     */
    public function getHtmlTableObject(array|string|null $columns = null): HtmlTableInterface
    {
        $columns = get_null(Arrays::force($columns ?? $this->columns));

        return HtmlTable::new($this)
                        ->setId(strtolower(Strings::fromReverse(static::class, '\\')))
                        ->setHeaders($this->prepareHeaders($columns))
                        ->setSource($this->getSource())
                        ->setRowCallbacks($this->row_callbacks)
                        ->setCheckboxSelectors(EnumTableIdColumn::checkbox);
    }


    /**
     * Returns a DataTable object containing the entries from this Iterator object
     *
     * @param array|string|null $columns
     *
     * @return HtmlDataTableInterface
     */
    public function getHtmlDataTableObject(array|string|null $columns = null): HtmlDataTableInterface
    {
        $columns = get_null(Arrays::force($columns ?? $this->columns));

        return HtmlDataTable::new($this)
                            ->setId(strtolower(Strings::fromReverse(static::class, '\\')))
                            ->setHeaders($this->prepareHeaders($columns))
                            ->setSource($this->getSource())
                            ->setRowCallbacks($this->row_callbacks)
                            ->setCheckboxSelectors(EnumTableIdColumn::checkbox);
    }


    /**
     * Returns a Select object containing the entries from this Iterator object
     *
     * @param string|null $value_column
     * @param string|null $key_column
     * @param string      $class
     *
     * @return InputSelectInterface
     */
    public function getHtmlSelectObject(?string $value_column = null, ?string $key_column = null, string $class = InputSelect::class): InputSelectInterface
    {
        $class = $this->ensureInputSelectClass($class);

        // Create and return the input select
        // TODO Change the hard coded "iterator" id and name to something programmable
        return $class::new($this)
                     ->setId('iterator')
                     ->setName('iterator')
                     ->setSource($this->getSource())
                     ->setKeyColumn($key_column)
                     ->setValueColumn($value_column);
    }


    /**
     * Returns an Accordion object containing the entries from this Iterator object
     *
     * @return AccordionInterface
     */
    public function getHtmlAccordionObject(): AccordionInterface
    {
        return Accordion::new($this);
    }


    /**
     * Returns a TreeViewer object containing the entries from this Iterator object
     *
     * @return TreeViewerInterface
     */
    public function getHtmlTreeViewerObject(): TreeViewerInterface
    {
        return TreeViewer::new($this);
    }


    /**
     * Returns an HTML <select> for the entries in this list
     *
     * @return InputSelectInterface
     */
    public function getHtmlSelectOld(): InputSelectInterface
    {
        return $this->input_select_class::new()->setSource($this->source);
    }


    /**
     * Ensures that the specified class is an InputSelect type class
     *
     * @param string $class
     *
     * @return string
     */
    protected function ensureInputSelectClass(string $class): string
    {
        if (is_a($class, InputSelect::class, true)) {
            return $class;
        }

        throw new OutOfBoundsException(tr('Invalid class ":invalid" specified, it must be ":class" or a child class of this type', [
            ':invalid' => $class,
            ':class'   => InputSelect::class,
        ]));
    }


    /**
     * Returns a SpreadSheet object with this object's source data in it
     *
     * @return SpreadSheetInterface
     */
    public function getSpreadSheet(): SpreadSheetInterface
    {
        return new SpreadSheet($this);
    }


    /**
     * Executes the specified callback function on each
     *
     * @param callable $callback
     *
     * @return static
     */
    public function forEachField(callable $callback): static
    {
        foreach ($this->source as $key => &$value) {
            $callback($value, $key);
        }

        unset($value);

        return $this;
    }


    /**
     * Returns a diff between this Iterator and the specified Iterator or array
     *
     * @param IteratorInterface|array $source
     *
     * @return static
     */
    public function diff(IteratorInterface|array $source): static
    {
        if ($source instanceof IteratorInterface) {
            $source = $source->getSource();
        }

        return new static(array_diff($this->source, $source));
    }


    /**
     * Removes all empty values from this Iterator object
     *
     * @return static
     */
    public function removeEmptyValues(): static
    {
        $this->source = Arrays::removeEmptyValues($this->source);

        return $this;
    }


    /**
     * Removes duplicate values from this Iterator
     *
     * @param int $flags Sorting type flags:
     *                   SORT_REGULAR - compare items normally (do not change types)
     *                   SORT_NUMERIC - compare items numerically
     *                   SORT_STRING - compare items as strings
     *                   SORT_LOCALE_STRING - compare items as strings, based on the current locale
     *
     * @return static
     */
    public function makeValuesUnique(int $flags = SORT_STRING): static
    {
        $this->source = array_unique($this->source, $flags);

        return $this;
    }


    /**
     * Will try to fetch the value key from the value itself
     *
     * By default this will return null, this way it will allow different iterator objects to fetch keys, or not at all
     *
     * @param mixed $value
     *
     * @return mixed
     */
    protected function fetchKeyFromValue(mixed $value): mixed
    {
        return null;
    }


    /**
     * Limit the number of source entries to the specified count
     *
     * @param int|bool $count
     *
     * @return static
     */
    public function limit(int|bool $count): static
    {
        if ($count >= 0) {
            $this->source = Arrays::limit($this->source, $count);
        }

        if ($count < 0) {
            throw new OutOfBoundsException(tr('Cannot limit :class to ":count" entries, the number must be a positive integer value', [
                ':class' => static::class,
                ':count' => $count,
            ]));
        }

        return $this;
    }


    /**
     * Executes the callback function on each entry in this Iterator
     *
     * Note: When setup to automatically ensure objects, all
     *
     * @param callable $callback The callback to execute on each entry in the iterator
     * @param bool     $ensure_objects
     *
     * @return static
     */
    public function onEach(callable $callback, bool $ensure_objects = true): static
    {
        if ($ensure_objects) {
            foreach ($this as $entry) {
                $callback($entry);
            }

        } else {
            foreach ($this->source as $entry) {
                $callback($entry);
            }
        }

        return $this;
    }


    /**
     * Ensures that all iterator entries are arrays
     *
     * @return static
     */
    public function ensureObjects(): static
    {
        foreach ($this->source as $key => &$value) {
            $value = $this->ensureObject($key, true);
        }

        unset($value);
        return $this;
    }


    /**
     * Ensure the entry we are going to return is from DataEntryInterface interface
     *
     * @param string|float|int|null $key
     * @param bool                  $force
     *
     * @return mixed
     */
    #[ReturnTypeWillChange] protected function ensureObject(string|float|int|null $key, bool $force = false): mixed
    {
        if ($key === null) {
            return null;
        }

        if ($this->ensure_objects or $force) {
            if (is_object($this->source[$key])) {
                // Already object, assume it is the right type
                return $this->source[$key];
            }

            if (!is_a($this->getAcceptedDataType(), ArraySourceInterface::class, true)) {
                // Can only do this for objects that have ArraySourceInterface so that we can dump array sources in them.
                return $this->source[$key];
            }

            if (!is_array($this->source[$key])) {
                // Can only do this with arrays!
                return $this->source[$key];
            }

            $this->source[$key] = $this->getAcceptedDataType()::new()->setSource($this->source[$key]);
        }

        return $this->source[$key];
    }
}
