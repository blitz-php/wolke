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

use BlitzPHP\Database\Exceptions\UniqueConstraintViolationException;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Utilities\Support\Invader;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithDictionary;

abstract class HasOneOrMany extends Relation
{
    use InteractsWithDictionary;

    /**
     * Create a new has one or many relationship instance.
     *
     * @param string $foreignKey The foreign key of the parent model.
     * @param string $localKey   The local key of the parent model.
     */
    public function __construct(Builder $query, Model $parent, protected string $foreignKey, protected string $localKey)
    {
        parent::__construct($query, $parent);
    }

    /**
     * Create and return an un-saved instance of the related model.
     */
    public function make(array $attributes = []): Model
    {
        return Helpers::tap($this->related->newInstance($attributes), function ($instance) {
            $this->setForeignAttributesForCreate($instance);
        });
    }

    /**
     * Create and return an un-saved instance of the related models.
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
     * Set the constraints for an eager load of the relation.
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
     */
    public function matchOne(array $models, Collection $results, string $relation): array
    {
        return $this->matchOneOrMany($models, $results, $relation, 'one');
    }

    /**
     * Match the eagerly loaded results to their many parents.
     */
    public function matchMany(array $models, Collection $results, string $relation): array
    {
        return $this->matchOneOrMany($models, $results, $relation, 'many');
    }

    /**
     * Match the eagerly loaded results to their many parents.
     */
    protected function matchOneOrMany(array $models, Collection $results, string $relation, string $type): array
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary we can simply spin through the parent models to
        // link them up with their children using the keyed dictionary to make the
        // matching very convenient and easy work. Then we'll just return them.
        foreach ($models as $model) {
            if (isset($dictionary[$key = $this->getDictionaryKey($model->getAttribute($this->localKey))])) {
                $model->setRelation(
                    $relation,
                    $this->getRelationValue($dictionary, $key, $type)
                );
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
     */
    protected function buildDictionary(Collection $results): array
    {
        $foreign = $this->getForeignKeyName();

        return $results->mapToDictionary(fn ($result) => [$this->getDictionaryKey($result->{$foreign}) => $result])->all();
    }

    /**
     * Find a model by its primary key or return a new instance of the related model.
     *
     * @return IterableCollection|Model
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
     */
    public function firstOrCreate(array $attributes = [], array $values = []): Model
    {
        if (null === $instance = (clone $this)->where($attributes)->first()) {
            $instance = $this->createOrFirst($attributes, $values);
        }

        return $instance;
    }

    /**
     * Attempt to create the record. If a unique constraint violation occurs, attempt to find the matching record.
     */
    public function createOrFirst(array $attributes = [], array $values = []): Model
    {
        try {
            return $this->getQuery()->withSavepointIfNeeded(fn () => $this->create(array_merge($attributes, $values)));
        } catch (UniqueConstraintViolationException $e) {
            return $this->where($attributes)->first() ?? throw $e;
        }
    }

    /**
     * Create or update a related record matching the attributes, and fill it with values.
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
     * Attach a model instance to the parent model.
     *
     * @return false|Model
     */
    public function save(Model $model)
    {
        $this->setForeignAttributesForCreate($model);

        return $model->save() ? $model : false;
    }

    /**
     * Attach a model instance without raising any events to the parent model.
     *
     * @return false|Model
     */
    public function saveQuietly(Model $model)
    {
        return Model::withoutEvents(fn () => $this->save($model));
    }

    /**
     * Attach a collection of models to the parent instance.
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
     */
    public function forceCreate(array $attributes = []): Model
    {
        $attributes[$this->getForeignKeyName()] = $this->getParentKey();

        return $this->related->forceCreate($attributes);
    }

    /**
     * Create a new instance of the related model with mass assignment without raising model events.
     */
    public function forceCreateQuietly(array $attributes = []): Model
    {
        return Model::withoutEvents(fn () => $this->forceCreate($attributes));
    }

    /**
     * Create a Collection of new instances of the related model.
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
     */
    public function createManyQuietly(iterable $records): Collection
    {
        return Model::withoutEvents(fn () => $this->createMany($records));
    }

    /**
     * Set the foreign ID for creating a related model.
     */
    protected function setForeignAttributesForCreate(Model $model): void
    {
        $model->setAttribute($this->getForeignKeyName(), $this->getParentKey());
    }

    /**
     * Add the constraints for a relationship query.
     *
     * @param array|mixed $columns
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if (Invader::make($query->getQuery())->table === Invader::make($parentQuery->getQuery())->table) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Add the constraints for a relationship query on the same table.
     *
     * @param array|mixed $columns
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
