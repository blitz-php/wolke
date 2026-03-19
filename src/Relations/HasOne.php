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

use BlitzPHP\Database\Builder\JoinClause;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Contracts\SupportsPartialRelations;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\CanBeOneOfMany;
use BlitzPHP\Wolke\Relations\Concerns\ComparesRelatedModels;
use BlitzPHP\Wolke\Relations\Concerns\SupportsDefaultModels;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasOneOrMany<TRelatedModel, TDeclaringModel, ?TRelatedModel>
 *
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\HasOne</a>
 */
class HasOne extends HasOneOrMany implements SupportsPartialRelations
{
    use ComparesRelatedModels;
    use CanBeOneOfMany;
    use SupportsDefaultModels;

    /**
     * {@inheritDoc}
     */
    public function getResults(): mixed
    {
        if (null === $this->getParentKey()) {
            return $this->getDefaultFor($this->parent);
        }

        return $this->query->first() ?: $this->getDefaultFor($this->parent);
    }

    /**
     * {@inheritDoc}
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->getDefaultFor($model));
        }

        return $models;
    }

    /**
     * {@inheritDoc}
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        return $this->matchOne($models, $results, $relation);
    }

    /**
     * {@inheritDoc}
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($this->isOneOfMany()) {
            $this->mergeOneOfManyJoinsTo($query);
        }

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Ajoute des contraintes pour la sous-requête de jointure interne pour les relations one of many.
     *
     * @param Builder<TRelatedModel>   $query
     * @param list<string>|string|null $aggregate
     */
    public function addOneOfManySubQueryConstraints(Builder $query, ?string $column = null, $aggregate = null): void
    {
        $query->select($this->foreignKey);
    }

    /**
     * Obtient les colonnes qui doivent être sélectionnées par la sous-requête one of many.
     */
    public function getOneOfManySubQuerySelectColumns(): string
    {
        return $this->foreignKey;
    }

    /**
     * Ajoute des contraintes de requête de jointure pour les relations one of many.
     */
    public function addOneOfManyJoinSubQueryConstraints(JoinClause $join): void
    {
        $join->on($this->qualifySubSelectColumn($this->foreignKey), '=', $this->qualifyRelatedColumn($this->foreignKey));
    }

    /**
     * Crée une nouvelle instance liée pour le modèle donné.
     *
     * @param TDeclaringModel $parent
     *
     * @return TRelatedModel
     */
    public function newRelatedInstanceFor(Model $parent): Model
    {
        return Helpers::tap($this->related->newInstance(), function ($instance) use ($parent) {
            $instance->setAttribute($this->getForeignKeyName(), $parent->{$this->localKey});
            $this->applyInverseRelationToModel($instance, $parent);
        });
    }

    /**
     * Obtient la valeur de la clé étrangère du modèle.
     *
     * @param TRelatedModel $model
     *
     * @return int|string
     */
    protected function getRelatedKeyFrom(Model $model): mixed
    {
        return $model->getAttribute($this->getForeignKeyName());
    }
}
