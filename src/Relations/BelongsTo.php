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
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\ComparesRelatedModels;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithDictionary;
use BlitzPHP\Wolke\Relations\Concerns\SupportsDefaultModels;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends Relation<TRelatedModel, TDeclaringModel, ?TRelatedModel>
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\BelongsTo</a>
 */
class BelongsTo extends Relation
{
    use ComparesRelatedModels;
    use InteractsWithDictionary;
    use SupportsDefaultModels;

    /**
     * L'instance du modèle enfant de la relation.
     *
     * @var TDeclaringModel
     */
    protected Model $child;

    /**
     * Crée une nouvelle instance de relation belongs to.
     *
     * @param Builder<TRelatedModel>  $query
     * @param TDeclaringModel  $child
     * @param string $foreignKey    La clé étrangère du modèle parent.
     * @param ?string $ownerKey      La clé associée sur le modèle parent.
     * @param string $relationName   Le nom de la relation.
     */
    public function __construct(Builder $query, Model $child, protected string $foreignKey, protected ?string $ownerKey, protected string $relationName)
    {
        // Dans la classe de relation de base sous-jacente, cette variable est appelée
        // "parent" car la plupart des relations ne sont pas inversées. Mais comme celle-ci
        // l'est, nous créerons une variable "enfant" pour une bien meilleure lisibilité.
        $this->child = $child;

        parent::__construct($query, $child);
    }

    /**
     * {@inheritDoc}
     */
    public function getResults(): mixed
    {
        if (null === $this->getForeignKeyFrom($this->child)) {
            return $this->getDefaultFor($this->parent);
        }

        return $this->query->first() ?: $this->getDefaultFor($this->parent);
    }

