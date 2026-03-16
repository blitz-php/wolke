<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke;

use BadMethodCallException;
use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Database\Exceptions\MultipleRecordsFoundException;
use BlitzPHP\Database\Exceptions\RecordsNotFoundException;
use BlitzPHP\Database\Exceptions\UniqueConstraintViolationException;
use BlitzPHP\Database\Query\Expression;
use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Invade\Invader;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Utilities\Iterable\LazyCollection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Concerns\BuildsQueries;
use BlitzPHP\Wolke\Concerns\QueriesRelationships;
use BlitzPHP\Wolke\Contracts\Scope;
use BlitzPHP\Wolke\Exceptions\CursorPaginationException;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Exceptions\RelationNotFoundException;
use BlitzPHP\Wolke\Pagination\Cursor;
use BlitzPHP\Wolke\Pagination\LengthAwarePaginator;
use BlitzPHP\Wolke\Pagination\Paginator;
use BlitzPHP\Wolke\Relations\BelongsToMany;
use BlitzPHP\Wolke\Relations\Relation;
use Closure;
use Exception;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

/**
 * @template TModel of Model
 *
 * @property-read HigherOrderBuilderProxy|$this $orWhere
 * @property-read HigherOrderBuilderProxy|$this $whereNot
 * @property-read HigherOrderBuilderProxy|$this $orWhereNot
 *
 * @mixin BaseBuilder
 */
class Builder
{
    use BuildsQueries, ForwardsCalls, QueriesRelationships {
        BuildsQueries::sole as baseSole;
    }

    /**
     * The model being queried.
     *
     * @var TModel
     */
    protected $model;

    /**
     * The attributes that should be added to new models created by this builder.
     */
    public array $pendingAttributes = [];

    /**
     * The relationships that should be eager loaded.
     */
    protected array $eagerLoad = [];

    /**
     * All of the globally registered builder macros.
     */
    protected static array $macros = [];

    /**
     * All of the locally registered builder macros.
     */
    protected array $localMacros = [];

    /**
     * A replacement for the typical delete function.
     *
     * @var Closure
     */
    protected $onDelete;

    /**
     * The properties that should be returned from query builder.
     *
     * @var list<string>
     */
    protected array $propertyPassthru = [
        'from',
    ];

    /**
     * The methods that should be returned from query builder.
     *
     * @var list<string>
     */
    protected array $passthru = [
        'aggregate',
        'average',
        'avg',
        'count',
        'db',
        'dd',
        'doesntExist',
        'doesntExistOr',
        'dump',
        'exists',
        'existsOr',
        'explain',
        'getBindings',
        'getConnection',
        'implode',
        'insert',
        'insertGetId',
        'insertOrIgnore',
        'insertUsing',
        'insertOrIgnoreUsing',
        'max',
        'min',
        'raw',
        'sum',
        'sql',
        'toSql',
        'toRawSql',
    ];

    /**
     * Applied global scopes.
     */
    protected array $scopes = [];

    /**
     * Removed global scopes.
     */
    protected array $removedScopes = [];

    /**
     * The callbacks that should be invoked after retrieving data from the database.
     */
    protected array $afterQueryCallbacks = [];

    /**
     * The callbacks that should be invoked on clone.
     * 
     * @var list<Closure(static): void>
     */
    protected array $onCloneCallbacks = [];

    /**
     * Create a new Orm query builder instance.
     *
     * @param BaseBuilder $query The base query builder instance.
     */
    public function __construct(protected BaseBuilder $query)
    {
    }

    /**
     * Create and return an un-saved model instance.
     *
     * @return TModel
     */
    public function make(array $attributes = [])
    {
        return $this->newModelInstance($attributes);
    }

    /**
     * Register a new global scope.
     */
    public function withGlobalScope(string $identifier, Closure|Scope $scope): static
    {
        $this->scopes[$identifier] = $scope;

        if (method_exists($scope, 'extend')) {
            $scope->{'extend'}($this);
        }

        return $this;
    }

    /**
     * Remove a registered global scope.
     */
    public function withoutGlobalScope(Scope|string $scope): static
    {
        if (! is_string($scope)) {
            $scope = get_class($scope);
        }

        unset($this->scopes[$scope]);

        $this->removedScopes[] = $scope;

        return $this;
    }

    /**
     * Remove all or passed registered global scopes.
     */
    public function withoutGlobalScopes(?array $scopes = null): static
    {
        if (! is_array($scopes)) {
            $scopes = array_keys($this->scopes);
        }

        foreach ($scopes as $scope) {
            $this->withoutGlobalScope($scope);
        }

        return $this;
    }

    /**
     * Remove all global scopes except the given scopes.
     */
    public function withoutGlobalScopesExcept(array $scopes = []): static
    {
        $this->withoutGlobalScopes(
            array_diff(array_keys($this->scopes), $scopes)
        );

        return $this;
    }

    /**
     * Get an array of global scopes that were removed from the query.
     */
    public function removedScopes(): array
    {
        return $this->removedScopes;
    }

    /**
     * Add a where clause on the primary key to the query.
     */
    public function whereKey(mixed $id): static
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        if (is_array($id) || $id instanceof Arrayable) {
            $this->query->whereIn($this->model->getQualifiedKeyName(), $id);

            return $this;
        }

        if ($id !== null && $this->model->getKeyType() === 'string') {
            $id = (string) $id;
        }

