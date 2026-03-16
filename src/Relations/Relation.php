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

use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Database\Query\Expression;
use BlitzPHP\Database\Exceptions\MultipleRecordsFoundException;
use BlitzPHP\Traits\Macroable;
use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Model;
use Closure;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TResult
 *
 * @mixin \BlitzPHP\Wolke\Builder
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\Relation</a>
 */
abstract class Relation
{
    use ForwardsCalls, Macroable {
        __call as macroCall;
    }

    /**
     * L'instance du modèle lié.
     *
     * @var TRelatedModel
     */
    protected Model $related;

    /**
     * Indique si la relation chargée avec empressement doit retourner implicitement une collection vide.
     */
    protected bool $eagerKeysWereEmpty = false;

    /**
     * Indique si la relation ajoute des contraintes.
     */
    protected static bool $constraints = true;

    /**
     * Un tableau pour mapper les noms de classe à leurs noms morph dans la base de données.
     * 
     * @var array<string, class-string<Model>>
     */
    public static array $morphMap = [];

    /**
     * Empêche les relations morph sans carte morph.
     */
    protected static bool $requireMorphMap = false;

    /**
     * Le nombre d'auto-jointures.
     */
    protected static int $selfJoinCount = 0;

    /**
     * Crée une nouvelle instance de relation.
     *
     * @param Builder<TRelatedModel> $query  L'instance de constructeur de requête Wolke.
     * @param TDeclaringModel        $parent L'instance du modèle parent.
     */
    public function __construct(protected Builder $query, protected Model $parent)
    {
        $this->related = $query->getModel();

        $this->addConstraints();
    }

    /**
     * Exécute un rappel avec les contraintes désactivées sur la relation.
     * 
     * @template TReturn of mixed
     *
     * @param  Closure(): TReturn  $callback
     * 
     * @return TReturn
     */
    public static function noConstraints(Closure $callback): mixed
    {
        $previous = static::$constraints;

        static::$constraints = false;

        // Lors de la réinitialisation de la clause where de la relation, nous voulons décaler le premier élément
        // des liaisons, ne laissant que les contraintes que les développeurs ont mises
        // comme "supplémentaires" sur les relations, et non les contraintes originales de la relation.
        try {
            return $callback();
        } finally {
            static::$constraints = $previous;
        }
    }

    /**
     * Définit les contraintes de base sur la requête de relation.
     */
    abstract public function addConstraints(): void;

    /**
     * Définit les contraintes pour un chargement empressé de la relation.
     * 
     * @param list<TDeclaringModel>  $models
     */
    abstract public function addEagerConstraints(array $models): void;

    /**
     * Initialise la relation sur un ensemble de modèles.
     *
     * @param list<TDeclaringModel>  $models
     * 
     * @return list<TDeclaringModel>
     */
    abstract public function initRelation(array $models, string $relation): array;

    /**
     * Fait correspondre les résultats chargés avec empressement à leurs parents.
     * 
     * @param  list<TDeclaringModel>  $models
     * @param  Collection<int, TRelatedModel>  $results
     * 
     * @return list<TDeclaringModel>
     */
    abstract public function match(array $models, Collection $results, string $relation): array;

    /**
     * Obtient les résultats de la relation.
     *
     * @return TResult
     */
    abstract public function getResults(): mixed;

    /**
     * Obtient la relation pour le chargement empressé.
     *
     * @return Collection<int, TRelatedModel>
     */
    public function getEager(): Collection
    {
        return $this->eagerKeysWereEmpty
            ? $this->query->getModel()->newCollection()
            : $this->get();
    }

    /**
     * Exécute la requête et obtient le premier résultat s'il est le seul enregistrement correspondant.
     *
     * @return TRelatedModel
     * 
     * @throws ModelNotFoundException
     * @throws MultipleRecordsFoundException
     */
    public function sole(array|string $columns = ['*']): Model
    {
        $result = $this->limit(2)->get($columns);

        $count = $result->count();

        if ($count === 0) {
            throw (new ModelNotFoundException())->setModel(get_class($this->related));
        }

        if ($count > 1) {
            throw new MultipleRecordsFoundException($count);
        }

        return $result->first();
    }

