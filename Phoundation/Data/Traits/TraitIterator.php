<?php

/**
 * Trait Iterator
 *
 * This is a very basic implementation of the default PHP iterator class.
 *
 * This trait contains the basic Iterator methods plus the following methods:
 *
 * IteratorBase::__toString(): string
 * IteratorBase::__toArray(): array
 * IteratorBase::getSource(): array
 * IteratorBase::getSourceKeys(): array
 * IteratorBase::setSource(IteratorInterface|PDOStatement|array|string|null $source = null, array|null $execute = null): static
 * IteratorBase::getCount(): int
 * IteratorBase::count(): int
 * IteratorBase::clear(): static
 *
 *
 * @author    Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @license   http://opensource.org/licenses/GPL-2.0 GNU Public License, Version 2
 * @copyright Copyright © 2025 Sven Olaf Oostenbrink <so.oostenbrink@gmail.com>
 * @package   Phoundation\Data
 */


declare(strict_types=1);

namespace Phoundation\Data\Traits;

use Phoundation\Exception\OutOfBoundsException;
use ReturnTypeWillChange;


trait TraitIterator
{
    use TraitDataArraySource;


    /**
     * Returns the current entry
     *
     * @return mixed
     */
    #[ReturnTypeWillChange] public function current(): mixed
    {
        return $this->source[key($this->source)];
    }


    /**
     * Progresses the internal pointer to the next entry
     *
     * @return void
     */
    public function next(): void
    {
        next($this->source);
    }


    /**
     * Progresses the internal pointer to the previous entry
     *
     * @return void
     */
    public function previous(): void
    {
        prev($this->source);
    }


    /**
     * Returns the current key for the current button
     *
     * @return string|int|null
     */
    public function key(): string|int|null
    {
        return key($this->source);
    }


    /**
     * Returns if the current pointer is valid or not
     *
     * @return bool
     */
    public function valid(): bool
    {
        $key    = key($this->source);
        $exists = array_key_exists($key, $this->source);

        if (!$exists) {
            return false;
        }

        // We are okay!
        return true;
    }


    /**
     * Rewinds the internal pointer
     *
     * @return void
     */
    public function rewind(): void
    {
        reset($this->source);
    }
}
