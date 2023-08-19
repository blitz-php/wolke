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

use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;

class MorphMany extends MorphOneOrMany
{
    /**
     * Convert the relationship to a "morph one" relationship.
     */
    public function one(): MorphOne
    {
        return MorphOne::noConstraints(fn () => new MorphOne(
            $this->getQuery(),
            $this->getParent(),
            $this->morphType,
            $this->foreignKey,
            $this->localKey
        ));
    }

    /**
     * Get the results of the relationship.
     */
    public function getResults(): mixed
    {
        return null !== $this->getParentKey()
                ? $this->query->get()
                : $this->related->newCollection();
    }

    /**
     * Initialize the relation on a set of models.
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        return $this->matchMany($models, $results, $relation);
    }

    /**
     * Create a new instance of the related model. Allow mass-assignment.
     */
    public function forceCreate(array $attributes = []): Model
    {
        $attributes[$this->getMorphType()] = $this->morphClass;

        return parent::forceCreate($attributes);
    }

    /**
     * Create a new instance of the related model with mass assignment without raising model events.
     */
    public function forceCreateQuietly(array $attributes = []): Model
    {
        return Model::withoutEvents(fn () => $this->forceCreate($attributes));
    }
}
