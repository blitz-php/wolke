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
use BlitzPHP\Database\Exceptions\MultipleRecordsFoundException;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\LazyCollection;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Contracts\CursorPaginator;
use BlitzPHP\Wolke\Contracts\LengthAwarePaginator;
use BlitzPHP\Wolke\Contracts\Paginator;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithDictionary;
use Closure;

/**
 * @template TRelatedModel of Model
 * @template TIntermediateModel of Model
 * @template TDeclaringModel of Model
 * @template TResult
 *
 * @extends Relation<TRelatedModel, TIntermediateModel, TResult>
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\HasOneOrManyThrough</a>
 */
abstract class HasOneOrManyThrough extends Relation
{
    use InteractsWithDictionary;

    /**
     * Crée une nouvelle instance de relation has many through.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $farParent L'instance du modèle parent éloigné.
     * @param  TIntermediateModel  $throughParent  L'instance du modèle parent "through".
     * @param string $firstKey       La clé proche sur la relation.
     * @param string $secondKey      La clé éloignée sur la relation.
     * @param string $localKey       La clé locale sur la relation.
     * @param string $secondLocalKey La clé locale sur le modèle intermédiaire.
     */
    public function __construct(Builder $query, protected Model $farParent, protected Model $throughParent, protected string $firstKey, protected string $secondKey, protected string $localKey, protected string $secondLocalKey)
    {
        parent::__construct($query, $throughParent);
    }

    /**
     * Définit les contraintes de base sur la requête de relation.
     */
    public function addConstraints(): void
    {
        $query = $this->getRelationQuery();

        $localValue = $this->farParent[$this->localKey];

        $this->performJoin($query);

        if (static::$constraints) {
            $query->where($this->getQualifiedFirstKeyName(), '=', $localValue);
        }
    }

    /**
     * Définit la clause de jointure sur la requête.
     *
     * @param Builder<TRelatedModel>|null  $query
     */
    protected function performJoin(?Builder $query = null): void
    {
        $query ??= $this->query;

        $farKey = $this->getQualifiedFarKeyName();

        $query->join($this->throughParent->getTable(), $this->getQualifiedParentKeyName(), '=', $farKey);

        if ($this->throughParentSoftDeletes()) {
            $query->withGlobalScope('SoftDeletableHasManyThrough', function ($query) {
                $query->whereNull($this->throughParent->getQualifiedDeletedAtColumn());
            });
        }
    }

    /**
     * Obtient le nom de la clé parente complètement qualifié.
     */
    public function getQualifiedParentKeyName(): string
    {
        return $this->parent->qualifyColumn($this->secondLocalKey);
    }

    /**
     * Détermine si le parent "through" de la relation utilise Soft Deletes.
     */
    public function throughParentSoftDeletes(): bool
    {
        return $this->throughParent::isSoftDeletable();
    }

    /**
     * Indique que les parents "through" supprimés doivent être inclus dans la requête.
     */
    public function withTrashedParents(): static
    {
        $this->query->withoutGlobalScope('SoftDeletableHasManyThrough');

        return $this;
    }

    /** 
     * {@inheritDoc}
     */
    public function addEagerConstraints(array $models): void
    {
        $whereIn = $this->whereInMethod($this->farParent, $this->localKey);

        $this->whereInEager(
            $whereIn,
            $this->getQualifiedFirstKeyName(),
            $this->getKeys($models, $this->localKey),
            $this->getRelationQuery(),
        );
    }

    /**
     * Construit un dictionnaire de modèles indexé par la clé étrangère de la relation.
     *
     * @param  Collection<int, TRelatedModel>  $results
     * 
     * @return array<array<array-key, TRelatedModel>>
     */
    protected function buildDictionary(Collection $results): array
    {
        $dictionary = [];

        $isAssociative = Arr::isAssoc($results->all());

        // Nous allons d'abord créer un dictionnaire de modèles indexé par la clé étrangère de la
        // relation car cela nous permettra d'accéder rapidement à tous les modèles liés
        // sans avoir à faire des boucles imbriquées qui seraient assez lentes.
        foreach ($results as $key => $result) {
            if ($isAssociative) {
                $dictionary[$result->blitz_through_key][$key] = $result;
            } else {
                $dictionary[$result->blitz_through_key][] = $result;
            }
        }

        return $dictionary;
    }

    /**
     * Obtient le premier modèle lié correspondant aux attributs ou l'instancie.
     *
     * @return TRelatedModel
     */
    public function firstOrNew(array $attributes = [], array $values = []): Model
    {
        if (null !== $instance = $this->where($attributes)->first()) {
            return $instance;
        }

        return $this->related->newInstance(array_merge($attributes, $values));
    }

