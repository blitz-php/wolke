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
use BlitzPHP\Utilities\Iterable\Arr;
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
 *
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\HasOneOrMany</a>
 */
abstract class HasOneOrMany extends Relation
{
    use InteractsWithDictionary;
    use SupportsInverseRelations;

    /**
     * Crée une nouvelle instance de relation has one ou many.
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel        $parent
     * @param string                 $foreignKey La clé étrangère du modèle parent.
     * @param string                 $localKey   La clé locale du modèle parent.
     */
    public function __construct(Builder $query, Model $parent, protected string $foreignKey, protected string $localKey)
    {
        parent::__construct($query, $parent);
    }

    /**
     * Crée et retourne une instance non sauvegardée du modèle lié.
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
     * Crée et retourne des instances non sauvegardées des modèles liés.
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
     * Définit les contraintes de base sur la requête de relation.
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
            $this->getRelationQuery(),
        );
    }

    /**
     * Fait correspondre les résultats chargés avec empressement à leurs parents uniques.
     *
     * @param array<int, TDeclaringModel>    $models
     * @param Collection<int, TRelatedModel> $results
     *
     * @return array<int, TDeclaringModel>
     */
    public function matchOne(array $models, Collection $results, string $relation): array
    {
        return $this->matchOneOrMany($models, $results, $relation, 'one');
    }

    /**
     * Fait correspondre les résultats chargés avec empressement à leurs parents multiples.
     *
     * @param array<int, TDeclaringModel>    $models
     * @param Collection<int, TRelatedModel> $results
     *
     * @return array<int, TDeclaringModel>
     */
    public function matchMany(array $models, Collection $results, string $relation): array
    {
        return $this->matchOneOrMany($models, $results, $relation, 'many');
    }

    /**
     * Fait correspondre les résultats chargés avec empressement à leurs parents multiples.
     *
     * @param array<int, TDeclaringModel>    $models
     * @param Collection<int, TRelatedModel> $results
     *
     * @return array<int, TDeclaringModel>
     */
    protected function matchOneOrMany(array $models, Collection $results, string $relation, string $type): array
    {
        $dictionary = $this->buildDictionary($results);

        // Une fois que nous avons le dictionnaire, nous pouvons simplement parcourir les modèles parents pour
        // les lier à leurs enfants en utilisant le dictionnaire indexé par clé pour rendre la
        // correspondance très pratique et facile à travailler. Ensuite, nous les retournerons.
        foreach ($models as $model) {
            $key = $this->getDictionaryKey($model->getAttribute($this->localKey));

            if ($key !== null && isset($dictionary[$key])) {
                $related = $this->getRelationValue($dictionary, $key, $type);

                $model->setRelation($relation, $related);

                // Applique la relation inverse si nous en avons une...
                $type === 'one'
                    ? $this->applyInverseRelationToModel($related, $model)
                    : $this->applyInverseRelationToCollection($related, $model);
            }
        }

        return $models;
    }

    /**
     * Obtient la valeur d'une relation par type one ou many.
     */
    protected function getRelationValue(array $dictionary, string $key, string $type): mixed
    {
        $value = $dictionary[$key];

        return $type === 'one' ? reset($value) : $this->related->newCollection($value);
    }

    /**
     * Construit un dictionnaire de modèles indexé par la clé étrangère de la relation.
     *
     * @param Collection<int, TRelatedModel> $results
     *
     * @return list<array<array-key, TRelatedModel>>
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
     * Trouve un modèle par sa clé primaire ou retourne une nouvelle instance du modèle lié.
     *
     * @return ($id is (Arrayable<array-key, mixed>|list<mixed>) ? Collection<int, TRelatedModel> : TRelatedModel)
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
     * Obtient le premier modèle lié correspondant aux attributs ou l'instancie.
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
     * Obtient le premier enregistrement lié correspondant aux attributs ou le crée.
     *
     * @param array|(Closure(): array) $values
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
     * Tente de créer l'enregistrement. Si une violation de contrainte unique se produit, tente de trouver l'enregistrement correspondant.
     *
     * @param array|(Closure(): array) $values
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
     * Crée ou met à jour un enregistrement lié correspondant aux attributs, et le remplit avec des valeurs.
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
     * Insère de nouveaux enregistrements ou met à jour ceux existants.
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
     * Attache une instance de modèle au modèle parent.
     *
     * @param TRelatedModel $model
     *
     * @return false|TRelatedModel
     */
    public function save(Model $model)
    {
        $this->setForeignAttributesForCreate($model);

        return $model->save() ? $model : false;
    }

