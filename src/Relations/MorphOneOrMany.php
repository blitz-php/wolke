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

use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Model;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TResult
 *
 * @extends HasOneOrMany<TRelatedModel, TDeclaringModel, TResult>
 *
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\MorphOneOrMany</a>
 */
abstract class MorphOneOrMany extends HasOneOrMany
{
    /**
     * Le type de clé étrangère pour la relation.
     */
    protected string $morphType;

    /**
     * Le nom de classe du modèle parent.
     *
     * @var class-string<TRelatedModel>
     */
    protected string $morphClass;

    /**
     * Crée une nouvelle instance de relation morph one or many.
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel        $parent
     */
    public function __construct(Builder $query, Model $parent, string $type, string $id, string $localKey)
    {
        $this->morphType = $type;

        $this->morphClass = $parent->getMorphClass();

        parent::__construct($query, $parent, $id, $localKey);
    }

    /**
     * Définit les contraintes de base sur la requête de relation.
     */
    public function addConstraints(): void
    {
        if (static::$constraints) {
            $this->getRelationQuery()->where($this->morphType, $this->morphClass);

            parent::addConstraints();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addEagerConstraints(array $models): void
    {
        parent::addEagerConstraints($models);

        $this->getRelationQuery()->where($this->morphType, $this->morphClass);
    }

    /**
     * Crée une nouvelle instance du modèle lié. Permet l'assignation en masse.
     *
     * @return TRelatedModel
     */
    public function forceCreate(array $attributes = []): Model
    {
        $attributes[$this->getForeignKeyName()] = $this->getParentKey();
        $attributes[$this->getMorphType()]      = $this->morphClass;

        return $this->applyInverseRelationToModel($this->related->forceCreate($attributes));
    }

    /**
     * Définit l'ID étranger et le type pour la création d'un modèle lié.
     *
     * @param TRelatedModel $model
     */
    protected function setForeignAttributesForCreate(Model $model): void
    {
        $model->{$this->getForeignKeyName()} = $this->getParentKey();

        $model->{$this->getMorphType()} = $this->morphClass;

        foreach ($this->getQuery()->pendingAttributes as $key => $value) {
            $attributes ??= $model->getAttributes();

            if (! array_key_exists($key, $attributes)) {
                $model->setAttribute($key, $value);
            }
        }

        $this->applyInverseRelationToModel($model);
    }

    /**
     * Insère de nouveaux enregistrements ou met à jour ceux existants.
     */
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null): int
    {
        if ($values !== [] && ! is_array(Arr::first($values))) {
            $values = [$values];
        }

        foreach ($values as $key => $value) {
            $values[$key][$this->getMorphType()] = $this->getMorphClass();
        }

        return parent::upsert($values, $uniqueBy, $update);
    }

    /**
     * Obtient la requête de relation.
     *
     * @param array|mixed $columns
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        return parent::getRelationExistenceQuery($query, $parentQuery, $columns)->where(
            $query->qualifyColumn($this->getMorphType()),
            $this->morphClass,
        );
    }

    /**
     * Obtient le nom du "type" de clé étrangère.
     */
    public function getQualifiedMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Obtient le nom du type morph simple sans la table.
     */
    public function getMorphType(): string
    {
        return Helpers::last(explode('.', $this->morphType));
    }

    /**
     * Obtient le nom de classe du modèle parent.
     *
     * @return class-string<TRelatedModel>
     */
    public function getMorphClass(): string
    {
        return $this->morphClass;
    }

    /**
     * Obtient les relations inverses possibles pour le modèle parent.
     *
     * @return list<non-empty-string>
     */
    protected function getPossibleInverseRelations(): array
    {
        return array_unique([
            Text::beforeLast($this->getMorphType(), '_type'),
            ...parent::getPossibleInverseRelations(),
        ]);
    }
}