    /**
     * Obtient le premier enregistrement correspondant aux attributs. Si l'enregistrement n'est pas trouvé, le crée.
     *
     * @param  (Closure(): array)|array  $values
     * 
     * @return TRelatedModel
     */
    public function firstOrCreate(array $attributes = [], Closure|array $values = []): Model
    {
        if (null !== $instance = (clone $this)->where($attributes)->first()) {
            return $instance;
        }

        return $this->createOrFirst(array_merge($attributes, Helpers::value($values)));
    }

    /**
     * Tente de créer l'enregistrement. Si une violation de contrainte unique se produit, tente de trouver l'enregistrement correspondant.
     *
     * @param  (Closure(): array)|array  $values
     * 
     * @return TRelatedModel
     */
    public function createOrFirst(array $attributes = [], Closure|array $values = []): Model
    {
        try {
            return $this->getQuery()->withSavepointIfNeeded(fn () => $this->create(array_merge($attributes, Helpers::value($values))));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->where($attributes)->first() ?? throw $exception;
        }
    }

    /**
     * Crée ou met à jour un enregistrement lié correspondant aux attributs, et le remplit avec des valeurs.
     *
     * @return TRelatedModel
     */
    public function updateOrCreate(array $attributes, array $values = []): Model
    {
        return Helpers::tap($this->firstOrCreate($attributes, $values), function ($instance) use ($values) {
            if (! $instance->wasRecentlyCreated) {
                $instance->fill($values)->save();
            }
        });
    }

    /**
     * Ajoute une clause where de base à la requête, et retourne le premier résultat.
     *
     * @return TRelatedModel|null
     */
    public function firstWhere(array|Closure|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
    {
        return $this->where($column, $operator, $value, $boolean)->first();
    }

    /**
     * Exécute la requête et obtient le premier modèle lié.
     *
     * @return TRelatedModel|null
     */
    public function first(array $columns = ['*']): mixed
    {
        $results = $this->limit(1)->get($columns);

        return count($results) > 0 ? $results->first() : null;
    }

    /**
     * Exécute la requête et obtient le premier résultat ou lance une exception.
     *
     * @return TRelatedModel
     *
     * @throws ModelNotFoundException<TRelatedModel>
     */
    public function firstOrFail(array $columns = ['*']): mixed
    {
        if (! is_null($model = $this->first($columns))) {
            return $model;
        }

        throw (new ModelNotFoundException())->setModel(get_class($this->related));
    }

    /**
     * Exécute la requête et obtient le premier résultat ou appelle un rappel.
     *
     * @template TValue
     *
     * @param  (Closure(): TValue)|list<string>  $columns
     * @param  (Closure(): TValue)|null  $callback
     * 
     * @return TRelatedModel|TValue
     */
    public function firstOr(array|Closure $columns = ['*'], ?Closure $callback = null)
    {
        if ($columns instanceof Closure) {
            $callback = $columns;

            $columns = ['*'];
        }

        if (null !== $model = $this->first($columns)) {
            return $model;
        }

        return $callback();
    }

    /**
     * Trouve un modèle lié par sa clé primaire.
     *
     * @return ($id is (Arrayable<array-key, mixed>|array<mixed>) ? Collection<int, TRelatedModel> : TRelatedModel|null)
     */
    public function find(mixed $id, array $columns = ['*'])
    {
        if (is_array($id) || $id instanceof Arrayable) {
            return $this->findMany($id, $columns);
        }

        return $this->where(
            $this->getRelated()->getQualifiedKeyName(), 
            '=', 
            $id
        )->first($columns);
    }

    /**
     * Trouve un seul modèle lié par sa clé primaire.
     *
     * @return TRelatedModel
     *
     * @throws ModelNotFoundException<TRelatedModel>
     * @throws MultipleRecordsFoundException
     */
    public function findSole(mixed $id, array $columns = ['*'])
    {
        return $this->where(
            $this->getRelated()->getQualifiedKeyName(), 
            '=', 
            $id
        )->sole($columns);
    }

    /**
     * Trouve plusieurs modèles liés par leurs clés primaires.
     *
     * @return Collection<int, TRelatedModel>
     */
    public function findMany(array|Arrayable $ids, array $columns = ['*']): Collection
    {
        $ids = $ids instanceof Arrayable ? $ids->toArray() : $ids;

        if ($ids === []) {
            return $this->getRelated()->newCollection();
        }

        return $this->whereIn(
            $this->getRelated()->getQualifiedKeyName(), 
            $ids
        )->get($columns);
    }

    /**
     * Trouve un modèle lié par sa clé primaire ou lance une exception.
     *
     * @return ($id is (Arrayable<array-key, mixed>|array<mixed>) ? Collection<int, TRelatedModel> : TRelatedModel)
     *
     * @throws ModelNotFoundException<TRelatedModel>
     */
    public function findOrFail(mixed $id, array $columns = ['*'])
    {
        $result = $this->find($id, $columns);

        $id = $id instanceof Arrayable ? $id->toArray() : $id;

        if (is_array($id)) {
            if (count($result) === count(array_unique($id))) {
                return $result;
            }
        } elseif (null !== $result) {
            return $result;
        }

        throw (new ModelNotFoundException)->setModel(get_class($this->related), $id);
    }

    /**
     * Trouve un modèle lié par sa clé primaire ou appelle un rappel.
     *
     * @template TValue
     *
     * @param (Closure(): TValue)|list<string>|string  $columns
     * @param (Closure(): TValue)|null  $callback
     * @return (
     *     $id is (Arrayable<array-key, mixed>|array<mixed>)
     *     ? Collection<int, TRelatedModel>|TValue
     *     : TRelatedModel|TValue
     * )
     */
    public function findOr(mixed $id, array|Closure|string $columns = ['*'], ?Closure $callback = null)
    {
        if ($columns instanceof Closure) {
            $callback = $columns;

            $columns = ['*'];
        }

        $result = $this->find($id, $columns);

        $id = $id instanceof Arrayable ? $id->toArray() : $id;

        if (is_array($id)) {
            if (count($result) === count(array_unique($id))) {
                return $result;
            }
        } elseif (null !== $result) {
            return $result;
        }

        return $callback();
    }

    /** 
     * {@inheritDoc}
     */
    public function get(array $columns = ['*']): Collection
    {
        $builder = $this->prepareQueryBuilder($columns);

        $models = $builder->getModels();

        // Si nous avons effectivement trouvé des modèles, nous chargerons également avec empressement toutes les relations qui
        // ont été spécifiées comme devant être chargées avec empressement. Cela résoudra le
        // problème de requête n + 1 pour le développeur et augmentera également les performances.
        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->query->applyAfterQueryCallbacks(
            $this->related->newCollection($models)
        );
    }

    /**
     * Obtient un paginateur pour l'instruction "select".
     */
    public function paginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        $this->query->select($this->shouldSelect($columns));

        return $this->query->paginate($perPage, $columns, $pageName, $page);
    }

