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

use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Utilities\Support\Invader;
use BlitzPHP\Wolke\Builder;
use Closure;
use InvalidArgumentException;

trait CanBeOneOfMany
{
    /**
     * Determines whether the relationship is one-of-many.
     */
    protected bool $isOneOfMany = false;

    /**
     * The name of the relationship.
     *
     * @var string
     */
    protected $relationName;

    /**
     * Add constraints for inner join subselect for one of many relationships.
     */
    abstract public function addOneOfManySubQueryConstraints(Builder $query, ?string $column = null, ?string $aggregate = null): void;

    /**
     * Get the columns the determine the relationship groups.
     *
     * @return array|string
     */
    abstract public function getOneOfManySubQuerySelectColumns();

    /**
     * Add join query constraints for one of many relationships.
     */
    abstract public function addOneOfManyJoinSubQueryConstraints(BaseBuilder $join): void;

    /**
     * Indicate that the relation is a single result of a larger one-to-many relationship.
     *
     * @throws InvalidArgumentException
     */
    public function ofMany(Closure|string|array|null $column = 'id', string|Closure|null $aggregate = 'MAX', ?string $relation = null): self
    {
        $this->isOneOfMany = true;

        if ($column instanceof Closure) {
            $column = $column();
        }

        $this->relationName = $relation ?: $this->getDefaultOneOfManyJoinAlias(
            $this->guessRelationship()
        );

        $keyName = $this->query->getModel()->getKeyName();

        $columns = is_string($columns = $column) ? [
            $column  => $aggregate,
            $keyName => $aggregate,
        ] : $column;

        if (! array_key_exists($keyName, $columns)) {
            $columns[$keyName] = 'MAX';
        }

        if ($aggregate instanceof Closure) {
            $closure = $aggregate;
        }

        foreach ($columns as $column => $aggregate) {
            if (! in_array(strtolower($aggregate), ['min', 'max'], true)) {
                throw new InvalidArgumentException("Invalid aggregate [{$aggregate}] used within ofMany relation. Available aggregates: MIN, MAX");
            }

            $subQuery = $this->newOneOfManySubQuery(
                isset($previous) ? $previous['column'] : $this->getOneOfManySubQuerySelectColumns(),
                $column,
                $aggregate
            );

            if (isset($previous)) {
                $this->addOneOfManyJoinSubQuery($subQuery, $previous['subQuery'], $previous['column']);
            } elseif (isset($closure)) {
                $closure($subQuery);
            }

            if (array_key_last($columns) === $column) {
                $this->addOneOfManyJoinSubQuery($this->query, $subQuery, $column);
            }

            $previous = [
                'subQuery' => $subQuery,
                'column'   => $column,
            ];
        }

        return $this;
    }

    /**
     * Indicate that the relation is the latest single result of a larger one-to-many relationship.
     */
    public function latestOfMany(string|array|null $column = 'id', ?string $relation = null): self
    {
        return $this->ofMany(Helpers::collect(Arr::wrap($column))->mapWithKeys(static fn ($column) => [$column => 'MAX'])->all(), 'MAX', $relation ?: $this->guessRelationship());
    }

    /**
     * Indicate that the relation is the oldest single result of a larger one-to-many relationship.
     */
    public function oldestOfMany(string|array|null $column = 'id', ?string $relation = null): self
    {
        return $this->ofMany(Helpers::collect(Arr::wrap($column))->mapWithKeys(static fn ($column) => [$column => 'MIN'])->all(), 'MIN', $relation ?: $this->guessRelationship());
    }

    /**
     * Get the default alias for the one of many inner join clause.
     */
    protected function getDefaultOneOfManyJoinAlias(string $relation): string
    {
        return $relation === $this->query->getModel()->getTable()
            ? $relation . '_of_many'
            : $relation;
    }

    /**
     * Get a new query for the related model, grouping the query by the given column, often the foreign key of the relationship.
     */
    protected function newOneOfManySubQuery(string|array $groupBy, ?string $column = null, ?string $aggregate = null): Builder
    {
        $subQuery = $this->query->getModel()
            ->newQuery();

        foreach (Arr::wrap($groupBy) as $group) {
            $subQuery->groupBy($this->qualifyRelatedColumn($group));
        }

        if (null !== $column) {
            $subQuery->select($aggregate . '(' . $column . ') as ' . $column);
        }

        $this->addOneOfManySubQueryConstraints($subQuery, $groupBy, $column, $aggregate);

        return $subQuery;
    }

    /**
     * Add the join subquery to the given query on the given column and the relationship's foreign key.
     */
    protected function addOneOfManyJoinSubQuery(Builder $parent, Builder $subQuery, string $on): void
    {
        $parent->joinSub($subQuery, $this->relationName, function ($join) use ($on) {
            $join->on($this->qualifySubSelectColumn($on), '=', $this->qualifyRelatedColumn($on));

            $this->addOneOfManyJoinSubQueryConstraints($join, $on);
        });
    }

    /**
     * Merge the relationship query joins to the given query builder.
     */
    protected function mergeOneOfManyJoinsTo(Builder $query): void
    {
        Invader::make($query->getQuery())->joins = Invader::make($this->query->getQuery())->joins;

        $query->addBinding($this->query->getBindings(), 'join');
    }

    /**
     * Get the qualified column name for the one-of-many relationship using the subselect join query's alias.
     */
    public function qualifySubSelectColumn(string $column): string
    {
        return $this->getRelationName() . '.' . end($parts = explode('.', $column));
    }

    /**
     * Qualify related column using the related table name if it is not already qualified.
     */
    protected function qualifyRelatedColumn(string $column): string
    {
        return Text::contains($column, '.') ? $column : $this->query->getModel()->getTable() . '.' . $column;
    }

    /**
     * Guess the "hasOne" relationship's name via backtrace.
     */
    protected function guessRelationship(): string
    {
        return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2]['function'];
    }

    /**
     * Determine whether the relationship is a one-of-many relationship.
     */
    public function isOneOfMany(): bool
    {
        return $this->isOneOfMany;
    }

    /**
     * Get the name of the relationship.
     */
    public function getRelationName(): string
    {
        return $this->relationName;
    }
}
