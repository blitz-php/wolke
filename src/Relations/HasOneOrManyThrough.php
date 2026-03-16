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

use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Database\Exceptions\UniqueConstraintViolationException;
use BlitzPHP\Database\Exceptions\MultipleRecordsFoundException;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\LazyCollection;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Contracts\CursorPaginator;
use BlitzPHP\Wolke\Contracts\LengthAwarePaginator;
use BlitzPHP\Wolke\Contracts\Paginator;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithDictionary;
use Closure;

/**
 * @template TRelatedModel of Model
 * @template TIntermediateModel of Model
 * @template TDeclaringModel of Model
 * @template TResult
 *
 * @extends Relation<TRelatedModel, TIntermediateModel, TResult>
 */
abstract class HasOneOrManyThrough extends Relation
{
    use InteractsWithDictionary;

    /**
     * Create a new has many through relationship instance.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $farParent The far parent model instance.
     * @param  TIntermediateModel  $throughParent  The "through" parent model instance.
     * @param string $firstKey       The near key on the relationship.
     * @param string $secondKey      The far key on the relationship.
     * @param string $localKey       The local key on the relationship.
     * @param string $secondLocalKey The local key on the intermediary model.
     */
    public function __construct(Builder $query, protected Model $farParent, protected Model $throughParent, protected string $firstKey, protected string $secondKey, protected string $localKey, protected string $secondLocalKey)
    {
        parent::__construct($query, $throughParent);
    }

    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        $query = $this->getRelationQuery();

        $localValue = $this->farParent[$this->localKey];

        $this->performJoin($query);

