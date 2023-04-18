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

use BadMethodCallException;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithDictionary;

class MorphTo extends BelongsTo
{
    use InteractsWithDictionary;

    /**
     * The type of the polymorphic relation.
     *
     * @var string
     */
    protected $morphType;

    /**
     * The models whose relations are being eager loaded.
     *
     * @var Collection
     */
    protected $models;

    /**
     * All of the models keyed by ID.
     */
    protected array $dictionary = [];

    /**
     * A buffer of dynamic calls to query macros.
     */
    protected array $macroBuffer = [];

    /**
     * A map of relations to load for each individual morph type.
     */
    protected array $morphableEagerLoads = [];

    /**
     * A map of relationship counts to load for each individual morph type.
     */
    protected array $morphableEagerLoadCounts = [];

    /**
     * A map of constraints to apply for each individual morph type.
     */
    protected array $morphableConstraints = [];

    /**
     * Create a new morph to relationship instance.
     */
    public function __construct(Builder $query, Model $parent, string $foreignKey, string $ownerKey, string $type, string $relation)
    {
        $this->morphType = $type;

        parent::__construct($query, $parent, $foreignKey, $ownerKey, $relation);
    }

    /**
     * Set the constraints for an eager load of the relation.
     */
    public function addEagerConstraints(array $models): void
    {
        $this->buildDictionary($this->models = Collection::make($models));
    }

    /**
     * Build a dictionary with the models.
     */
    protected function buildDictionary(Collection $models): void
    {
        foreach ($models as $model) {
            if ($model->{$this->morphType}) {
                $morphTypeKey  = $this->getDictionaryKey($model->{$this->morphType});
                $foreignKeyKey = $this->getDictionaryKey($model->{$this->foreignKey});

                $this->dictionary[$morphTypeKey][$foreignKeyKey][] = $model;
            }
        }
    }

    /**
     * Get the results of the relationship.
     *
     * Called via eager load method of Eloquent query builder.
     */
    public function getEager(): Collection
    {
        foreach (array_keys($this->dictionary) as $type) {
            $this->matchToMorphParents($type, $this->getResultsByType($type));
        }

        return $this->models;
    }

    /**
     * Get all of the relation results for a type.
     */
    protected function getResultsByType(string $type): Collection
    {
        $instance = $this->createModelByType($type);

        $ownerKey = $this->ownerKey ?? $instance->getKeyName();

        $query = $this->replayMacros($instance->newQuery())
            ->mergeConstraintsFrom($this->getQuery())
            ->with(array_merge(
                $this->getQuery()->getEagerLoads(),
                (array) ($this->morphableEagerLoads[get_class($instance)] ?? [])
            ))
            ->withCount(
                (array) ($this->morphableEagerLoadCounts[get_class($instance)] ?? [])
            );

        if ($callback = ($this->morphableConstraints[get_class($instance)] ?? null)) {
            $callback($query);
        }

        $whereIn = $this->whereInMethod($instance, $ownerKey);

        return $query->{$whereIn}(
            $instance->getTable() . '.' . $ownerKey,
            $this->gatherKeysByType($type, $instance->getKeyType())
        )->get();
    }

    /**
     * Gather all of the foreign keys for a given type.
     */
    protected function gatherKeysByType(string $type, string $keyType): array
    {
        return $keyType !== 'string'
                    ? array_keys($this->dictionary[$type])
                    : array_map(static fn ($modelId) => (string) $modelId, array_filter(array_keys($this->dictionary[$type])));
    }

    /**
     * Create a new model instance by type.
     */
    public function createModelByType(string $type): Model
    {
        $class = Model::getActualClassNameForMorph($type);

        return Helpers::tap(new $class(), static function ($instance) {
            if (! $instance->getConnectionName()) {
                $instance->setConnection('default');
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        return $models;
    }

    /**
     * Match the results for a given type to their parents.
     */
    protected function matchToMorphParents(string $type, Collection $results): void
    {
        foreach ($results as $result) {
            $ownerKey = null !== $this->ownerKey ? $this->getDictionaryKey($result->{$this->ownerKey}) : $result->getKey();

            if (isset($this->dictionary[$type][$ownerKey])) {
                foreach ($this->dictionary[$type][$ownerKey] as $model) {
                    $model->setRelation($this->relationName, $result);
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param Model $model
     */
    public function associate($model): Model
    {
        if ($model instanceof Model) {
            $foreignKey = $this->ownerKey && $model->{$this->ownerKey}
                            ? $this->ownerKey
                            : $model->getKeyName();
        }

        $this->parent->setAttribute(
            $this->foreignKey,
            $model instanceof Model ? $model->{$foreignKey} : null
        );

        $this->parent->setAttribute(
            $this->morphType,
            $model instanceof Model ? $model->getMorphClass() : null
        );

        return $this->parent->setRelation($this->relationName, $model);
    }

    /**
     * Dissociate previously associated model from the given parent.
     */
    public function dissociate(): Model
    {
        $this->parent->setAttribute($this->foreignKey, null);

        $this->parent->setAttribute($this->morphType, null);

        return $this->parent->setRelation($this->relationName, null);
    }

    /**
     * Touch all of the related models for the relationship.
     */
    public function touch(): void
    {
        if (null !== $this->child->{$this->foreignKey}) {
            parent::touch();
        }
    }

    /**
     * Make a new related instance for the given model.
     */
    protected function newRelatedInstanceFor(Model $parent): Model
    {
        return $parent->{$this->getRelationName()}()->getRelated()->newInstance();
    }

    /**
     * Get the foreign key "type" name.
     */
    public function getMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Get the dictionary used by the relationship.
     */
    public function getDictionary(): array
    {
        return $this->dictionary;
    }

    /**
     * Specify which relations to load for a given morph type.
     */
    public function morphWith(array $with): self
    {
        $this->morphableEagerLoads = array_merge(
            $this->morphableEagerLoads,
            $with
        );

        return $this;
    }

    /**
     * Specify which relationship counts to load for a given morph type.
     */
    public function morphWithCount(array $withCount): self
    {
        $this->morphableEagerLoadCounts = array_merge(
            $this->morphableEagerLoadCounts,
            $withCount
        );

        return $this;
    }

    /**
     * Specify constraints on the query for a given morph type.
     */
    public function constrain(array $callbacks): self
    {
        $this->morphableConstraints = array_merge(
            $this->morphableConstraints,
            $callbacks
        );

        return $this;
    }

    /**
     * Replay stored macro calls on the actual related instance.
     */
    protected function replayMacros(Builder $query): Builder
    {
        foreach ($this->macroBuffer as $macro) {
            $query->{$macro['method']}(...$macro['parameters']);
        }

        return $query;
    }

    /**
     * Handle dynamic method calls to the relationship.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        try {
            $result = parent::__call($method, $parameters);

            if (in_array($method, ['select', 'selectRaw', 'selectSub', 'addSelect', 'withoutGlobalScopes'], true)) {
                $this->macroBuffer[] = compact('method', 'parameters');
            }

            return $result;
        }

        // If we tried to call a method that does not exist on the parent Builder instance,
        // we'll assume that we want to call a query macro (e.g. withTrashed) that only
        // exists on related models. We will just store the call and replay it later.
        catch (BadMethodCallException $e) {
            $this->macroBuffer[] = compact('method', 'parameters');

            return $this;
        }
    }
}
