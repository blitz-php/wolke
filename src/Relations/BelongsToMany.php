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
use BlitzPHP\Database\Query\Expression;
use BlitzPHP\Database\Exceptions\MultipleRecordsFoundException;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Utilities\Iterable\LazyCollection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Contracts\CursorPaginator;
use BlitzPHP\Wolke\Contracts\LengthAwarePaginator;
use BlitzPHP\Wolke\Contracts\Paginator;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\AsPivot;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithDictionary;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithPivotTable;
use Closure;
use InvalidArgumentException;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TPivotModel of Pivot = Pivot
 * @template TAccessor of string = 'pivot'
 *
 * @extends Relation<TRelatedModel, TDeclaringModel, Collection<int, TRelatedModel&object{pivot: TPivotModel}>>
 */
class BelongsToMany extends Relation
{
    use InteractsWithDictionary;
    use InteractsWithPivotTable;

    /**
     * The intermediate table for the relation.
     */
    protected string $table;

    /**
     * The pivot table columns to retrieve.
     * 
     * @var list<string|Expression>
     */
    protected array $pivotColumns = [];

    /**
     * Any pivot table restrictions for where clauses.
     */
    protected array $pivotWheres = [];

    /**
     * Any pivot table restrictions for whereIn clauses.
     */
    protected array $pivotWhereIns = [];

    /**
     * Any pivot table restrictions for whereNull clauses.
     */
    protected array $pivotWhereNulls = [];

    /**
     * The default values for the pivot columns.
     */
    protected array $pivotValues = [];

    /**
     * Indicates if timestamps are available on the pivot table.
     */
    public bool $withTimestamps = false;

    /**
     * The custom pivot table column for the created_at timestamp.
     */
    protected ?string $pivotCreatedAt = null;

    /**
     * The custom pivot table column for the updated_at timestamp.
     */
    protected ?string $pivotUpdatedAt = null;

    /**
     * The class name of the custom pivot model to use for the relationship.
     *
     * @var class-string<TPivotModel>
     */
    protected string $using = null;

    /**
     * The name of the accessor to use for the "pivot" relationship.
     *
     * @var TAccessor
     */
    protected string $accessor = 'pivot';

    /**
     * Create a new belongs to many relationship instance.
     *
     * @param Builder<TRelatedModel>  $query
     * @param TDeclaringModel  $parent
     * @param string|class-string<TRelatedModel>  $table
     * @param string  $foreignPivotKey The foreign key of the parent model.
     * @param string  $relatedPivotKey The associated key of the relation.
     * @param string  $parentKey       The key name of the parent model.
     * @param string  $relatedKey      The key name of the related model.
     * @param ?string $relationName    The "name" of the relationship.
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $table,
        protected string $foreignPivotKey,
        protected string $relatedPivotKey,
        protected string $parentKey,
        protected string $relatedKey,
        protected ?string $relationName = null
    ) {
        $this->table = $this->resolveTableName($table);

        parent::__construct($query, $parent);
    }

    /**
     * Attempt to resolve the intermediate table name from the given string.
     */
    protected function resolveTableName(string $table): string
    {
        if (! str_contains($table, '\\') || ! class_exists($table)) {
            return $table;
        }

        $model = new $table();

        if (! $model instanceof Model) {
            return $table;
        }

        if (in_array(AsPivot::class, Helpers::classUsesRecursive($model), true)) {
            $this->using($table);
        }

        return $model->getTable();
    }

    /**
     * {@inheritDoc}
     */
    public function addConstraints(): void
    {
        $this->performJoin();

        if (static::$constraints) {
            $this->addWhereConstraints();
        }
    }

    /**
     * Set the join clause for the relation query.
     *
     * @param Builder<TRelatedModel>|null $query
     */
    protected function performJoin(?Builder $query = null): static
    {
        $query = $query ?: $this->query;

        // We need to join to the intermediate table on the related model's primary
        // key column with the intermediate table's foreign key for the related
        // model instance. Then we can set the "where" for the parent models.
        $query->join(
            $this->table,
            $this->getQualifiedRelatedKeyName(),
            '=',
            $this->getQualifiedRelatedPivotKeyName()
        );

        return $this;
    }

