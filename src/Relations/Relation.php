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
use BlitzPHP\Traits\Macroable;
use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Exceptions\MultipleRecordsFoundException;
use BlitzPHP\Wolke\Model;
use Closure;

/**
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
     * @var Model
     */
    protected $related;

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
     * @param Builder $query  The Wolke query builder instance.
     * @param Model   $parent The parent model instance.
     *
     * @return void
     */
    public function __construct(protected Builder $query, protected Model $parent)
    {
        $this->related = $query->getModel();

        $this->addConstraints();
    }

    /**
     * Run a callback with constraints disabled on the relation.
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
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Initialize the relation on a set of models.
     */
    abstract public function initRelation(array $models, string $relation): array;

    /**
     * Match the eagerly loaded results to their parents.
     */
    abstract public function match(array $models, Collection $results, string $relation): array;

    /**
     * Get the results of the relationship.
     */
    abstract public function getResults(): mixed;

    /**
     * Get the relationship for eager loading.
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
     */
    public function getRelationExistenceCountQuery(Builder $query, Builder $parentQuery): Builder
    {
        return $this->getRelationExistenceQuery(
            $query,
            $parentQuery,
            'count(*)'
        );
        // )->setBindings([], 'select');
    }

    /**
     * Add the constraints for an internal relationship existence query.
     *
     * Essentially, these queries compare on column names like whereColumn.
     *
     * @param array|mixed $columns
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
     */
    protected function getKeys(array $models, ?string $key = null): array
    {
        return Helpers::collect($models)->map(static fn ($value) => $key ? $value->getAttribute($key) : $value->getKey())->values()->unique(null, true)->sort()->all();
    }

    /**
     * Get the query builder that will contain the relationship constraints.
     */
    protected function getRelationQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Get the underlying query for the relation.
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
     */
    public static function enforceMorphMap(array $map, bool $merge = true): array
    {
        static::requireMorphMap();

        return static::morphMap($map, $merge);
    }

    /**
     * Set or get the morph map for polymorphic relations.
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
     * @param string[]|null $models
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
     */
    public static function getMorphedModel(string $alias): ?string
    {
        return static::$morphMap[$alias] ?? null;
    }

    /**
     * Handle dynamic method calls to the relationship.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        $result = $this->forwardCallTo($this->query, $method, $parameters);

        if ($result === $this->query) {
            return $this;
        }

        return $result;
    }

    /**
     * Force a clone of the underlying query builder when cloning.
     */
    public function __clone(): void
    {
        $this->query = clone $this->query;
    }
}