    /**
     * Attache une instance de modèle au modèle parent sans déclencher d'événements.
     *
     * @param TRelatedModel $model
     *
     * @return false|TRelatedModel
     */
    public function saveQuietly(Model $model)
    {
        return Model::withoutEvents(fn () => $this->save($model));
    }

    /**
     * Attache une collection de modèles à l'instance parente.
     *
     * @param iterable<TRelatedModel> $models
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
     * Attache une collection de modèles à l'instance parente sans déclencher d'événements.
     *
     * @param iterable<TRelatedModel> $models
     *
     * @return iterable<TRelatedModel>
     */
    public function saveManyQuietly(iterable $models): iterable
    {
        return Model::withoutEvents(fn () => $this->saveMany($models));
    }

    /**
     * Crée une nouvelle instance du modèle lié.
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
     * Crée une nouvelle instance du modèle lié sans déclencher d'événements.
     */
    public function createQuietly(array $attributes = []): Model
    {
        return Model::withoutEvents(fn () => $this->create($attributes));
    }

    /**
     * Crée une nouvelle instance du modèle lié. Permet l'assignation en masse.
     *
     * @return TRelatedModel
     */
    public function forceCreate(array $attributes = []): Model
    {
        $attributes[$this->getForeignKeyName()] = $this->getParentKey();

        return $this->applyInverseRelationToModel($this->related->forceCreate($attributes));
    }

    /**
     * Crée une nouvelle instance du modèle lié avec assignation en masse sans déclencher d'événements.
     *
     * @return TRelatedModel
     */
    public function forceCreateQuietly(array $attributes = []): Model
    {
        return Model::withoutEvents(fn () => $this->forceCreate($attributes));
    }

    /**
     * Crée une Collection de nouvelles instances du modèle lié.
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
     * Crée une Collection de nouvelles instances du modèle lié sans déclencher d'événements.
     *
     * @return Collection<int, TRelatedModel>
     */
    public function createManyQuietly(iterable $records): Collection
    {
        return Model::withoutEvents(fn () => $this->createMany($records));
    }

    /**
     * Crée une Collection de nouvelles instances du modèle lié, permettant l'assignation en masse.
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
     * Crée une Collection de nouvelles instances du modèle lié, permettant l'assignation en masse et sans déclencher d'événements.
     *
     * @return Collection<int, TRelatedModel>
     */
    public function forceCreateManyQuietly(iterable $records): Collection
    {
        return Model::withoutEvents(fn () => $this->forceCreateMany($records));
    }

    /**
     * Définit l'ID étranger pour la création d'un modèle lié.
     *
     * @param TRelatedModel $model
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
     * Ajoute les contraintes pour une requête de relation sur la même table.
     *
     * @param Builder<TRelatedModel>   $query
     * @param Builder<TDeclaringModel> $parentQuery
     *
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $query->from($query->getModel()->getTable() . ' as ' . $hash = $this->getRelationCountHash());

        $query->getModel()->setTable($hash);

        return $query->select($columns)->whereColumn(
            $this->getQualifiedParentKeyName(),
            '=',
            $hash . '.' . $this->getForeignKeyName(),
        );
    }

    /**
     * Obtient la clé pour la comparaison avec la clé parente dans la requête "has".
     */
    public function getExistenceCompareKey(): string
    {
        return $this->getQualifiedForeignKeyName();
    }

    /**
     * Obtient la valeur de la clé de la clé locale du parent.
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
     * Obtient la clé étrangère simple.
     */
    public function getForeignKeyName(): string
    {
        $segments = explode('.', $this->getQualifiedForeignKeyName());

        return end($segments);
    }

    /**
     * Obtient la clé étrangère pour la relation.
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->foreignKey;
    }

    /**
     * Obtient la clé locale pour la relation.
     */
    public function getLocalKeyName(): string
    {
        return $this->localKey;
    }
}
