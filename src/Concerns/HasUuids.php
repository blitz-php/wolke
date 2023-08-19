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

use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Relation;

trait HasUuids
{
    /**
     * Initialize the trait.
     */
    public function initializeHasUuids(): void
    {
        $this->usesUniqueIds = true;
    }

    /**
     * Get the columns that should receive a unique identifier.
     */
    public function uniqueIds(): array
    {
        return [$this->getKeyName()];
    }

    /**
     * Generate a new UUID for the model.
     */
    public function newUniqueId(): string
    {
        return (string) Text::orderedUuid();
    }

    /**
     * Retrieve the model for a bound value.
     *
     * @throws ModelNotFoundException
     */
    public function resolveRouteBindingQuery(Model|Relation $query, mixed $value, ?string $field = null): Relation
    {
        if ($field && in_array($field, $this->uniqueIds(), true) && ! Str::isUuid($value)) {
            throw (new ModelNotFoundException())->setModel(static::class, $value);
        }

        if (! $field && in_array($this->getRouteKeyName(), $this->uniqueIds(), true) && ! Str::isUuid($value)) {
            throw (new ModelNotFoundException())->setModel(static::class, $value);
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

        return $this->keyType;
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     */
    public function getIncrementing(): bool
    {
        if (in_array($this->getKeyName(), $this->uniqueIds(), true)) {
            return false;
        }

        return $this->incrementing;
    }
}
