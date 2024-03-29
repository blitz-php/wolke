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
     */
    protected string $relationName = '';

    /**
     * The one of many inner join subselect query builder instance.
     */
    protected ?Builder $oneOfManySubQuery = null;

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
    abstract public function addOneOfManyJoinSubQueryConstraints(BaseBuilder $join, string $on): void;

    /**
     * Indicate that the relation is a single result of a larger one-to-many relationship.
     *
     * @throws InvalidArgumentException
     */
    public function ofMany(null|array|string $column = 'id', null|Closure|string $aggregate = 'MAX', ?string $relation = null): self
    {
        $this->isOneOfMany = true;

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
                $this->getOneOfManySubQuerySelectColumns(),
                array_merge([$column], $previous['columns'] ?? []),
                $aggregate,
            );

            if (isset($previous)) {
                $this->addOneOfManyJoinSubQuery($subQuery, $previous['subQuery'], $previous['column']);
            }
            if (isset($closure)) {
                $closure($subQuery);
            }
            if (! isset($previous)) {
                $this->oneOfManySubQuery = $subQuery;
            }

            if (array_key_last($columns) === $column) {
                $this->addOneOfManyJoinSubQuery(
                    $this->query,
                    $subQuery,
                    array_merge([$column], $previous['columns'] ?? []),
                );
            }

            $previous = [
                'subQuery' => $subQuery,
                'columns'  => array_merge([$column], $previous['columns'] ?? []),
            ];
        }

        $this->addConstraints();

        $columns = Invader::make($this->query->getQuery())->fields;

        if (null === $columns || $columns === ['*']) {
            $this->select([$this->qualifyColumn('*')]);
        }

        return $this;
    }

    /**
     * Indicate that the relation is the latest single result of a larger one-to-many relationship.
     */
    public function latestOfMany(null|array|string $column = 'id', ?string $relation = null): self
    {
        return $this->ofMany(Helpers::collect(Arr::wrap($column))->mapWithKeys(static fn ($column) => [$column => 'MAX'])->all(), 'MAX', $relation);
    }

    /**
     * Indicate that the relation is the oldest single result of a larger one-to-many relationship.
     */
    public function oldestOfMany(null|array|string $column = 'id', ?string $relation = null): self
    {
        return $this->ofMany(Helpers::collect(Arr::wrap($column))->mapWithKeys(static fn ($column) => [$column => 'MIN'])->all(), 'MIN', $relation);
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
     *
     * @param string[]|null $columns
     */
    protected function newOneOfManySubQuery(array|string $groupBy, ?array $columns = null, ?string $aggregate = null): Builder
    {
        $subQuery = $this->query->getModel()
            ->newQuery()
            ->withoutGlobalScopes($this->removedScopes());

        foreach (Arr::wrap($groupBy) as $group) {
            $subQuery->groupBy($this->qualifyRelatedColumn($group));
        }

        if (null !== $columns) {
            foreach ($columns as $key => $column) {
                $aggregatedColumn = $subQuery->qualifyColumn($column);

                if ($key === 0) {
                    $aggregatedColumn = "{$aggregate}({$aggregatedColumn})";
                } else {
                    $aggregatedColumn = "min({$aggregatedColumn})";
                }

                $subQuery->select($aggregatedColumn . ' as ' . $column . '_aggregate');
            }
        }

        $this->addOneOfManySubQueryConstraints($subQuery, $groupBy, $columns, $aggregate);

        return $subQuery;
    }

    /**
     * Add the join subquery to the given query on the given column and the relationship's foreign key.
     *
     * @param string[] $on
     *
     * @todo Modifier en fonction du querybuilder de blitz
     */
    protected function addOneOfManyJoinSubQuery(Builder $parent, Builder $subQuery, array $on): void
    {
        $parent->beforeQuery(function ($parent) use ($subQuery, $on) {
            $subQuery->applyBeforeQueryCallbacks();

            $parent->joinSub($subQuery, $this->relationName, function ($join) use ($on) {
                foreach ($on as $onColumn) {
                    $join->on($this->qualifySubSelectColumn($onColumn . '_aggregate'), '=', $this->qualifyRelatedColumn($onColumn));
                }

                $this->addOneOfManyJoinSubQueryConstraints($join, $on);
            });
        });
    }

    /**
     * Merge the relationship query joins to the given query builder.
     *
     * @todo Modifier en fonction du querybuilder de blitz
     */
    protected function mergeOneOfManyJoinsTo(Builder $query): void
    {
        $query->getQuery()->beforeQueryCallbacks = $this->query->getQuery()->beforeQueryCallbacks;

        $query->applyBeforeQueryCallbacks();
    }

    /**
     * Get the query builder that will contain the relationship constraints.
     */
    protected function getRelationQuery(): Builder
    {
        return $this->isOneOfMany()
            ? $this->oneOfManySubQuery
            : $this->query;
    }

    /**
     * Get the one of many inner join subselect builder instance.
     */
    public function getOneOfManySubQuery(): ?Builder
    {
        return $this->oneOfManySubQuery;
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
        return str_contains($column, '.') ? $column : $this->query->getModel()->getTable() . '.' . $column;
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