    /**
     * Exécute la requête en tant qu'instruction "select".
     * 
     * @return Collection<int, TRelatedModel>
     */
    public function get(array $columns = ['*']): Collection
    {
        return $this->query->get($columns);
    }

    /**
     * Touche tous les modèles liés pour la relation.
     */
    public function touch(): void
    {
        $model = $this->getRelated();

        if (! $model::isIgnoringTouch()) {
            $this->rawUpdate([
                $model->getUpdatedAtColumn() => $model->freshTimestampString(),
            ]);
        }
    }

    /**
     * Exécute une mise à jour brute sur la requête de base.
     *
     * @return int
     */
    public function rawUpdate(array $attributes = [])
    {
        return $this->query->withoutGlobalScopes()->update($attributes);
    }

    /**
     * Ajoute les contraintes pour une requête de comptage de relation.
     *
     * @param Builder<TRelatedModel>  $query
     * @param Builder<TDeclaringModel>  $parentQuery
     * 
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceCountQuery(Builder $query, Builder $parentQuery): Builder
    {
        return $this->getRelationExistenceQuery(
            $query,
            $parentQuery,
            new Expression('count(*)')
        );
        // )->setBindings([], 'select');
    }

    /**
     * Ajoute les contraintes pour une requête d'existence de relation interne.
     *
     * Essentiellement, ces requêtes comparent les noms de colonnes comme whereColumn.
     *
     * @param Builder<TRelatedModel>  $query
     * @param Builder<TDeclaringModel>  $parentQuery
     * 
     * @return Builder<TRelatedModel>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        return $query->select($columns)->whereColumn(
            $this->getQualifiedParentKeyName(),
            '=',
            $this->getExistenceCompareKey()
        );
    }

    /**
     * Obtient un hachage de table de jointure de relation.
     */
    public function getRelationCountHash(bool $incrementJoinCount = true): string
    {
        return 'blitz_reserved_' . ($incrementJoinCount ? static::$selfJoinCount++ : static::$selfJoinCount);
    }

    /**
     * Obtient toutes les clés primaires d'un tableau de modèles.
     * 
     * @param list<TDeclaringModel> $models
     * 
     * @return list<int|string|null>
     */
    protected function getKeys(array $models, ?string $key = null): array
    {
        return (new IterableCollection($models))
            ->map(static fn ($value) => $key ? $value->getAttribute($key) : $value->getKey())
            ->values()
            ->unique(null, true)
            ->sort()
            ->all();
    }