    /**
     * {@inheritDoc}
     */
    public function addConstraints(): void
    {
        if (static::$constraints) {
            // Pour les relations belongs to, qui sont essentiellement l'inverse des relations has one
            // ou has many, nous devons en fait interroger sur la clé primaire
            // des modèles liés correspondant à la clé étrangère qui se trouve sur un parent.
            $key = $this->getQualifiedOwnerKeyName();

            $this->query->where($key, '=', $this->getForeignKeyFrom($this->child));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addEagerConstraints(array $models): void
    {
        // Nous allons récupérer le nom de la clé primaire des modèles liés car il pourrait être défini sur
        // un nom non standard et pas "id". Nous allons ensuite construire la contrainte pour
        // notre requête de chargement empressé afin qu'elle renvoie les bons modèles lors de l'exécution.
        $key = $this->getQualifiedOwnerKeyName();

        $whereIn = $this->whereInMethod($this->related, $this->ownerKey);

        $this->whereInEager($whereIn, $key, $this->getEagerModelKeys($models));
    }

    /**
     * Rassemble les clés d'un tableau de modèles liés.
     *
     * @param  array<int, TDeclaringModel>  $models
     */
    protected function getEagerModelKeys(array $models): array
    {
        $keys = [];

        // Nous devons d'abord rassembler toutes les clés des modèles parents afin de savoir
        // quoi interroger via la requête de chargement empressé. Nous les ajouterons à un tableau
        // puis exécuterons une déclaration "where in" pour rassembler tous ces enregistrements liés.
        foreach ($models as $model) {
            if (null !== ($value = $this->getForeignKeyFrom($model))) {
                $keys[] = $value;
            }
        }

        sort($keys);

        return array_values(array_unique($keys));
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
     * Fait correspondre les résultats chargés avec empressement à leurs parents.
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        // Nous allons d'abord construire un dictionnaire des modèles enfants par leur clé primaire
        // de la relation, puis nous pourrons facilement faire correspondre les enfants sur
        // les parents en utilisant ce dictionnaire et la clé primaire des enfants.
        $dictionary = [];

        foreach ($results as $result) {
            $attribute = $this->getDictionaryKey($this->getRelatedKeyFrom($result));

            $dictionary[$attribute] = $result;
        }

        // Une fois que nous avons construit le dictionnaire, nous pouvons parcourir tous les parents
        // et faire correspondre leurs enfants en utilisant ces clés du dictionnaire et
        // la clé primaire des enfants pour les mapper sur les bonnes instances.
        foreach ($models as $model) {
            $attribute = $this->getDictionaryKey($this->getForeignKeyFrom($model));

            if (isset($dictionary[$attribute ?? ''])) {
                $model->setRelation($relation, $dictionary[$attribute ?? '']);
            }
        }

        return $models;
    }

    /**
     * Associe l'instance de modèle au parent donné.
     *
     * @param  TRelatedModel|int|string|null  $model
     * @return TDeclaringModel
     */
    public function associate($model): Model
    {
        $ownerKey = $model instanceof Model ? $model->getAttribute($this->ownerKey) : $model;

        $this->child->setAttribute($this->foreignKey, $ownerKey);

        if ($model instanceof Model) {
            $this->child->setRelation($this->relationName, $model);
        } else {
            $this->child->unsetRelation($this->relationName);
        }

        return $this->child;
    }

    /**
     * Dissocie le modèle précédemment associé du parent donné.
     *
     * @return TDeclaringModel
     */
    public function dissociate(): Model
    {
        $this->child->setAttribute($this->foreignKey, null);

        return $this->child->setRelation($this->relationName, null);
    }

    /**
     * Alias de la méthode "dissociate".
     *
     * @return TDeclaringModel
     */
    public function disassociate(): Model
    {
        return $this->dissociate();
    }

    /**
     * Touche tous les modèles liés pour la relation.
     */
    public function touch(): void
    {
        if (null !== $this->getParentKey()) {
            parent::touch();
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($parentQuery->getQuery()->getTable() === $query->getQuery()->getTable()) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        return $query->select($columns)->whereColumn(
            $this->getQualifiedForeignKeyName(),
            '=',
            $query->qualifyColumn($this->ownerKey)
        );
    }

    /**
     * Ajoute les contraintes pour une requête de relation sur la même table.
     *
     * @param Builder<TRelatedModel> $query
     * @param Builder<TDeclaringModel> $parentQuery
     * 
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $query->select($columns)->from(
            $query->getModel()->getTable() . ' as ' . $hash = $this->getRelationCountHash()
        );

        $query->getModel()->setTable($hash);

        return $query->whereColumn(
            $hash . '.' . $this->ownerKey,
            '=',
            $this->getQualifiedForeignKeyName()
        );
    }

    /**
     * Détermine si le modèle lié a un ID auto-incrémenté.
     */
    protected function relationHasIncrementingId(): bool
    {
        return $this->related->getIncrementing()
            && in_array($this->related->getKeyType(), ['int', 'integer'], true);
    }

    /**
     * Crée une nouvelle instance liée pour le modèle donné.
     *
     * @param  TDeclaringModel  $parent
     * 
     * @return TRelatedModel
     */
    protected function newRelatedInstanceFor(Model $parent): Model
    {
        return $this->related->newInstance();
    }

    /**
     * Obtient l'enfant de la relation.
     *
     * @return TDeclaringModel
     */
    public function getChild(): Model
    {
        return $this->child;
    }

    /**
     * Obtient le nom de la clé étrangère de la relation.
     */
    public function getForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    /**
     * Obtient la clé étrangère complètement qualifiée de la relation.
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->child->qualifyColumn($this->foreignKey);
    }

    /**
     * Obtient la valeur de la clé de la clé étrangère de l'enfant.
     */
    public function getParentKey(): mixed
    {
        return $this->getForeignKeyFrom($this->child);
    }

    /**
     * Obtient le nom de la clé associée de la relation.
     */
    public function getOwnerKeyName(): string
    {
        return $this->ownerKey;
    }

    /**
     * Obtient la clé associée complètement qualifiée de la relation.
     */
    public function getQualifiedOwnerKeyName(): string
    {
        return $this->related->qualifyColumn($this->ownerKey);
    }

    /**
     * Obtient la valeur de la clé associée du modèle.
     *
     * @param  TRelatedModel  $model
     * 
     * @return int|string
     */
    protected function getRelatedKeyFrom(Model $model): mixed
    {
        return $model->{$this->ownerKey};
    }

    /**
     * Obtient la valeur de la clé étrangère du modèle.
     *
     * @param TDeclaringModel $model
     */
    protected function getForeignKeyFrom(Model $model): mixed
    {
        $foreignKey = $model->{$this->foreignKey};

        return Helpers::enumValue($foreignKey);
    }

    /**
     * Obtient le nom de la relation.
     */
    public function getRelationName(): string
    {
        return $this->relationName;
    }
}
