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

use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Database\Query\Expression;
use BlitzPHP\Database\Exceptions\MultipleRecordsFoundException;
use BlitzPHP\Traits\Macroable;
use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Model;
use Closure;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TResult
 *
 * @mixin \BlitzPHP\Wolke\Builder
 */
abstract class Relation
{
    use ForwardsCalls, Macroable {
        __call as macroCall;
    }

    /**
     * The related model instance.
     *
     * @var TRelatedModel
     */
    protected Model $related;

    /**
     * Indicates whether the eagerly loaded relation should implicitly return an empty collection.
     */
    protected bool $eagerKeysWereEmpty = false;

    /**
     * Indicates if the relation is adding constraints.
     */
    protected static bool $constraints = true;

    /**
     * An array to map class names to their morph names in the database.
     * 
     * @var array<string, class-string<Model>>
     */
    public static array $morphMap = [];

    /**
     * Prevents morph relationships without a morph map.
     */
    protected static bool $requireMorphMap = false;

    /**
     * The count of self joins.
     */
    protected static int $selfJoinCount = 0;

    /**
     * Create a new relation instance.
     *
     * @param Builder<TRelatedModel> $query  The Wolke query builder instance.
     * @param TDeclaringModel        $parent The parent model instance.
     */
    public function __construct(protected Builder $query, protected Model $parent)
    {
        $this->related = $query->getModel();

        $this->addConstraints();
    }

    /**
     * Run a callback with constraints disabled on the relation.
     * 
     * @template TReturn of mixed
     *
     * @param  Closure(): TReturn  $callback
     * 
     * @return TReturn
     */
    public static function noConstraints(Closure $callback): mixed
    {
        $previous = static::$constraints;

        static::$constraints = false;

        // When resetting the relation where clause, we want to shift the first element
        // off of the bindings, leaving only the constraints that the developers put
        // as "extra" on the relationships, and not original relation constraints.
        try {
            return $callback();
        } finally {
            static::$constraints = $previous;
        }
    }

    /**
     * Set the base constraints on the relation query.
     */
    abstract public function addConstraints(): void;

    /**
     * Set the constraints for an eager load of the relation.
     * 
     * @param list<TDeclaringModel>  $models
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Initialize the relation on a set of models.
     *
     * @param list<TDeclaringModel>  $models
     * 
     * @return list<TDeclaringModel>
     */
    abstract public function initRelation(array $models, string $relation): array;

    /**
     * Match the eagerly loaded results to their parents.
     * 
     * @param  list<TDeclaringModel>  $models
     * @param  Collection<int, TRelatedModel>  $results
     * 
     * @return list<TDeclaringModel>
     */
    abstract public function match(array $models, Collection $results, string $relation): array;

    /**
     * Get the results of the relationship.
     *
     * @return TResult
     */
    abstract public function getResults(): mixed;

    /**
     * Get the relationship for eager loading.
     *
     * @return Collection<int, TRelatedModel>
     */
    public function getEager(): Collection
    {
        return $this->eagerKeysWereEmpty
            ? $this->query->getModel()->newCollection()
            : $this->get();
    }

    /**
     * Execute the query and get the first result if it's the sole matching record.
     *
     * @return TRelatedModel
     * 
     * @throws ModelNotFoundException
     * @throws MultipleRecordsFoundException
     */
    public function sole(array|string $columns = ['*']): Model
    {
        $result = $this->limit(2)->get($columns);

        $count = $result->count();

        if ($count === 0) {
            throw (new ModelNotFoundException())->setModel(get_class($this->related));
        }

        if ($count > 1) {
            throw new MultipleRecordsFoundException($count);
        }

        return $result->first();
    }

    /**
     * Execute the query as a "select" statement.
     * 
     * @return Collection<int, TRelatedModel>
     */
    public function get(array $columns = ['*']): Collection
    {
        return $this->query->get($columns);
    }

    /**
     * Touch all of the related models for the relationship.
     */
    public function touch(): void
    {
        $model = $this->getRelated();

        if (! $model::isIgnoringTouch()) {
            $this->rawUpdate([
                $model->getUpdatedAtColumn() => $model->freshTimestampString(),
            ]);
        }
    }

    /**
     * Run a raw update against the base query.
     *
     * @return int
     */
    public function rawUpdate(array $attributes = [])
    {
        return $this->query->withoutGlobalScopes()->update($attributes);
    }

    /**
     * Add the constraints for a relationship count query.
     *
     * @param Builder<TRelatedModel>  $query
     * @param Builder<TDeclaringModel>  $parentQuery
     * 
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceCountQuery(Builder $query, Builder $parentQuery): Builder
    {
        return $this->getRelationExistenceQuery(
            $query,
            $parentQuery,
            new Expression('count(*)')
        );
        // )->setBindings([], 'select');
    }

    /**
     * Add the constraints for an internal relationship existence query.
     *
     * Essentially, these queries compare on column names like whereColumn.
     *
     * @param Builder<TRelatedModel>  $query
     * @param Builder<TDeclaringModel>  $parentQuery
     * 
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        return $query->select($columns)->whereColumn(
            $this->getQualifiedParentKeyName(),
            '=',
            $this->getExistenceCompareKey()
        );
    }

    /**
     * Get a relationship join table hash.
     */
    public function getRelationCountHash(bool $incrementJoinCount = true): string
    {
        return 'blitz_reserved_' . ($incrementJoinCount ? static::$selfJoinCount++ : static::$selfJoinCount);
    }

