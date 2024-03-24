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
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Utilities\Iterable\LazyCollection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Utilities\Support\Invader;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\AsPivot;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithDictionary;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithPivotTable;
use Closure;
use InvalidArgumentException;

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
     *
     * @var string
     */
    protected $pivotCreatedAt;

    /**
     * The custom pivot table column for the updated_at timestamp.
     *
     * @var string
     */
    protected $pivotUpdatedAt;

    /**
     * The class name of the custom pivot model to use for the relationship.
     *
     * @var string
     */
    protected $using;

    /**
     * The name of the accessor to use for the "pivot" relationship.
     */
    protected string $accessor = 'pivot';

    /**
     * Create a new belongs to many relationship instance.
     *
     * @param string  $foreignPivotKey The foreign key of the parent model.
     * @param string  $relatedPivotKey The associated key of the relation.
     * @param string  $parentKey       The key name of the parent model.
     * @param string  $relatedKey      The key name of the related model.
     * @param ?string $relationName    The "name" of the relationship.
     *
     * @return void
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
     */
    protected function performJoin(?Builder $query = null): self
    {
        $query = $query ?: $this->query;

        // We need to join to the intermediate table on the related model's primary
        // key column with the intermediate table's foreign key for the related
        // model instance. Then we can set the "where" for the parent models.
        $query->join(
            $this->table,
            [$this->getQualifiedRelatedKeyName() => $this->getQualifiedRelatedPivotKeyName()]
        );

        return $this;
    }

    /**
     * Set the where clause for the relation query.
     */
    protected function addWhereConstraints(): self
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
     * @param Model[] $models
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
     * @param Model[] $models
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
     */
    protected function buildDictionary(Collection $results): array
    {
        // First we will build a dictionary of child models keyed by the foreign key
        // of the relation so that we will easily and quickly match them to their
        // parents without having a possibly slow inner loops for every models.
        $dictionary = [];

        foreach ($results as $result) {
            $value = $this->getDictionaryKey($result->{$this->accessor}->{$this->foreignPivotKey});

            $dictionary[$value][] = $result;
        }

        return $dictionary;
    }

    /**
     * Get the class being used for pivot models.
     */
    public function getPivotClass(): string
    {
        return $this->using ?? Pivot::class;
    }

    /**
     * Specify the custom pivot model to use for the relationship.
     */
    public function using(string $class): self
    {
        $this->using = $class;

        return $this;
    }

    /**
     * Specify the custom pivot accessor to use for the relationship.
     */
    public function as(string $accessor): self
    {
        $this->accessor = $accessor;

        return $this;
    }

    /**
     * Set a where clause for a pivot table column.
     */
    public function wherePivot(string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): self
    {
        $this->pivotWheres[] = func_get_args();

        return $this->where($this->qualifyPivotColumn($column), $operator, $value, $boolean);
    }

    /**
     * Set a "where between" clause for a pivot table column.
     */
    public function wherePivotBetween(string $column, array $values, string $boolean = 'and', bool $not = false): self
    {
        return $this->whereBetween($this->qualifyPivotColumn($column), $values, $boolean, $not);
    }

    /**
     * Set a "or where between" clause for a pivot table column.
     */
    public function orWherePivotBetween(string $column, array $values): self
    {
        return $this->wherePivotBetween($column, $values, 'or');
    }

    /**
     * Set a "where pivot not between" clause for a pivot table column.
     */
    public function wherePivotNotBetween(string $column, array $values, string $boolean = 'and'): self
    {
        return $this->wherePivotBetween($column, $values, $boolean, true);
    }

    /**
     * Set a "or where not between" clause for a pivot table column.
     */
    public function orWherePivotNotBetween(string $column, array $values): self
    {
        return $this->wherePivotBetween($column, $values, 'or', true);
    }

    /**
     * Set a "where in" clause for a pivot table column.
     */
    public function wherePivotIn(string $column, mixed $values, string $boolean = 'and', bool $not = false): self
    {
        $this->pivotWhereIns[] = func_get_args();

        return $this->whereIn($this->qualifyPivotColumn($column), $values, $boolean, $not);
    }

    /**
     * Set an "or where" clause for a pivot table column.
     */
    public function orWherePivot(string $column, mixed $operator = null, mixed $value = null): self
    {
        return $this->wherePivot($column, $operator, $value, 'or');
    }

    /**
     * Set a where clause for a pivot table column.
     *
     * In addition, new pivot records will receive this value.
     *
     * @throws InvalidArgumentException
     */
    public function withPivotValue(array|string $column, mixed $value = null): self
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
    public function orWherePivotIn(string $column, mixed $values): self
    {
        return $this->wherePivotIn($column, $values, 'or');
    }

    /**
     * Set a "where not in" clause for a pivot table column.
     */
    public function wherePivotNotIn(string $column, mixed $values, string $boolean = 'and'): self
    {
        return $this->wherePivotIn($column, $values, $boolean, true);
    }

    /**
     * Set an "or where not in" clause for a pivot table column.
     */
    public function orWherePivotNotIn(string $column, mixed $values): self
    {
        return $this->wherePivotNotIn($column, $values, 'or');
    }

    /**
     * Set a "where null" clause for a pivot table column.
     */
    public function wherePivotNull(string $column, string $boolean = 'and', bool $not = false): self
    {
        $this->pivotWhereNulls[] = func_get_args();

        return $this->whereNull($this->qualifyPivotColumn($column), $boolean, $not);
    }

    /**
     * Set a "where not null" clause for a pivot table column.
     */
    public function wherePivotNotNull(string $column, string $boolean = 'and'): self
    {
        return $this->wherePivotNull($column, $boolean, true);
    }

    /**
     * Set a "or where null" clause for a pivot table column.
     */
    public function orWherePivotNull(string $column, bool $not = false): self
    {
        return $this->wherePivotNull($column, 'or', $not);
    }

    /**
     * Set a "or where not null" clause for a pivot table column.
     */
    public function orWherePivotNotNull(string $column): self
    {
        return $this->orWherePivotNull($column, true);
    }

    /**
     * Add an "order by" clause for a pivot table column.
     */
    public function orderByPivot(string $column, string $direction = 'asc'): self
    {
        return $this->orderBy($this->qualifyPivotColumn($column), $direction);
    }

    /**
     * Find a related model by its primary key or return a new instance of the related model.
     *
     * @return Collection|Model
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
     */
    public function firstOrCreate(array $attributes = [], array $values = [], array $joining = [], bool $touch = true): Model
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
     */
    public function createOrFirst(array $attributes = [], array $values = [], array $joining = [], bool $touch = true): Model
    {
        try {
            return $this->getQuery()->withSavePointIfNeeded(fn () => $this->create(array_merge($attributes, $values), $joining, $touch));
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
     * @return Collection<Model>|Model|null
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
     * Find multiple related models by their primary keys.
     *
     * @return Collection<Model>
     */
    public function findMany(array|Arrayable $ids, array $columns = ['*']): Collection
    {
        $ids = $ids instanceof Arrayable ? $ids->toArray() : $ids;

        if (empty($ids)) {
            return $this->getRelated()->newCollection();
        }

        return $this->whereKey(
            $this->parseIds($ids)
        )->get($columns);
    }

    /**
     * Find a related model by its primary key or throw an exception.
     *
     * @return Collection<Model>|Model
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
     * @return Collection<Model>|mixed|Model
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
     * @return Model|static
     */
    public function firstWhere($column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
    {
        return $this->where($column, $operator, $value, $boolean)->first();
    }

    /**
     * Execute the query and get the first result.
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

        $fields  = Invader::make($builder->getQuery())->fields;
        $columns = $fields !== [] ? [] : $columns;

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

        return $this->related->newCollection($models);
    }

    /**
     * Get the select columns for the relation query.
     */
    protected function shouldSelect(array $columns = ['*']): array
    {
        if ($columns === ['*']) {
            $columns = [$this->related->getTable() . '.*'];
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
        $defaults = [$this->foreignPivotKey, $this->relatedPivotKey];

        return Helpers::collect(array_merge($defaults, $this->pivotColumns))->map(fn ($column) => $this->qualifyPivotColumn($column) . ' as pivot_' . $column)->unique()->all();
    }

    /**
     * Get a paginator for the "select" statement.
     *
     * @return \BlitzPHP\Wolke\Contracts\LengthAwarePaginator
     */
    public function paginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null)
    {
        $this->query->select($this->shouldSelect($columns));

        return Helpers::tap($this->query->paginate($perPage, $columns, $pageName, $page), function ($paginator) {
            $this->hydratePivotRelation($paginator->items());
        });
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @return \BlitzPHP\Wolke\Contracts\Paginator
     */
    public function simplePaginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null)
    {
        $this->query->select($this->shouldSelect($columns));

        return Helpers::tap($this->query->simplePaginate($perPage, $columns, $pageName, $page), function ($paginator) {
            $this->hydratePivotRelation($paginator->items());
        });
    }

    /**
     * Paginate the given query into a cursor paginator.
     *
     * @return \BlitzPHP\Wolke\Contracts\CursorPaginator
     */
    public function cursorPaginate(?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', ?string $cursor = null)
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
        $this->prepareQueryBuilder();

        $column ??= $this->getRelated()->qualifyColumn(
            $this->getRelatedKeyName()
        );

        $alias ??= $this->getRelatedKeyName();

        return $this->query->chunkById($count, function ($results) use ($callback) {
            $this->hydratePivotRelation($results->all());

            return $callback($results);
        }, $column, $alias);
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
        return $this->prepareQueryBuilder()->lazy($chunkSize)->map(function ($model) {
            $this->hydratePivotRelation([$model]);

            return $model;
        });
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs.
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        $column ??= $this->getRelated()->qualifyColumn(
            $this->getRelatedKeyName()
        );

        $alias ??= $this->getRelatedKeyName();

        return $this->prepareQueryBuilder()->lazyById($chunkSize, $column, $alias)->map(function ($model) {
            $this->hydratePivotRelation([$model]);

            return $model;
        });
    }

    /**
     * Get a lazy collection for the given query.
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
     */
    protected function prepareQueryBuilder(): Builder
    {
        return $this->query->select($this->shouldSelect());
    }

    /**
     * Hydrate the pivot table relationship on the models.
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
     */
    public function allRelatedIds(): IterableCollection
    {
        return Helpers::collect($this->newPivotQuery()->value($this->relatedPivotKey));
    }

    /**
     * Save a new model and attach it to the parent model.
     */
    public function save(Model $model, array $pivotAttributes = [], bool $touch = true): Model
    {
        $model->save(['touch' => false]);

        $this->attach($model, $pivotAttributes, $touch);

        return $model;
    }

    /**
     * Save a new model without raising any events and attach it to the parent model.
     */
    public function saveQuietly(Model $model, array $pivotAttributes = [], bool $touch = true): Model
    {
        return Model::withoutEvents(fn () => $this->save($model, $pivotAttributes, $touch));
    }

    /**
     * Save an array of new models and attach them to the parent model.
     *
     * @param Collection<Model>|Model[] $models
     *
     * @return Collection<Model>|Model[]
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
     * @param Collection<Model>|Model[] $models
     *
     * @return Collection<Model>|Model[]
     */
    public function saveManyQuietly(array|Collection $models, array $pivotAttributes = [])
    {
        return Model::withoutEvents(fn () => $this->saveMany($models, $pivotAttributes));
    }

    /**
     * Create a new instance of the related model.
     */
    public function create(array $attributes = [], array $joining = [], bool $touch = true): Model
    {
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
     * @return Model[]
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
        if (Invader::make($parentQuery->getQuery())->table === Invader::make($query->getQuery())->table) {
            return $this->getRelationExistenceQueryForSelfJoin($query, $parentQuery, $columns);
        }

        $this->performJoin($query);

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param array|mixed $columns
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
    public function withTimestamps(?string $createdAt = null, ?string $updatedAt = null): self
    {
        $this->withTimestamps = true;

        $this->pivotCreatedAt = $createdAt;
        $this->pivotUpdatedAt = $updatedAt;

        return $this->withPivot($this->createdAt(), $this->updatedAt());
    }

    /**
     * Get the name of the "created at" column.
     */
    public function createdAt(): string
    {
        return $this->pivotCreatedAt ?: $this->parent->getCreatedAtColumn();
    }

    /**
     * Get the name of the "updated at" column.
     */
    public function updatedAt(): string
    {
        return $this->pivotUpdatedAt ?: $this->parent->getUpdatedAtColumn();
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
    public function qualifyPivotColumn(string $column): string
    {
        return str_contains($column, '.')
            ? $column
            : $this->table . '.' . $column;
    }
}
