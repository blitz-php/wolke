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

class HasOne extends HasOneOrMany implements SupportsPartialRelations
{
    use ComparesRelatedModels;
    use CanBeOneOfMany;
    use SupportsDefaultModels;

    /**
     * {@inheritDoc}
     */
    public function getResults(): mixed
    {
        if (null === $this->getParentKey()) {
            return $this->getDefaultFor($this->parent);
        }

        return $this->query->first() ?: $this->getDefaultFor($this->parent);
    }

    /**
     * {@inheritDoc}
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->getDefaultFor($model));
        }

        return $models;
    }

    /**
     * {@inheritDoc}
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        return $this->matchOne($models, $results, $relation);
    }

    /**
     * {@inheritDoc}
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
        $query->select($this->foreignKey);
    }

    /**
     * Get the columns that should be selected by the one of many subquery.
     */
    public function getOneOfManySubQuerySelectColumns(): string
    {
        return $this->foreignKey;
    }

    /**
     * Add join query constraints for one of many relationships.
     */
    public function addOneOfManyJoinSubQueryConstraints(BaseBuilder $query): void
    {
        $query->join($query->getTable(), [$this->qualifySubSelectColumn($this->foreignKey) => $this->qualifyRelatedColumn($this->foreignKey)]);
    }

    /**
     * Make a new related instance for the given model.
     */
    public function newRelatedInstanceFor(Model $parent): Model
    {
        return $this->related->newInstance()->setAttribute(
            $this->getForeignKeyName(),
            $parent->{$this->localKey}
        );
    }

    /**
     * Get the value of the model's foreign key.
     */
    protected function getRelatedKeyFrom(Model $model): mixed
    {
        return $model->getAttribute($this->getForeignKeyName());
    }
}
