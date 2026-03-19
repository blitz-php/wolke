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

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\Concerns\SupportsInverseRelations</a>
 */
trait SupportsInverseRelations
{
    /**
     * Le nom de la relation inverse.
     */
    protected ?string $inverseRelationship = null;

    /**
     * Indique à Wolke de lier les modèles liés au parent après l'exécution de la requête de relation.
     *
     * Alias de "chaperone".
     */
    public function inverse(?string $relation = null): static
    {
        return $this->chaperone($relation);
    }

    /**
     * Indique à Wolke de lier les modèles liés au parent après l'exécution de la requête de relation.
     */
    public function chaperone(?string $relation = null): static
    {
        $relation ??= $this->guessInverseRelation();

        if (! $relation || ! $this->getModel()->isRelation($relation)) {
            throw RelationNotFoundException::make($this->getModel(), $relation ?: 'null');
        }

        if ($this->inverseRelationship === null && $relation) {
            $this->query->afterQuery(fn ($result) => $this->inverseRelationship
                    ? $this->applyInverseRelationToCollection($result, $this->getParent())
                    : $result);
        }

        $this->inverseRelationship = $relation;

        return $this;
    }

    /**
     * Devine le nom de la relation inverse.
     */
    protected function guessInverseRelation(): ?string
    {
        return Arr::first(
            $this->getPossibleInverseRelations(),
            fn ($relation) => $relation && $this->getModel()->isRelation($relation),
        );
    }

    /**
     * Obtient les relations inverses possibles pour le modèle parent.
     *
     * @return list<non-empty-string>
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
     * Définit la relation inverse sur tous les modèles d'une collection.
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
     * Définit la relation inverse sur un modèle.
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
     * Obtient le nom de la relation inverse.
     */
    public function getInverseRelationship(): ?string
    {
        return $this->inverseRelationship;
    }

    /**
     * Supprime la relation chaperone / inverse pour cette requête.
     *
     * Alias de "withoutChaperone".
     */
    public function withoutInverse(): static
    {
        return $this->withoutChaperone();
    }

    /**
     * Supprime la relation chaperone / inverse pour cette requête.
     */
    public function withoutChaperone(): static
    {
        $this->inverseRelationship = null;

        return $this;
    }
}
