<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Concerns;

use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Utilities\Support\Invader;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Relations\BelongsTo;
use BlitzPHP\Wolke\Relations\MorphTo;
use BlitzPHP\Wolke\Relations\Relation;
use Closure;

trait QueriesRelationships
{
    /**
     * Add a relationship count / exists condition to the query.
     *
     * @return Builder|static
     *
     * @throws RuntimeException
     */
    public function has(Relation|string $relation, string $operator = '>=', int $count = 1, string $boolean = 'and', ?Closure $callback = null)
    {
        if (is_string($relation)) {
            if (strpos($relation, '.') !== false) {
                return $this->hasNested($relation, $operator, $count, $boolean, $callback);
            }

            $relation = $this->getRelationWithoutConstraints($relation);
        }

        if ($relation instanceof MorphTo) {
            return $this->hasMorph($relation, ['*'], $operator, $count, $boolean, $callback);
        }

        // If we only need to check for the existence of the relation, then we can optimize
        // the subquery to only run a "where exists" clause instead of this full "count"
        // clause. This will make these queries run much faster compared with a count.
        $method = $this->canUseExistsForExistenceCheck($operator, $count)
                        ? 'getRelationExistenceQuery'
                        : 'getRelationExistenceCountQuery';

        $hasQuery = $relation->{$method}(
            $relation->getRelated()->newQueryWithoutRelationships(),
            $this
        );

        // Next we will call any given callback as an "anonymous" scope so they can get the
        // proper logical grouping of the where clauses if needed by this Eloquent query
        // builder. Then, we will be ready to finalize and return this query instance.
        if ($callback) {
            $hasQuery->callScope($callback);
        }

        return $this->addHasWhere(
            $hasQuery,
            $relation,
            $operator,
            $count,
            $boolean
        );
    }

    /**
     * Add nested relationship count / exists conditions to the query.
     *
     * Sets up recursive call to whereHas until we finish the nested relation.
     *
     * @return Builder|static
     */
    protected function hasNested(string $relations, string $operator = '>=', int $count = 1, string $boolean = 'and', ?Closure $callback = null)
    {
        $relations = explode('.', $relations);

        $doesntHave = $operator === '<' && $count === 1;

        if ($doesntHave) {
            $operator = '>=';
            $count    = 1;
        }

        $closure = static function ($q) use (&$closure, &$relations, $operator, $count, $callback) {
            // In order to nest "has", we need to add count relation constraints on the
            // callback Closure. We'll do this by simply passing the Closure its own
            // reference to itself so it calls itself recursively on each segment.
            count($relations) > 1
                ? $q->whereHas(array_shift($relations), $closure)
                : $q->has(array_shift($relations), $operator, $count, 'and', $callback);
        };

        return $this->has(array_shift($relations), $doesntHave ? '<' : '>=', 1, $boolean, $closure);
    }

    /**
     * Add a relationship count / exists condition to the query with an "or".
     *
     * @return Builder|static
     */
    public function orHas(string $relation, string $operator = '>=', int $count = 1)
    {
        return $this->has($relation, $operator, $count, 'or');
    }

