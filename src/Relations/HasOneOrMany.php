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
use BlitzPHP\Utilities\Invade\Invader;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithDictionary;
use BlitzPHP\Wolke\Relations\Concerns\SupportsInverseRelations;
use Closure;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TResult
 *
 * @extends Relation<TRelatedModel, TDeclaringModel, TResult>
 */
abstract class HasOneOrMany extends Relation
{
    use InteractsWithDictionary;
    use SupportsInverseRelations;

    /**
     * Create a new has one or many relationship instance.
     *
     * @param Builder<TRelatedModel>  $query
     * @param TDeclaringModel  $parent
     * @param string $foreignKey The foreign key of the parent model.
     * @param string $localKey   The local key of the parent model.
     */
    public function __construct(Builder $query, Model $parent, protected string $foreignKey, protected string $localKey)
    {
        parent::__construct($query, $parent);
    }

    /**
     * Create and return an un-saved instance of the related model.
     * 
     * @return TRelatedModel
     */
    public function make(array $attributes = []): Model
    {
        return Helpers::tap($this->related->newInstance($attributes), function ($instance) {
            $this->setForeignAttributesForCreate($instance);
            $this->applyInverseRelationToModel($instance);
        });
    }

    /**
     * Create and return an un-saved instance of the related models.
     * 
     * @return Collection<int, TRelatedModel>
     */
    public function makeMany(iterable $records): Collection
    {
        $instances = $this->related->newCollection();

        foreach ($records as $record) {
            $instances->push($this->make($record));
        }

        return $instances;
    }

    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        if (static::$constraints) {
            $query = $this->getRelationQuery();

            $query->where($this->foreignKey, '=', $this->getParentKey());

            $query->whereNotNull($this->foreignKey);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addEagerConstraints(array $models): void
    {
        $whereIn = $this->whereInMethod($this->parent, $this->localKey);

        $this->whereInEager(
            $whereIn,
            $this->foreignKey,
            $this->getKeys($models, $this->localKey),
            $this->getRelationQuery()
        );
    }

    /**
     * Match the eagerly loaded results to their single parents.
     *
     * @param array<int, TDeclaringModel>  $models
     * @param Collection<int, TRelatedModel>  $results
     * 
     * @return array<int, TDeclaringModel>
     */
    public function matchOne(array $models, Collection $results, string $relation): array
    {
        return $this->matchOneOrMany($models, $results, $relation, 'one');
    }

    /**
     * Match the eagerly loaded results to their many parents.
     *
     * @param array<int, TDeclaringModel>  $models
     * @param Collection<int, TRelatedModel>  $results
     * 
     * @return array<int, TDeclaringModel>
     */
    public function matchMany(array $models, Collection $results, string $relation): array
    {
        return $this->matchOneOrMany($models, $results, $relation, 'many');
    }

    /**
     * Match the eagerly loaded results to their many parents.
     *
     * @param array<int, TDeclaringModel>  $models
     * @param Collection<int, TRelatedModel>  $results
     * 
     * @return array<int, TDeclaringModel>
     */
    protected function matchOneOrMany(array $models, Collection $results, string $relation, string $type): array
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary we can simply spin through the parent models to
        // link them up with their children using the keyed dictionary to make the
        // matching very convenient and easy work. Then we'll just return them.
        foreach ($models as $model) {
            $key = $this->getDictionaryKey($model->getAttribute($this->localKey));

            if ($key !== null && isset($dictionary[$key])) {
                $related = $this->getRelationValue($dictionary, $key, $type);

                $model->setRelation($relation, $related);

                // Apply the inverse relation if we have one...
                $type === 'one'
                    ? $this->applyInverseRelationToModel($related, $model)
                    : $this->applyInverseRelationToCollection($related, $model);
            }
        }

        return $models;
    }

    /**
     * Get the value of a relationship by one or many type.
     */
    protected function getRelationValue(array $dictionary, string $key, string $type): mixed
    {
        $value = $dictionary[$key];

        return $type === 'one' ? reset($value) : $this->related->newCollection($value);
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
        $foreign = $this->getForeignKeyName();

        $dictionary = [];

        $isAssociative = Arr::isAssoc($results->all());

        foreach ($results as $key => $item) {
            $pairKey = $this->getDictionaryKey($item->{$foreign});

            if ($isAssociative) {
                $dictionary[$pairKey][$key] = $item;
            } else {
                $dictionary[$pairKey][] = $item;
            }
        }

        return $dictionary;
    }

