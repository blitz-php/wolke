<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Concerns;

use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Relation;

trait HasUniqueStringIds
{
    /**
     * Generate a new unique key for the model.
     */
    abstract public function newUniqueId(): mixed;

    /**
     * Determine if given key is valid.
     */
    abstract protected function isValidUniqueId(mixed $value): bool;

    /**
     * Initialize the trait.
     */
    public function initializeHasUniqueStringIds(): void
    {
        $this->usesUniqueIds = true;
    }

    /**
     * Get the columns that should receive a unique identifier.
     */
    public function uniqueIds(): array
    {
        return $this->usesUniqueIds() ? [$this->getKeyName()] : parent::uniqueIds();
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @throws ModelNotFoundException
     */
    public function resolveRouteBindingQuery(Model|Relation $query, mixed $value, ?string $field = null): Relation
    {
        if ($field && in_array($field, $this->uniqueIds(), true) && ! $this->isValidUniqueId($value)) {
            $this->handleInvalidUniqueId($value, $field);
        }
            
        if (! $field && in_array($this->getRouteKeyName(), $this->uniqueIds(), true) && ! $this->isValidUniqueId($value)) {
            $this->handleInvalidUniqueId($value, $field);
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    /**
     * Get the auto-incrementing key type.
     */
    public function getKeyType(): string
    {
        if (in_array($this->getKeyName(), $this->uniqueIds(), true)) {
            return 'string';
        }

        return parent::getKeyType();
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     */
    public function getIncrementing(): bool
    {
        if (in_array($this->getKeyName(), $this->uniqueIds(), true)) {
            return false;
        }

        return parent::getIncrementing();
    }

    /**
     * Throw an exception for the given invalid unique ID.
     * 
     * @return never
     *
     * @throws ModelNotFoundException
     */
    protected function handleInvalidUniqueId(mixed $value, ?string $field)
    {
        throw (new ModelNotFoundException())->setModel(static::class, $value);
    }
}
