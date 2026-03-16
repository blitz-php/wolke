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

use BadMethodCallException;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Database\Query\Expression;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Invade\Invader;
use BlitzPHP\Utilities\Iterable\Collection as BaseCollection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Exceptions\RelationNotFoundException;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\BelongsTo;
use BlitzPHP\Wolke\Relations\BelongsToMany;
use BlitzPHP\Wolke\Relations\MorphTo;
use BlitzPHP\Wolke\Relations\Relation;
use Closure;
use InvalidArgumentException;

/** 
 * @mixin Builder 
 */
trait QueriesRelationships
{
    /**
     * Add a relationship count / exists condition to the query.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param  (Closure(Builder<TRelatedModel>): mixed)|null  $callback
     * 
     * @throws \RuntimeException
     */
    public function has(Relation|string $relation, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and', ?Closure $callback = null): static
    {
        if (is_string($relation)) {
            if (str_contains($relation, '.')) {
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
     * @param  (Closure(Builder<*>): mixed)|null  $callback
     */
    protected function hasNested(string $relations, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and', ?Closure $callback = null): static
    {
        $relations = explode('.', $relations);

        $initialRelations = [...$relations];

        $doesntHave = $operator === '<' && $count === 1;

        if ($doesntHave) {
            $operator = '>=';
            $count    = 1;
        }

        $closure = static function ($q) use (&$closure, &$relations, $operator, $count, $callback, $initialRelations) {
            // If the same closure is called multiple times, reset the relation array to loop through them again...
            if ($count === 1 && empty($relations)) {
                $relations = [...$initialRelations];

                array_shift($relations);
            }

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
     * @param Relation<*, *, *>|string  $relation
     */
    public function orHas(Relation|string $relation, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'or');
    }

    /**
     * Add a relationship count / exists condition to the query.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param  (Closure(Builder<TRelatedModel>): mixed)|null  $callback
     */
    public function doesntHave(Relation|string $relation, string $boolean = 'and', ?Closure $callback = null): static
    {
        return $this->has($relation, '<', 1, $boolean, $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with an "or".
     * 
     * @param  Relation<*, *, *>|string  $relation
     */
    public function orDoesntHave(Relation|string $relation): static
    {
        return $this->doesntHave($relation, 'or');
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param  (Closure(Builder<TRelatedModel>): mixed)|null  $callback
     */
    public function whereHas(Relation|string $relation, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'and', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses.
     *
     * Also load the relationship with same condition.
     *
     * @param  (Closure(Builder<*>|Relation<*, *, *>): mixed)|null  $callback
     */
    public function withWhereHas(string $relation, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->whereHas(Text::before($relation, ':'), $callback, $operator, $count)
            ->with($callback ? [$relation => static fn ($query) => $callback($query)] : $relation);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses and an "or".
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param  (Closure(Builder<TRelatedModel>): mixed)|null  $callback
     */
    public function orWhereHas(Relation|string $relation, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'or', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param  (Closure(Builder<TRelatedModel>): mixed)|null  $callback
     */
    public function whereDoesntHave(Relation|string $relation, ?Closure $callback = null): static
    {
        return $this->doesntHave($relation, 'and', $callback);
    }

    /**
     * Add a relationship count / exists condition to the query with where clauses and an "or".
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param  (Closure(Builder<TRelatedModel>): mixed)|null  $callback
     */
    public function orWhereDoesntHave(string $relation, ?Closure $callback = null): static
    {
        return $this->doesntHave($relation, 'or', $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string  $relation
     * @param string|array<int, string>  $types
     * @param (Closure(Builder<TRelatedModel>, string): mixed)|null  $callback
     */
    public function hasMorph(MorphTo|string $relation, array|string $types, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and', ?Closure $callback = null): static
    {
        if (is_string($relation)) {
            $relation = $this->getRelationWithoutConstraints($relation);
        }

        $types = (array) $types;

        $checkMorphNull = $types === ['*']
            && (($operator === '<' && $count >= 1)
                || ($operator === '<=' && $count >= 0)
                || ($operator === '=' && $count === 0)
                || ($operator === '!=' && $count >= 1));

        if ($types === ['*']) {
            $types = $this->model->newModelQuery()->distinct()->pluck($relation->getMorphType())
                ->filter()
                ->map(fn ($item) => Helpers::enumValue($item))
                ->all();
        }

        if (empty($types)) {
            return $this->where(new Expression('0'), $operator, $count, $boolean);
        }

        foreach ($types as &$type) {
            $type = Relation::getMorphedModel($type) ?? $type;
        }

        return $this->where(function ($query) use ($relation, $callback, $operator, $count, $types, $checkMorphNull) {
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

            $query->when($checkMorphNull, fn (self $query) => $query->orWhereMorphedTo($relation, null));
        }, null, null, $boolean);
    }

    /**
     * Get the BelongsTo relationship for a single polymorphic type.
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param  MorphTo<*, TDeclaringModel>  $relation
     * @param  class-string<TRelatedModel>  $type
     * 
     * @return BelongsTo<TRelatedModel, TDeclaringModel>
     */
    protected function getBelongsToRelation(MorphTo $relation, string $type): BelongsTo
    {
        $belongsTo = Relation::noConstraints(fn () => $this->model->belongsTo(
            $type,
            $relation->getForeignKeyName(),
            $relation->getOwnerKeyName()
        ));

        $belongsTo->getQuery()->mergeConstraintsFrom($relation->getQuery());

        return $belongsTo;
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with an "or".
     *
     * @param MorphTo<*, *>|string  $relation
     * @param string|array<int, string>  $types
     */
    public function orHasMorph(MorphTo|string $relation, array|string $types, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->hasMorph($relation, $types, $operator, $count, 'or');
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string  $relation
     * @param string|array<int, string>  $types
     * @param (Closure(Builder<TRelatedModel>, string): mixed)|null  $callback
     */
    public function doesntHaveMorph(MorphTo|string $relation, array|string $types, string $boolean = 'and', ?Closure $callback = null): static
    {
        return $this->hasMorph($relation, $types, '<', 1, $boolean, $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with an "or".
     *
     * @param MorphTo<*, *>|string  $relation
     * @param string|array<int, string>  $types
     */
    public function orDoesntHaveMorph(MorphTo|string $relation, array|string $types): static
    {
        return $this->doesntHaveMorph($relation, $types, 'or');
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with where clauses.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string  $relation
     * @param string|array<int, string>  $types
     * @param (Closure(Builder<TRelatedModel>, string): mixed)|null  $callback
     */
    public function whereHasMorph(MorphTo|string $relation, array|string $types, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->hasMorph($relation, $types, $operator, $count, 'and', $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with where clauses and an "or".
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string  $relation
     * @param string|array<int, string>  $types
     * @param (Closure(Builder<TRelatedModel>, string): mixed)|null  $callback
     */
    public function orWhereHasMorph(MorphTo|string $relation, array|string $types, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->hasMorph($relation, $types, $operator, $count, 'or', $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with where clauses.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string  $relation
     * @param string|array<int, string>  $types
     * @param (Closure(Builder<TRelatedModel>, string): mixed)|null  $callback
     */
    public function whereDoesntHaveMorph(MorphTo|string $relation, array|string $types, ?Closure $callback = null): static
    {
        return $this->doesntHaveMorph($relation, $types, 'and', $callback);
    }

    /**
     * Add a polymorphic relationship count / exists condition to the query with where clauses and an "or".
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string  $relation
     * @param string|array<int, string>  $types
     * @param (Closure(Builder<TRelatedModel>, string): mixed)|null  $callback
     */
    public function orWhereDoesntHaveMorph(MorphTo|string $relation, array|string $types, ?Closure $callback = null)
    {
        return $this->doesntHaveMorph($relation, $types, 'or', $callback);
    }

    /**
     * Add a basic where clause to a relationship query.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param  (Closure(Builder<TRelatedModel>): mixed)|string|array|Expression $column
     */
    public function whereRelation(Relation|string $relation, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereHas($relation, static function ($query) use ($column, $operator, $value) {
            if ($column instanceof Closure) {
                $column($query);
            } else {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Add a basic where clause to a relationship query and eager-load the relationship with the same conditions.
     *
     * @param Relation<*, *, *>|string  $relation
     * @param  Closure|string|array|Expression  $column
     */
    public function withWhereRelation(Relation|string $relation, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereRelation($relation, $column, $operator, $value)
            ->with([
                $relation => fn ($query) => $column instanceof Closure
                    ? $column($query)
                    : $query->where($column, $operator, $value),
            ]);
    }
    
    /**
     * Add an "or where" clause to a relationship query.
     *
     * @template TRelatedModel of Model
     *
     * @param Relation<TRelatedModel, *, *>|string  $relation
     * @param (Closure(Builder<TRelatedModel>): mixed)|string|array|Expression  $column
     */
    public function orWhereRelation(Relation|string $relation, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->orWhereHas($relation, static function ($query) use ($column, $operator, $value) {
            if ($column instanceof Closure) {
                $column($query);
            } else {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Add a basic count / exists condition to a relationship query.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param  (Closure(Builder<TRelatedModel>): mixed)|string|array|Expression  $column
     */
    public function whereDoesntHaveRelation(Relation|string $relation, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereDoesntHave($relation, function ($query) use ($column, $operator, $value) {
            if ($column instanceof Closure) {
                $column($query);
            } else {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Add an "or where" clause to a relationship query.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param  (Closure(Builder<TRelatedModel>): mixed)|string|array|Expression  $column
     */
    public function orWhereDoesntHaveRelation(Relation|string $relation, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->orWhereDoesntHave($relation, function ($query) use ($column, $operator, $value) {
            if ($column instanceof Closure) {
                $column($query);
            } else {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Add a polymorphic relationship condition to the query with a where clause.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string  $relation
     * @param string|array<int, string>  $types
     * @param (Closure(Builder<TRelatedModel>): mixed)|string|array|Expression  $column
     */
    public function whereMorphRelation(MorphTo|string $relation, array|string $types, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereHasMorph($relation, $types, static function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Add a polymorphic relationship condition to the query with an "or where" clause.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string  $relation
     * @param string|array<int, string>  $types
     * @param (Closure(Builder<TRelatedModel>): mixed)|string|array|Expression  $column
     */
    public function orWhereMorphRelation(MorphTo|string $relation, array|string $types, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->orWhereHasMorph($relation, $types, static function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Add a polymorphic relationship condition to the query with a doesn't have clause.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string  $relation
     * @param string|array<int, string>  $types
     * @param (Closure(Builder<TRelatedModel>): mixed)|string|array|Expression  $column
     */
    public function whereMorphDoesntHaveRelation(MorphTo|string $relation, array|string $types, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereDoesntHaveMorph($relation, $types, function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Add a polymorphic relationship condition to the query with an "or doesn't have" clause.
     *
     * @template TRelatedModel of Model
     *
     * @param  MorphTo<TRelatedModel, *>|string  $relation
     * @param  string|array<int, string>  $types
     * @param  (Closure(Builder<TRelatedModel>): mixed)|string|array|Expression  $column
     */
    public function orWhereMorphDoesntHaveRelation(MorphTo|string $relation, array|string $types, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->orWhereDoesntHaveMorph($relation, $types, function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Add a morph-to relationship condition to the query.
     *
     * @param MorphTo<*, *>|string  $relation
     * @param Model|iterable<int, Model>|string|null  $model
     *
     * @throws InvalidArgumentException
     */
    public function whereMorphedTo(MorphTo|string $relation, Model|iterable|string|null $model, string $boolean = 'and'): static
    {
        if (is_string($relation)) {
            $relation = $this->getRelationWithoutConstraints($relation);
        }

        if (null === $model) {
            return $this->whereNull($relation->qualifyColumn($relation->getMorphType()), $boolean);
        }

        if (is_string($model)) {
            $morphMap = Relation::morphMap();

            if (! empty($morphMap) && in_array($model, $morphMap, true)) {
                $model = array_search($model, $morphMap, true);
            }

            return $this->where($relation->qualifyColumn($relation->getMorphType()), $model, null, $boolean);
        }

        $models = BaseCollection::wrap($model);

        if ($models->isEmpty()) {
            throw new InvalidArgumentException('Collection given to whereMorphedTo method may not be empty.');
        }
        
        return $this->where(function ($query) use ($relation, $models) {
            $models->groupBy(fn ($model) => $model->getMorphClass())->each(function ($models) use ($query, $relation) {
                $query->orWhere(function ($query) use ($relation, $models) {
                    $query->where($relation->qualifyColumn($relation->getMorphType()), $models->first()->getMorphClass())
                        ->whereIn($relation->qualifyColumn($relation->getForeignKeyName()), $models->map->getKey());
                });
            });
        }, null, null, $boolean);
    }

    /**
     * Add a not morph-to relationship condition to the query.
     *
     * @param MorphTo<*, *>|string  $relation
     * @param Model|iterable<int, Model>|string  $model
     *
     * @throws InvalidArgumentException
     */
    public function whereNotMorphedTo(MorphTo|string $relation, Model|iterable|string $model, string $boolean = 'and'): static
    {
        if (is_string($relation)) {
            $relation = $this->getRelationWithoutConstraints($relation);
        }

        if (is_string($model)) {
            $morphMap = Relation::morphMap();

            if (! empty($morphMap) && in_array($model, $morphMap, true)) {
                $model = array_search($model, $morphMap, true);
            }

            return $this->whereNot(fn ($query) => $query->whereNullSafeEquals(
                $relation->qualifyColumn($relation->getMorphType()), $model
            ), null, null, $boolean);
        }

        $models = BaseCollection::wrap($model);

        if ($models->isEmpty()) {
            throw new InvalidArgumentException('Collection given to whereNotMorphedTo method may not be empty.');
        }

        return $this->whereNot(function ($query) use ($relation, $models) {
            $models->groupBy(fn ($model) => $model->getMorphClass())->each(function ($models) use ($query, $relation) {
                $query->orWhere(function ($query) use ($relation, $models) {
                    $query->whereNullSafeEquals($relation->qualifyColumn($relation->getMorphType()), $models->first()->getMorphClass())
                        ->whereIn($relation->qualifyColumn($relation->getForeignKeyName()), $models->map->getKey());
                });
            });
        }, null, null, $boolean);
    }

    /**
     * Add a morph-to relationship condition to the query with an "or where" clause.
     *
     * @param MorphTo<*, *>|string  $relation
     * @param Model|iterable<int, Model>|string|null  $model
     */
    public function orWhereMorphedTo(MorphTo|string $relation, Model|iterable|string|null $model): static
    {
        return $this->whereMorphedTo($relation, $model, 'or');
    }

    /**
     * Add a not morph-to relationship condition to the query with an "or where" clause.
     *
     * @param MorphTo<*, *>|string  $relation
     * @param Model|iterable<int, Model>|string  $model
     */
    public function orWhereNotMorphedTo(MorphTo|string $relation, Model|iterable|string $model): static
    {
        return $this->whereNotMorphedTo($relation, $model, 'or');
    }

    /**
     * Add a "belongs to" relationship where clause to the query.
     *
     * @param Collection<int, Model>|Model $related
     *
     * @throws RelationNotFoundException
     */
    public function whereBelongsTo(Collection|Model $related, ?string $relationshipName = null, string $boolean = 'and'): static
    {
        if (! $related instanceof Collection) {
            $relatedCollection = $related->newCollection([$related]);
        } else {
            $relatedCollection = $related;

            $related = $relatedCollection->first();
        }

        if ($relatedCollection->isEmpty()) {
            throw new InvalidArgumentException('Collection given to whereBelongsTo method may not be empty.');
        }

        if ($relationshipName === null) {
            $relationshipName = Text::camel(Helpers::classBasename($related));
        }

        try {
            $relationship = $this->model->{$relationshipName}();
        } catch (BadMethodCallException) {
            throw RelationNotFoundException::make($this->model, $relationshipName);
        }

        if (! $relationship instanceof BelongsTo) {
            throw RelationNotFoundException::make($this->model, $relationshipName, BelongsTo::class);
        }

        $this->whereIn(
            $relationship->getQualifiedForeignKeyName(),
            $relatedCollection->pluck($relationship->getOwnerKeyName())->toArray(),
            $boolean,
        );

        return $this;
    }

    /**
     * Add an "BelongsTo" relationship with an "or where" clause to the query.
     *
     * @throws \RuntimeException
     */
    public function orWhereBelongsTo(Model $related, ?string $relationshipName = null): static
    {
        return $this->whereBelongsTo($related, $relationshipName, 'or');
    }

    /**
     * Add a "belongs to many" relationship where clause to the query.
     *
     * @param Collection<int, Model>|Model $related
     *
     * @throws RelationNotFoundException
     */
    public function whereAttachedTo(Collection|Model $related, ?string $relationshipName = null, string $boolean = 'and'): static
    {
        $relatedCollection = $related instanceof Collection ? $related : $related->newCollection([$related]);

        $related = $relatedCollection->first();

        if ($relatedCollection->isEmpty()) {
            throw new InvalidArgumentException('Collection given to whereAttachedTo method may not be empty.');
        }

        if ($relationshipName === null) {
            $relationshipName = Text::plural(Text::camel(Helpers::classBasename($related)));
        }

        try {
            $relationship = $this->model->{$relationshipName}();
        } catch (BadMethodCallException) {
            throw RelationNotFoundException::make($this->model, $relationshipName);
        }

        if (! $relationship instanceof BelongsToMany) {
            throw RelationNotFoundException::make($this->model, $relationshipName, BelongsToMany::class);
        }

        $this->has(
            $relationshipName,
            boolean: $boolean,
            callback: fn (Builder $query) => $query->whereKey($relatedCollection->pluck($related->getKeyName())),
        );

        return $this;
    }

    /**
     * Add a "belongs to many" relationship with an "or where" clause to the query.
     *
     * @param Collection<int, Model>|Model $related
     *
     * @throws \RuntimeException
     */
    public function orWhereAttachedTo(Collection|Model $related, ?string $relationshipName = null): static
    {
        return $this->whereAttachedTo($related, $relationshipName, 'or');
    }

    /**
     * Add subselect queries to include an aggregate value for a relationship.
     */
    public function withAggregate(mixed $relations, Expression|string $column, ?string $function = null): static
    {
        if (empty($relations)) {
            return $this;
        }

        if ($this->query->columns === []) {
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
                if ($column instanceof Expression) {
                    $aggregateColumn = $column->getValue();
                } else {
                    $hashedColumn = $this->getRelationHashedColumn($column, $relation);

                    $aggregateColumn = $column === '*' ? $column : $relation->getRelated()->qualifyColumn($hashedColumn);
                }

                $expression = $function === 'exists' ? $aggregateColumn : sprintf('%s(%s)', $function, $aggregateColumn);
            } else {
                $expression = (string) $column;
            }

            // Here, we will grab the relationship sub-query and prepare to add it to the main query
            // as a sub-select. First, we'll get the "has" query and use that to get the relation
            // sub-query. We'll format this relationship name and append this column if needed.
            $query = $relation->getRelationExistenceQuery(
                $relation->getRelated()->newQuery(),
                $this,
                new Expression($expression)
            );

            $query->callScope($constraints);

            $query = $query->mergeConstraintsFrom($relation->getQuery())->toBase();

            // If the query contains certain elements like orderings / more than one column selected
            // then we will remove those elements from the query so that it will execute properly
            // when given to the database. Otherwise, we may receive SQL errors or poor syntax.
            Invader::make($query)->orders = [];
            // $query->setBindings([], 'order');

            $fields = $query->columns;

            if (count($fields) > 1) {
                $invader = Invader::make($query);
                $invader->columns = [$fields[0]];
                $invader->wheres = [];
                // $query->bindings['select'] = [];
            }

            // Finally, we will make the proper column alias to the query and run this sub-select on
            // the query builder. Then, we will return the builder instance back to the developer
            // for further constraint chaining that needs to take place on the query as needed.
            $alias ??= Text::snake(
                preg_replace(
                    '/[^[:alnum:][:space:]_]/u',
                    '',
                    sprintf('%s %s %s', $name, $function, strtolower((string) $column))
                )
            );

            if ($function === 'exists') {
                $this->selectRaw(
                    sprintf('exists(%s) as %s', $query->toSql(), $alias),
                    $query->bindings->getValues()
                )->withCasts([$alias => 'bool']);
            } else {
                $this->selectSubquery(
                    $function ? $query : $query->limit(1),
                    $alias
                );
            }
        }

        return $this;
    }

    /**
     * Get the relation hashed column name for the given column and relation.
     *
     * @param Relation<*, *, *> $relation
     */
    protected function getRelationHashedColumn(string $column, Relation $relation): string
    {
        if (str_contains($column, '.')) {
            return $column;
        }

        return $this->getQuery()->getTable() === $relation->getQuery()->getQuery()->getTable()
            ? "{$relation->getRelationCountHash(false)}.{$column}"
            : $column;
    }

    /**
     * Add subselect queries to count the relations.
     */
    public function withCount(mixed $relations): static
    {
        return $this->withAggregate(is_array($relations) ? $relations : func_get_args(), '*', 'count');
    }

    /**
     * Add subselect queries to include the max of the relation's column.
     */
    public function withMax(array|string $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'max');
    }

    /**
     * Add subselect queries to include the min of the relation's column.
     */
    public function withMin(array|string $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'min');
    }

    /**
     * Add subselect queries to include the sum of the relation's column.
     */
    public function withSum(array|string $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'sum');
    }

    /**
     * Add subselect queries to include the average of the relation's column.
     */
    public function withAvg(array|string $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'avg');
    }

    /**
     * Add subselect queries to include the existence of related models.
     */
    public function withExists(array|string $relation): static
    {
        return $this->withAggregate($relation, '*', 'exists');
    }

    /**
     * Add the "has" condition where clause to the query.
     *
     * @param Builder<*>  $hasQuery
     * @param Relation<*, *, *>  $relation
     */
    protected function addHasWhere(Builder $hasQuery, Relation $relation, string $operator, Expression|int $count, string $boolean): static
    {
        $hasQuery->mergeConstraintsFrom($relation->getQuery());

        return $this->canUseExistsForExistenceCheck($operator, $count)
                ? $this->addWhereExistsQuery($hasQuery->toBase(), $boolean, $operator === '<' && $count === 1)
                : $this->addWhereCountQuery($hasQuery->toBase(), $operator, $count, $boolean);
    }

    /**
     * Merge the where constraints from another query to the current query.
     */
    public function mergeConstraintsFrom(Builder $from)
    {
        $whereBindings = $from->getQuery()->bindings->getValues();

        $wheres = $from->getQuery()->getTable() !== $this->getQuery()->getTable()
            ? $this->requalifyWhereTables(
                $from->getQuery()->wheres,
                $from->getQuery()->getTable(),
                $this->getModel()->getTable()
            ) : $from->getQuery()->wheres;

        // Here we have some other query that we want to merge the where constraints from. We will
        // copy over any where constraints on the query as well as remove any global scopes the
        // query might have removed. Then we will return ourselves with the finished merging.
        return $this->withoutGlobalScopes(
            $from->removedScopes()
        )->mergeWheres(
            $wheres,
            $whereBindings
        );
    }

    /**
     * Updates the table name for any columns with a new qualified name.
     */
    protected function requalifyWhereTables(array $wheres, string $from, string $to): array
    {
        return (new BaseCollection($wheres))->map(static fn ($where) 
            => (new BaseCollection($where))->map(static fn ($value) 
                => is_string($value) && str_starts_with($value, $from . '.')
                    ? $to . '.' . Text::afterLast($value, '.')
                    : $value
            )
        )->toArray();
    }

    /**
     * Add a sub-query count clause to this query.
     */
    protected function addWhereCountQuery(BaseBuilder $query, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and'): static
    {
        $this->query->bindings->addMany($query->bindings->getValues());

        return $this->where(
            new Expression('(' . $query->toSql() . ')'),
            $operator,
            is_numeric($count) ? new Expression($count) : $count,
            $boolean
        );
    }

    /**
     * Get the "has relation" base query instance.
     * 
     * @return Relation<*, *, *>
     */
    protected function getRelationWithoutConstraints(string $relation): Relation
    {
        return Relation::noConstraints(fn () => $this->getModel()->{$relation}());
    }

    /**
     * Check if we can run an "exists" query to optimize performance.
     */
    protected function canUseExistsForExistenceCheck(string $operator, Expression|int $count): bool
    {
        return ($operator === '>=' || $operator === '<') && $count === 1;
    }
}
