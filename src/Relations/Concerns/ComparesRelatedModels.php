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

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\Concerns\ComparesRelatedModels</a>
 */
trait ComparesRelatedModels
{
    /**
     * Détermine si le modèle est l'instance liée de la relation.
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
     * Détermine si le modèle n'est pas l'instance liée de la relation.
     */
    public function isNot(?Model $model): bool
    {
        return ! $this->is($model);
    }

    /**
     * Obtient la valeur de la clé du modèle parent.
     */
    abstract public function getParentKey(): mixed;

    /**
     * Obtient la valeur de la clé liée du modèle.
     */
    abstract protected function getRelatedKeyFrom(Model $model): mixed;

    /**
     * Compare la clé parent avec la clé liée.
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