    /**
     * Find a model by its primary key or return a new instance of the related model.
     *
     * @return ($id is (Arrayable<array-key, mixed>|array<mixed>) ? Collection<int, TRelatedModel> : TRelatedModel)
     */
    public function findOrNew(mixed $id, array $columns = ['*'])
    {
        if (null === ($instance = $this->find($id, $columns))) {
            $instance = $this->related->newInstance();

            $this->setForeignAttributesForCreate($instance);
        }

        return $instance;
    }

    /**
     * Get the first related model record matching the attributes or instantiate it.
     * 
     * @return TRelatedModel
     */
    public function firstOrNew(array $attributes = [], array $values = []): Model
    {
        if (null === ($instance = $this->where($attributes)->first())) {
            $instance = $this->related->newInstance(array_merge($attributes, $values));

            $this->setForeignAttributesForCreate($instance);
        }

        return $instance;
    }

    /**
     * Get the first related record matching the attributes or create it.
     * 
     * @param  (Closure(): array)|array  $values
     * 
     * @return TRelatedModel
     */
    public function firstOrCreate(array $attributes = [], array|Closure $values = []): Model
    {
        if (null === $instance = (clone $this)->where($attributes)->first()) {
            $instance = $this->createOrFirst($attributes, $values);
        }

        return $instance;
    }

    /**
     * Attempt to create the record. If a unique constraint violation occurs, attempt to find the matching record.
     * 
     * @param  (Closure(): array)|array  $values
     * 
     * @return TRelatedModel
     */
    public function createOrFirst(array $attributes = [], array|Closure $values = []): Model
    {
        try {
            return $this->getQuery()->withSavepointIfNeeded(fn () => $this->create(array_merge($attributes, Helpers::value($values))));
        } catch (UniqueConstraintViolationException $e) {
            return $this->where($attributes)->first() ?? throw $e;
        }
    }