    /**
     * Get all of the primary keys for an array of models.
     * 
     * @param list<TDeclaringModel> $models
     * 
     * @return list<int|string|null>
     */
    protected function getKeys(array $models, ?string $key = null): array
    {
        return (new IterableCollection($models))
            ->map(static fn ($value) => $key ? $value->getAttribute($key) : $value->getKey())
            ->values()
            ->unique(null, true)
            ->sort()
            ->all();
    }

    /**
     * Get the query builder that will contain the relationship constraints.
     * 
     * @return Builder<TRelatedModel>
     */
    protected function getRelationQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Get the underlying query for the relation.
     * 
     * @return Builder<TRelatedModel>
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Get the base query builder driving the Eloquent builder.
     */
    public function getBaseQuery(): BaseBuilder
    {
        return $this->query->getQuery();
    }

    /**
     * Get a base query builder instance.
     */
    public function toBase(): BaseBuilder
    {
        return $this->query->toBase();
    }

    /**
     * Get the parent model of the relation.
     *
     * @return TDeclaringModel
     */
    public function getParent(): Model
    {
        return $this->parent;
    }

    /**
     * Get the fully qualified parent key name.
     */
    public function getQualifiedParentKeyName(): string
    {
        return $this->parent->getQualifiedKeyName();
    }

    /**
     * Get the related model of the relation.
     *
     * @return TRelatedModel
     */
    public function getRelated(): Model
    {
        return $this->related;
    }

    /**
     * Get the name of the "created at" column.
     */
    public function createdAt(): string
    {
        return $this->parent->getCreatedAtColumn();
    }

    /**
     * Get the name of the "updated at" column.
     */
    public function updatedAt(): string
    {
        return $this->parent->getUpdatedAtColumn();
    }

    /**
     * Get the name of the related model's "updated at" column.
     */
    public function relatedUpdatedAt(): string
    {
        return $this->related->getUpdatedAtColumn();
    }

    /**
     * Add a whereIn eager constraint for the given set of model keys to be loaded.
     * 
     * @param Builder<TRelatedModel>|null  $query
     */
    protected function whereInEager(string $whereIn, string $key, array $modelKeys, ?Builder $query = null): void
    {
        ($query ?? $this->query)->{$whereIn}($key, $modelKeys);

        if ($modelKeys === []) {
            $this->eagerKeysWereEmpty = true;
        }
    }

    /**
     * Get the name of the "where in" method for eager loading.
     */
    protected function whereInMethod(Model $model, string $key): string
    {
        return $model->getKeyName() === Arr::last(explode('.', $key))
                    && in_array($model->getKeyType(), ['int', 'integer'], true)
                        ? 'whereIn'
                        : 'whereIn';
    }

    /**
     * Prevent polymorphic relationships from being used without model mappings.
     */
    public static function requireMorphMap(bool $requireMorphMap = true): void
    {
        static::$requireMorphMap = $requireMorphMap;
    }

    /**
     * Determine if polymorphic relationships require explicit model mapping.
     */
    public static function requiresMorphMap(): bool
    {
        return static::$requireMorphMap;
    }

    /**
     * Define the morph map for polymorphic relations and require all morphed models to be explicitly mapped.
     * 
     * @param array<array-key, class-string<Model>> $map
     */
    public static function enforceMorphMap(array $map, bool $merge = true): array
    {
        static::requireMorphMap();

        return static::morphMap($map, $merge);
    }

    /**
     * Set or get the morph map for polymorphic relations.
     *
     * @param array<array-key, class-string<Model>>|null $map
     * 
     * @return array<string, class-string<Model>>
     */
    public static function morphMap(?array $map = null, bool $merge = true): array
    {
        $map = static::buildMorphMapFromModels($map);

        if (is_array($map)) {
            static::$morphMap = $merge && static::$morphMap
                            ? $map + static::$morphMap : $map;
        }

        return static::$morphMap;
    }

    /**
     * Builds a table-keyed array from model class names.
     *
     * @param  array<array-key, class-string<Model>>|null  $models
     * 
     * @return array<string, class-string<Model>>|null
     */
    protected static function buildMorphMapFromModels(?array $models = null): ?array
    {
        if (null === $models || ! array_is_list($models)) {
            return $models;
        }

        return array_combine(array_map(static fn ($model) => (new $model())->getTable(), $models), $models);
    }

    /**
     * Get the model associated with a custom polymorphic type.
     * 
     * @return class-string<Model>|null
     */
    public static function getMorphedModel(string $alias): ?string
    {
        return static::$morphMap[$alias] ?? null;
    }

    /**
     * Get the alias associated with a custom polymorphic class.
     *
     * @param  class-string<Model>  $className
     * 
     * @return int|string
     */
    public static function getMorphAlias(string $className)
    {
        return array_search($className, static::$morphMap, strict: true) ?: $className;
    }

    /**
     * Handle dynamic method calls to the relationship.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->forwardDecoratedCallTo($this->query, $method, $parameters);
    }

    /**
     * Force a clone of the underlying query builder when cloning.
     */
    public function __clone(): void
    {
        $this->query = clone $this->query;
    }
}
