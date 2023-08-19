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
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\LazyCollection;
use BlitzPHP\Utilities\Support\Invader;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithDictionary;
use BlitzPHP\Wolke\SoftDeletes;
use Closure;

class HasManyThrough extends Relation
{
    use InteractsWithDictionary;

    /**
     * Create a new has many through relationship instance.
     *
     * @param Model  $farParent      The far parent model instance.
     * @param Model  $throughParent  The "through" parent model instance.
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
     * Convert the relationship to a "has one through" relationship.
     */
    public function one(): HasOneThrough
    {
        return HasOneThrough::noConstraints(fn () => new HasOneThrough(
            $this->getQuery(),
            $this->farParent,
            $this->throughParent,
            $this->getFirstKeyName(),
            $this->secondKey,
            $this->getLocalKeyName(),
            $this->getSecondLocalKeyName(),
        ));
    }

    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        $localValue = $this->farParent[$this->localKey];

        $this->performJoin();

        if (static::$constraints) {
            $this->query->where($this->getQualifiedFirstKeyName(), '=', $localValue);
        }
    }

    /**
     * Set the join clause on the query.
     */
    protected function performJoin(?Builder $query = null): void
    {
        $query = $query ?: $this->query;

        $farKey = $this->getQualifiedFarKeyName();

        $query->join($this->throughParent->getTable(), [$this->getQualifiedParentKeyName() => $farKey]);

        if ($this->throughParentSoftDeletes()) {
            $query->withGlobalScope('SoftDeletableHasManyThrough', function ($query) {
                $query->whereNull($this->throughParent->getQualifiedDeletedAtColumn());
            });
        }
    }

    /**
     * Get the fully qualified parent key name.
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
        return in_array(SoftDeletes::class, Helpers::classUsesRecursive($this->throughParent), true);
    }

    /**
     * Indicate that trashed "through" parents should be included in the query.
     */
    public function withTrashedParents(): self
    {
        $this->query->withoutGlobalScope('SoftDeletableHasManyThrough');

        return $this;
    }

    /**
     * Set the constraints for an eager load of the relation.
     */
    public function addEagerConstraints(array $models): void
    {
        $whereIn = $this->whereInMethod($this->farParent, $this->localKey);

        $this->whereInEager(
            $whereIn,
            $this->getQualifiedFirstKeyName(),
            $this->getKeys($models, $this->localKey)
        );
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
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary we can simply spin through the parent models to
        // link them up with their children using the keyed dictionary to make the
        // matching very convenient and easy work. Then we'll just return them.
        foreach ($models as $model) {
            if (isset($dictionary[$key = $this->getDictionaryKey($model->getAttribute($this->localKey))])) {
                $model->setRelation(
                    $relation,
                    $this->related->newCollection($dictionary[$key])
                );
            }
        }

        return $models;
    }

    /**
     * Build model dictionary keyed by the relation's foreign key.
     */
    protected function buildDictionary(Collection $results): array
    {
        $dictionary = [];

        // First we will create a dictionary of models keyed by the foreign key of the
        // relationship as this will allow us to quickly access all of the related
        // models without having to do nested looping which will be quite slow.
        foreach ($results as $result) {
            $dictionary[$result->blitz_through_key][] = $result;
        }

        return $dictionary;
    }

    /**
     * Get the first related model record matching the attributes or instantiate it.
     */
    public function firstOrNew(array $attributes): Model
    {
        if (null === ($instance = $this->where($attributes)->first())) {
            $instance = $this->related->newInstance($attributes);
        }

        return $instance;
    }

    /**
     * Create or update a related record matching the attributes, and fill it with values.
     */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        $instance = $this->firstOrNew($attributes);

        $instance->fill($values)->save();

        return $instance;
    }

    /**
     * Add a basic where clause to the query, and return the first result.
     *
     * @return Model|static
     */
    public function firstWhere(array|Closure|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
    {
        return $this->where($column, $operator, $value, $boolean)->first();
    }

    /**
     * Execute the query and get the first related model.
     */
    public function first(array $columns = ['*']): mixed
    {
        $results = $this->limit(1)->get($columns);

        return count($results) > 0 ? $results->first() : null;
    }

    /**
     * Execute the query and get the first result or throw an exception.
     *
     * @return Model|static
     *
     * @throws ModelNotFoundException
     */
    public function firstOrFail(array $columns = ['*'])
    {
        if (null !== ($model = $this->first($columns))) {
            return $model;
        }

        throw (new ModelNotFoundException())->setModel(get_class($this->related));
    }

    /**
     * Execute the query and get the first result or call a callback.
     *
     * @return mixed|Model|static
     */
    public function firstOr(array|Closure $columns = ['*'], ?Closure $callback = null)
    {
        if ($columns instanceof Closure) {
            $callback = $columns;

            $columns = ['*'];
        }

        if (null !== ($model = $this->first($columns))) {
            return $model;
        }

        return $callback();
    }

    /**
     * Find a related model by its primary key.
     *
     * @return Collection|Model|null
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
     * Find multiple related models by their primary keys.
     */
    public function findMany(array|Arrayable $ids, array $columns = ['*']): Collection
    {
        $ids = $ids instanceof Arrayable ? $ids->toArray() : $ids;

        if (empty($ids)) {
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
     * @return Collection|Model
     *
     * @throws ModelNotFoundException
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

        throw (new ModelNotFoundException())->setModel(get_class($this->related), $id);
    }

    /**
     * Find a related model by its primary key or call a callback.
     *
     * @return Collection|mixed|Model
     */
    public function findOr(mixed $id, array|Closure $columns = ['*'], ?Closure $callback = null)
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
     * Get the results of the relationship.
     */
    public function getResults(): mixed
    {
        return null !== $this->farParent->{$this->localKey}
                ? $this->get()
                : $this->related->newCollection();
    }

    /**
     * Execute the query as a "select" statement.
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

        return $this->related->newCollection($models);
    }

    /**
     * Get a paginator for the "select" statement.
     *
     * @return \BlitzPHP\Wolke\Contracts\LengthAwarePaginator
     */
    public function paginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null)
    {
        $this->query->select($this->shouldSelect($columns));

        return $this->query->paginate($perPage, $columns, $pageName, $page);
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @return \BlitzPHP\Wolke\Contracts\Paginator
     */
    public function simplePaginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null)
    {
        $this->query->select($this->shouldSelect($columns));

        return $this->query->simplePaginate($perPage, $columns, $pageName, $page);
    }

    /**
     * Paginate the given query into a cursor paginator.
     *
     * @return \BlitzPHP\Wolke\Contracts\CursorPaginator
     */
    public function cursorPaginate(?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', ?string $cursor = null)
    {
        $this->query->select($this->shouldSelect($columns));

        return $this->query->cursorPaginate($perPage, $columns, $cursorName, $cursor);
    }

    /**
     * Set the select clause for the relation query.
     */
    protected function shouldSelect(array $columns = ['*']): array
    {
        if ($columns === ['*']) {
            $columns = [$this->related->getTable() . '.*'];
        }

        return array_merge($columns, [$this->getQualifiedFirstKeyName() . ' as blitz_through_key']);
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
     * Execute a callback over each item while chunking by ID.
     */
    public function eachById(callable $callback, int $count = 1000, ?string $column = null, ?string $alias = null): bool
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->eachById($callback, $count, $column, $alias);
    }

    /**
     * Get a generator for the given query.
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
     */
    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        return $this->prepareQueryBuilder()->lazy($chunkSize);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs.
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->lazyById($chunkSize, $column, $alias);
    }

    /**
     * Prepare the query builder for query execution.
     */
    protected function prepareQueryBuilder(array $columns = ['*']): Builder
    {
        $builder = $this->query->applyScopes();
        $fields  = Invader::make($builder->getQuery())->fields;

        return $builder->select(
            $this->shouldSelect($fields !== [] ? $fields : $columns)
        );
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param array|mixed $columns
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($parentQuery->getQuery()->getTable() === $query->getQuery()->getTable()) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        if ($parentQuery->getQuery()->getTable() === $this->throughParent->getTable()) {
            return $this->getRelationExistenceQueryForThroughSelfRelation($query, $parentQuery, $columns);
        }

        $this->performJoin($query);

        return $query->select($columns)->whereColumn(
            $this->getQualifiedLocalKeyName(),
            '=',
            $this->getQualifiedFirstKeyName()
        );
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param array|mixed $columns
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $query->from($query->getModel()->getTable() . ' as ' . $hash = $this->getRelationCountHash());

        $query->join($this->throughParent->getTable(), [$this->getQualifiedParentKeyName() => $hash . '.' . $this->secondKey]);

        if ($this->throughParentSoftDeletes()) {
            $query->whereNull($this->throughParent->getQualifiedDeletedAtColumn());
        }

        $query->getModel()->setTable($hash);

        return $query->select($columns)->whereColumn(
            $parentQuery->getQuery()->getTable() . '.' . $this->localKey,
            '=',
            $this->getQualifiedFirstKeyName()
        );
    }

    /**
     * Add the constraints for a relationship query on the same table as the through parent.
     *
     * @param array|mixed $columns
     */
    public function getRelationExistenceQueryForThroughSelfRelation(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $table = $this->throughParent->getTable() . ' as ' . $hash = $this->getRelationCountHash();

        $query->join($table, [$hash . '.' . $this->secondLocalKey => $this->getQualifiedFarKeyName()]);

        if ($this->throughParentSoftDeletes()) {
            $query->whereNull($hash . '.' . $this->throughParent->getDeletedAtColumn());
        }

        return $query->select($columns)->whereColumn(
            $parentQuery->getQuery()->getTable() . '.' . $this->localKey,
            '=',
            $hash . '.' . $this->firstKey
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
    public function getQualifiedFirstKeyName(): string
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
