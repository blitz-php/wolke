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

use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Model;

abstract class MorphOneOrMany extends HasOneOrMany
{
    /**
     * The foreign key type for the relationship.
     *
     * @var string
     */
    protected $morphType;

    /**
     * The class name of the parent model.
     *
     * @var string
     */
    protected $morphClass;

    /**
     * Create a new morph one or many relationship instance.
     */
    public function __construct(Builder $query, Model $parent, string $type, string $id, string $localKey)
    {
        $this->morphType = $type;

        $this->morphClass = $parent->getMorphClass();

        parent::__construct($query, $parent, $id, $localKey);
    }

    /**
     * Set the base constraints on the relation query.
     */
    public function addConstraints(): void
    {
        if (static::$constraints) {
            $this->getRelationQuery()->where($this->morphType, $this->morphClass);

            parent::addConstraints();
        }
    }

    /**
     * Set the constraints for an eager load of the relation.
     */
    public function addEagerConstraints(array $models): void
    {
        parent::addEagerConstraints($models);

        $this->getRelationQuery()->where($this->morphType, $this->morphClass);
    }

    /**
     * Create a new instance of the related model. Allow mass-assignment.
     */
    public function forceCreate(array $attributes = []): Model
    {
        $attributes[$this->getForeignKeyName()] = $this->getParentKey();
        $attributes[$this->getMorphType()]      = $this->morphClass;

        return $this->related->forceCreate($attributes);
    }

    /**
     * Set the foreign ID and type for creating a related model.
     */
    protected function setForeignAttributesForCreate(Model $model): void
    {
        $model->{$this->getForeignKeyName()} = $this->getParentKey();

        $model->{$this->getMorphType()} = $this->morphClass;
    }

    /**
     * Get the relationship query.
     *
     * @param array|mixed $columns
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        return parent::getRelationExistenceQuery($query, $parentQuery, $columns)->where(
            $query->qualifyColumn($this->getMorphType()),
            $this->morphClass
        );
    }

    /**
     * Get the foreign key "type" name.
     */
    public function getQualifiedMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Get the plain morph type name without the table.
     */
    public function getMorphType(): string
    {
        return end($last = explode('.', $this->morphType));
    }

    /**
     * Get the class name of the parent model.
     */
    public function getMorphClass(): string
    {
        return $this->morphClass;
    }
}
