<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Relations;

use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasOneOrMany<TRelatedModel, TDeclaringModel, Collection<int, TRelatedModel>>
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\HasMany</a>
 */
class HasMany extends HasOneOrMany
{
    /**
     * Convertit la relation en une relation "has one".
     *
     * @return HasOne<TRelatedModel, TDeclaringModel>
     */
    public function one(): HasOne
    {
        return HasOne::noConstraints(fn () => Helpers::tap(
            new HasOne(
                $this->getQuery(),
                $this->parent,
                $this->foreignKey,
                $this->localKey
            ),
            function ($hasOne) {
                if ($inverse = $this->getInverseRelationship()) {
                    $hasOne->inverse($inverse);
                }
            }
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function getResults(): mixed
    {
        return null !== $this->getParentKey()
                ? $this->query->get()
                : $this->related->newCollection();
    }

    /**
     * {@inheritDoc}
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * {@inheritDoc}
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        return $this->matchMany($models, $results, $relation);
    }
}
