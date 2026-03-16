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

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TPivotModel of Pivot = MorphPivot
 * @template TAccessor of string = 'pivot'
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>
 */
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
     * @param Builder<TRelatedModel>  $query
     * @param TDeclaringModel  $parent
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
    protected function addWhereConstraints(): static
    {
        parent::addWhereConstraints();

        $this->query->where($this->qualifyPivotColumn($this->morphType), $this->morphClass);

        return $this;
    }

    /**
     * {@inheritDoc}
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
     * {@inheritDoc}
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
     * Get the pivot models that are currently attached, filtered by related model keys.
     *
     * @return IterableCollection<int, TPivotModel>
     */
    protected function getCurrentlyAttachedPivotsForIds(mixed $ids = null): IterableCollection
    {
        return parent::getCurrentlyAttachedPivotsForIds($ids)->map(function ($record) {
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
     * 
     * @return TPivotModel
     */
    public function newPivot(array $attributes = [], bool $exists = false): Pivot
    {
        $using = $this->using;

        $attributes = array_merge([$this->morphType => $this->morphClass], $attributes);

        $pivot = $using ? $using::fromRawAttributes($this->parent, $attributes, $this->table, $exists)
                        : MorphPivot::fromAttributes($this->parent, $attributes, $this->table, $exists);

        $pivot->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey)
            ->setRelatedModel($this->related)
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
        return (new IterableCollection([
            $this->foreignPivotKey,
            $this->relatedPivotKey,
            $this->morphType,
            ...$this->pivotColumns,
        ]))
            ->map(fn ($column) => $this->qualifyPivotColumn($column).' as pivot_' . $column)
            ->unique()
            ->all();
    }

    /**
     * Get the foreign key "type" name.
     */
    public function getMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Get the fully qualified morph type for the relation.
     */
    public function getQualifiedMorphTypeName(): string
    {
        return $this->qualifyPivotColumn($this->morphType);
    }

    /**
     * Get the class name of the parent model.
     *
     * @return class-string<TRelatedModel>
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
