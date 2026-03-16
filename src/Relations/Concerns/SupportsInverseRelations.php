<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Relations\Concerns;

use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Exceptions\RelationNotFoundException;
use BlitzPHP\Wolke\Model;

trait SupportsInverseRelations
{
    /**
     * The name of the inverse relationship.
     */
    protected ?string $inverseRelationship = null;

    /**
     * Instruct Wolke to link the related models back to the parent after the relationship query has run.
     *
     * Alias of "chaperone".
     */
    public function inverse(?string $relation = null): static
    {
        return $this->chaperone($relation);
    }

    /**
     * Instruct Wolke to link the related models back to the parent after the relationship query has run.
     */
    public function chaperone(?string $relation = null): static
    {
        $relation ??= $this->guessInverseRelation();

        if (! $relation || ! $this->getModel()->isRelation($relation)) {
            throw RelationNotFoundException::make($this->getModel(), $relation ?: 'null');
        }

        if ($this->inverseRelationship === null && $relation) {
            $this->query->afterQuery(function ($result) {
                return $this->inverseRelationship
                    ? $this->applyInverseRelationToCollection($result, $this->getParent())
                    : $result;
            });
        }

        $this->inverseRelationship = $relation;

        return $this;
    }

    /**
     * Guess the name of the inverse relationship.
     */
    protected function guessInverseRelation(): ?string
    {
        return Arr::first(
            $this->getPossibleInverseRelations(),
            fn ($relation) => $relation && $this->getModel()->isRelation($relation)
        );
    }

    /**
     * Get the possible inverse relations for the parent model.
     *
     * @return array<non-empty-string>
     */
    protected function getPossibleInverseRelations(): array
    {
        return array_filter(array_unique([
            Text::camel(Text::beforeLast($this->getForeignKeyName(), $this->getParent()->getKeyName())),
            Text::camel(Text::beforeLast($this->getParent()->getForeignKey(), $this->getParent()->getKeyName())),
            Text::camel(Helpers::classBasename($this->getParent())),
            'owner',
            get_class($this->getParent()) === get_class($this->getModel()) ? 'parent' : null,
        ]));
    }

    /**
     * Set the inverse relation on all models in a collection.
     */
    protected function applyInverseRelationToCollection(Collection $models, ?Model $parent = null): Collection
    {
        $parent ??= $this->getParent();

        foreach ($models as $model) {
            $model instanceof Model && $this->applyInverseRelationToModel($model, $parent);
        }

        return $models;
    }

    /**
     * Set the inverse relation on a model.
     */
    protected function applyInverseRelationToModel(Model $model, ?Model $parent = null): Model
    {
        if ($inverse = $this->getInverseRelationship()) {
            $parent ??= $this->getParent();

            $model->setRelation($inverse, $parent);
        }

        return $model;
    }

    /**
     * Get the name of the inverse relationship.
     */
    public function getInverseRelationship(): ?string
    {
        return $this->inverseRelationship;
    }

    /**
     * Remove the chaperone / inverse relationship for this query.
     *
     * Alias of "withoutChaperone".
     */
    public function withoutInverse(): static
    {
        return $this->withoutChaperone();
    }

    /**
     * Remove the chaperone / inverse relationship for this query.
     */
    public function withoutChaperone(): static
    {
        $this->inverseRelationship = null;

        return $this;
    }
}