    /**
     * Add a relationship count / exists condition to the query.
     *
     * @return Builder|static
     */
    public function doesntHave(string $relation, string $boolean = 'and', ?Closure $callback = null)
    {
        return $this->has($relation, '<', 1, $boolean, $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with an "or".
     *
     * @return Builder|static
     */
    public function orDoesntHave(string $relation)
    {
        return $this->doesntHave($relation, 'or');
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses.
     *
     * @return Builder|static
     */
    public function whereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1)
    {
        return $this->has($relation, $operator, $count, 'and', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses and an "or".
     *
     * @return Builder|static
     */
    public function orWhereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1)
    {
        return $this->has($relation, $operator, $count, 'or', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses.
     *
     * @return Builder|static
     */
    public function whereDoesntHave(string $relation, ?Closure $callback = null)
    {
        return $this->doesntHave($relation, 'and', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses and an "or".
     *
     * @return Builder|static
     */
    public function orWhereDoesntHave(string $relation, ?Closure $callback = null)
    {
        return $this->doesntHave($relation, 'or', $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query.
     *
     * @return Builder|static
     */
    public function hasMorph(MorphTo|string $relation, string|array $types, string $operator = '>=', int $count = 1, string $boolean = 'and', ?Closure $callback = null)
    {
        if (is_string($relation)) {
            $relation = $this->getRelationWithoutConstraints($relation);
        }

        $types = (array) $types;

        if ($types === ['*']) {
            $types = $this->model->newModelQuery()->distinct()->pluck($relation->getMorphType())->filter()->all();
        }

        foreach ($types as &$type) {
            $type = Relation::getMorphedModel($type) ?? $type;
        }

        return $this->where(function ($query) use ($relation, $callback, $operator, $count, $types) {
            foreach ($types as $type) {
                $query->orWhere(function ($query) use ($relation, $callback, $operator, $count, $type) {
                    $belongsTo = $this->getBelongsToRelation($relation, $type);

                    if ($callback) {
                        $callback = static fn ($query) => $callback($query, $type);
                    }

                    $query->where($this->qualifyColumn($relation->getMorphType()), '=', (new $type())->getMorphClass())
                        ->whereHas($belongsTo, $callback, $operator, $count);
                });
            }
        }, null, null, $boolean);
    }

    /**
     * Get the BelongsTo relationship for a single polymorphic type.
     */
    protected function getBelongsToRelation(MorphTo $relation, string $type): BelongsTo
    {
        $belongsTo = Relation::noConstraints(function () use ($relation, $type) {
            return $this->model->belongsTo(
                $type,
                $relation->getForeignKeyName(),
                $relation->getOwnerKeyName()
            );
        });

        $belongsTo->getQuery()->mergeConstraintsFrom($relation->getQuery());

        return $belongsTo;
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with an "or".
     *
     * @return Builder|static
     */
    public function orHasMorph(MorphTo|string $relation, string|array $types, string $operator = '>=', int $count = 1)
    {
        return $this->hasMorph($relation, $types, $operator, $count, 'or');
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query.
     *
     * @return Builder|static
     */
    public function doesntHaveMorph(MorphTo|string $relation, string|array $types, string $boolean = 'and', ?Closure $callback = null)
    {
        return $this->hasMorph($relation, $types, '<', 1, $boolean, $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with an "or".
     *
     * @return Builder|static
     */
    public function orDoesntHaveMorph(MorphTo|string $relation, string|array $types)
    {
        return $this->doesntHaveMorph($relation, $types, 'or');
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with where clauses.
     *
     * @return Builder|static
     */
    public function whereHasMorph(MorphTo|string $relation, string|array $types, ?Closure $callback = null, string $operator = '>=', int $count = 1)
    {
        return $this->hasMorph($relation, $types, $operator, $count, 'and', $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with where clauses and an "or".
     *
     * @return Builder|static
     */
    public function orWhereHasMorph(MorphTo|string $relation, string|array $types, ?Closure $callback = null, string $operator = '>=', int $count = 1)
    {
        return $this->hasMorph($relation, $types, $operator, $count, 'or', $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with where clauses.
     *
     * @return Builder|static
     */
    public function whereDoesntHaveMorph(MorphTo|string $relation, string|array $types, ?Closure $callback = null)
    {
        return $this->doesntHaveMorph($relation, $types, 'and', $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with where clauses and an "or".
     *
     * @return Builder|static
     */
    public function orWhereDoesntHaveMorph(MorphTo|string $relation, string|array $types, ?Closure $callback = null)
    {
        return $this->doesntHaveMorph($relation, $types, 'or', $callback);
    }

    /**
     * Add subselect queries to include an aggregate value for a relationship.
     */
    public function withAggregate(mixed $relations, string $column, ?string $function = null): self
    {
        if (empty($relations)) {
            return $this;
        }

        if (empty(Invader::make($this->query)->fields)) {
            $this->query->select("{$this->query->getTable()}.*");
        }

        $relations = is_array($relations) ? $relations : [$relations];

        foreach ($this->parseWithRelations($relations) as $name => $constraints) {
            // First we will determine if the name has been aliased using an "as" clause on the name
            // and if it has we will extract the actual relationship name and the desired name of
            // the resulting column. This allows multiple aggregates on the same relationships.
            $segments = explode(' ', $name);

            unset($alias);

            if (count($segments) === 3 && Text::lower($segments[1]) === 'as') {
                [$name, $alias] = [$segments[0], $segments[2]];
            }

            $relation = $this->getRelationWithoutConstraints($name);

            if ($function) {
                $hashedColumn = $this->getQuery()->getTable() === $relation->getQuery()->getQuery()->getTable()
                    ? "{$relation->getRelationCountHash(false)}.{$column}"
                    : $column;

                $wrappedColumn = $column === '*' ? $column : $relation->getRelated()->qualifyColumn($hashedColumn);

                $expression = $function === 'exists' ? $wrappedColumn : sprintf('%s(%s)', $function, $wrappedColumn);
            } else {
                $expression = $column;
            }

            // Here, we will grab the relationship sub-query and prepare to add it to the main query
            // as a sub-select. First, we'll get the "has" query and use that to get the relation
            // sub-query. We'll format this relationship name and append this column if needed.
            $query = $relation->getRelationExistenceQuery(
                $relation->getRelated()->newQuery(),
                $this,
                $expression
            )->setBindings([], 'select');

            $query->callScope($constraints);

            $query = $query->mergeConstraintsFrom($relation->getQuery())->toBase();

            // If the query contains certain elements like orderings / more than one column selected
            // then we will remove those elements from the query so that it will execute properly
            // when given to the database. Otherwise, we may receive SQL errors or poor syntax.
            $query->orders = null;
            $query->setBindings([], 'order');

            if (count($query->columns) > 1) {
                $query->columns            = [$query->columns[0]];
                $query->bindings['select'] = [];
            }

            // Finally, we will make the proper column alias to the query and run this sub-select on
            // the query builder. Then, we will return the builder instance back to the developer
            // for further constraint chaining that needs to take place on the query as needed.
            $alias ??= Text::snake(
                preg_replace('/[^[:alnum:][:space:]_]/u', '', "{$name} {$function} {$column}")
            );

            if ($function === 'exists') {
                $this->selectRaw(
                    sprintf('exists(%s) as %s', $query->toSql(), $this->getQuery()->grammar->wrap($alias)),
                    $query->getBindings()
                )->withCasts([$alias => 'bool']);
            } else {
                $this->selectSub(
                    $function ? $query : $query->limit(1),
                    $alias
                );
            }
        }

        return $this;
    }

    /**
     * Add subselect queries to count the relations.
     */
    public function withCount(mixed $relations): self
    {
        return $this->withAggregate(is_array($relations) ? $relations : func_get_args(), '*', 'count');
    }

    /**
     * Add subselect queries to include the max of the relation's column.
     */
    public function withMax(string|array $relation, string $column): self
    {
        return $this->withAggregate($relation, $column, 'max');
    }

    /**
     * Add subselect queries to include the min of the relation's column.
     */
    public function withMin(string|array $relation, string $column): self
    {
        return $this->withAggregate($relation, $column, 'min');
    }

    /**
     * Add subselect queries to include the sum of the relation's column.
     */
    public function withSum(string|array $relation, string $column): self
    {
        return $this->withAggregate($relation, $column, 'sum');
    }

    /**
     * Add subselect queries to include the average of the relation's column.
     */
    public function withAvg(string|array $relation, string $column): self
    {
        return $this->withAggregate($relation, $column, 'avg');
    }

    /**
     * Add subselect queries to include the existence of related models.
     */
    public function withExists(string|array $relation): self
    {
        return $this->withAggregate($relation, '*', 'exists');
    }

    /**
     * Add the "has" condition where clause to the query.
     *
     * @return Builder|static
     */
    protected function addHasWhere(Builder $hasQuery, Relation $relation, string $operator, int $count, string $boolean)
    {
        $hasQuery->mergeConstraintsFrom($relation->getQuery());

        return $this->canUseExistsForExistenceCheck($operator, $count)
                ? $this->addWhereExistsQuery($hasQuery->toBase(), $boolean, $operator === '<' && $count === 1)
                : $this->addWhereCountQuery($hasQuery->toBase(), $operator, $count, $boolean);
    }

    /**
     * Merge the where constraints from another query to the current query.
     *
     * @return Builder|static
     */
    public function mergeConstraintsFrom(Builder $from)
    {
        $whereBindings = $from->getQuery()->getCompiledQBWhere() ?? [];

        // Here we have some other query that we want to merge the where constraints from. We will
        // copy over any where constraints on the query as well as remove any global scopes the
        // query might have removed. Then we will return ourselves with the finished merging.
        return $this->withoutGlobalScopes(
            $from->removedScopes()
        )->mergeWheres(
            $from->getQuery()->getCompiledQBWhere(),
            $whereBindings
        );
    }

    /**
     * Add a sub-query count clause to this query.
     */
    protected function addWhereCountQuery(BaseBuilder $query, string $operator = '>=', int $count = 1, string $boolean = 'and'): self
    {
        // $this->query->addBinding($query->getBindings(), 'where');

        return $this->where(
            '(' . $query->sql() . ')',
            $operator,
            $count,
            $boolean
        );
    }

    /**
     * Get the "has relation" base query instance.
     */
    protected function getRelationWithoutConstraints(string $relation): Relation
    {
        return Relation::noConstraints(fn () => $this->getModel()->{$relation}());
    }

    /**
     * Check if we can run an "exists" query to optimize performance.
     */
    protected function canUseExistsForExistenceCheck(string $operator, int $count): bool
    {
        return ($operator === '>=' || $operator === '<') && $count === 1;
    }
}
