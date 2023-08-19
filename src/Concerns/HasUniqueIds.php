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

trait HasUniqueIds
{
    /**
     * Indicates if the model uses unique ids.
     */
    public bool $usesUniqueIds = false;

    /**
     * Determine if the model uses unique ids.
     */
    public function usesUniqueIds(): bool
    {
        return $this->usesUniqueIds;
    }

    /**
     * Generate unique keys for the model.
     */
    public function setUniqueIds(): void
    {
        foreach ($this->uniqueIds() as $column) {
            if (empty($this->{$column})) {
                $this->{$column} = $this->newUniqueId();
            }
        }
    }

    /**
     * Generate a new key for the model.
     */
    public function newUniqueId(): ?string
    {
        return null;
    }

    /**
     * Get the columns that should receive a unique identifier.
     */
    public function uniqueIds(): array
    {
        return [];
    }
}