    /**
     * Create or update a related record matching the attributes, and fill it with values.
     * 
     * @return TRelatedModel
     */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        return Helpers::tap($this->firstOrCreate($attributes, $values), static function ($instance) use ($values) {
            if (! $instance->wasRecentlyCreated) {
                $instance->fill($values)->save();
            }
        });
    }

    /**
     * Insert new records or update the existing ones.
     */
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null): int
    {
        if ($values !== [] && ! is_array(Arr::first($values))) {
            $values = [$values];
        }

        foreach ($values as $key => $value) {
            $values[$key][$this->getForeignKeyName()] = $this->getParentKey();
        }

        return $this->getQuery()->upsert($values, $uniqueBy, $update);
    }

    /**
     * Attach a model instance to the parent model.
     *
     * @param  TRelatedModel  $model
     * 
     * @return TRelatedModel|false
     */
    public function save(Model $model)
    {
        $this->setForeignAttributesForCreate($model);

        return $model->save() ? $model : false;
    }

    /**
     * Attach a model instance without raising any events to the parent model.
     *
     * @param  TRelatedModel  $model
     * 
     * @return TRelatedModel|false
     */
    public function saveQuietly(Model $model)
    {
        return Model::withoutEvents(fn () => $this->save($model));
    }

    /**
     * Attach a collection of models to the parent instance.
     *
     * @param  iterable<TRelatedModel>  $models
     * 
     * @return iterable<TRelatedModel>
     */
    public function saveMany(iterable $models): iterable
    {
        foreach ($models as $model) {
            $this->save($model);
        }

        return $models;
    }

    /**
     * Attach a collection of models to the parent instance without raising any events to the parent model.
     *
     * @param  iterable<TRelatedModel>  $models
     * 
     * @return iterable<TRelatedModel>
     */
    public function saveManyQuietly(iterable $models): iterable
    {
        return Model::withoutEvents(fn () => $this->saveMany($models));
    }

    /**
     * Create a new instance of the related model.
     */
    public function create(array $attributes = []): Model
    {
        return Helpers::tap($this->related->newInstance($attributes), function ($instance) {
            $this->setForeignAttributesForCreate($instance);

            $instance->save();

            $this->applyInverseRelationToModel($instance);
        });
    }

    /**
     * Create a new instance of the related model without raising any events to the parent model.
     */
    public function createQuietly(array $attributes = []): Model
    {
        return Model::withoutEvents(fn () => $this->create($attributes));
    }

    /**
     * Create a new instance of the related model. Allow mass-assignment.
     * 
     * @return TRelatedModel
     */
    public function forceCreate(array $attributes = []): Model
    {
        $attributes[$this->getForeignKeyName()] = $this->getParentKey();

        return $this->applyInverseRelationToModel($this->related->forceCreate($attributes));
    }

    /**
     * Create a new instance of the related model with mass assignment without raising model events.
     * 
     * @return TRelatedModel
     */
    public function forceCreateQuietly(array $attributes = []): Model
    {
        return Model::withoutEvents(fn () => $this->forceCreate($attributes));
    }

    /**
     * Create a Collection of new instances of the related model.
     * 
     * @return Collection<int, TRelatedModel>
     */
    public function createMany(iterable $records): Collection
    {
        $instances = $this->related->newCollection();

        foreach ($records as $record) {
            $instances->push($this->create($record));
        }

        return $instances;
    }

    /**
     * Create a Collection of new instances of the related model without raising any events to the parent model.
     * 
     * @return Collection<int, TRelatedModel>
     */
    public function createManyQuietly(iterable $records): Collection
    {
        return Model::withoutEvents(fn () => $this->createMany($records));
    }

    /**
     * Create a Collection of new instances of the related model, allowing mass-assignment.
     *
     * @param  iterable  $records
     * 
     * @return Collection<int, TRelatedModel>
     */
    public function forceCreateMany(iterable $records): Collection
    {
        $instances = $this->related->newCollection();

        foreach ($records as $record) {
            $instances->push($this->forceCreate($record));
        }

        return $instances;
    }

    /**
     * Create a Collection of new instances of the related model, allowing mass-assignment and without raising any events to the parent model.
     *
     * @return Collection<int, TRelatedModel>
     */
    public function forceCreateManyQuietly(iterable $records): Collection
    {
        return Model::withoutEvents(fn () => $this->forceCreateMany($records));
    }

    /**
     * Set the foreign ID for creating a related model.
     * 
     * @param  TRelatedModel  $model
     */
    protected function setForeignAttributesForCreate(Model $model): void
    {
        $model->setAttribute($this->getForeignKeyName(), $this->getParentKey());

        foreach ($this->getQuery()->pendingAttributes as $key => $value) {
            $attributes ??= $model->getAttributes();

            if (! array_key_exists($key, $attributes)) {
                $model->setAttribute($key, $value);
            }
        }

        $this->applyInverseRelationToModel($model);
    }

    /**
     * {@inheritDoc}
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($query->getQuery()->from === $parentQuery->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param Builder<TRelatedModel>  $query
     * @param Builder<TDeclaringModel>  $parentQuery
     * 
     * @returnBuilder<TRelatedModel>
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $query->from($query->getModel()->getTable() . ' as ' . $hash = $this->getRelationCountHash());

        $query->getModel()->setTable($hash);

        return $query->select($columns)->whereColumn(
            $this->getQualifiedParentKeyName(),
            '=',
            $hash . '.' . $this->getForeignKeyName()
        );
    }

    /**
     * Get the key for comparing against the parent key in "has" query.
     */
    public function getExistenceCompareKey(): string
    {
        return $this->getQualifiedForeignKeyName();
    }

    /**
     * Get the key value of the parent's local key.
     */
    public function getParentKey(): mixed
    {
        return $this->parent->getAttribute($this->localKey);
    }

    /**
     * {@inheritDoc}
     */
    public function getQualifiedParentKeyName(): string
    {
        return $this->parent->qualifyColumn($this->localKey);
    }

    /**
     * Get the plain foreign key.
     */
    public function getForeignKeyName(): string
    {
        $segments = explode('.', $this->getQualifiedForeignKeyName());

        return end($segments);
    }

    /**
     * Get the foreign key for the relationship.
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the local key for the relationship.
     */
    public function getLocalKeyName(): string
    {
        return $this->localKey;
    }
}