    /**
     * Obtient le constructeur de requête qui contiendra les contraintes de relation.
     * 
     * @return Builder<TRelatedModel>
     */
    protected function getRelationQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Obtient la requête sous-jacente pour la relation.
     * 
     * @return Builder<TRelatedModel>
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }

    /**
     * Obtient le constructeur de requête de base qui pilote le constructeur Eloquent.
     */
    public function getBaseQuery(): BaseBuilder
    {
        return $this->query->getQuery();
    }

    /**
     * Obtient une instance de constructeur de requête de base.
     */
    public function toBase(): BaseBuilder
    {
        return $this->query->toBase();
    }

    /**
     * Obtient le modèle parent de la relation.
     *
     * @return TDeclaringModel
     */
    public function getParent(): Model
    {
        return $this->parent;
    }

    /**
     * Obtient le nom de la clé parente complètement qualifié.
     */
    public function getQualifiedParentKeyName(): string
    {
        return $this->parent->getQualifiedKeyName();
    }

    /**
     * Obtient le modèle lié de la relation.
     *
     * @return TRelatedModel
     */
    public function getRelated(): Model
    {
        return $this->related;
    }

    /**
     * Obtient le nom de la colonne "created at".
     */
    public function createdAt(): string
    {
        return $this->parent->getCreatedAtColumn();
    }

    /**
     * Obtient le nom de la colonne "updated at".
     */
    public function updatedAt(): string
    {
        return $this->parent->getUpdatedAtColumn();
    }

    /**
     * Obtient le nom de la colonne "updated at" du modèle lié.
     */
    public function relatedUpdatedAt(): string
    {
        return $this->related->getUpdatedAtColumn();
    }

    /**
     * Ajoute une contrainte whereIn avec empressement pour l'ensemble donné de clés de modèle à charger.
     * 
     * @param Builder<TRelatedModel>|null  $query
     */
    protected function whereInEager(string $whereIn, string $key, array $modelKeys, ?Builder $query = null): void
    {
        ($query ?? $this->query)->{$whereIn}($key, $modelKeys);

        if ($modelKeys === []) {
            $this->eagerKeysWereEmpty = true;
        }
    }

    /**
     * Obtient le nom de la méthode "where in" pour le chargement empressé.
     */
    protected function whereInMethod(Model $model, string $key): string
    {
        return $model->getKeyName() === Arr::last(explode('.', $key))
                    && in_array($model->getKeyType(), ['int', 'integer'], true)
                        ? 'whereIn'
                        : 'whereIn';
    }

    /**
     * Empêche les relations polymorphes d'être utilisées sans mappages de modèles.
     */
    public static function requireMorphMap(bool $requireMorphMap = true): void
    {
        static::$requireMorphMap = $requireMorphMap;
    }

    /**
     * Détermine si les relations polymorphes nécessitent un mappage de modèle explicite.
     */
    public static function requiresMorphMap(): bool
    {
        return static::$requireMorphMap;
    }

    /**
     * Définit la carte morph pour les relations polymorphes et exige que tous les modèles morph soient explicitement mappés.
     * 
     * @param array<array-key, class-string<Model>> $map
     */
    public static function enforceMorphMap(array $map, bool $merge = true): array
    {
        static::requireMorphMap();

        return static::morphMap($map, $merge);
    }

    /**
     * Définit ou obtient la carte morph pour les relations polymorphes.
     *
     * @param array<array-key, class-string<Model>>|null $map
     * 
     * @return array<string, class-string<Model>>
     */
    public static function morphMap(?array $map = null, bool $merge = true): array
    {
        $map = static::buildMorphMapFromModels($map);

        if (is_array($map)) {
            static::$morphMap = $merge && static::$morphMap
                            ? $map + static::$morphMap : $map;
        }

        return static::$morphMap;
    }

    /**
     * Construit un tableau indexé par table à partir des noms de classe de modèles.
     *
     * @param  array<array-key, class-string<Model>>|null  $models
     * 
     * @return array<string, class-string<Model>>|null
     */
    protected static function buildMorphMapFromModels(?array $models = null): ?array
    {
        if (null === $models || ! array_is_list($models)) {
            return $models;
        }

        return array_combine(array_map(static fn ($model) => (new $model())->getTable(), $models), $models);
    }

    /**
     * Obtient le modèle associé à un type polymorphe personnalisé.
     * 
     * @return class-string<Model>|null
     */
    public static function getMorphedModel(string $alias): ?string
    {
        return static::$morphMap[$alias] ?? null;
    }

    /**
     * Obtient l'alias associé à une classe polymorphe personnalisée.
     *
     * @param  class-string<Model>  $className
     * 
     * @return int|string
     */
    public static function getMorphAlias(string $className)
    {
        return array_search($className, static::$morphMap, strict: true) ?: $className;
    }

    /**
     * Gère les appels de méthode dynamiques à la relation.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        return $this->forwardDecoratedCallTo($this->query, $method, $parameters);
    }

    /**
     * Force un clonage du constructeur de requête sous-jacent lors du clonage.
     */
    public function __clone(): void
    {
        $this->query = clone $this->query;
    }
}