    /**
     * Set the where clause for the relation query.
     */
    protected function addWhereConstraints(): static
    {
        $this->query->where(
            $this->getQualifiedForeignPivotKeyName(),
            '=',
            $this->parent->{$this->parentKey}
        );

        return $this;
    }

    /**
     * Set the constraints for an eager load of the relation.
     */
    public function addEagerConstraints(array $models): void
    {
        $whereIn = $this->whereInMethod($this->parent, $this->parentKey);

        $this->whereInEager(
            $whereIn,
            $this->getQualifiedForeignPivotKeyName(),
            $this->getKeys($models, $this->parentKey)
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param list<Model> $models
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * {@inheritDoc}
     *
     * @param list<Model> $models
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have an array dictionary of child objects we can easily match the
        // children back to their parent using the dictionary and the keys on the
        // the parent models. Then we will return the hydrated models back out.
        foreach ($models as $model) {
            $key = $this->getDictionaryKey($model->{$this->parentKey});

            if (isset($dictionary[$key])) {
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
     *
     * @param Collection<int, TRelatedModel>  $results
     * 
     * @return array<array<array-key, TRelatedModel>>
     */
    protected function buildDictionary(Collection $results): array
    {
        // First we will build a dictionary of child models keyed by the foreign key
        // of the relation so that we will easily and quickly match them to their
        // parents without having a possibly slow inner loops for every models.
        $dictionary = [];

        $isAssociative = Arr::isAssoc($results->all());

        foreach ($results as $key => $result) {
            $value = $this->getDictionaryKey($result->{$this->accessor}->{$this->foreignPivotKey});

            if ($isAssociative) {
                $dictionary[$value][$key] = $result;
            } else {
                $dictionary[$value][] = $result;
            }
        }

        return $dictionary;
    }

    /**
     * Get the class being used for pivot models.
     *
     * @return class-string<TPivotModel>
     */
    public function getPivotClass(): string
    {
        return $this->using ?? Pivot::class;
    }

    /**
     * Specify the custom pivot model to use for the relationship.
     *
     * @template TNewPivotModel of Pivot
     *
     * @param  class-string<TNewPivotModel>  $class
     * 
     * @phpstan-this-out static<TRelatedModel, TDeclaringModel, TNewPivotModel, TAccessor>
     */
    public function using(string $class): static
    {
        $this->using = $class;

        return $this;
    }

    /**
     * Specify the custom pivot accessor to use for the relationship.
     * 
     * @phpstan-this-out static<TRelatedModel, TDeclaringModel, TPivotModel, TNewAccessor>
     */
    public function as(string $accessor): static
    {
        $this->accessor = $accessor;

        return $this;
    }

    /**
     * Set a where clause for a pivot table column.
     */
    public function wherePivot(string|Expression $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        $this->pivotWheres[] = func_get_args();

        return $this->where($this->qualifyPivotColumn($column), $operator, $value, $boolean);
    }

    /**
     * Set a "where between" clause for a pivot table column.
     */
    public function wherePivotBetween(string|Expression $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        return $this->whereBetween($this->qualifyPivotColumn($column), $values, $boolean, $not);
    }

    /**
     * Set a "or where between" clause for a pivot table column.
     */
    public function orWherePivotBetween(string|Expression $column, array $values): static
    {
        return $this->wherePivotBetween($column, $values, 'or');
    }

    /**
     * Set a "where pivot not between" clause for a pivot table column.
     */
    public function wherePivotNotBetween(string|Expression $column, array $values, string $boolean = 'and'): static
    {
        return $this->wherePivotBetween($column, $values, $boolean, true);
    }

    /**
     * Set a "or where not between" clause for a pivot table column.
     */
    public function orWherePivotNotBetween(string|Expression $column, array $values): static
    {
        return $this->wherePivotBetween($column, $values, 'or', true);
    }

    /**
     * Set a "where in" clause for a pivot table column.
     */
    public function wherePivotIn(string|Expression $column, mixed $values, string $boolean = 'and', bool $not = false): static
    {
        $this->pivotWhereIns[] = func_get_args();

        return $this->whereIn($this->qualifyPivotColumn($column), $values, $boolean, $not);
    }

    /**
     * Set an "or where" clause for a pivot table column.
     */
    public function orWherePivot(string|Expression $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->wherePivot($column, $operator, $value, 'or');
    }

    /**
     * Set a where clause for a pivot table column.
     *
     * In addition, new pivot records will receive this value.
     *
     * @param string|Expression|array<string, string> $column
     * 
     * @throws InvalidArgumentException
     */
    public function withPivotValue(array|string|Expression $column, mixed $value = null): static
    {
        if (is_array($column)) {
            foreach ($column as $name => $value) {
                $this->withPivotValue($name, $value);
            }

            return $this;
        }

        if (null === $value) {
            throw new InvalidArgumentException('The provided value may not be null.');
        }

        $this->pivotValues[] = compact('column', 'value');

        return $this->wherePivot($column, '=', $value);
    }

    /**
     * Set an "or where in" clause for a pivot table column.
     */
    public function orWherePivotIn(string|Expression $column, mixed $values): static
    {
        return $this->wherePivotIn($column, $values, 'or');
    }

    /**
     * Set a "where not in" clause for a pivot table column.
     */
    public function wherePivotNotIn(string|Expression $column, mixed $values, string $boolean = 'and'): static
    {
        return $this->wherePivotIn($column, $values, $boolean, true);
    }

    /**
     * Set an "or where not in" clause for a pivot table column.
     */
    public function orWherePivotNotIn(string|Expression $column, mixed $values): static
    {
        return $this->wherePivotNotIn($column, $values, 'or');
    }

    /**
     * Set a "where null" clause for a pivot table column.
     */
    public function wherePivotNull(string|Expression $column, string $boolean = 'and', bool $not = false): static
    {
        $this->pivotWhereNulls[] = func_get_args();

        return $this->whereNull($this->qualifyPivotColumn($column), $boolean, $not);
    }

    /**
     * Set a "where not null" clause for a pivot table column.
     */
    public function wherePivotNotNull(string|Expression $column, string $boolean = 'and'): static
    {
        return $this->wherePivotNull($column, $boolean, true);
    }

    /**
     * Set a "or where null" clause for a pivot table column.
     */
    public function orWherePivotNull(string|Expression $column, bool $not = false): static
    {
        return $this->wherePivotNull($column, 'or', $not);
    }

    /**
     * Set a "or where not null" clause for a pivot table column.
     */
    public function orWherePivotNotNull(string|Expression $column): static
    {
        return $this->orWherePivotNull($column, true);
    }

    /**
     * Add an "order by" clause for a pivot table column.
     */
    public function orderByPivot(string|Expression $column, string $direction = 'asc'): static
    {
        return $this->orderBy($this->qualifyPivotColumn($column), $direction);
    }

    /**
     * Add an "order by desc" clause for a pivot table column.
     */
    public function orderByPivotDesc(string|Expression $column): static
    {
        return $this->orderBy($this->qualifyPivotColumn($column), 'desc');
    }

    /**
     * Find a related model by its primary key or return a new instance of the related model.
     *
     * @return (
     *     $id is (Arrayable<array-key, mixed>|array<mixed>)
     *     ? Collection<int, TRelatedModel&object{pivot: TPivotModel}>
     *     : TRelatedModel&object{pivot: TPivotModel}
     * )
     */
    public function findOrNew(mixed $id, array $columns = ['*'])
    {
        if (null === ($instance = $this->find($id, $columns))) {
            $instance = $this->related->newInstance();
        }

        return $instance;
    }

    /**
     * Get the first related model record matching the attributes or instantiate it.
     * 
     * @return TRelatedModel&object{pivot: TPivotModel}
     */
    public function firstOrNew(array $attributes, array $values = []): Model
    {
        if (null === ($instance = $this->related->where($attributes)->first())) {
            $instance = $this->related->newInstance(array_merge($attributes, $values));
        }

        return $instance;
    }

    /**
     * Get the first related record matching the attributes or create it.
     *
     * @param  (Closure(): array)|array  $values
     * 
     * @return TRelatedModel&object{pivot: TPivotModel}
     */
    public function firstOrCreate(array $attributes = [], array|Closure $values = [], array $joining = [], bool $touch = true): Model
    {
        if (null === ($instance = (clone $this)->where($attributes)->first())) {
            if (null === ($instance = $this->related->where($attributes)->first())) {
                $instance = $this->createOrFirst($attributes, $values, $joining, $touch);
            } else {
                try {
                    $this->getQuery()->withSavepointIfNeeded(fn () => $this->attach($instance, $joining, $touch));
                } catch (UniqueConstraintViolationException) {
                    // Nothing to do, the model was already attached...
                }
            }
        }

        return $instance;
    }

    /**
     * Attempt to create the record. If a unique constraint violation occurs, attempt to find the matching record.
     *
     * @param  (Closure(): array)|array  $values
     * 
     * @return TRelatedModel&object{pivot: TPivotModel}
     */
    public function createOrFirst(array $attributes = [], array|Closure $values = [], array $joining = [], bool $touch = true): Model
    {
        try {
            return $this->getQuery()->withSavePointIfNeeded(fn () => $this->create(array_merge($attributes, Helpers::value($values)), $joining, $touch));
        } catch (UniqueConstraintViolationException $e) {
            // ...
        }

        try {
            return Helpers::tap($this->related->where($attributes)->first() ?? throw $e, function ($instance) use ($joining, $touch) {
                $this->getQuery()->withSavepointIfNeeded(fn () => $this->attach($instance, $joining, $touch));
            });
        } catch (UniqueConstraintViolationException $e) {
            return (clone $this)->where($attributes)->first() ?? throw $e;
        }
    }

    /**
     * Create or update a related record matching the attributes, and fill it with values.
     * 
     * @return TRelatedModel&object{pivot: TPivotModel}
     */
    public function updateOrCreate(array $attributes, array $values = [], array $joining = [], bool $touch = true): Model
    {
        return Helpers::tap($this->firstOrCreate($attributes, $values, $joining, $touch), static function ($instance) use ($values) {
            if (! $instance->wasRecentlyCreated) {
                $instance->fill($values);

                $instance->save(['touch' => false]);
            }
        });
    }

    /**
     * Find a related model by its primary key.
     *
     * @return (
     *     $id is (Arrayable<array-key, mixed>|array<mixed>)
     *     ? Collection<int, TRelatedModel&object{pivot: TPivotModel}>
     *     : (TRelatedModel&object{pivot: TPivotModel})|null
     * )
     */
    public function find(mixed $id, array $columns = ['*'])
    {
        if (! $id instanceof Model && (is_array($id) || $id instanceof Arrayable)) {
            return $this->findMany($id, $columns);
        }

        return $this->where(
            $this->getRelated()->getQualifiedKeyName(),
            '=',
            $this->parseId($id)
        )->first($columns);
    }

    /**
     * Find a sole related model by its primary key.
     *
     * @return TRelatedModel&object{pivot: TPivotModel}
     *
     * @throws ModelNotFoundException<TRelatedModel>
     * @throws MultipleRecordsFoundException
     */
    public function findSole(mixed $id, array $columns = ['*'])
    {
        return $this->where(
            $this->getRelated()->getQualifiedKeyName(), 
            '=', 
            $this->parseId($id)
        )->sole($columns);
    }

    /**
     * Find multiple related models by their primary keys.
     *
     * @return Collection<int, TRelatedModel&object{pivot: TPivotModel}>
     */
    public function findMany(array|Arrayable $ids, array $columns = ['*']): Collection
    {
        $ids = $ids instanceof Arrayable ? $ids->toArray() : $ids;

        if ($ids === []) {
            return $this->getRelated()->newCollection();
        }

        return $this->whereKey(
            $this->parseIds($ids)
        )->get($columns);
    }

    /**
     * Find a related model by its primary key or throw an exception.
     *
     * @return (
     *     $id is (Arrayable<array-key, mixed>|array<mixed>)
     *     ? Collection<int, TRelatedModel&object{pivot: TPivotModel}>
     *     : TRelatedModel&object{pivot: TPivotModel}
     * )
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

        throw (new ModelNotFoundException())->setModel(get_class($this->related), $id);
    }

    /**
     * Find a related model by its primary key or call a callback.
     *
     * @template TValue
     *
     * @param  (Closure(): TValue)|list<string>|string  $columns
     * @param  (Closure(): TValue)|null  $callback
     * 
     * @return (
     *     $id is (Arrayable<array-key, mixed>|array<mixed>)
     *     ? Collection<int, TRelatedModel&object{pivot: TPivotModel}>|TValue
     *     : (TRelatedModel&object{pivot: TPivotModel})|TValue
     * )
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
     * Add a basic where clause to the query, and return the first result.
     *
     * @param array|Closure|string $column
     *
     * @return (TRelatedModel&object{pivot: TPivotModel})|null
     */
    public function firstWhere($column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
    {
        return $this->where($column, $operator, $value, $boolean)->first();
    }

    /**
     * Execute the query and get the first result.
     * 
     * @return (TRelatedModel&object{pivot: TPivotModel})|null
     */
    public function first(array $columns = ['*'])
    {
        $results = $this->limit(1)->get($columns);

        return count($results) > 0 ? $results->first() : null;
    }

    /**
     * Execute the query and get the first result or throw an exception.
     *
     * @return TRelatedModel&object{pivot: TPivotModel}
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
     * @template TValue
     *
     * @param  (Closure(): TValue)|list<string>  $columns
     * @param  (Closure(): TValue)|null  $callback
     * 
     * @return (TRelatedModel&object{pivot: TPivotModel})|TValue
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
     * Get the results of the relationship.
     */
    public function getResults(): mixed
    {
        return null !== $this->parent->{$this->parentKey}
                ? $this->get()
                : $this->related->newCollection();
    }

    /**
     * Execute the query as a "select" statement.
     */
    public function get(array $columns = ['*']): Collection
    {
        // First we'll add the proper select columns onto the query so it is run with
        // the proper columns. Then, we will get the results and hydrate out pivot
        // models with the result of those columns as a separate model relation.
        $builder = $this->query->applyScopes();

        $columns = $builder->getQuery()->columns !== [] ? [] : $columns;

        $models = $builder->select(
            $this->shouldSelect($columns)
        )->getModels();

        $this->hydratePivotRelation($models);

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
     * Get the select columns for the relation query.
     */
    protected function shouldSelect(array $columns = ['*']): array
    {
        if ($columns === ['*']) {
            $columns = [$this->related->qualifyColumn('*')];
        }

        return array_merge($columns, $this->aliasedPivotColumns());
    }

    /**
     * Get the pivot columns for the relation.
     *
     * "pivot_" is prefixed ot each column for easy removal later.
     */
    protected function aliasedPivotColumns(): array
    {
        return (new IterableCollection([
            $this->foreignPivotKey, 
            $this->relatedPivotKey,
            ...$this->pivotColumns,
        ]))
        ->map(fn($column) => $this->qualifyPivotColumn($column) . ' as pivot_' . $column)
        ->unique()
        ->all();
    }

    /**
     * Get a paginator for the "select" statement.
     *
     * @return LengthAwarePaginator<int, TRelatedModel&object{pivot: TPivotModel}>
     */
    public function paginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        $this->query->select($this->shouldSelect($columns));

        return Helpers::tap($this->query->paginate($perPage, $columns, $pageName, $page), function ($paginator) {
            $this->hydratePivotRelation($paginator->items());
        });
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @return Paginator<int, TRelatedModel&object{pivot: TPivotModel}>
     */
    public function simplePaginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): Paginator
    {
        $this->query->select($this->shouldSelect($columns));

        return Helpers::tap($this->query->simplePaginate($perPage, $columns, $pageName, $page), function ($paginator) {
            $this->hydratePivotRelation($paginator->items());
        });
    }

    /**
     * Paginate the given query into a cursor paginator.
     *
     * @return CursorPaginator<int, TRelatedModel&object{pivot: TPivotModel}>
     */
    public function cursorPaginate(?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', ?string $cursor = null): CursorPaginator
    {
        $this->query->select($this->shouldSelect($columns));

        return Helpers::tap($this->query->cursorPaginate($perPage, $columns, $cursorName, $cursor), function ($paginator) {
            $this->hydratePivotRelation($paginator->items());
        });
    }

    /**
     * Chunk the results of the query.
     */
    public function chunk(int $count, callable $callback): bool
    {
        return $this->prepareQueryBuilder()->chunk($count, function ($results, $page) use ($callback) {
            $this->hydratePivotRelation($results->all());

            return $callback($results, $page);
        });
    }

    /**
     * Chunk the results of a query by comparing numeric IDs.
     */
    public function chunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        return $this->orderedChunkById($count, $callback, $column, $alias);
    }

    /**
     * Chunk the results of a query by comparing IDs in descending order.
     */
    public function chunkByIdDesc(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        return $this->orderedChunkById($count, $callback, $column, $alias, descending: true);
    }

    /**
     * Execute a callback over each item while chunking by ID.
     */
    public function eachById(callable $callback, int $count = 1000, ?string $column = null, ?string $alias = null): bool
    {
        return $this->chunkById($count, function ($results, $page) use ($callback, $count) {
            foreach ($results as $key => $value) {
                if ($callback($value, (($page - 1) * $count) + $key) === false) {
                    return false;
                }
            }

            return true;
        }, $column, $alias);
    }

    /**
     * Chunk the results of a query by comparing IDs in a given order.
     */
    public function orderedChunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null, bool $descending = false): bool
    {
        $column ??= $this->getRelated()->qualifyColumn(
            $this->getRelatedKeyName()
        );

        $alias ??= $this->getRelatedKeyName();

        return $this->prepareQueryBuilder()->orderedChunkById($count, function ($results, $page) use ($callback) {
            $this->hydratePivotRelation($results->all());

            return $callback($results, $page);
        }, $column, $alias, $descending);
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
     * @return LazyCollection<int, TRelatedModel&object{pivot: TPivotModel}>
     */
    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        return $this->prepareQueryBuilder()->lazy($chunkSize)->map(function ($model) {
            $this->hydratePivotRelation([$model]);

            return $model;
        });
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs.
     * 
     * @return LazyCollection<int, TRelatedModel&object{pivot: TPivotModel}>
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return $this->orderedLazyById($chunkSize, $column, $alias);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs in descending order.
     *
     * @return LazyCollection<int, TRelatedModel&object{pivot: TPivotModel}>
     */
    public function lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return $this->orderedLazyById($chunkSize, $column, $alias, true);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs in a given order.
     */
    public function orderedLazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null, bool $descending = false): LazyCollection
    {
        $column ??= $this->getRelated()->qualifyColumn(
            $this->getRelatedKeyName()
        );

        $alias ??= $this->getRelatedKeyName();

        return $this->prepareQueryBuilder()->orderedLazyById($chunkSize, $column, $alias, $descending)->map(function ($model) {
            $this->hydratePivotRelation([$model]);

            return $model;
        });
    }

    /**
     * Get a lazy collection for the given query.
     * 
     * @return LazyCollection<int, TRelatedModel&object{pivot: TPivotModel}>
     */
    public function cursor(): LazyCollection
    {
        return $this->prepareQueryBuilder()->cursor()->map(function ($model) {
            $this->hydratePivotRelation([$model]);

            return $model;
        });
    }

    /**
     * Prepare the query builder for query execution.
     *
     * @return Builder<TRelatedModel>
     */
    protected function prepareQueryBuilder(): Builder
    {
        return $this->query->select($this->shouldSelect());
    }

    /**
     * Hydrate the pivot table relationship on the models.
     * 
     * @param  array<int, TRelatedModel>  $models
     */
    protected function hydratePivotRelation(array $models): void
    {
        // To hydrate the pivot relationship, we will just gather the pivot attributes
        // and create a new Pivot model, which is basically a dynamic model that we
        // will set the attributes, table, and connections on it so it will work.
        foreach ($models as $model) {
            $model->setRelation($this->accessor, $this->newExistingPivot(
                $this->migratePivotAttributes($model)
            ));
        }
    }

    /**
     * Get the pivot attributes from a model.
     *
     * @param  TRelatedModel  $model
     */
    protected function migratePivotAttributes(Model $model): array
    {
        $values = [];

        foreach ($model->getAttributes() as $key => $value) {
            // To get the pivots attributes we will just take any of the attributes which
            // begin with "pivot_" and add those to this arrays, as well as unsetting
            // them from the parent's models since they exist in a different table.
            if (str_starts_with($key, 'pivot_')) {
                $values[substr($key, 6)] = $value;

                unset($model->{$key});
            }
        }

        return $values;
    }

    /**
     * If we're touching the parent model, touch.
     */
    public function touchIfTouching(): void
    {
        if ($this->touchingParent()) {
            $this->getParent()->touch();
        }

        if ($this->getParent()->touches($this->relationName)) {
            $this->touch();
        }
    }

    /**
     * Determine if we should touch the parent on sync.
     */
    protected function touchingParent(): bool
    {
        return $this->getRelated()->touches($this->guessInverseRelation());
    }

    /**
     * Attempt to guess the name of the inverse of the relation.
     */
    protected function guessInverseRelation(): string
    {
        return Text::camel(Text::pluralStudly(Helpers::classBasename($this->getParent())));
    }

    /**
     * {@inheritDoc}
     */
    public function touch(): void
    {
        if ($this->related->isIgnoringTouch()) {
            return;
        }

        $columns = [
            $this->related->getUpdatedAtColumn() => $this->related->freshTimestampString(),
        ];

        // If we actually have IDs for the relation, we will run the query to update all
        // the related model's timestamps, to make sure these all reflect the changes
        // to the parent models. This will help us keep any caching synced up here.
        if (count($ids = $this->allRelatedIds()) > 0) {
            $this->getRelated()->newQueryWithoutRelationships()->whereKey($ids)->update($columns);
        }
    }

    /**
     * Get all of the IDs for the related models.
     *
     * @return IterableCollection<int, int|string>
     */
    public function allRelatedIds(): IterableCollection
    {
        return new IterableCollection($this->newPivotQuery()->value($this->relatedPivotKey));
    }

    /**
     * Save a new model and attach it to the parent model.
     *
     * @param  TRelatedModel  $model
     * 
     * @return TRelatedModel&object{pivot: TPivotModel}
     */
    public function save(Model $model, array $pivotAttributes = [], bool $touch = true): Model
    {
        $model->save(['touch' => false]);

        $this->attach($model, $pivotAttributes, $touch);

        return $model;
    }

    /**
     * Save a new model without raising any events and attach it to the parent model.
     *
     * @param  TRelatedModel  $model
     * 
     * @return TRelatedModel&object{pivot: TPivotModel}
     */
    public function saveQuietly(Model $model, array $pivotAttributes = [], bool $touch = true): Model
    {
        return Model::withoutEvents(fn () => $this->save($model, $pivotAttributes, $touch));
    }

    /**
     * Save an array of new models and attach them to the parent model.
     *
     * @template TContainer of Collection<array-key, TRelatedModel>|array<array-key, TRelatedModel>
     *
     * @param  TContainer  $models
     * 
     * @return TContainer
     */
    public function saveMany(array|Collection $models, array $pivotAttributes = [])
    {
        foreach ($models as $key => $model) {
            $this->save($model, (array) ($pivotAttributes[$key] ?? []), false);
        }

        $this->touchIfTouching();

        return $models;
    }

    /**
     * Save an array of new models without raising any events and attach them to the parent model.
     *
     * @template TContainer of Collection<array-key, TRelatedModel>|array<array-key, TRelatedModel>
     *
     * @param  TContainer  $models
     * 
     * @return TContainer
     */
    public function saveManyQuietly(array|Collection $models, array $pivotAttributes = [])
    {
        return Model::withoutEvents(fn () => $this->saveMany($models, $pivotAttributes));
    }

    /**
     * Create a new instance of the related model.
     * 
     * @return TRelatedModel&object{pivot: TPivotModel}
     */
    public function create(array $attributes = [], array $joining = [], bool $touch = true): Model
    {
        $attributes = array_merge($this->getQuery()->pendingAttributes, $attributes);

        $instance = $this->related->newInstance($attributes);

        // Once we save the related model, we need to attach it to the base model via
        // through intermediate table so we'll use the existing "attach" method to
        // accomplish this which will insert the record and any more attributes.
        $instance->save(['touch' => false]);

        $this->attach($instance, $joining, $touch);

        return $instance;
    }

    /**
     * Create an array of new instances of the related models.
     *
     * @return list<TRelatedModel&object{pivot: TPivotModel}>
     */
    public function createMany(iterable $records, array $joinings = []): array
    {
        $instances = [];

        foreach ($records as $key => $record) {
            $instances[] = $this->create($record, (array) ($joinings[$key] ?? []), false);
        }

        $this->touchIfTouching();

        return $instances;
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param array|mixed $columns
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($parentQuery->getQuery()->from === $query->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfJoin($query, $parentQuery, $columns);
        }

        $this->performJoin($query);

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * 
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQueryForSelfJoin(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $query->select($columns);

        $query->from($this->related->getTable() . ' as ' . $hash = $this->getRelationCountHash());

        $this->related->setTable($hash);

        $this->performJoin($query);

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Get the key for comparing against the parent key in "has" query.
     */
    public function getExistenceCompareKey(): string
    {
        return $this->getQualifiedForeignPivotKeyName();
    }

    /**
     * Specify that the pivot table has creation and update timestamps.
     */
    public function withTimestamps(string|null|false $createdAt = null, string|null|false $updatedAt = null): static
    {
         $this->pivotCreatedAt = $createdAt !== false ? $createdAt : null;
        $this->pivotUpdatedAt = $updatedAt !== false ? $updatedAt : null;

        $pivots = array_filter([
            $createdAt !== false ? $this->createdAt() : null,
            $updatedAt !== false ? $this->updatedAt() : null,
        ]);

        $this->withTimestamps = $pivots !== [];

        return $this->withTimestamps ? $this->withPivot($pivots) : $this;
    }

    /**
     * Get the name of the "created at" column.
     */
    public function createdAt(): string
    {
        return $this->pivotCreatedAt ?? $this->parent->getCreatedAtColumn() ?? Model::CREATED_AT;
    }

    /**
     * Get the name of the "updated at" column.
     */
    public function updatedAt(): string
    {
        return $this->pivotUpdatedAt ?? $this->parent->getUpdatedAtColumn() ?? Model::UPDATED_AT;
    }

    /**
     * Get the foreign key for the relation.
     */
    public function getForeignPivotKeyName(): string
    {
        return $this->foreignPivotKey;
    }

    /**
     * Get the fully qualified foreign key for the relation.
     */
    public function getQualifiedForeignPivotKeyName(): string
    {
        return $this->qualifyPivotColumn($this->foreignPivotKey);
    }

    /**
     * Get the "related key" for the relation.
     */
    public function getRelatedPivotKeyName(): string
    {
        return $this->relatedPivotKey;
    }

    /**
     * Get the fully qualified "related key" for the relation.
     */
    public function getQualifiedRelatedPivotKeyName(): string
    {
        return $this->qualifyPivotColumn($this->relatedPivotKey);
    }

    /**
     * Get the parent key for the relationship.
     */
    public function getParentKeyName(): string
    {
        return $this->parentKey;
    }

    /**
     * Get the fully qualified parent key name for the relation.
     */
    public function getQualifiedParentKeyName(): string
    {
        return $this->parent->qualifyColumn($this->parentKey);
    }

    /**
     * Get the related key for the relationship.
     */
    public function getRelatedKeyName(): string
    {
        return $this->relatedKey;
    }

    /**
     * Get the fully qualified related key name for the relation.
     */
    public function getQualifiedRelatedKeyName(): string
    {
        return $this->related->qualifyColumn($this->relatedKey);
    }

    /**
     * Get the intermediate table for the relationship.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Get the relationship name for the relationship.
     */
    public function getRelationName(): string
    {
        return $this->relationName;
    }

    /**
     * Get the name of the pivot accessor for this relationship.
     */
    public function getPivotAccessor(): string
    {
        return $this->accessor;
    }

    /**
     * Get the pivot columns for this relationship.
     */
    public function getPivotColumns(): array
    {
        return $this->pivotColumns;
    }

    /**
     * Qualify the given column name by the pivot table.
     */
    public function qualifyPivotColumn(string|Expression $column): string|Expression
    {
        if ($column instanceof Expression) {
            return $column;
        }

        return str_contains($column, '.')
            ? $column
            : $this->table . '.' . $column;
    }
}