        return $this->where($this->model->getQualifiedKeyName(), '=', $id);
    }

    /**
     * Add a where clause on the primary key to the query.
     */
    public function whereKeyNot(mixed $id): static
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        if (is_array($id) || $id instanceof Arrayable) {
            $this->query->whereNotIn($this->model->getQualifiedKeyName(), $id);

            return $this;
        }

        if ($id !== null && $this->model->getKeyType() === 'string') {
            $id = (string) $id;
        }

        return $this->where($this->model->getQualifiedKeyName(), '!=', $id);
    }

    /**
     * Exclude the given models from the query results.
     *
     * @param  iterable|mixed  $models
     */
    public function except($models): static
    {
        return $this->whereKeyNot(
            $models instanceof Model
                ? $models->getKey()
                : Collection::wrap($models)->modelKeys()
        );
    }

    /**
     * Ajoute une clause "where" basique a la requete.
     * 
     * @param  (\Closure(static): mixed)|string|array|Expression  $column
     */
    public function where(array|Closure|Expression|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if ($column instanceof Closure && is_null($operator)) {
            $column($query = $this->model->newQueryWithoutRelationships());

            $this->eagerLoad = array_merge($this->eagerLoad, $query->getEagerLoads());

            $this->query->addNestedWhereQuery($query->getQuery(), $boolean);
        } else {
            $this->query->where(...func_get_args());
        }

        return $this;
    }

    /**
     * Add a basic where clause to the query, and return the first result.
     *
     * @param  (Closure(static): mixed)|string|array|Expression  $column
     * 
     * @return TModel|null
     */
    public function firstWhere(array|Closure|Expression|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
    {
        return $this->where(...func_get_args())->first();
    }

    /**
     * Add an "or where" clause to the query.
     * 
     * @param  (Closure(static): mixed)|array|string|Expression  $column
     */
    public function orWhere(array|Closure|Expression|string $column, Closure|string|null $operator = null, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value, $operator, func_num_args() === 2
        );

        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Add a basic "where not" clause to the query.
     * 
     * @param  (Closure(static): mixed)|array|string|Expression  $column
     */
    public function whereNot(array|Closure|Expression|string $column, mixed $value = null, string $boolean = 'and'): static
    {
        return $this->where($column, '!=', $value, $boolean);
    }

    /**
     * Add a basic "or where not" clause to the query.
     * 
     * @param  (Closure(static): mixed)|array|string|Expression  $column
     */
    public function orWhereNot(array|Closure|Expression|string $column, mixed $value = null): static
    {
        return $this->whereNot($column, $value, 'or');
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     */
    public function latest(Expression|string|null $column = null): static
    {
        if (null === $column) {
            $column = $this->model->getCreatedAtColumn() ?? 'created_at';
        }

        $this->query->latest($column);

        return $this;
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     */
    public function oldest(?string $column = null): static
    {
        if (null === $column) {
            $column = $this->model->getCreatedAtColumn() ?? 'created_at';
        }

        $this->query->oldest($column);

        return $this;
    }

    /**
     * Create a collection of models from plain arrays.
     * 
     * @return Collection<int, TModel>
     */
    public function hydrate(array $items): Collection
    {
        $instance = $this->newModelInstance();

        return $instance->newCollection(array_map(static function ($item) use ($items, $instance) {
            $model = $instance->newFromBuilder($item);

            if (count($items) > 1) {
                $model->preventsLazyLoading = Model::preventsLazyLoading();
            }

            return $model;
        }, $items));
    }

    /**
     * Insert into the database after merging the model's default attributes, setting timestamps, and casting values.
     *
     * @param  array<int, array<string, mixed>>  $values
     */
    public function fillAndInsert(array $values): bool
    {
        return $this->insert($this->fillForInsert($values));
    }

    /**
     * Insert (ignoring errors) into the database after merging the model's default attributes, setting timestamps, and casting values.
     *
     * @param  array<int, array<string, mixed>>  $values
     */
    public function fillAndInsertOrIgnore(array $values): int
    {
        return $this->insertOrIgnore($this->fillForInsert($values));
    }

    /**
     * Insert a record into the database and get its ID after merging the model's default attributes, setting timestamps, and casting values.
     *
     * @param  array<string, mixed>  $values
     */
    public function fillAndInsertGetId(array $values): int
    {
        return $this->insertGetId($this->fillForInsert([$values])[0]);
    }

    /**
     * Enrich the given values by merging in the model's default attributes, adding timestamps, and casting values.
     *
     * @param  array<int, array<string, mixed>>  $values
     * 
     * @return array<int, array<string, mixed>>
     */
    public function fillForInsert(array $values)
    {
        if (empty($values)) {
            return [];
        }

        if (! is_array(Arr::first($values))) {
            $values = [$values];
        }

        $this->model->unguarded(function () use (&$values) {
            foreach ($values as $key => $rowValues) {
                $values[$key] = Helpers::tap(
                    $this->newModelInstance($rowValues),
                    fn ($model) => $model->setUniqueIds()
                )->getAttributes();
            }
        });

        return $this->addTimestampsToUpsertValues($values);
    }

    /**
     * Create a collection of models from a raw query.
     * 
     * @return Collection<int, TModel>
     */
    public function fromQuery(string $query, array $bindings = []): Collection
    {
        return $this->hydrate(
            $this->query->db()->query($query, $bindings)->resultObject()
        );
    }

    /**
     * Find a model by its primary key.
     *
     * @return ($id is (Arrayable<array-key, mixed>|array<mixed>) ? Collection<int, TModel> : TModel|null)
     */
    public function find(mixed $id, array $columns = ['*'])
    {
        if (is_array($id) || $id instanceof Arrayable) {
            return $this->findMany($id, $columns);
        }

        return $this->whereKey($id)->first($columns);
    }

    /**
     * Find a sole model by its primary key.
     *
     * @return TModel
     *
     * @throws ModelNotFoundException<TModel>
     * @throws MultipleRecordsFoundException
     */
    public function findSole(mixed $id, array $columns = ['*'])
    {
        return $this->whereKey($id)->sole($columns);
    }

    /**
     * Find multiple models by their primary keys.
     * 
     * @return Collection<int, TModel>
     */
    public function findMany(array|Arrayable $ids, array $columns = ['*']): Collection
    {
        $ids = $ids instanceof Arrayable ? $ids->toArray() : $ids;

        if (empty($ids)) {
            return $this->model->newCollection();
        }

        return $this->whereKey($ids)->get($columns);
    }

    /**
     * Find a model by its primary key or throw an exception.
     *
     * @return ($id is (Arrayable<array-key, mixed>|array<mixed>) ? Collection<int, TModel> : TModel)
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(mixed $id, array $columns = ['*'])
    {
        $result = $this->find($id, $columns);

        $id = $id instanceof Arrayable ? $id->toArray() : $id;

        if (is_array($id)) {
            if (count($result) !== count(array_unique($id))) {
                throw (new ModelNotFoundException())->setModel(
                    get_class($this->model),
                    array_diff($id, $result->modelKeys())
                );
            }

            return $result;
        }

        if (null === $result) {
            throw (new ModelNotFoundException())->setModel(
                get_class($this->model),
                $id
            );
        }

        return $result;
    }

    /**
     * Find a model by its primary key or return fresh model instance.
     *
     * @return ($id is (Arrayable<array-key, mixed>|array<mixed>) ? Collection<int, TModel> : TModel)
     */
    public function findOrNew(mixed $id, array $columns = ['*'])
    {
        if (null !== ($model = $this->find($id, $columns))) {
            return $model;
        }

        return $this->newModelInstance();
    }

    /**
     * Find a model by its primary key or call a callback.
     *
     * @template TValue
     *
     * @param  (Closure(): TValue)|list<string>|string  $columns
     * @param  (Closure(): TValue)|null  $callback
     * 
     * @return (
     *     $id is (Arrayable<array-key, mixed>|array<mixed>)
     *     ? Collection<int, TModel>
     *     : TModel|TValue
     * )
     */
    public function findOr(mixed $id, array|Closure|string $columns = ['*'], ?Closure $callback = null)
    {
        if ($columns instanceof Closure) {
            $callback = $columns;

            $columns = ['*'];
        }

        if (null !== ($model = $this->find($id, $columns))) {
            return $model;
        }

        return $callback();
    }

    /**
     * Get the first record matching the attributes or instantiate it.
     *
     * @return TModel
     */
    public function firstOrNew(array $attributes = [], array $values = [])
    {
        if (null !== ($instance = $this->where($attributes)->first())) {
            return $instance;
        }

        return $this->newModelInstance(array_merge($attributes, $values));
    }

    /**
     * Get the first record matching the attributes or create it.
     *
     * @param  (Closure(): array)|array  $values
     * 
     * @return TModel
     */
    public function firstOrCreate(array $attributes = [], Closure|array $values = [])
    {
        if (null !== ($instance = (clone $this)->where($attributes)->first())) {
            return $instance;
        }

        return $this->createOrFirst($attributes, $values);
    }

    /**
     * Attempt to create the record. If a unique constraint violation occurs, attempt to find the matching record.
     *
     * @param  (Closure(): array)|array  $values
     * 
     * @return TModel
     */
    public function createOrFirst(array $attributes = [], Closure|array $values = [])
    {
        try {
            return $this->withSavepointIfNeeded(fn () => $this->create(array_merge($attributes, Helpers::value($values))));
        } catch (UniqueConstraintViolationException $e) {
            return $this->where($attributes)->first() ?? throw $e;
        }
    }

    /**
     * Create or update a record matching the attributes, and fill it with values.
     *
     * @return TModel
     */
    public function updateOrCreate(array $attributes, array $values = [])
    {
        return Helpers::tap($this->firstOrCreate($attributes, $values), static function ($instance) use ($values) {
            if (! $instance->wasRecentlyCreated) {
                $instance->fill($values)->save();
            }
        });
    }

    /**
     * Create a record matching the attributes, or increment the existing record.
     *
     * @return TModel
     */
    public function incrementOrCreate(array $attributes, string $column = 'count', float|int $default = 1, float|int $step = 1, array $extra = [])
    {
        return Helpers::tap($this->firstOrCreate($attributes, [$column => $default]), function ($instance) use ($column, $step, $extra) {
            if (! $instance->wasRecentlyCreated) {
                $instance->increment($column, $step, $extra);
            }
        });
    }

    /**
     * Execute the query and get the first result or throw an exception.
     *
     * @return TModel
     *
     * @throws ModelNotFoundException<TModel>
     */
    public function firstOrFail(array $columns = ['*'])
    {
        if (null !== ($model = $this->first($columns))) {
            return $model;
        }

        throw (new ModelNotFoundException())->setModel(get_class($this->model));
    }

    /**
     * Execute the query and get the first result or call a callback.
     *
     * @template TValue
     *
     * @param  (Closure(): TValue)|list<string>  $columns
     * @param  (Closure(): TValue)|null  $callback
     * 
     * @return TModel|TValue
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
     * Execute the query and get the first result if it's the sole matching record.
     *
     * @return TModel
     *
     * @throws ModelNotFoundException
     * @throws MultipleRecordsFoundException
     */
    public function sole(array|string $columns = ['*'])
    {
        try {
            return $this->baseSole($columns);
        } catch (RecordsNotFoundException) {
            throw (new ModelNotFoundException())->setModel(get_class($this->model));
        }
    }

    /**
     * Get a single column's value from the first result of a query.
     */
    public function value(string|Expression $column): mixed
    {
        if ($result = $this->first([$column])) {
            $column = $column instanceof Expression ? $column->getValue() : $column;

            return $result->{Text::afterLast($column, '.')};
        }

        return null;
    }

    /**
     * Get a single column's value from the first result of a query if it's the sole matching record.
     *
     * @throws ModelNotFoundException<TModel>
     * @throws MultipleRecordsFoundException
     */
    public function soleValue(string|Expression $column): mixed
    {
        $column = $column instanceof Expression ? $column->getValue() : $column;

        return $this->sole([$column])->{Text::afterLast($column, '.')};
    }

    /**
     * Get a single column's value from the first result of the query or throw an exception.
     *
     * @throws ModelNotFoundException<Model>
     */
    public function valueOrFail(string|Expression $column): mixed
    {
        $column = $column instanceof Expression ? $column->getValue() : $column;

        return $this->firstOrFail([$column])->{Text::afterLast($column, '.')};
    }

    /**
     * Execute the query as a "select" statement.
     * 
     * @return list<TModel>
     */
    public function all(array|string $columns = ['*']): array
    {
        return $this->get($columns)->all();
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @return Collection<int, TModel>
     */
    public function get(array|string $columns = ['*']): Collection
    {
        $builder = $this->applyScopes();

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded, which will solve the
        // n+1 query issue for the developers to avoid running a lot of queries.

        if (count($models = $builder->getModels($columns)) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->applyAfterQueryCallbacks(
            $builder->getModel()->newCollection($models)
        );
    }

    /**
     * Get the hydrated models without eager loading.
     *
     * @return list<TModel>
     */
    public function getModels(array|string $columns = []): array
    {
        return $this->model->hydrate(
            $this->query->from($this->model->getTable())->select($columns)->result()
        )->all();
    }

    /**
     * Eager load the relationships for the models.
     * 
     * @param  list<TModel>  $models
     * 
     * @return list<TModel>
     */
    public function eagerLoadRelations(array $models): array
    {
        foreach ($this->eagerLoad as $name => $constraints) {
            // For nested eager loads we'll skip loading them here and they will be set as an
            // eager load on the query to retrieve the relation so that they will be eager
            // loaded on that query, because that is where they get hydrated as models.
            if (! str_contains($name, '.')) {
                $models = $this->eagerLoadRelation($models, $name, $constraints);
            }
        }

        return $models;
    }

    /**
     * Eagerly load the relationship on a set of models.
     */
    protected function eagerLoadRelation(array $models, string $name, Closure $constraints): array
    {
        // First we will "back up" the existing where conditions on the query so we can
        // add our eager constraints. Then we will merge the wheres that were on the
        // query back to it in order that any where conditions might be specified.
        $relation = $this->getRelation($name);

        $relation->addEagerConstraints($models);

        $constraints($relation);

        // Once we have the results, we just match those back up to their parent models
        // using the relationship instance. Then we just return the finished arrays
        // of models which have been eagerly hydrated and are readied for return.
        return $relation->match(
            $relation->initRelation($models, $name),
            $relation->getEager(),
            $name
        );
    }

    /**
     * Get the relation instance for the given relation name.
     * 
     * @return Relation<Model, TModel, *>
     */
    public function getRelation(string $name): Relation
    {
        // We want to run a relationship query without any constrains so that we will
        // not have to remove these where clauses manually which gets really hacky
        // and error prone. We don't want constraints because we add eager ones.
        $relation = Relation::noConstraints(function () use ($name) {
            try {
                return $this->getModel()->newInstance()->{$name}();
            } catch (BadMethodCallException) {
                throw RelationNotFoundException::make($this->getModel(), $name);
            }
        });

        $nested = $this->relationsNestedUnder($name);

        // If there are nested relationships set on the query, we will put those onto
        // the query instances so that they can be handled after this relationship
        // is loaded. In this way they will all trickle down as they are loaded.
        if (count($nested) > 0) {
            $relation->getQuery()->with($nested);
        }

        return $relation;
    }

    /**
     * Get the deeply nested relations for a given top-level relation.
     */
    protected function relationsNestedUnder(string $relation): array
    {
        $nested = [];

        // We are basically looking for any relationships that are nested deeper than
        // the given top-level relationship. We will just check for any relations
        // that start with the given top relations and adds them to our arrays.
        foreach ($this->eagerLoad as $name => $constraints) {
            if ($this->isNestedUnder($relation, $name)) {
                $nested[substr($name, strlen($relation . '.'))] = $constraints;
            }
        }

        return $nested;
    }

    /**
     * Determine if the relationship is nested.
     */
    protected function isNestedUnder(string $relation, string $name): bool
    {
        return str_contains($name, '.') && str_starts_with($name, $relation . '.');
    }

    /**
     * Register a closure to be invoked after the query is executed.
     *
     * @param Closure(mixed): mixed  $callback
     */
    public function afterQuery(Closure $callback): static
    {
        $this->afterQueryCallbacks[] = $callback;

        return $this;
    }

    /**
     * Invoke the "after query" modification callbacks.
     */
    public function applyAfterQueryCallbacks(mixed $result): mixed
    {
        foreach ($this->afterQueryCallbacks as $afterQueryCallback) {
            $result = $afterQueryCallback($result) ?: $result;
        }

        return $result;
    }

    /**
     * Get a lazy collection for the given query.
     *
     * @return LazyCollection<int, TModel>
     */
    public function cursor(): LazyCollection
    {
        return $this->applyScopes()
            ->baseCursor()
            // ->query->cursor() // @TODO A voir si on met le cursor au niveau du query builder (ps: je ne suis pas d'accord)
            ->map(function ($record) {
                $model = $this->newModelInstance()->newFromBuilder($record);

                return $this->applyAfterQueryCallbacks($this->newModelInstance()->newCollection([$model]))->first();
            })
            ->reject(fn ($model) => is_null($model));
    }

    /**
     * Add a generic "order by" clause if the query doesn't already have one.
     */
    protected function enforceOrderBy(): void
    {
        if ($this->query->orders === []) {
            $this->orderBy($this->model->getQualifiedKeyName(), 'asc');
        }
    }

    /**
     * Get an array with the values of a given column.
     * 
     * @return IterableCollection<array-key, mixed>
     */
    public function pluck(string|Expression $column, ?string $key = null): IterableCollection
    {
        $column = $column instanceof Expression ? $column->getValue() : $column;

        $results = new IterableCollection($this->toBase()->values($column));

        $column = Text::after($column, "{$this->model->getTable()}.");

        // If the model has a mutator for the requested column, we will spin through
        // the results and mutate the values so that the mutated version of these
        // columns are returned as you would expect from these Wolke models.
        if (! $this->model->hasAnyGetMutator($column) &&
            ! $this->model->hasCast($column) &&
            ! in_array($column, $this->model->getDates())) {
            return $this->applyAfterQueryCallbacks($results);
        }

        return $this->applyAfterQueryCallbacks(
            $results->map(function ($value) use ($column) {
                return $this->model->newFromBuilder([$column => $value])->{$column};
            })
        );
    }

    /**
     * Paginate the given query.
     *
     * @throws InvalidArgumentException
     */
    public function paginate(Closure|int|null $perPage = null, array|string $columns = [], string $pageName = 'page', ?int $page = null, Closure|int|null $total = null): LengthAwarePaginator
    {
        $page    = $page ?: Paginator::resolveCurrentPage($pageName);
        $total   = Helpers::value($total) ?? (clone $this->toBase())->count();
        $perPage = Helpers::value($perPage, $total) ?: $this->model->getPerPage();

        $results = $total
            ? $this->forPage($page, $perPage)->get($columns)
            : $this->model->newCollection();

        return $this->paginator($results, $total, $perPage, $page, [
            'path'     => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Paginate the given query into a simple paginator.
     *
     * @return Contracts\Paginator
     */
    public function simplePaginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        // Next we will set the limit and offset for this query so that when we get the
        // results we get the proper section of results. Then, we'll create the full
        // paginator instances for these results with the given page and per page.

        $this->offset(($page - 1) * $perPage)->limit($perPage + 1);

        return $this->simplePaginator($this->get($columns), $perPage, $page, [
            'path'     => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Paginate the given query into a cursor paginator.
     *
     * @return Contracts\CursorPaginator
     *
     * @throws CursorPaginationException
     */
    public function cursorPaginate(?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', Cursor|string|null $cursor = null)
    {
        $perPage = $perPage ?: $this->model->getPerPage();

        return $this->paginateUsingCursor($perPage, $columns, $cursorName, $cursor);
    }

    /**
     * Ensure the proper order by required for cursor pagination.
     */
    protected function ensureOrderForCursorPagination(bool $shouldReverse = false): IterableCollection
    {
        if ($this->query->orders === []) {
            $this->enforceOrderBy();
        }

        $reverseDirection = function ($order) {
            if (! isset($order['direction'])) {
                return $order;
            }

            $order['direction'] = $order['direction'] === 'asc' ? 'desc' : 'asc';

            return $order;
        };

        if ($shouldReverse) {
            Invader::make($this->query)->orders = (new IterableCollection($this->query->orders))->map($reverseDirection)->toArray();
        }

        $orders = $this->query->orders;

        return (new IterableCollection($orders))
            ->filter(fn ($order) => Arr::has($order, 'direction'))
            ->values();
    }

    /**
     * Save a new model and return the instance.
     *
     * @return TModel
     */
    public function create(array $attributes = [])
    {
        return Helpers::tap($this->newModelInstance($attributes), static function ($instance) {
            $instance->save();
        });
    }

    /**
     * Save a new model and return the instance without raising model events.
     *
     * @return TModel
     */
    public function createQuietly(array $attributes = [])
    {
        return Model::withoutEvents(fn () => $this->create($attributes));
    }

    /**
     * Save a new model and return the instance. Allow mass-assignment.
     *
     * @return TModel
     */
    public function forceCreate(array $attributes)
    {
        return $this->model->unguarded(fn () => $this->newModelInstance()->create($attributes));
    }

    /**
     * Save a new model instance with mass assignment without raising model events.
     *
     * @return TModel
     */
    public function forceCreateQuietly(array $attributes = [])
    {
        return Model::withoutEvents(fn () => $this->forceCreate($attributes));
    }

    /**
     * Update records in the database.
     */
    public function update(array $values): int
    {
        return $this->toBase()->update($this->addUpdatedAtColumn($values));
    }

    /**
     * Insert new records or update the existing ones.
     */
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null): int
    {
        if ($values === []) {
            return 0;
        }
        if (! is_array(reset($values))) {
            $values = [$values];
        }
        if (null === $update) {
            $update = array_keys(reset($values));
        }

        return $this->toBase()->upsert(
            $this->addTimestampsToUpsertValues($this->addUniqueIdsToUpsertValues($values)),
            (array) $uniqueBy,
            $this->addUpdatedAtToUpsertColumns($update)
        );
    }

    /**
     * Update the column's update timestamp.
     *
     * @return false|int
     */
    public function touch(?string $column = null)
    {
        $time = $this->model->freshTimestamp();

        if ($column) {
            return $this->toBase()->update([$column => $time]);
        }

        $column = $this->model->getUpdatedAtColumn();

        if (! $this->model->usesTimestamps() || null === $column) {
            return false;
        }

        return $this->toBase()->update([$column => $time]);
    }

    /**
     * Increment a column's value by a given amount.
     */
    public function increment(string $column, float|int $amount = 1, array $extra = []): int
    {
        return $this->toBase()->increment($column, $amount, $this->addUpdatedAtColumn($extra));
    }

    /**
     * Decrement a column's value by a given amount.
     */
    public function decrement(string $column, float|int $amount = 1, array $extra = []): bool
    {
        return $this->toBase()->decrement($column, $amount, $this->addUpdatedAtColumn($extra));
    }

    /**
     * Add the "updated at" column to an array of values.
     */
    protected function addUpdatedAtColumn(array $values): array
    {
        if (
            ! $this->model->usesTimestamps()
            || null === $this->model->getUpdatedAtColumn()
        ) {
            return $values;
        }

        $column = $this->model->getUpdatedAtColumn();

        if (! array_key_exists($column, $values)) {
            $timestamp = $this->model->freshTimestampString();

            if (
                $this->model->hasSetMutator($column)
                || $this->model->hasAttributeSetMutator($column)
                || $this->model->hasCast($column)
            ) {
                $timestamp = $this->model->newInstance()
                    ->forceFill([$column => $timestamp])
                    ->getAttributes()[$column] ?? $timestamp;
            }

            $values = array_merge([$column => $timestamp], $values);
        }

        $segments = preg_split('/\s+as\s+/i', $this->query->getTable());

        $qualifiedColumn = end($segments) . '.' . $column;

        $values[$qualifiedColumn] = Arr::get($values, $qualifiedColumn, $values[$column]);

        unset($values[$column]);

        return $values;
    }

    /**
     * Add unique IDs to the inserted values.
     */
    protected function addUniqueIdsToUpsertValues(array $values): array
    {
        if (! $this->model->usesUniqueIds()) {
            return $values;
        }

        foreach ($this->model->uniqueIds() as $uniqueIdAttribute) {
            foreach ($values as &$row) {
                if (! array_key_exists($uniqueIdAttribute, $row)) {
                    $row = array_merge([$uniqueIdAttribute => $this->model->newUniqueId()], $row);
                }
            }
        }

        return $values;
    }

    /**
     * Add timestamps to the inserted values.
     */
    protected function addTimestampsToUpsertValues(array $values): array
    {
        if (! $this->model->usesTimestamps()) {
            return $values;
        }

        $timestamp = $this->model->freshTimestampString();

        $columns = array_filter([
            $this->model->getCreatedAtColumn(),
            $this->model->getUpdatedAtColumn(),
        ]);

        foreach ($columns as $column) {
            foreach ($values as &$row) {
                $row = array_merge([$column => $timestamp], $row);
            }
        }

        return $values;
    }

    /**
     * Add the "updated at" column to the updated columns.
     */
    protected function addUpdatedAtToUpsertColumns(array $update): array
    {
        if (! $this->model->usesTimestamps()) {
            return $update;
        }

        $column = $this->model->getUpdatedAtColumn();

        if (
            null !== $column
            && ! array_key_exists($column, $update)
            && ! in_array($column, $update, true)
        ) {
            $update[] = $column;
        }

        return $update;
    }

    /**
     * Delete records from the database.
     */
    public function delete(): mixed
    {
        if (isset($this->onDelete)) {
            return call_user_func($this->onDelete, $this);
        }

        return $this->toBase()->delete();
    }

    /**
     * Run the default delete function on the builder.
     *
     * Since we do not apply scopes here, the row will actually be deleted.
     *
     * @return int
     */
    public function forceDelete()
    {
        return $this->query->delete();
    }

    /**
     * Register a replacement for the default delete function.
     */
    public function onDelete(Closure $callback): void
    {
        $this->onDelete = $callback;
    }

    /**
     * Determine if the given model has a scope.
     */
    public function hasNamedScope(string $scope): bool
    {
        return $this->model && $this->model->hasNamedScope($scope);
    }

    /**
     * Call the given local model scopes.
     *
     * @return mixed|static
     */
    public function scopes(array|string $scopes)
    {
        $builder = $this;

        foreach (Arr::wrap($scopes) as $scope => $parameters) {
            // If the scope key is an integer, then the scope was passed as the value and
            // the parameter list is empty, so we will format the scope name and these
            // parameters here. Then, we'll be ready to call the scope on the model.
            if (is_int($scope)) {
                [$scope, $parameters] = [$parameters, []];
            }

            // Next we'll pass the scope callback to the callScope method which will take
            // care of grouping the "wheres" properly so the logical order doesn't get
            // messed up when adding scopes. Then we'll return back out the builder.
            $builder = $builder->callNamedScope($scope, Arr::wrap($parameters));
        }

        return $builder;
    }

    /**
     * Apply the scopes to the Orm builder instance and return it.
     */
    public function applyScopes(): static
    {
        if (! $this->scopes) {
            return $this;
        }

        $builder = clone $this;

        foreach ($this->scopes as $identifier => $scope) {
            if (! isset($builder->scopes[$identifier])) {
                continue;
            }

            $builder->callScope(function (self $builder) use ($scope) {
                // If the scope is a Closure we will just go ahead and call the scope with the
                // builder instance. The "callScope" method will properly group the clauses
                // that are added to this query so "where" clauses maintain proper logic.
                if ($scope instanceof Closure) {
                    $scope($builder);
                }

                // If the scope is a scope object, we will call the apply method on this scope
                // passing in the builder and the model instance. After we run all of these
                // scopes we will return back the builder instance to the outside caller.
                if ($scope instanceof Scope) {
                    $scope->apply($builder, $this->getModel());
                }
            });
        }

        return $builder;
    }

    /**
     * Apply the given scope on the current builder instance.
     */
    protected function callScope(callable $scope, array $parameters = []): mixed
    {
        array_unshift($parameters, $this);

        $query = $this->getQuery();

        // We will keep track of how many wheres are on the query before running the
        // scope so that we can properly group the added scope constraints in the
        // query as their own isolated nested where statement and avoid issues.
        $originalWhereCount = count($query->wheres);

        $result = $scope(...$parameters) ?? $this;

        if (count($query->wheres) > $originalWhereCount) {
            $this->addNewWheresWithinGroup($query, $originalWhereCount);
        }

        return $result;
    }

    /**
     * Apply the given named scope on the current builder instance.
     */
    protected function callNamedScope(string $scope, array $parameters = []): mixed
    {
        return $this->callScope(fn (...$parameters) => $this->model->callNamedScope($scope, $parameters), $parameters);
    }

    /**
     * Nest where conditions by slicing them at the given where count.\
     */
    protected function addNewWheresWithinGroup(BaseBuilder $query, int $originalWhereCount): void
    {
        // Here, we totally remove all of the where clauses since we are going to
        // rebuild them as nested queries by slicing the groups of wheres into
        // their own sections. This is to prevent any confusing logic order.
        $allWheres = $query->wheres;
        
        Invader::make($query)->wheres = [];

        $this->groupWhereSliceForScope(
            $query,
            array_slice($allWheres, 0, $originalWhereCount)
        );

        $this->groupWhereSliceForScope(
            $query,
            array_slice($allWheres, $originalWhereCount)
        );
    }

    /**
     * Slice where conditions at the given offset and add them to the query as a nested condition.
     */
    protected function groupWhereSliceForScope(BaseBuilder $query, array $whereSlice): void
    {
        $whereBooleans = (new IterableCollection($whereSlice))->pluck('boolean');

        // Here we'll check if the given subset of where clauses contains any "or"
        // booleans and in this case create a nested where expression. That way
        // we don't add any unnecessary nesting thus keeping the query clean.
        if ($whereBooleans->contains(fn ($logicalOperator) => str_contains($logicalOperator, 'or'))) {
            $wheres = $query->wheres;

            $wheres[] = $this->createNestedWhere(
                $whereSlice,
                str_replace(' not', '', $whereBooleans->first())
            );
            Invader::make($query)->wheres = $wheres;
        } else {
            Invader::make($query)->wheres = array_merge($query->wheres, $whereSlice);
        }
    }

    /**
     * Create a where array with nested where conditions.
     */
    protected function createNestedWhere(array $whereSlice, string $boolean = 'and'): array
    {
        $whereGroup = $this->getQuery()->reset()->from($this->model->getTable());

        Invader::make($whereGroup)->wheres = $whereSlice;

        return ['type' => 'nested', 'query' => $whereGroup, 'boolean' => $boolean];
    }

    /**
     * Set the relationships that should be eager loaded.
     *
     * @param array<array-key, array|(Closure(Relation<*,*,*>): mixed)|string>|string  $relations
     * @param (Closure(Relation<*,*,*>): mixed)|string|null  $callback
     */
    public function with($relations, Closure|string|null $callback = null): static
    {
        if ($callback instanceof Closure) {
            $eagerLoad = $this->parseWithRelations([$relations => $callback]);
        } else {
            $eagerLoad = $this->parseWithRelations(is_string($relations) ? func_get_args() : $relations);
        }

        $this->eagerLoad = array_merge($this->eagerLoad, $eagerLoad);

        return $this;
    }

    /**
     * Prevent the specified relations from being eager loaded.
     */
    public function without(mixed $relations): static
    {
        $this->eagerLoad = array_diff_key($this->eagerLoad, array_flip(
            is_string($relations) ? func_get_args() : $relations
        ));

        return $this;
    }

    /**
     * Set the relationships that should be eager loaded while removing any previously added eager loading specifications.
     * 
     * @param  array<array-key, array|(Closure(Relation<*,*,*>): mixed)|string>|string  $relations
     */
    public function withOnly($relations): static
    {
        $this->eagerLoad = [];

        return $this->with($relations);
    }

    /**
     * Create a new instance of the model being queried.
     * 
     * @return TModel
     */
    public function newModelInstance(array $attributes = []): Model
    {
        $attributes = array_merge($this->pendingAttributes, $attributes);

        return $this->model->newInstance($attributes)->setConnection(
            $this->query->getConnection()->getName()
        );
    }

    /**
     * Parse a list of relations into individuals.
     */
    protected function parseWithRelations(array $relations): array
    {
        if ($relations === []) {
            return [];
        }

        $results = [];

        foreach ($this->prepareNestedWithRelationships($relations) as $name => $constraints) {
            // We need to separate out any nested includes, which allows the developers
            // to load deep relationships using "dots" without stating each level of
            // the relationship with its own key in the array of eager-load names.
            $results = $this->addNestedWiths($name, $results);

            $results[$name] = $constraints;
        }

        return $results;
    }

    /**
     * Prepare nested with relationships.
     */
    protected function prepareNestedWithRelationships(array $relations, string $prefix = ''): array
    {
        $preparedRelationships = [];

        if ($prefix !== '') {
            $prefix .= '.';
        }

        // If any of the relationships are formatted with the [$attribute => array()]
        // syntax, we shall loop over the nested relations and prepend each key of
        // this array while flattening into the traditional dot notation format.
        foreach ($relations as $key => $value) {
            if (! is_string($key) || ! is_array($value)) {
                continue;
            }

            [$attribute, $attributeSelectConstraint] = $this->parseNameAndAttributeSelectionConstraint($key);

            $preparedRelationships = array_merge(
                $preparedRelationships,
                ["{$prefix}{$attribute}" => $attributeSelectConstraint],
                $this->prepareNestedWithRelationships($value, "{$prefix}{$attribute}"),
            );

            unset($relations[$key]);
        }

        // We now know that the remaining relationships are in a dot notation format
        // and may be a string or Closure. We'll loop over them and ensure all of
        // the present Closures are merged + strings are made into constraints.
        foreach ($relations as $key => $value) {
            if (is_numeric($key) && is_string($value)) {
                [$key, $value] = $this->parseNameAndAttributeSelectionConstraint($value);
            }

            $preparedRelationships[$prefix . $key] = $this->combineConstraints([
                $value,
                $preparedRelationships[$prefix . $key] ?? static function () {
                },
            ]);
        }

        return $preparedRelationships;
    }

    /**
     * Combine an array of constraints into a single constraint.
     */
    protected function combineConstraints(array $constraints): Closure
    {
        return static function ($builder) use ($constraints) {
            foreach ($constraints as $constraint) {
                $builder = $constraint($builder) ?? $builder;
            }

            return $builder;
        };
    }

    /**
     * Parse the attribute select constraints from the name.
     */
    protected function parseNameAndAttributeSelectionConstraint(string $name): array
    {
        return str_contains($name, ':')
            ? $this->createSelectWithConstraint($name)
            : [$name, static function () {
            }];
    }

    /**
     * Create a constraint to select the given columns for the relation.
     */
    protected function createSelectWithConstraint(string $name): array
    {
        return [explode(':', $name)[0], static function ($query) use ($name) {
            $query->select(array_map(static function ($column) use ($query) {
                return $query instanceof BelongsToMany
                    ? $query->getRelated()->qualifyColumn($column)
                    : $column;
            }, explode(',', explode(':', $name)[1])));
        }];
    }

    /**
     * Parse the nested relationships in a relation.
     */
    protected function addNestedWiths(string $name, array $results): array
    {
        $progress = [];

        // If the relation has already been set on the result array, we will not set it
        // again, since that would override any constraints that were already placed
        // on the relationships. We will only set the ones that are not specified.
        foreach (explode('.', $name) as $segment) {
            $progress[] = $segment;

            if (! isset($results[$last = implode('.', $progress)])) {
                $results[$last] = static function () {
                };
            }
        }

        return $results;
    }

    /**
     * Specify attributes that should be added to any new models created by this builder.
     *
     * The given key / value pairs will also be added as where conditions to the query.
     */
    public function withAttributes(Expression|array|string $attributes, mixed $value = null, bool $asConditions = true): static
    {
        if (! is_array($attributes)) {
            $attributes = [$attributes => $value];
        }

        if ($asConditions) {
            foreach ($attributes as $column => $value) {
                $this->where($this->qualifyColumn($column), $value);
            }
        }

        $this->pendingAttributes = array_merge($this->pendingAttributes, $attributes);

        return $this;
    }

    /**
     * Apply query-time casts to the model instance.
     */
    public function withCasts(array $casts): static
    {
        $this->model->mergeCasts($casts);

        return $this;
    }

    /**
     * Execute the given Closure within a transaction savepoint if needed.
     *
     * @template TModelValue
     *
     * @param Closure(): TModelValue $scope
     *
     * @return TModelValue
     */
    public function withSavepointIfNeeded(Closure $scope): mixed
    {
        return $this->getQuery()->db()->transactionLevel() > 0
            ? $this->getQuery()->db()->transaction($scope)
            : $scope();
    }

    /**
     * Get the Wolke builder instances that are used in the union of the query.
     */
    protected function getUnionBuilders(): IterableCollection
    {
        return $this->query->unions !== []
            ? (new IterableCollection($this->query->unions))->pluck('query')
            : new IterableCollection();
    }

    /**
     * Get the underlying query builder instance.
     */
    public function getQuery(): BaseBuilder
    {
        return $this->query;
    }

    /**
     * Set the underlying query builder instance.
     */
    public function setQuery(BaseBuilder $query): static
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Get a base query builder instance.
     */
    public function toBase(): BaseBuilder
    {
        return $this->applyScopes()->getQuery();
    }

    /**
     * Get the relationships being eagerly loaded.
     */
    public function getEagerLoads(): array
    {
        return $this->eagerLoad;
    }

    /**
     * Set the relationships being eagerly loaded.
     */
    public function setEagerLoads(array $eagerLoad): static
    {
        $this->eagerLoad = $eagerLoad;

        return $this;
    }

    /**
     * Indicate that the given relationships should not be eagerly loaded.
     */
    public function withoutEagerLoad(array $relations): static
    {
        $relations = array_diff(array_keys($this->model->getRelations()), $relations);

        return $this->with($relations);
    }

    /**
     * Flush the relationships being eagerly loaded.
     */
    public function withoutEagerLoads(): static
    {
        return $this->setEagerLoads([]);
    }

    /**
     * Get the "limit" value from the query or null if it's not set.
     */
    public function getLimit(): ?int
    {
        return $this->query->limit;
    }

    /**
     * Get the "offset" value from the query or null if it's not set.
     */
    public function getOffset(): ?int
    {
        return $this->query->offset;
    }

    /**
     * Get the default key name of the table.
     */
    protected function defaultKeyName(): string
    {
        return $this->getModel()->getKeyName();
    }

    /**
     * Get the model instance being queried.
     * 
     * @return TModel
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Set a model instance for the model being queried.
     *
     * @template TModelNew of Model
     *
     * @param  TModelNew  $model
     * 
     * @return static<TModelNew>
     */
    public function setModel(Model $model): static
    {
        $this->model = $model;

        $this->query->from($model->getTable(), true);

        return $this;
    }

    /**
     * Qualify the given column name by the model's table.
     */
    public function qualifyColumn(string|Expression $column): string
    {
        $column = $column instanceof Expression ? $column->getValue() : $column;

        return $this->model->qualifyColumn($column);
    }

    /**
     * Qualify the given columns with the model's table.
     */
    public function qualifyColumns(array|Expression $columns): array
    {
        return $this->model->qualifyColumns($columns);
    }

    /**
     * Get the given macro by name.
     */
    public function getMacro(string $name): Closure
    {
        return Arr::get($this->localMacros, $name);
    }

    /**
     * Checks if a macro is registered.
     */
    public function hasMacro(string $name): bool
    {
        return isset($this->localMacros[$name]);
    }

    /**
     * Get the given global macro by name.
     */
    public static function getGlobalMacro(string $name): Closure
    {
        return Arr::get(static::$macros, $name);
    }

    /**
     * Checks if a global macro is registered.
     */
    public static function hasGlobalMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    /**
     * Dynamically access builder proxies.
     *
     * @throws Exception
     */
    public function __get(string $key): mixed
    {
        if (in_array($key, ['orWhere', 'whereNot', 'orWhereNot'], true)) {
            return new HigherOrderBuilderProxy($this, $key);
        }

        if (in_array($key, $this->propertyPassthru, true)) {
            return $this->toBase()->{$key};
        }

        throw new Exception("Property [{$key}] does not exist on the Wolke builder instance.");
    }

    /**
     * Dynamically handle calls into the query instance.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        if ($method === 'macro') {
            $this->localMacros[$parameters[0]] = $parameters[1];

            return null;
        }

        if ($this->hasMacro($method)) {
            array_unshift($parameters, $this);

            return $this->localMacros[$method](...$parameters);
        }

        if (static::hasGlobalMacro($method)) {
            $callable = static::$macros[$method];

            if ($callable instanceof Closure) {
                $callable = $callable->bindTo($this, static::class);
            }

            return $callable(...$parameters);
        }

        if ($this->hasNamedScope($method)) {
            return $this->callNamedScope($method, $parameters);
        }

        if (in_array($method, $this->passthru, true)) {
            return (clone $this->toBase())->{$method}(...$parameters);
        }

        $result = $this->forwardCallTo($this->query, $method, $parameters);

        if ($result instanceof BaseBuilder) {
            $this->query = $result;
        }

        return $this;
    }

    /**
     * Dynamically handle calls into the query instance.
     *
     * @throws BadMethodCallException
     */
    public static function __callStatic(string $method, array $parameters = []): mixed
    {
        if ($method === 'macro') {
            static::$macros[$parameters[0]] = $parameters[1];

            return null;
        }

        if ($method === 'mixin') {
            static::registerMixin($parameters[0], $parameters[1] ?? true);

            return null;
        }

        if (! static::hasGlobalMacro($method)) {
            static::throwBadMethodCallException($method);
        }

        $callable = static::$macros[$method];

        if ($callable instanceof Closure) {
            $callable = $callable->bindTo(null, static::class);
        }

        return $callable(...$parameters);
    }

    /**
     * Register the given mixin with the builder.
     */
    protected static function registerMixin(string $mixin, bool $replace): void
    {
        $methods = (new ReflectionClass($mixin))->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED
        );

        foreach ($methods as $method) {
            if ($replace || ! static::hasGlobalMacro($method->name)) {
                static::macro($method->name, $method->invoke($mixin));
            }
        }
    }

    /**
     * Clone the Wolke query builder.
     */
    public function clone(): static
    {
        return clone $this;
    }
    /**
     * Register a closure to be invoked on a clone.
     * 
     * @var Closure(static): void $callback
     */
    public function onClone(Closure $callback): static
    {
        $this->onCloneCallbacks[] = $callback;

        return $this;
    }

    /**
     * Force a clone of the underlying query builder when cloning.
     */
    public function __clone(): void
    {
        $this->query = clone $this->query;

        foreach ($this->onCloneCallbacks as $onCloneCallback) {
            $onCloneCallback($this);
        }
    }
}
