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
use BlitzPHP\Database\Result\BaseResult;
use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Utilities\Iterable\LazyCollection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Utilities\Support\Invader;
use BlitzPHP\Wolke\Concerns\BuildsQueries;
use BlitzPHP\Wolke\Concerns\QueriesRelationships;
use BlitzPHP\Wolke\Contracts\Scope;
use BlitzPHP\Wolke\Exceptions\CursorPaginationException;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Exceptions\MultipleRecordsFoundException;
use BlitzPHP\Wolke\Exceptions\RecordsNotFoundException;
use BlitzPHP\Wolke\Exceptions\RelationNotFoundException;
use BlitzPHP\Wolke\Pagination\CursorPaginator;
use BlitzPHP\Wolke\Pagination\LengthAwarePaginator;
use BlitzPHP\Wolke\Pagination\Paginator;
use BlitzPHP\Wolke\Relations\BelongsToMany;
use BlitzPHP\Wolke\Relations\Relation;
use Closure;
use Exception;
use ReflectionClass;
use ReflectionMethod;

/**
 * @property HigherOrderBuilderProxy $orWhere
 * @property HigherOrderBuilderProxy $orWhereNot
 * @property HigherOrderBuilderProxy $whereNot
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
     * @var \BlitzPHP\Wolke\Model
     */
    protected $model;

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
     * @var string[]
     */
    protected array $propertyPassthru = [
        'from',
    ];

    /**
     * The methods that should be returned from query builder.
     *
     * @var string[]
     */
    protected array $passthru = [
        'aggregate',
        'average',
        'avg',
        'count',
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
        'max',
        'min',
        'raw',
        'sum',
        'sql',
        'toSql',
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
     * @return Model|static
     */
    public function make(array $attributes = [])
    {
        return $this->newModelInstance($attributes);
    }

    /**
     * Register a new global scope.
     */
    public function withGlobalScope(string $identifier, Closure|Scope $scope): self
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
    public function withoutGlobalScope(Scope|string $scope): self
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
    public function withoutGlobalScopes(?array $scopes = null): self
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
     * Get an array of global scopes that were removed from the query.
     */
    public function removedScopes(): array
    {
        return $this->removedScopes;
    }

    /**
     * Add a where clause on the primary key to the query.
     */
    public function whereKey(mixed $id): self
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
    public function whereKeyNot(mixed $id): self
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
     * Add a "where null" clause to the query.
     */
    public function whereNull(array|string $columns, string $boolean = 'and', bool $not = false): self
    {
        if ($boolean === 'and') {
            if ($not) {
                $this->query->whereNotNull($columns);
            } else {
                $this->query->whereNull($columns);
            }
        } else {
            if ($not) {
                $this->query->orWhereNotNull($columns);
            } else {
                $this->query->orWhereNull($columns);
            }
        }

        return $this;
    }

    /**
     * Add a "where not null" clause to the query.
     */
    public function whereNotNull(array|string $columns, string $boolean = 'and'): self
    {
        return $this->whereNull($columns, $boolean, true);
    }

    /**
     * Add a "where" clause comparing two columns to the query.
     */
    public function whereColumn(string $first, ?string $operator = null, ?string $second = null, string $boolean = 'and'): self
    {
        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        [$first, $operator] = $this->prepareValueAndOperator(
            $first,
            $operator,
            func_num_args() === 2
        );

        if ($boolean === 'and') {
            $this->query->where("{$first} {$operator}", $second, false);
        } else {
            $this->query->orWhere("{$first} {$operator}", $second, false);
        }

        return $this;
    }

    /**
     * Add an "or where" clause comparing two columns to the query.
     */
    public function orWhereColumn(string $first, ?string $operator = null, ?string $second = null): self
    {
        return $this->whereColumn($first, $operator, $second, 'or');
    }

    /**
     * Add a basic where clause to the query.
     */
    public function where(array|Closure|string $column, null|Closure|string $operator = null, mixed $value = null, string $boolean = 'and'): self
    {
        if ($column instanceof Closure) {
            $column = $column($this);
        }

        // Here we will make some assumptions about the operator. If only 2 values are
        // passed to the method, we will assume that the operator is an equals sign
        // and keep going. Otherwise, we'll require the operator to be passed in.
        [$value, $operator] = $this->prepareValueAndOperator(
            (string) $value,
            $operator,
            func_num_args() === 2
        );

        $columnAndOperator = is_array($column) ? $column : "{$column} {$operator}";

        if ($boolean === 'and') {
            $this->query->where($columnAndOperator, $value, true);
        } else {
            $this->query->orWhere($columnAndOperator, $value, true);
        }

        return $this;
    }

    /**
     * Add a basic where clause to the query, and return the first result.
     *
     * @return Model|static
     */
    public function firstWhere(array|Closure|string $column, null|Closure|string $operator = null, mixed $value = null, string $boolean = 'and')
    {
        return $this->where($column, $operator, $value, $boolean)->first();
    }

    /**
     * Add an "or where" clause to the query.
     */
    public function orWhere(array|Closure|string $column, null|Closure|string $operator = null, mixed $value = null): self
    {
        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Add a basic "where not" clause to the query.
     */
    public function whereNot(array|Closure|string $column, null|Closure|string $operator = null, mixed $value = null, string $boolean = 'and'): self
    {
        return $this->where($column, $operator, $value, $boolean . ' not');
    }

    /**
     * Add a basic "or where not" clause to the query.
     */
    public function orWhereNot(array|Closure|string $column, null|Closure|string $operator = null, mixed $value = null): self
    {
        return $this->whereNot($column, $operator, $value, 'or');
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     */
    public function latest(?string $column = null): self
    {
        if (null === $column) {
            $column = $this->model->getCreatedAtColumn() ?? 'created_at';
        }

        $this->query->sortDesc($column);

        return $this;
    }

    /**
     * Add an "order by" clause for a timestamp to the query.
     */
    public function oldest(?string $column = null): self
    {
        if (null === $column) {
            $column = $this->model->getCreatedAtColumn() ?? 'created_at';
        }

        $this->query->sortAsc($column);

        return $this;
    }

    /**
     * Create a collection of models from plain arrays.
     */
    public function hydrate(array $items): Collection
    {
        $instance = $this->newModelInstance();

        return $instance->newCollection(array_map(static function ($item) use ($items, $instance) {
            $model = $instance->newFromBuilder((array) $item);

            if (count($items) > 1) {
                $model->preventsLazyLoading = Model::preventsLazyLoading();
            }

            return $model;
        }, $items));
    }

    /**
     * Create a collection of models from a raw query.
     */
    public function fromQuery(string $query, array $bindings = []): Collection
    {
        return $this->hydrate(
            $this->query->db()->query($query, $bindings)->result()
        );
    }

    /**
     * Find a model by its primary key.
     *
     * @return Collection|Model|static|static[]|null
     */
    public function find(mixed $id, array $columns = ['*'])
    {
        if (is_array($id) || $id instanceof Arrayable) {
            return $this->findMany($id, $columns);
        }

        return $this->whereKey($id)->first($columns);
    }

    /**
     * Find multiple models by their primary keys.
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
     * @return Collection|Model|static|static[]
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
     * @return Model|static
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
     * @return Collection|mixed|Model|static|static[]
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
     * @return Model|static
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
     * @return Model|static
     */
    public function firstOrCreate(array $attributes = [], array $values = [])
    {
        if (null !== ($instance = $this->where($attributes)->first())) {
            return $instance;
        }

        return Helpers::tap($this->newModelInstance(array_merge($attributes, $values)), static function ($instance) {
            $instance->save();
        });
    }

    /**
     * Create or update a record matching the attributes, and fill it with values.
     *
     * @return Model|static
     */
    public function updateOrCreate(array $attributes, array $values = [])
    {
        return Helpers::tap($this->firstOrNew($attributes), static function ($instance) use ($values) {
            $instance->fill($values)->save();
        });
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

        throw (new ModelNotFoundException())->setModel(get_class($this->model));
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
     * Execute the query and get the first result if it's the sole matching record.
     *
     * @return Model
     *
     * @throws ModelNotFoundException
     * @throws MultipleRecordsFoundException
     */
    public function sole(array|string $columns = ['*'])
    {
        try {
            return $this->baseSole($columns);
        } catch (RecordsNotFoundException $exception) {
            throw (new ModelNotFoundException())->setModel(get_class($this->model));
        }
    }

    /**
     * Get a single column's value from the first result of a query.
     */
    public function value(string $column): mixed
    {
        if ($result = $this->first([$column])) {
            return $result->{Text::afterLast($column, '.')};
        }
    }

    /**
     * Get a single column's value from the first result of a query if it's the sole matching record.
     *
     * @throws ModelNotFoundException<\Illuminate\Database\Eloquent\Model>
     * @throws MultipleRecordsFoundException
     */
    public function soleValue(string $column): mixed
    {
        return $this->sole([$column])->{Text::afterLast($column, '.')};
    }

    /**
     * Get a single column's value from the first result of the query or throw an exception.
     *
     * @throws ModelNotFoundException<Model>
     */
    public function valueOrFail(string $column): mixed
    {
        return $this->firstOrFail([$column])->{Text::afterLast($column, '.')};
    }

    /**
     * Execute the query as a "select" statement.
     */
    public function all(array|string $columns = ['*']): array
    {
        return $this->get($columns)->all();
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @return Collection<Model>
     */
    public function get(array|string $columns = []): Collection
    {
        $builder = $this->applyScopes();

        // If we actually found models we will also eager load any relationships that
        // have been specified as needing to be eager loaded, which will solve the
        // n+1 query issue for the developers to avoid running a lot of queries.

        if (count($models = $builder->getModels($columns)) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $builder->getModel()->newCollection($models);
    }

    /**
     * Get the hydrated models without eager loading.
     *
     * @return Model[]|static[]
     */
    public function getModels(array|string $columns = [])
    {
        return $this->model->hydrate(
            $this->query->from($this->model->getTable())->select($columns)->result()
        )->all();
    }

    /**
     * Eager load the relationships for the models.
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
     */
    public function getRelation(string $name): Relation
    {
        // We want to run a relationship query without any constrains so that we will
        // not have to remove these where clauses manually which gets really hacky
        // and error prone. We don't want constraints because we add eager ones.
        $relation = Relation::noConstraints(function () use ($name) {
            try {
                return $this->getModel()->newInstance()->{$name}();
            } catch (BadMethodCallException $e) {
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
     * Get a lazy collection for the given query.
     */
    public function cursor(): LazyCollection
    {
        return $this->applyScopes()->baseCursor()->map(fn ($record) => $this->newModelInstance()->newFromBuilder($record));
    }

    /**
     * Add a generic "order by" clause if the query doesn't already have one.
     */
    protected function enforceOrderBy(): void
    {
        if (empty(Invader::make($this->query)->order)) {
            $this->orderBy($this->model->getQualifiedKeyName(), 'asc');
        }
    }

    /**
     * Get an array with the values of a given column.
     */
    public function pluck(string $column, ?string $key = null): IterableCollection
    {
        // First, we will need to select the results of the query accounting for the
        // given columns / key. Once we have the results, we will be able to take
        // the results and get the exact data that was requested for the query.
        $queryResult = $this->onceWithColumns(
            null === $key ? [$column] : [$column, $key],
            fn () => $this->fromQuery($this->toSql())
        );

        if (empty($queryResult)) {
            return Helpers::collect();
        }

        // If the columns are qualified with a table or have an alias, we cannot use
        // those directly in the "pluck" operations since the results from the DB
        // are only keyed by the column itself. We'll strip the table out here.
        $column = $this->stripTableForPluck($column);

        $key = $this->stripTableForPluck($key);

        $results = is_array($queryResult[0])
            ? $this->pluckFromArrayColumn($queryResult, $column, $key)
            : $this->pluckFromObjectColumn($queryResult, $column, $key);

        // If the model has a mutator for the requested column, we will spin through
        // the results and mutate the values so that the mutated version of these
        // columns are returned as you would expect from these Orm models.
        if (! $this->model->hasGetMutator($column)
            && ! $this->model->hasCast($column)
            && ! in_array($column, $this->model->getDates(), true)) {
            return $results;
        }

        return $results->map(fn ($value) => $this->model->newFromBuilder([$column => $value])->{$column});
    }

    /**
     * Execute the given callback while selecting the given columns.
     *
     * After running the callback, the columns are reset to the original value.
     */
    protected function onceWithColumns(array $columns, callable $callback): mixed
    {
        $original = Invader::make($this->query)->fields;

        if (empty($original)) {
            Invader::make($this->query)->fields = $columns;
        }

        $result = $callback();

        Invader::make($this->query)->fields = $original;

        return $result;
    }

    /**
     * Strip off the table name or alias from a column identifier.
     */
    protected function stripTableForPluck(?string $column): ?string
    {
        if (null === $column) {
            return $column;
        }

        $separator = str_contains(strtolower($column), ' as ') ? ' as ' : '\.';

        return Arr::last(preg_split('~' . $separator . '~i', $column));
    }

    /**
     * Retrieve column values from rows represented as arrays.
     */
    protected function pluckFromArrayColumn(iterable $queryResult, string $column, ?string $key): IterableCollection
    {
        $results = [];

        if (null === $key) {
            foreach ($queryResult as $row) {
                $results[] = $row[$column];
            }
        } else {
            foreach ($queryResult as $row) {
                $results[$row[$key]] = $row[$column];
            }
        }

        return Helpers::collect($results);
    }

    /**
     * Retrieve column values from rows represented as objects.
     */
    protected function pluckFromObjectColumn(iterable $queryResult, string $column, ?string $key): IterableCollection
    {
        $results = [];

        if (null === $key) {
            foreach ($queryResult as $row) {
                $results[] = $row->{$column};
            }
        } else {
            foreach ($queryResult as $row) {
                $results[$row->{$key}] = $row->{$column};
            }
        }

        return Helpers::collect($results);
    }

    /**
     * Paginate the given query.
     *
     * @throws InvalidArgumentException
     */
    public function paginate(null|Closure|int $perPage = null, array|string $columns = [], string $pageName = 'page', ?int $page = null, null|Closure|int $total = null): LengthAwarePaginator
    {
        $page    = $page ?: Paginator::resolveCurrentPage($pageName);
        $total   = null !== $total ? Helpers::value($total) : (clone $this->toBase())->count();
        $perPage = $perPage ?: $this->model->getPerPage();

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
     * @return \BlitzPHP\Wolke\Contracts\Paginator
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
     * @return \BlitzPHP\Wolke\Contracts\Paginator
     *
     * @throws CursorPaginationException
     */
    public function cursorPaginate(?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', ?string $cursor = null)
    {
        $cursor = $cursor ?: CursorPaginator::resolveCurrentCursor($cursorName);

        $perPage = $perPage ?: $this->model->getPerPage();

        $orders = $this->ensureOrderForCursorPagination(null !== $cursor && $cursor->pointsToPreviousItems());

        $orderDirection = $orders->first()['direction'] ?? 'asc';

        $comparisonOperator = Text::lower($orderDirection) === ' asc' ? '>' : '<';

        $parameters = $orders->pluck('field')->toArray();

        if (null !== $cursor) {
            if (count($parameters) === 1) {
                $this->where($column = $parameters[0], $comparisonOperator, $cursor->parameter($column));
            } elseif (count($parameters) > 1) {
                $this->whereRowValues($parameters, $comparisonOperator, $cursor->parameters($parameters));
            }
        }

        $this->limit($perPage + 1);

        return $this->cursorPaginator($this->get($columns), $perPage, $cursor, [
            'path'       => Paginator::resolveCurrentPath(),
            'cursorName' => $cursorName,
            'parameters' => $parameters,
        ]);
    }

    /**
     * Ensure the proper order by required for cursor pagination.
     *
     * @throws CursorPaginationException
     */
    protected function ensureOrderForCursorPagination(bool $shouldReverse = false): IterableCollection
    {
        $orderDirections = Helpers::collect($this->query->QBOrderBy)->pluck('direction')->unique();

        if ($orderDirections->count() > 1) {
            throw new CursorPaginationException('Only a single order by direction is supported when using cursor pagination.');
        }

        if ($orderDirections->count() === 0) {
            $this->enforceOrderBy();
        }

        if ($shouldReverse) {
            $this->query->QBOrderBy = Helpers::collect($this->query->QBOrderBy)->map(static function ($order) {
                $order['direction'] = Text::lower($order['direction']) === ' asc' ? 'desc' : 'asc';

                return $order;
            })->toArray();
        }

        return Helpers::collect($this->query->QBOrderBy);
    }

    /**
     * Save a new model and return the instance.
     *
     * @return Model|self
     */
    public function create(array $attributes = [])
    {
        return Helpers::tap($this->newModelInstance($attributes), static function ($instance) {
            $instance->save();
        });
    }

    /**
     * Save a new model and return the instance. Allow mass-assignment.
     *
     * @return Model|self
     */
    public function forceCreate(array $attributes)
    {
        return $this->model->unguarded(fn () => $this->newModelInstance()->create($attributes));
    }

    /**
     * Save a new model instance with mass assignment without raising model events.
     *
     * @return Model|self
     */
    public function forceCreateQuietly(array $attributes = [])
    {
        return Model::withoutEvents(fn () => $this->forceCreate($attributes));
    }

    /**
     * Update records in the database.
     *
     * @return BaseBuilder|BaseResult
     */
    public function update(array $values)
    {
        return $this->toBase()->update($this->addUpdatedAtColumn($values));
    }

    // /**
    //  * Insert new records or update the existing ones.
    //  *
    //  * @param  array  $values
    //  * @param  array|string  $uniqueBy
    //  * @param  array|null  $update
    //  * @return int
    //  */
    // public function upsert(array $values, $uniqueBy, $update = null)
    // {
    //     if (empty($values)) {
    //         return 0;
    //     }

    //     if (! is_array(reset($values))) {
    //         $values = [$values];
    //     }

    //     if (is_null($update)) {
    //         $update = array_keys(reset($values));
    //     }

    //     return $this->toBase()->upsert(
    //         $this->addTimestampsToUpsertValues($this->addUniqueIdsToUpsertValues($values)),
    //         $uniqueBy,
    //         $this->addUpdatedAtToUpsertColumns($update)
    //     );
    // }

    /**
     * Update the column's update timestamp.
     *
     * @return false|int
     */
    public function touch(?string $column = null)
    {
        $time = $this->model->freshTimestamp();

        if (! $column) {
            $column = $this->model->getUpdatedAtColumn();
        }

        if (! $this->model->usesTimestamps() || null === $column) {
            return false;
        }

        $result = $this->toBase()->update([$column => $time]);
        if ($result instanceof BaseResult) {
            return $result->affectedRows();
        }

        return false;
    }

    /**
     * Increment a column's value by a given amount.
     */
    public function increment(string $column, float|int $amount = 1, array $extra = []): bool
    {
        return $this->toBase()->increment(
            $column,
            $amount
        );
    }

    /**
     * Decrement a column's value by a given amount.
     */
    public function decrement(string $column, float|int $amount = 1, array $extra = []): bool
    {
        return $this->toBase()->decrement(
            $column,
            $amount
        );
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
                    ->getAttributes()[$column];
            }

            $values = array_merge([$column => $timestamp], $values);
        }

        $segments = preg_split('/\s+as\s+/i', $this->query->getTable());

        $qualifiedColumn = match ($this->query->db()->driver) {
            'sqlite' => $column,
            default  => end($segments) . '.' . $column
        };

        $values[$qualifiedColumn] = $values[$column];

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
     *
     * @return BaseBuilder|BaseResult
     */
    public function delete()
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
     * @return BaseBuilder|BaseResult
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
     *
     * @return static
     */
    public function applyScopes()
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

        if (empty(Invader::make($builder->query)->table)) {
            $builder->query->from($this->getModel()->getTable());
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
        $originalWhereCount = [] === $query->getCompiledWhere()
            ? 0
            : count($query->getCompiledWhere());

        $result = $scope(...array_values($parameters)) ?? $this;

        if (count((array) $query->getCompiledWhere()) > $originalWhereCount) {
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
        // @todo a verifier et COMPRENDRE car ne fonctionne pas
        return;
        // Here, we totally remove all of the where clauses since we are going to
        // rebuild them as nested queries by slicing the groups of wheres into
        // their own sections. This is to prevent any confusing logic order.
        $allWheres = $query->getCompiledWhere();

        // @todo implementation d'un tableau de where au niveau de basebuilder

        Invader::make($this->query)->where = '';

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
        $whereBooleans = Helpers::collect($whereSlice)->pluck('boolean');

        // Here we'll check if the given subset of where clauses contains any "or"
        // booleans and in this case create a nested where expression. That way
        // we don't add any unnecessary nesting thus keeping the query clean.
        if ($whereBooleans->contains('or')) {
            Invader::make($query)->compileWhere[] = $this->createNestedWhere(
                $whereSlice,
                $whereBooleans->first()
            );
        } else {
            Invader::make($query)->compileWhere = array_merge($query->getCompiledWhere(), $whereSlice);
        }
    }

    /**
     * Create a where array with nested where conditions.
     */
    protected function createNestedWhere(array $whereSlice, string $boolean = 'and'): array
    {
        $whereGroup = $this->getQuery()->reset()->from($this->model->getTable());

        $whereGroup->where($whereSlice);

        return ['type' => 'Nested', 'query' => $whereGroup, 'boolean' => $boolean];
    }

    /**
     * Set the relationships that should be eager loaded.
     *
     * @param  string...|array  $relations
     */
    public function with($relations, null|Closure|string $callback = null): self
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
    public function without(mixed $relations): self
    {
        $this->eagerLoad = array_diff_key($this->eagerLoad, array_flip(
            is_string($relations) ? func_get_args() : $relations
        ));

        return $this;
    }

    /**
     * Set the relationships that should be eager loaded while removing any previously added eager loading specifications.
     */
    public function withOnly(mixed $relations): self
    {
        $this->eagerLoad = [];

        return $this->with($relations);
    }

    /**
     * Create a new instance of the model being queried.
     */
    public function newModelInstance(array $attributes = []): Model
    {
        return $this->model->newInstance($attributes)->setConnection(
            'default'
            // $this->query->getConnectionName()
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
                if (str_contains($column, '.')) {
                    return $column;
                }

                return $query instanceof BelongsToMany
                    ? $query->getRelated()->getTable() . '.' . $column
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
     * Apply query-time casts to the model instance.
     */
    public function withCasts(array $casts): self
    {
        $this->model->mergeCasts($casts);

        return $this;
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
    public function setQuery(BaseBuilder $query): self
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
    public function setEagerLoads(array $eagerLoad): self
    {
        $this->eagerLoad = $eagerLoad;

        return $this;
    }

    /**
     * Indicate that the given relationships should not be eagerly loaded.
     */
    public function withoutEagerLoad(array $relations): self
    {
        $relations = array_diff(array_keys($this->model->getRelations()), $relations);

        return $this->with($relations);
    }

    /**
     * Flush the relationships being eagerly loaded.
     */
    public function withoutEagerLoads(): self
    {
        return $this->setEagerLoads([]);
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
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Set a model instance for the model being queried.
     */
    public function setModel(Model $model): self
    {
        $this->model = $model;

        $this->query->from($model->getTable(), true);

        return $this;
    }

    /**
     * Qualify the given column name by the model's table.
     */
    public function qualifyColumn(string $column): string
    {
        return $this->model->qualifyColumn($column);
    }

    /**
     * Qualify the given columns with the model's table.
     */
    public function qualifyColumns(string $columns): array
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

        throw new Exception("Property [{$key}] does not exist on the Eloquent builder instance.");
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
            return static::registerMixin($parameters[0], $parameters[1] ?? true);
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
                $method->setAccessible(true);

                static::macro($method->name, $method->invoke($mixin));
            }
        }
    }

    /**
     * Clone the Orm query builder.
     *
     * @return static
     */
    public function clone()
    {
        return clone $this;
    }

    /**
     * Force a clone of the underlying query builder when cloning.
     *
     * @return void
     */
    public function __clone()
    {
        $this->query = clone $this->query;
    }
}