    /**
     * Pagine la requête donnée dans un paginateur simple.
     */
    public function simplePaginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): Paginator
    {
        $this->query->select($this->shouldSelect($columns));

        return $this->query->simplePaginate($perPage, $columns, $pageName, $page);
    }

    /**
     * Pagine la requête donnée dans un paginateur à curseur.
     */
    public function cursorPaginate(?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', ?string $cursor = null): CursorPaginator
    {
        $this->query->select($this->shouldSelect($columns));

        return $this->query->cursorPaginate($perPage, $columns, $cursorName, $cursor);
    }

    /**
     * Définit la clause de sélection pour la requête de relation.
     */
    protected function shouldSelect(array $columns = ['*']): array
    {
        if ($columns == ['*']) {
            $columns = [$this->related->qualifyColumn('*')];
        }

        return array_merge($columns, [$this->getQualifiedFirstKeyName().' as blitz_through_key']);
    }

    /**
     * Traite les résultats de la requête par lots.
     */
    public function chunk(int $count, callable $callback): bool
    {
        return $this->prepareQueryBuilder()->chunk($count, $callback);
    }

    /**
     * Traite les résultats d'une requête par lots en comparant les ID numériques.
     */
    public function chunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->chunkById($count, $callback, $column, $alias);
    }

    /**
     * Traite les résultats d'une requête par lots en comparant les ID dans l'ordre décroissant.
     */
    public function chunkByIdDesc(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->chunkByIdDesc($count, $callback, $column, $alias);
    }

    /**
     * Exécute un rappel sur chaque élément tout en traitant par lots par ID.
     */
    public function eachById(callable $callback, int $count = 1000, ?string $column = null, ?string $alias = null): bool
    {
        $column = $column ?? $this->getRelated()->getQualifiedKeyName();

        $alias = $alias ?? $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->eachById($callback, $count, $column, $alias);
    }

    /**
     * Obtient un générateur pour la requête donnée.
     *
     * @return LazyCollection<int, TRelatedModel>
     */
    public function cursor(): LazyCollection
    {
        return $this->prepareQueryBuilder()->cursor();
    }

    /**
     * Exécute un rappel sur chaque élément tout en traitant par lots.
     */
    public function each(callable $callback, int $count = 1000): bool
    {
        return $this->chunk($count, static function ($results) use ($callback) {
            foreach ($results as $key => $value) {
                if ($callback($value, $key) === false) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Interroge paresseusement, par lots de la taille donnée.
     *
     * @return LazyCollection<int, TRelatedModel>
     */
    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        return $this->prepareQueryBuilder()->lazy($chunkSize);
    }

    /**
     * Interroge paresseusement, en traitant les résultats d'une requête par lots en comparant les ID.
     *
     * @return LazyCollection<int, TRelatedModel>
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->lazyById($chunkSize, $column, $alias);
    }

    /**
     * Interroge paresseusement, en traitant les résultats d'une requête par lots en comparant les ID dans l'ordre décroissant.
     */
    public function lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        $column ??= $this->getRelated()->getQualifiedKeyName();

        $alias ??= $this->getRelated()->getKeyName();

        return $this->prepareQueryBuilder()->lazyByIdDesc($chunkSize, $column, $alias);
    }

    /**
     * Prépare le constructeur de requête pour l'exécution de la requête.
     *
     * @return Builder<TRelatedModel>
     */
    protected function prepareQueryBuilder(array $columns = ['*']): Builder
    {
        $builder = $this->query->applyScopes();

        return $builder->select(
            $this->shouldSelect($builder->getQuery()->columns !== [] ? [] : $columns)
        );
    }

    /** 
     * {@inheritDoc}
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($parentQuery->getQuery()->from === $query->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfRelation($query, $parentQuery, $columns);
        }

        if ($parentQuery->getQuery()->from === $this->throughParent->getTable()) {
            return $this->getRelationExistenceQueryForThroughSelfRelation($query, $parentQuery, $columns);
        }

        $this->performJoin($query);

        return $query->select($columns)->whereColumn(
            $this->getQualifiedLocalKeyName(), '=', $this->getQualifiedFirstKeyName()
        );
    }

    /**
     * Ajoute les contraintes pour une requête de relation sur la même table.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQueryForSelfRelation(Builder $query, Builder $parentQuery, array $columns = ['*'])
    {
        $query->from($query->getModel()->getTable().' as '.$hash = $this->getRelationCountHash());

        $query->join($this->throughParent->getTable(), $this->getQualifiedParentKeyName(), '=', $hash.'.'.$this->secondKey);

        if ($this->throughParentSoftDeletes()) {
            $query->whereNull($this->throughParent->getQualifiedDeletedAtColumn());
        }

        $query->getModel()->setTable($hash);

        return $query->select($columns)->whereColumn(
            $parentQuery->getQuery()->from.'.'.$this->localKey, '=', $this->getQualifiedFirstKeyName()
        );
    }

    /**
     * Ajoute les contraintes pour une requête de relation sur la même table que le parent through.
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  array|mixed  $columns
     * 
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQueryForThroughSelfRelation(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $table = $this->throughParent->getTable().' as '.$hash = $this->getRelationCountHash();

        $query->join($table, $hash.'.'.$this->secondLocalKey, '=', $this->getQualifiedFarKeyName());

        if ($this->throughParentSoftDeletes()) {
            $query->whereNull($hash . '.' . $this->throughParent->getDeletedAtColumn());
        }

        return $query->select($columns)->whereColumn(
            $parentQuery->getQuery()->from . '.'.$this->localKey, '=', $hash.'.'.$this->firstKey
        );
    }

    /**
     * Obtient la clé étrangère qualifiée sur le modèle lié. 
     */
    public function getQualifiedFarKeyName(): string
    {
        return $this->getQualifiedForeignKeyName();
    }

    /**
     * Obtient la clé étrangère sur le modèle "through".
     */
    public function getFirstKeyName(): string
    {
        return $this->firstKey;
    }

    /**
     * Obtient la clé étrangère qualifiée sur le modèle "through".
     */
    public function getQualifiedFirstKeyName()
    {
        return $this->throughParent->qualifyColumn($this->firstKey);
    }

    /**
     * Obtient la clé étrangère sur le modèle lié.
     */
    public function getForeignKeyName(): string
    {
        return $this->secondKey;
    }

    /**
     * Obtient la clé étrangère qualifiée sur le modèle lié.
     */
    public function getQualifiedForeignKeyName(): string
    {
        return $this->related->qualifyColumn($this->secondKey);
    }

    /**
     * Obtient la clé locale sur le modèle parent éloigné.
     */
    public function getQualifiedRelatedKeyName(): string
    {
        return $this->farParent->qualifyColumn($this->localKey);
    }

    public function getLocalKeyName(): string
    {
        return $this->localKey;
    }

    /**
     * Obtient la clé locale qualifiée sur le modèle parent éloigné.
     */
    public function getQualifiedLocalKeyName(): string
    {
        return $this->farParent->qualifyColumn($this->localKey);
    }

    /**
     * Obtient la clé locale sur le modèle intermédiaire.
     */
    public function getSecondLocalKeyName(): string
    {
        return $this->secondLocalKey;
    }
}