        if (static::$constraints) {
            $query->where($this->getQualifiedFirstKeyName(), '=', $localValue);
        }
    }

    /**
     * Set the join clause on the query.
     *
     * @param Builder<TRelatedModel>|null  $query
     */
    protected function performJoin(?Builder $query = null): void
    {
        $query ??= $this->query;

        $farKey = $this->getQualifiedFarKeyName();

        $query->join($this->throughParent->getTable(), $this->getQualifiedParentKeyName(), '=', $farKey);

        if ($this->throughParentSoftDeletes()) {
            $query->withGlobalScope('SoftDeletableHasManyThrough', function ($query) {
                $query->whereNull($this->throughParent->getQualifiedDeletedAtColumn());
            });
        }
    }

    /**
     * Get the fully-qualified parent key name.
     */
    public function getQualifiedParentKeyName(): string
    {
        return $this->parent->qualifyColumn($this->secondLocalKey);
    }

    /**
     * Determine whether "through" parent of the relation uses Soft Deletes.
     */
    public function throughParentSoftDeletes(): bool
    {
        return $this->throughParent::isSoftDeletable();
    }

    /**
     * Indicate that trashed "through" parents should be included in the query.
     */
    public function withTrashedParents(): static
    {
        $this->query->withoutGlobalScope('SoftDeletableHasManyThrough');

        return $this;
    }

    /** 
     * {@inheritDoc}
     */
    public function addEagerConstraints(array $models): void
    {
        $whereIn = $this->whereInMethod($this->farParent, $this->localKey);

        $this->whereInEager(
            $whereIn,
            $this->getQualifiedFirstKeyName(),
            $this->getKeys($models, $this->localKey),
            $this->getRelationQuery(),
        );
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     *
     * @param  Collection<int, TRelatedModel>  $results
     * 
     * @return array<array<array-key, TRelatedModel>>
     */
    protected function buildDictionary(Collection $results): array
    {
        $dictionary = [];

        $isAssociative = Arr::isAssoc($results->all());

        // First we will create a dictionary of models keyed by the foreign key of the
        // relationship as this will allow us to quickly access all of the related
        // models without having to do nested looping which will be quite slow.
        foreach ($results as $key => $result) {
            if ($isAssociative) {
                $dictionary[$result->blitz_through_key][$key] = $result;
            } else {
                $dictionary[$result->blitz_through_key][] = $result;
            }
        }

        return $dictionary;
    }

    /**
     * Get the first related model record matching the attributes or instantiate it.
     *
     * @return TRelatedModel
     */
    public function firstOrNew(array $attributes = [], array $values = []): Model
    {
        if (null !== $instance = $this->where($attributes)->first()) {
            return $instance;
        }

        return $this->related->newInstance(array_merge($attributes, $values));
    }

    /**
     * Get the first record matching the attributes. If the record is not found, create it.
     *
     * @param  (Closure(): array)|array  $values
     * 
     * @return TRelatedModel
     */
    public function firstOrCreate(array $attributes = [], Closure|array $values = []): Model
    {
        if (null !== $instance = (clone $this)->where($attributes)->first()) {
            return $instance;
        }

        return $this->createOrFirst(array_merge($attributes, Helpers::value($values)));
    }

    /**
     * Attempt to create the record. If a unique constraint violation occurs, attempt to find the matching record.
     *
     * @param  (Closure(): array)|array  $values
     * 
     * @return TRelatedModel
     */
    public function createOrFirst(array $attributes = [], Closure|array $values = []): Model
    {
        try {
            return $this->getQuery()->withSavepointIfNeeded(fn () => $this->create(array_merge($attributes, Helpers::value($values))));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->where($attributes)->first() ?? throw $exception;
        }
    }

    /**
     * Create or update a related record matching the attributes, and fill it with values.
     *
     * @return TRelatedModel
     */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        return Helpers::tap($this->firstOrCreate($attributes, $values), function ($instance) use ($values) {
            if (! $instance->wasRecentlyCreated) {
                $instance->fill($values)->save();
            }
        });
    }

    /**
     * Add a basic where clause to the query, and return the first result.
     *
     * @return TRelatedModel|null
     */
    public function firstWhere(array|Closure|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
    {
        return $this->where($column, $operator, $value, $boolean)->first();
    }

    /**
     * Execute the query and get the first related model.
     *
     * @return TRelatedModel|null
     */
    public function first(array $columns = ['*']): mixed
    {
        $results = $this->limit(1)->get($columns);

        return count($results) > 0 ? $results->first() : null;
    }

    /**
     * Execute the query and get the first result or throw an exception.
     *
     * @return TRelatedModel
     *
     * @throws ModelNotFoundException<TRelatedModel>
     */
    public function firstOrFail(array $columns = ['*']): mixed
    {
        if (! is_null($model = $this->first($columns))) {
            return $model;
        }

        throw (new ModelNotFoundException())->setModel(get_class($this->related));
    }

    /**
     * Execute the query and get the first result or call a callback.
     *
     * @template TValue
     *
     * @param  (Closure(): TValue)|list<string>  $columns
     * @param  (Closure(): TValue)|null  $callback
     * 
     * @return TRelatedModel|TValue
     */
    public function firstOr(array|Closure $columns = ['*'], ?Closure $callback = null)
    {
        if ($columns instanceof Closure) {
            $callback = $columns;

            $columns = ['*'];
        }

        if (null !== $model = $this->first($columns)) {
            return $model;
        }

        return $callback();
    }

    /**
     * Find a related model by its primary key.
     *
     * @return ($id is (Arrayable<array-key, mixed>|array<mixed>) ? Collection<int, TRelatedModel> : TRelatedModel|null)
     */
    public function find(mixed $id, array $columns = ['*'])
    {
        if (is_array($id) || $id instanceof Arrayable) {
            return $this->findMany($id, $columns);
        }

        return $this->where(
            $this->getRelated()->getQualifiedKeyName(), 
            '=', 
            $id
        )->first($columns);
    }

    /**
     * Find a sole related model by its primary key.
     *
     * @return TRelatedModel
     *
     * @throws ModelNotFoundException<TRelatedModel>
     * @throws MultipleRecordsFoundException
     */
    public function findSole(mixed $id, array $columns = ['*'])
    {
        return $this->where(
            $this->getRelated()->getQualifiedKeyName(), 
            '=', 
            $id
        )->sole($columns);
    }

    /**
     * Find multiple related models by their primary keys.
     *
     * @return Collection<int, TRelatedModel>
     */
    public function findMany(array|Arrayable $ids, array $columns = ['*']): Collection
    {
        $ids = $ids instanceof Arrayable ? $ids->toArray() : $ids;

        if ($ids === []) {
            return $this->getRelated()->newCollection();
        }

        return $this->whereIn(
            $this->getRelated()->getQualifiedKeyName(), 
            $ids
        )->get($columns);
    }

    /**
     * Find a related model by its primary key or throw an exception.
     *
     * @return ($id is (Arrayable<array-key, mixed>|array<mixed>) ? Collection<int, TRelatedModel> : TRelatedModel)
     *
     * @throws ModelNotFoundException<TRelatedModel>
     */
    public function findOrFail(mixed $id, array $columns = ['*'])
    {
        $result = $this->find($id, $columns);

        $id = $id instanceof Arrayable ? $id->toArray() : $id;

        if (is_array($id)) {
            if (count($result) === count(array_unique($id))) {
                return $result;
            }
        } elseif (null !== $result) {
            return $result;
        }

        throw (new ModelNotFoundException)->setModel(get_class($this->related), $id);
    }

    /**
     * Find a related model by its primary key or call a callback.
     *
     * @template TValue
     *
     * @param (Closure(): TValue)|list<string>|string  $columns
     * @param (Closure(): TValue)|null  $callback
     * @return (
     *     $id is (Arrayable<array-key, mixed>|array<mixed>)
     *     ? Collection<int, TRelatedModel>|TValue
     *     : TRelatedModel|TValue
     * )
     */
    public function findOr(mixed $id, array|Closure|string $columns = ['*'], ?Closure $callback = null)
    {
        if ($columns instanceof Closure) {
            $callback = $columns;

            $columns = ['*'];
        }

        $result = $this->find($id, $columns);

        $id = $id instanceof Arrayable ? $id->toArray() : $id;

        if (is_array($id)) {
            if (count($result) === count(array_unique($id))) {
                return $result;
            }
        } elseif (null !== $result) {
            return $result;
        }

        return $callback();
    }

    /** 
     * {@inheritDoc}
     */
    public function get(array $columns = ['*']): Collection
    {
        $builder = $this->prepareQueryBuilder($columns);

        $models = $builder->getModels();

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded. This will solve the
        // n + 1 query problem for the developer and also increase performance.
        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->query->applyAfterQueryCallbacks(
            $this->related->newCollection($models)
        );
    }

    /**
     * Get a paginator for the "select" statement.
     */
    public function paginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        $this->query->select($this->shouldSelect($columns));

        return $this->query->paginate($perPage, $columns, $pageName, $page);
    }

    /**
     * Paginate the given query into a simple paginator.
     */
    public function simplePaginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): Paginator
    {
        $this->query->select($this->shouldSelect($columns));

        return $this->query->simplePaginate($perPage, $columns, $pageName, $page);
    }

    /**
     * Paginate the given query into a cursor paginator.
     */
    public function cursorPaginate(?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', ?string $cursor = null): CursorPaginator
    {
        $this->query->select($this->shouldSelect($columns));

        return $this->query->cursorPaginate($perPage, $columns, $cursorName, $cursor);
    }

    /**
     * Set the select clause for the relation query.
     */
    protected function shouldSelect(array $columns = ['*']): array
    {
        if ($columns == ['*']) {
            $columns = [$this->related->qualifyColumn('*')];
        }

        return array_merge($columns, [$this->getQualifiedFirstKeyName().' as blitz_through_key']);
    }

    /**
     * Chunk the results of the query.
     */
    public function chunk(int $count, callable $callback): bool
    {
        return $this->prepareQueryBuilder()->chunk($count, $callback);
    }

    /**
     * Chunk the results of a query by comparing numeric IDs.
     */
    public function chunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->chunkById($count, $callback, $column, $alias);
    }

    /**
     * Chunk the results of a query by comparing IDs in descending order.
     */
    public function chunkByIdDesc(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->chunkByIdDesc($count, $callback, $column, $alias);
    }

    /**
     * Execute a callback over each item while chunking by ID.
     */
    public function eachById(callable $callback, int $count = 1000, ?string $column = null, ?string $alias = null): bool
    {
        $column = $column ?? $this->getRelated()->getQualifiedKeyName();

        $alias = $alias ?? $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->eachById($callback, $count, $column, $alias);
    }

    /**
     * Get a generator for the given query.
     *
     * @return LazyCollection<int, TRelatedModel>
     */
    public function cursor(): LazyCollection
    {
        return $this->prepareQueryBuilder()->cursor();
    }

    /**
     * Execute a callback over each item while chunking.
     */
    public function each(callable $callback, int $count = 1000): bool
    {
        return $this->chunk($count, static function ($results) use ($callback) {
            foreach ($results as $key => $value) {
                if ($callback($value, $key) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Query lazily, by chunks of the given size.
     *
     * @return LazyCollection<int, TRelatedModel>
     */
    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        return $this->prepareQueryBuilder()->lazy($chunkSize);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs.
     *
     * @return LazyCollection<int, TRelatedModel>
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->lazyById($chunkSize, $column, $alias);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs in descending order.
     */
    public function lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->lazyByIdDesc($chunkSize, $column, $alias);
    }

    /**
     * Prepare the query builder for query execution.
     *
     * @return Builder<TRelatedModel>
     */
    protected function prepareQueryBuilder(array $columns = ['*']): Builder
    {
        $builder = $this->query->applyScopes();

        return $builder->select(
            $this->shouldSelect($builder->getQuery()->columns !== [] ? [] : $columns)
        );
    }

    /** 
     * {@inheritDoc}
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($parentQuery->getQuery()->from === $query->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        if ($parentQuery->getQuery()->from === $this->throughParent->getTable()) {
            return $this->getRelationExistenceQueryForThroughSelfRelation($query, $parentQuery, $columns);
        }

        $this->performJoin($query);

        return $query->select($columns)->whereColumn(
            $this->getQualifiedLocalKeyName(), '=', $this->getQualifiedFirstKeyName()
        );
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, array $columns = ['*'])
    {
        $query->from($query->getModel()->getTable().' as '.$hash = $this->getRelationCountHash());

        $query->join($this->throughParent->getTable(), $this->getQualifiedParentKeyName(), '=', $hash.'.'.$this->secondKey);

        if ($this->throughParentSoftDeletes()) {
            $query->whereNull($this->throughParent->getQualifiedDeletedAtColumn());
        }

        $query->getModel()->setTable($hash);

        return $query->select($columns)->whereColumn(
            $parentQuery->getQuery()->from.'.'.$this->localKey, '=', $this->getQualifiedFirstKeyName()
        );
    }

    /**
     * Add the constraints for a relationship query on the same table as the through parent.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  array|mixed  $columns
     * 
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQueryForThroughSelfRelation(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $table = $this->throughParent->getTable().' as '.$hash = $this->getRelationCountHash();

        $query->join($table, $hash.'.'.$this->secondLocalKey, '=', $this->getQualifiedFarKeyName());

        if ($this->throughParentSoftDeletes()) {
            $query->whereNull($hash . '.' . $this->throughParent->getDeletedAtColumn());
        }

        return $query->select($columns)->whereColumn(
            $parentQuery->getQuery()->from . '.'.$this->localKey, '=', $hash.'.'.$this->firstKey
        );
    }

    /**
     * Get the qualified foreign key on the related model. 
     */
    public function getQualifiedFarKeyName(): string
    {
        return $this->getQualifiedForeignKeyName();
    }

    /**
     * Get the foreign key on the "through" model.
     */
    public function getFirstKeyName(): string
    {
        return $this->firstKey;
    }

    /**
     * Get the qualified foreign key on the "through" model.
     */
    public function getQualifiedFirstKeyName()
    {
        return $this->throughParent->qualifyColumn($this->firstKey);
    }

    /**
     * Get the foreign key on the related model.
     */
    public function getForeignKeyName(): string
    {
        return $this->secondKey;
    }

    /**
     * Get the qualified foreign key on the related model.
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->related->qualifyColumn($this->secondKey);
    }

    /**
     * Get the local key on the far parent model.
     */
    public function getQualifiedRelatedKeyName(): string
    {
        return $this->farParent->qualifyColumn($this->localKey);
    }

    public function getLocalKeyName(): string
    {
        return $this->localKey;
    }

    /**
     * Get the qualified local key on the far parent model.
     */
    public function getQualifiedLocalKeyName(): string
    {
        return $this->farParent->qualifyColumn($this->localKey);
    }

    /**
     * Get the local key on the intermediary model.
     */
    public function getSecondLocalKeyName(): string
    {
        return $this->secondLocalKey;
    }
}
