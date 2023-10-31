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

use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Contracts\SupportsPartialRelations;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\CanBeOneOfMany;
use BlitzPHP\Wolke\Relations\Concerns\ComparesRelatedModels;
use BlitzPHP\Wolke\Relations\Concerns\SupportsDefaultModels;

class MorphOne extends MorphOneOrMany implements SupportsPartialRelations
{
    use CanBeOneOfMany;
    use ComparesRelatedModels;
    use SupportsDefaultModels;

    /**
     * Get the results of the relationship.
     */
    public function getResults(): mixed
    {
        if (null === $this->getParentKey()) {
            return $this->getDefaultFor($this->parent);
        }

        return $this->query->first() ?: $this->getDefaultFor($this->parent);
    }

    /**
     * Initialize the relation on a set of models.
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->getDefaultFor($model));
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        return $this->matchOne($models, $results, $relation);
    }

    /**
     * Get the relationship query.
     *
     * @param array|mixed $columns
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($this->isOneOfMany()) {
            $this->mergeOneOfManyJoinsTo($query);
        }

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Add constraints for inner join subselect for one of many relationships.
     */
    public function addOneOfManySubQueryConstraints(Builder $query, ?string $column = null, ?string $aggregate = null): void
    {
        $query->select([$this->foreignKey, $this->morphType]);
    }

    /**
     * Get the columns that should be selected by the one of many subquery.
     */
    public function getOneOfManySubQuerySelectColumns(): array
    {
        return [$this->foreignKey, $this->morphType];
    }

    /**
     * Add join query constraints for one of many relationships.
     *
     * @param string $on deprecated
     */
    public function addOneOfManyJoinSubQueryConstraints(BaseBuilder $query, string $on): void
    {
        $query
            ->join($query->getTable(), [$this->qualifySubSelectColumn($this->morphType) => $this->qualifyRelatedColumn($this->morphType)])
            ->join($query->getTable(), [$this->qualifySubSelectColumn($this->foreignKey) => $this->qualifyRelatedColumn($this->foreignKey)]);
    }

    /**
     * Make a new related instance for the given model.
     */
    public function newRelatedInstanceFor(Model $parent): Model
    {
        return $this->related->newInstance()
            ->setAttribute($this->getForeignKeyName(), $parent->{$this->localKey})
            ->setAttribute($this->getMorphType(), $this->morphClass);
    }

    /**
     * Get the value of the model's foreign key.
     */
    protected function getRelatedKeyFrom(Model $model): mixed
    {
        return $model->getAttribute($this->getForeignKeyName());
    }
}
