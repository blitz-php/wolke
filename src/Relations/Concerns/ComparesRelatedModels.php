<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Relations\Concerns;

use BlitzPHP\Wolke\Contracts\SupportsPartialRelations;
use BlitzPHP\Wolke\Model;

trait ComparesRelatedModels
{
    /**
     * Determine if the model is the related instance of the relationship.
     */
    public function is(?Model $model): bool
    {
        $match = null !== $model
               && $this->compareKeys($this->getParentKey(), $this->getRelatedKeyFrom($model))
               && $this->related->getTable() === $model->getTable()
               && $this->related->getConnectionName() === $model->getConnectionName();

        if ($match && $this instanceof SupportsPartialRelations && $this->isOneOfMany()) {
            return $this->query
                ->whereKey($model->getKey())
                ->exists();
        }

        return $match;
    }

    /**
     * Determine if the model is not the related instance of the relationship.
     */
    public function isNot(?Model $model): bool
    {
        return ! $this->is($model);
    }

    /**
     * Get the value of the parent model's key.
     */
    abstract public function getParentKey(): mixed;

    /**
     * Get the value of the model's related key.
     */
    abstract protected function getRelatedKeyFrom(Model $model): mixed;

    /**
     * Compare the parent key with the related key.
     */
    protected function compareKeys(mixed $parentKey, mixed $relatedKey): bool
    {
        if (empty($parentKey) || empty($relatedKey)) {
            return false;
        }

        if (is_int($parentKey) || is_int($relatedKey)) {
            return (int) $parentKey === (int) $relatedKey;
        }

        return $parentKey === $relatedKey;
    }
}
