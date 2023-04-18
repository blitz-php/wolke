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
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Model;

class MorphToMany extends BelongsToMany
{
    /**
     * The type of the polymorphic relation.
     */
    protected string $morphType;

    /**
     * The class name of the morph type constraint.
     */
    protected string $morphClass;

    /**
     * Create a new morph to many relationship instance.
     *
     * @param bool $inverse Indicates if we are connecting the inverse of the relation.
     *                      This primarily affects the morphClass constraint.
     *
     * @return void
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $name,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $relationName = null,
        protected bool $inverse = false
    ) {
        $this->morphType  = $name . '_type';
        $this->morphClass = $inverse ? $query->getModel()->getMorphClass() : $parent->getMorphClass();

        parent::__construct(
            $query,
            $parent,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName
        );
    }

    /**
     * Set the where clause for the relation query.
     */
    protected function addWhereConstraints(): self
    {
        parent::addWhereConstraints();

        $this->query->where($this->qualifyPivotColumn($this->morphType), $this->morphClass);

        return $this;
    }

    /**
     * Set the constraints for an eager load of the relation.
     */
    public function addEagerConstraints(array $models): void
    {
        parent::addEagerConstraints($models);

        $this->query->where($this->qualifyPivotColumn($this->morphType), $this->morphClass);
    }

    /**
     * Create a new pivot attachment record.
     */
    protected function baseAttachRecord(int|string $id, bool $timed): array
    {
        return Arr::add(
            parent::baseAttachRecord($id, $timed),
            $this->morphType,
            $this->morphClass
        );
    }

    /**
     * Add the constraints for a relationship count query.
     *
     * @param array|mixed $columns
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        return parent::getRelationExistenceQuery($query, $parentQuery, $columns)->where(
            $this->qualifyPivotColumn($this->morphType),
            $this->morphClass
        );
    }

    /**
     * Get the pivot models that are currently attached.
     */
    protected function getCurrentlyAttachedPivots(): IterableCollection
    {
        return parent::getCurrentlyAttachedPivots()->map(function ($record) {
            return $record instanceof MorphPivot
                            ? $record->setMorphType($this->morphType)
                                ->setMorphClass($this->morphClass)
                            : $record;
        });
    }

    /**
     * Create a new query builder for the pivot table.
     */
    public function newPivotQuery(): BaseBuilder
    {
        return parent::newPivotQuery()->where($this->morphType, $this->morphClass);
    }

    /**
     * Create a new pivot model instance.
     */
    public function newPivot(array $attributes = [], bool $exists = false): Pivot
    {
        $using = $this->using;

        $pivot = $using ? $using::fromRawAttributes($this->parent, $attributes, $this->table, $exists)
                        : MorphPivot::fromAttributes($this->parent, $attributes, $this->table, $exists);

        $pivot->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey)
            ->setMorphType($this->morphType)
            ->setMorphClass($this->morphClass);

        return $pivot;
    }

    /**
     * Get the pivot columns for the relation.
     *
     * "pivot_" is prefixed at each column for easy removal later.
     */
    protected function aliasedPivotColumns(): array
    {
        $defaults = [$this->foreignPivotKey, $this->relatedPivotKey, $this->morphType];

        return Helpers::collect(array_merge($defaults, $this->pivotColumns))->map(fn ($column) => $this->qualifyPivotColumn($column) . ' as pivot_' . $column)->unique()->all();
    }

    /**
     * Get the foreign key "type" name.
     */
    public function getMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Get the class name of the parent model.
     */
    public function getMorphClass(): string
    {
        return $this->morphClass;
    }

    /**
     * Get the indicator for a reverse relationship.
     */
    public function getInverse(): bool
    {
        return $this->inverse;
    }
}
