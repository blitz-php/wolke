<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke;

use BadMethodCallException;
use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Database\Exceptions\MultipleRecordsFoundException;
use BlitzPHP\Database\Exceptions\RecordsNotFoundException;
use BlitzPHP\Database\Exceptions\UniqueConstraintViolationException;
use BlitzPHP\Database\Query\Expression;
use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Invade\Invader;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Utilities\Iterable\LazyCollection;
use BlitzPHP\Utilities\Pagination\Cursor;
use BlitzPHP\Utilities\Pagination\LengthAwarePaginator;
use BlitzPHP\Utilities\Pagination\Paginator;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Concerns\BuildsQueries;
use BlitzPHP\Wolke\Concerns\QueriesRelationships;
use BlitzPHP\Wolke\Contracts\Scope;
use BlitzPHP\Wolke\Exceptions\CursorPaginationException;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Exceptions\RelationNotFoundException;
use BlitzPHP\Wolke\Relations\BelongsToMany;
use BlitzPHP\Wolke\Relations\Relation;
use Closure;
use Exception;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;

/**
 * @template TModel of Model
 *
 * @property-read $this|HigherOrderBuilderProxy $orWhere
 * @property-read $this|HigherOrderBuilderProxy $orWhereNot
 * @property-read $this|HigherOrderBuilderProxy $whereNot
 *
 * @mixin BaseBuilder
 *
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Builder</a>
 */
class Builder
{
    use BuildsQueries, ForwardsCalls, QueriesRelationships {
        BuildsQueries::sole as baseSole;
    }

    /**
     * Le modèle en cours d'interrogation.
     *
     * @var TModel
     */
    protected $model;

    /**
     * Les attributs qui doivent être ajoutés aux nouveaux modèles créés par ce constructeur.
     */
    public array $pendingAttributes = [];

    /**
     * Les relations qui doivent être chargées avec empressement.
     */
    protected array $eagerLoad = [];

    /**
     * Toutes les macros de constructeur enregistrées globalement.
     */
    protected static array $macros = [];

    /**
     * Toutes les macros de constructeur enregistrées localement.
     */
    protected array $localMacros = [];

    /**
     * Un remplacement pour la fonction de suppression typique.
     *
     * @var Closure
     */
    protected $onDelete;

    /**
     * Les propriétés qui doivent être retournées par le constructeur de requête.
     *
     * @var list<string>
     */
    protected array $propertyPassthru = [
        'from',
    ];

    /**
     * Les méthodes qui doivent être retournées par le constructeur de requête.
     *
     * @var list<string>
     */
    protected array $passthru = [
        'aggregate',
        'average',
        'avg',
        'count',
        'db',
        'dd',
        'doesntExist',
        'doesntExistOr',
        'dump',
        'exists',
        'existsOr',
        'explain',
        'getBindings',
        'getConnection',
        'implode',
        'insert',
        'insertGetId',
        'insertOrIgnore',
        'insertUsing',
        'insertOrIgnoreUsing',
        'max',
        'min',
        'raw',
        'sum',
        'sql',
        'toSql',
        'toRawSql',
    ];

    /**
     * Portées globales appliquées.
     */
    protected array $scopes = [];

    /**
     * Portées globales supprimées.
     */
    protected array $removedScopes = [];

    /**
     * Les rappels qui doivent être invoqués après la récupération des données de la base de données.
     */
    protected array $afterQueryCallbacks = [];

    /**
     * Les rappels qui doivent être invoqués lors du clonage.
     *
     * @var list<Closure(static): void>
     */
    protected array $onCloneCallbacks = [];

    /**
     * Crée une nouvelle instance de constructeur de requête Orm.
     *
     * @param BaseBuilder $query L'instance de constructeur de requête de base.
     */
    public function __construct(protected BaseBuilder $query)
    {
    }

    /**
     * Crée et retourne une instance de modèle non sauvegardée.
     *
     * @return TModel
     */
    public function make(array $attributes = [])
    {
        return $this->newModelInstance($attributes);
    }

    /**
     * Enregistre une nouvelle portée globale.
     */
    public function withGlobalScope(string $identifier, Closure|Scope $scope): static
    {
        $this->scopes[$identifier] = $scope;

        if (method_exists($scope, 'extend')) {
            $scope->{'extend'}($this);
        }

        return $this;
    }

    /**
     * Supprime une portée globale enregistrée.
     */
    public function withoutGlobalScope(Scope|string $scope): static
    {
        if (! is_string($scope)) {
            $scope = $scope::class;
        }

        unset($this->scopes[$scope]);

        $this->removedScopes[] = $scope;

        return $this;
    }

    /**
     * Supprime toutes les portées globales enregistrées ou celles passées.
     */
    public function withoutGlobalScopes(?array $scopes = null): static
    {
        if (! is_array($scopes)) {
            $scopes = array_keys($this->scopes);
        }

        foreach ($scopes as $scope) {
            $this->withoutGlobalScope($scope);
        }

        return $this;
    }

    /**
     * Supprime toutes les portées globales sauf celles données.
     */
    public function withoutGlobalScopesExcept(array $scopes = []): static
    {
        $this->withoutGlobalScopes(
            array_diff(array_keys($this->scopes), $scopes),
        );

        return $this;
    }

    /**
     * Obtient un tableau des portées globales qui ont été supprimées de la requête.
     */
    public function removedScopes(): array
    {
        return $this->removedScopes;
    }

    /**
     * Ajoute une clause where sur la clé primaire à la requête.
     */
    public function whereKey(mixed $id): static
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        if (is_array($id) || $id instanceof Arrayable) {
            $this->query->whereIn($this->model->getQualifiedKeyName(), $id);

            return $this;
        }

        if ($id !== null && $this->model->getKeyType() === 'string') {
            $id = (string) $id;
        }

        return $this->where($this->model->getQualifiedKeyName(), '=', $id);
    }

    /**
     * Ajoute une clause where sur la clé primaire à la requête.
     */
    public function whereKeyNot(mixed $id): static
    {
        if ($id instanceof Model) {
            $id = $id->getKey();
        }

        if (is_array($id) || $id instanceof Arrayable) {
            $this->query->whereNotIn($this->model->getQualifiedKeyName(), $id);

            return $this;
        }

        if ($id !== null && $this->model->getKeyType() === 'string') {
            $id = (string) $id;
        }

        return $this->where($this->model->getQualifiedKeyName(), '!=', $id);
    }

    /**
     * Exclut les modèles donnés des résultats de la requête.
     *
     * @param iterable|mixed $models
     */
    public function except($models): static
    {
        return $this->whereKeyNot(
            $models instanceof Model
                ? $models->getKey()
                : Collection::wrap($models)->modelKeys(),
        );
    }

    /**
     * Ajoute une clause "where" basique à la requête.
     *
     * @param array|(Closure(static): mixed)|Expression|string $column
     */
    public function where(array|Closure|Expression|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        if ($column instanceof Closure && null === $operator) {
            $column($query = $this->model->newQueryWithoutRelationships());

            $this->eagerLoad = array_merge($this->eagerLoad, $query->getEagerLoads());

            $this->query->addNestedWhereQuery($query->getQuery(), $boolean);
        } else {
            $this->query->where(...func_get_args());
        }

        return $this;
    }

    /**
     * Ajoute une clause where de base à la requête et retourne le premier résultat.
     *
     * @param array|(Closure(static): mixed)|Expression|string $column
     *
     * @return TModel|null
     */
    public function firstWhere(array|Closure|Expression|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
    {
        return $this->where(...func_get_args())->first();
    }

    /**
     * Ajoute une clause "or where" à la requête.
     *
     * @param array|(Closure(static): mixed)|Expression|string $column
     */
    public function orWhere(array|Closure|Expression|string $column, Closure|string|null $operator = null, mixed $value = null): static
    {
        [$value, $operator] = $this->prepareValueAndOperator(
            $value,
            $operator,
            func_num_args() === 2,
        );

        return $this->where($column, $operator, $value, 'or');
    }

    /**
     * Ajoute une clause "where not" de base à la requête.
     *
     * @param array|(Closure(static): mixed)|Expression|string $column
     */
    public function whereNot(array|Closure|Expression|string $column, mixed $value = null, string $boolean = 'and'): static
    {
        return $this->where($column, '!=', $value, $boolean);
    }

    /**
     * Ajoute une clause "or where not" de base à la requête.
     *
     * @param array|(Closure(static): mixed)|Expression|string $column
     */
    public function orWhereNot(array|Closure|Expression|string $column, mixed $value = null): static
    {
        return $this->whereNot($column, $value, 'or');
    }

    /**
     * Ajoute une clause "order by" pour un horodatage à la requête.
     */
    public function latest(Expression|string|null $column = null): static
    {
        if (null === $column) {
            $column = $this->model->getCreatedAtColumn() ?? 'created_at';
        }

        $this->query->latest($column);

        return $this;
    }

    /**
     * Ajoute une clause "order by" pour un horodatage à la requête.
     */
    public function oldest(?string $column = null): static
    {
        if (null === $column) {
            $column = $this->model->getCreatedAtColumn() ?? 'created_at';
        }

        $this->query->oldest($column);

        return $this;
    }

    /**
     * Crée une collection de modèles à partir de tableaux simples.
     *
     * @return Collection<int, TModel>
     */
    public function hydrate(array $items): Collection
    {
        $instance = $this->newModelInstance();

        return $instance->newCollection(array_map(static function ($item) use ($items, $instance) {
            $model = $instance->newFromBuilder($item);

            if (count($items) > 1) {
                $model->preventsLazyLoading = Model::preventsLazyLoading();
            }

            return $model;
        }, $items));
    }

    /**
     * Insère dans la base de données après avoir fusionné les attributs par défaut du modèle, défini les horodatages et casté les valeurs.
     *
     * @param array<int, array<string, mixed>> $values
     */
    public function fillAndInsert(array $values): bool
    {
        return $this->insert($this->fillForInsert($values));
    }

    /**
     * Insère (en ignorant les erreurs) dans la base de données après avoir fusionné les attributs par défaut du modèle, défini les horodatages et casté les valeurs.
     *
     * @param array<int, array<string, mixed>> $values
     */
    public function fillAndInsertOrIgnore(array $values): int
    {
        return $this->insertOrIgnore($this->fillForInsert($values));
    }

    /**
     * Insère un enregistrement dans la base de données et obtient son ID après avoir fusionné les attributs par défaut du modèle, défini les horodatages et casté les valeurs.
     *
     * @param array<string, mixed> $values
     */
    public function fillAndInsertGetId(array $values): int
    {
        return $this->insertGetId($this->fillForInsert([$values])[0]);
    }

    /**
     * Enrichit les valeurs données en fusionnant les attributs par défaut du modèle, en ajoutant les horodatages et en castant les valeurs.
     *
     * @param array<int, array<string, mixed>> $values
     *
     * @return array<int, array<string, mixed>>
     */
    public function fillForInsert(array $values)
    {
        if (empty($values)) {
            return [];
        }

        if (! is_array(Arr::first($values))) {
            $values = [$values];
        }

        $this->model->unguarded(function () use (&$values) {
            foreach ($values as $key => $rowValues) {
                $values[$key] = Helpers::tap(
                    $this->newModelInstance($rowValues),
                    static fn ($model) => $model->setUniqueIds(),
                )->getAttributes();
            }
        });

        return $this->addTimestampsToUpsertValues($values);
    }

    /**
     * Crée une collection de modèles à partir d'une requête brute.
     *
     * @return Collection<int, TModel>
     */
    public function fromQuery(string $query, array $bindings = []): Collection
    {
        return $this->hydrate(
            $this->query->db()->query($query, $bindings)->resultArray(),
        );
    }

    /**
     * Trouve un modèle par sa clé primaire.
     *
     * @return ($id is (Arrayable<array-key, mixed>|list<mixed>) ? Collection<int, TModel> : TModel|null)
     */
    public function find(mixed $id, array $columns = ['*'])
    {
        if (is_array($id) || $id instanceof Arrayable) {
            return $this->findMany($id, $columns);
        }

        return $this->whereKey($id)->first($columns);
    }

    /**
     * Trouve un seul modèle par sa clé primaire.
     *
     * @return TModel
     *
     * @throws ModelNotFoundException<TModel>
     * @throws MultipleRecordsFoundException
     */
    public function findSole(mixed $id, array $columns = ['*'])
    {
        return $this->whereKey($id)->sole($columns);
    }

    /**
     * Trouve plusieurs modèles par leurs clés primaires.
     *
     * @return Collection<int, TModel>
     */
    public function findMany(array|Arrayable $ids, array $columns = ['*']): Collection
    {
        $ids = $ids instanceof Arrayable ? $ids->toArray() : $ids;

        if (empty($ids)) {
            return $this->model->newCollection();
        }

        return $this->whereKey($ids)->get($columns);
    }

    /**
     * Trouve un modèle par sa clé primaire ou lance une exception.
     *
     * @return ($id is (Arrayable<array-key, mixed>|list<mixed>) ? Collection<int, TModel> : TModel)
     *
     * @throws ModelNotFoundException
     */
    public function findOrFail(mixed $id, array $columns = ['*'])
    {
        $result = $this->find($id, $columns);

        $id = $id instanceof Arrayable ? $id->toArray() : $id;

        if (is_array($id)) {
            if (count($result) !== count(array_unique($id))) {
                throw (new ModelNotFoundException())->setModel(
                    get_class($this->model),
                    array_diff($id, $result->modelKeys()),
                );
            }

            return $result;
        }

        if (null === $result) {
            throw (new ModelNotFoundException())->setModel(
                get_class($this->model),
                $id,
            );
        }

        return $result;
    }

    /**
     * Trouve un modèle par sa clé primaire ou retourne une nouvelle instance de modèle.
     *
     * @return ($id is (Arrayable<array-key, mixed>|list<mixed>) ? Collection<int, TModel> : TModel)
     */
    public function findOrNew(mixed $id, array $columns = ['*'])
    {
        if (null !== ($model = $this->find($id, $columns))) {
            return $model;
        }

        return $this->newModelInstance();
    }

    /**
     * Trouve un modèle par sa clé primaire ou appelle un rappel.
     *
     * @template TValue
     *
     * @param (Closure(): TValue)|list<string>|string $columns
     * @param (Closure(): TValue)|null                $callback
     *
     * @return (
     *     $id is (Arrayable<array-key, mixed>|array<mixed>)
     *     ? Collection<int, TModel>
     *     : TModel|TValue
     * )
     */
    public function findOr(mixed $id, array|Closure|string $columns = ['*'], ?Closure $callback = null)
    {
        if ($columns instanceof Closure) {
            $callback = $columns;

            $columns = ['*'];
        }

        if (null !== ($model = $this->find($id, $columns))) {
            return $model;
        }

        return $callback();
    }

    /**
     * Obtient le premier enregistrement correspondant aux attributs ou l'instancie.
     *
     * @return TModel
     */
    public function firstOrNew(array $attributes = [], array $values = [])
    {
        if (null !== ($instance = $this->where($attributes)->first())) {
            return $instance;
        }

        return $this->newModelInstance(array_merge($attributes, $values));
    }

    /**
     * Obtient le premier enregistrement correspondant aux attributs ou le crée.
     *
     * @param array|(Closure(): array) $values
     *
     * @return TModel
     */
    public function firstOrCreate(array $attributes = [], array|Closure $values = [])
    {
        if (null !== ($instance = (clone $this)->where($attributes)->first())) {
            return $instance;
        }

        return $this->createOrFirst($attributes, $values);
    }

    /**
     * Tente de créer l'enregistrement. Si une violation de contrainte unique se produit, tente de trouver l'enregistrement correspondant.
     *
     * @param array|(Closure(): array) $values
     *
     * @return TModel
     */
    public function createOrFirst(array $attributes = [], array|Closure $values = [])
    {
        try {
            return $this->withSavepointIfNeeded(fn () => $this->create(array_merge($attributes, Helpers::value($values))));
        } catch (UniqueConstraintViolationException $e) {
            return $this->where($attributes)->first() ?? throw $e;
        }
    }

    /**
     * Crée ou met à jour un enregistrement correspondant aux attributs, et le remplit avec des valeurs.
     *
     * @return TModel
     */
    public function updateOrCreate(array $attributes, array $values = [])
    {
        return Helpers::tap($this->firstOrCreate($attributes, $values), static function ($instance) use ($values) {
            if (! $instance->wasRecentlyCreated) {
                $instance->fill($values)->save();
            }
        });
    }

    /**
     * Crée un enregistrement correspondant aux attributs, ou incrémente l'enregistrement existant.
     *
     * @return TModel
     */
    public function incrementOrCreate(array $attributes, string $column = 'count', float|int $default = 1, float|int $step = 1, array $extra = [])
    {
        return Helpers::tap($this->firstOrCreate($attributes, [$column => $default]), static function ($instance) use ($column, $step, $extra) {
            if (! $instance->wasRecentlyCreated) {
                $instance->increment($column, $step, $extra);
            }
        });
    }

    /**
     * Exécute la requête et obtient le premier résultat ou lance une exception.
     *
     * @return TModel
     *
     * @throws ModelNotFoundException<TModel>
     */
    public function firstOrFail(array $columns = ['*'])
    {
        if (null !== ($model = $this->first($columns))) {
            return $model;
        }

        throw (new ModelNotFoundException())->setModel(get_class($this->model));
    }

    /**
     * Exécute la requête et obtient le premier résultat ou appelle un rappel.
     *
     * @template TValue
     *
     * @param (Closure(): TValue)|list<string> $columns
     * @param (Closure(): TValue)|null         $callback
     *
     * @return TModel|TValue
     */
    public function firstOr(array|Closure $columns = ['*'], ?Closure $callback = null)
    {
        if ($columns instanceof Closure) {
            $callback = $columns;

            $columns = ['*'];
        }

        if (null !== ($model = $this->first($columns))) {
            return $model;
        }

        return $callback();
    }

    /**
     * Exécute la requête et obtient le premier résultat s'il est le seul enregistrement correspondant.
     *
     * @return TModel
     *
     * @throws ModelNotFoundException
     * @throws MultipleRecordsFoundException
     */
    public function sole(array|string $columns = ['*'])
    {
        try {
            return $this->baseSole($columns);
        } catch (RecordsNotFoundException) {
            throw (new ModelNotFoundException())->setModel(get_class($this->model));
        }
    }

    /**
     * Obtient la valeur d'une seule colonne à partir du premier résultat d'une requête.
     */
    public function value(Expression|string $column): mixed
    {
        if ($result = $this->first([$column])) {
            $column = $column instanceof Expression ? $column->getValue() : $column;

            return $result->{Text::afterLast($column, '.')};
        }

        return null;
    }

    /**
     * Obtient la valeur d'une seule colonne à partir du premier résultat d'une requête s'il est le seul enregistrement correspondant.
     *
     * @throws ModelNotFoundException<TModel>
     * @throws MultipleRecordsFoundException
     */
    public function soleValue(Expression|string $column): mixed
    {
        $column = $column instanceof Expression ? $column->getValue() : $column;

        return $this->sole([$column])->{Text::afterLast($column, '.')};
    }

    /**
     * Obtient la valeur d'une seule colonne à partir du premier résultat de la requête ou lance une exception.
     *
     * @throws ModelNotFoundException<Model>
     */
    public function valueOrFail(Expression|string $column): mixed
    {
        $column = $column instanceof Expression ? $column->getValue() : $column;

        return $this->firstOrFail([$column])->{Text::afterLast($column, '.')};
    }

    /**
     * Exécute la requête en tant qu'instruction "select".
     *
     * @return list<TModel>
     */
    public function all(array|string $columns = ['*']): array
    {
        return $this->get($columns)->all();
    }

    /**
     * Exécute la requête en tant qu'instruction "select".
     *
     * @return Collection<int, TModel>
     */
    public function get(array|string $columns = ['*']): Collection
    {
        $builder = $this->applyScopes();

        // Si nous avons effectivement trouvé des modèles, nous chargerons également avec empressement toutes les relations qui
        // ont été spécifiées comme devant être chargées avec empressement, ce qui résoudra le
        // problème de requête n+1 pour les développeurs afin d'éviter d'exécuter beaucoup de requêtes.
        if (count($models = $builder->getModels($columns)) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->applyAfterQueryCallbacks(
            $builder->getModel()->newCollection($models),
        );
    }

    /**
     * Obtient les modèles hydratés sans chargement empressé.
     *
     * @return list<TModel>
     */
    public function getModels(array|string $columns = []): array
    {
        return $this->model->hydrate(
            $this->query->from($this->model->getTable())->select($columns)->result('array'),
        )->all();
    }

    /**
     * Charge avec empressement les relations pour les modèles.
     *
     * @param list<TModel> $models
     *
     * @return list<TModel>
     */
    public function eagerLoadRelations(array $models): array
    {
        foreach ($this->eagerLoad as $name => $constraints) {
            // Pour les chargements empressés imbriqués, nous sauterons le chargement ici et ils seront définis comme un
            // chargement empressé sur la requête pour récupérer la relation afin qu'ils soient chargés
            // avec empressement sur cette requête, car c'est là qu'ils sont hydratés en tant que modèles.
            if (! str_contains($name, '.')) {
                $models = $this->eagerLoadRelation($models, $name, $constraints);
            }
        }

        return $models;
    }

    /**
     * Charge avec empressement la relation sur un ensemble de modèles.
     */
    protected function eagerLoadRelation(array $models, string $name, Closure $constraints): array
    {
        // Nous allons d'abord "sauvegarder" les conditions where existantes sur la requête afin de pouvoir
        // ajouter nos contraintes empressées. Ensuite, nous fusionnerons les wheres qui étaient sur la
        // requête pour qu'en ordre, toutes les conditions where puissent être spécifiées.
        $relation = $this->getRelation($name);

        $relation->addEagerConstraints($models);

        $constraints($relation);

        // Une fois que nous avons les résultats, nous les faisons simplement correspondre à leurs modèles parents
        // en utilisant l'instance de relation. Ensuite, nous retournons simplement les tableaux finis
        // de modèles qui ont été hydratés avec empressement et sont prêts à être retournés.
        return $relation->match(
            $relation->initRelation($models, $name),
            $relation->getEager(),
            $name,
        );
    }

    /**
     * Obtient l'instance de relation pour le nom de relation donné.
     *
     * @return Relation<Model, TModel, *>
     */
    public function getRelation(string $name): Relation
    {
        // Nous voulons exécuter une requête de relation sans aucune contrainte afin de
        // ne pas avoir à supprimer ces clauses where manuellement, ce qui devient vraiment compliqué
        // et sujet aux erreurs. Nous ne voulons pas de contraintes car nous ajoutons des contraintes empressées.
        $relation = Relation::noConstraints(function () use ($name) {
            try {
                return $this->getModel()->newInstance()->{$name}();
            } catch (BadMethodCallException) {
                throw RelationNotFoundException::make($this->getModel(), $name);
            }
        });

        $nested = $this->relationsNestedUnder($name);

        // S'il y a des relations imbriquées définies sur la requête, nous les mettrons sur
        // les instances de requête afin qu'elles puissent être traitées après que cette relation
        // soit chargée. De cette façon, elles descendront toutes en cascade lorsqu'elles seront chargées.
        if (count($nested) > 0) {
            $relation->getQuery()->with($nested);
        }

        return $relation;
    }

    /**
     * Obtient les relations profondément imbriquées pour une relation de niveau supérieur donnée.
     */
    protected function relationsNestedUnder(string $relation): array
    {
        $nested = [];

        // Nous cherchons essentiellement toutes les relations qui sont imbriquées plus profondément que
        // la relation de niveau supérieur donnée. Nous vérifierons simplement toutes les relations
        // qui commencent par les relations supérieures données et les ajouterons à nos tableaux.
        foreach ($this->eagerLoad as $name => $constraints) {
            if ($this->isNestedUnder($relation, $name)) {
                $nested[substr($name, strlen($relation . '.'))] = $constraints;
            }
        }

        return $nested;
    }

    /**
     * Détermine si la relation est imbriquée.
     */
    protected function isNestedUnder(string $relation, string $name): bool
    {
        return str_contains($name, '.') && str_starts_with($name, $relation . '.');
    }

    /**
     * Enregistre une fermeture à invoquer après l'exécution de la requête.
     *
     * @param Closure(mixed): mixed $callback
     */
    public function afterQuery(Closure $callback): static
    {
        $this->afterQueryCallbacks[] = $callback;

        return $this;
    }

    /**
     * Invoque les rappels de modification "après la requête".
     */
    public function applyAfterQueryCallbacks(mixed $result): mixed
    {
        foreach ($this->afterQueryCallbacks as $afterQueryCallback) {
            $result = $afterQueryCallback($result) ?: $result;
        }

        return $result;
    }

    /**
     * Obtient une collection paresseuse pour la requête donnée.
     *
     * @return LazyCollection<int, TModel>
     */
    public function cursor(): LazyCollection
    {
        return $this->applyScopes()
            ->baseCursor()
            // ->query->cursor() // @TODO A voir si on met le cursor au niveau du query builder (ps: je ne suis pas d'accord)
            ->map(function ($record) {
                $model = $this->newModelInstance()->newFromBuilder($record);

                return $this->applyAfterQueryCallbacks($this->newModelInstance()->newCollection([$model]))->first();
            })
            ->reject(static fn ($model) => null === $model);
    }

    /**
     * Ajoute une clause "order by" générique si la requête n'en a pas déjà une.
     */
    protected function enforceOrderBy(): void
    {
        if ($this->query->orders === []) {
            $this->orderBy($this->model->getQualifiedKeyName(), 'asc');
        }
    }

    /**
     * Obtient un tableau avec les valeurs d'une colonne donnée.
     *
     * @return IterableCollection<array-key, mixed>
     */
    public function pluck(Expression|string $column, ?string $key = null): IterableCollection
    {
        $column = $column instanceof Expression ? $column->getValue() : $column;

        $results = new IterableCollection($this->toBase()->values($column));

        $column = Text::after($column, "{$this->model->getTable()}.");

        // Si le modèle a un mutateur pour la colonne demandée, nous parcourrons
        // les résultats et muterons les valeurs afin que la version mutée de ces
        // colonnes soit retournée comme vous vous y attendriez de ces modèles Wolke.
        if (! $this->model->hasAnyGetMutator($column)
            && ! $this->model->hasCast($column)
            && ! in_array($column, $this->model->getDates(), true)) {
            return $this->applyAfterQueryCallbacks($results);
        }

        return $this->applyAfterQueryCallbacks(
            $results->map(fn ($value) => $this->model->newFromBuilder([$column => $value])->{$column}),
        );
    }

    /**
     * Pagine la requête donnée.
     *
     * @throws InvalidArgumentException
     */
    public function paginate(Closure|int|null $perPage = null, array|string $columns = [], string $pageName = 'page', ?int $page = null, Closure|int|null $total = null): LengthAwarePaginator
    {
        $page    = $page ?: Paginator::resolveCurrentPage($pageName);
        $total   = Helpers::value($total) ?? (clone $this->toBase())->count();
        $perPage = Helpers::value($perPage, $total) ?: $this->model->getPerPage();

        $results = $total
            ? $this->forPage($page, $perPage)->get($columns)
            : $this->model->newCollection();

        return $this->paginator($results, $total, $perPage, $page, [
            'path'     => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Pagine la requête donnée dans un paginateur simple.
     *
     * @return Contracts\Paginator
     */
    public function simplePaginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null)
    {
        $page = $page ?: Paginator::resolveCurrentPage($pageName);

        $perPage = $perPage ?: $this->model->getPerPage();

        // Ensuite, nous définirons la limite et le décalage pour cette requête afin que lorsque nous obtenons
        // les résultats, nous obtenions la section appropriée des résultats. Ensuite, nous créerons les
        // instances de paginateur complètes pour ces résultats avec la page et le nombre par page donnés.
        $this->offset(($page - 1) * $perPage)->limit($perPage + 1);

        return $this->simplePaginator($this->get($columns), $perPage, $page, [
            'path'     => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    /**
     * Pagine la requête donnée dans un paginateur à curseur.
     *
     * @return Contracts\CursorPaginator
     *
     * @throws CursorPaginationException
     */
    public function cursorPaginate(?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', Cursor|string|null $cursor = null)
    {
        $perPage = $perPage ?: $this->model->getPerPage();

        return $this->paginateUsingCursor($perPage, $columns, $cursorName, $cursor);
    }

    /**
     * Assure l'ordre approprié requis pour la pagination par curseur.
     */
    protected function ensureOrderForCursorPagination(bool $shouldReverse = false): IterableCollection
    {
        if ($this->query->orders === []) {
            $this->enforceOrderBy();
        }

        $reverseDirection = static function ($order) {
            if (! isset($order['direction'])) {
                return $order;
            }

            $order['direction'] = $order['direction'] === 'asc' ? 'desc' : 'asc';

            return $order;
        };

        if ($shouldReverse) {
            Invader::make($this->query)->orders = (new IterableCollection($this->query->orders))->map($reverseDirection)->toArray();
        }

        $orders = $this->query->orders;

        return (new IterableCollection($orders))
            ->filter(static fn ($order) => Arr::has($order, 'direction'))
            ->values();
    }

    /**
     * Sauvegarde un nouveau modèle et retourne l'instance.
     *
     * @return TModel
     */
    public function create(array $attributes = [])
    {
        return Helpers::tap($this->newModelInstance($attributes), static function ($instance) {
            $instance->save();
        });
    }

    /**
     * Sauvegarde un nouveau modèle et retourne l'instance sans déclencher d'événements.
     *
     * @return TModel
     */
    public function createQuietly(array $attributes = [])
    {
        return Model::withoutEvents(fn () => $this->create($attributes));
    }

    /**
     * Sauvegarde un nouveau modèle et retourne l'instance. Permet l'assignation en masse.
     *
     * @return TModel
     */
    public function forceCreate(array $attributes)
    {
        return $this->model->unguarded(fn () => $this->newModelInstance()->create($attributes));
    }

    /**
     * Sauvegarde une nouvelle instance de modèle avec assignation en masse sans déclencher d'événements.
     *
     * @return TModel
     */
    public function forceCreateQuietly(array $attributes = [])
    {
        return Model::withoutEvents(fn () => $this->forceCreate($attributes));
    }

    /**
     * Met à jour les enregistrements dans la base de données.
     */
    public function update(array $values): int
    {
        return $this->toBase()->update($this->addUpdatedAtColumn($values));
    }

    /**
     * Insère de nouveaux enregistrements ou met à jour ceux existants.
     */
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null): int
    {
        if ($values === []) {
            return 0;
        }
        if (! is_array(reset($values))) {
            $values = [$values];
        }
        if (null === $update) {
            $update = array_keys(reset($values));
        }

        return $this->toBase()->upsert(
            $this->addTimestampsToUpsertValues($this->addUniqueIdsToUpsertValues($values)),
            (array) $uniqueBy,
            $this->addUpdatedAtToUpsertColumns($update),
        );
    }

    /**
     * Met à jour l'horodatage de mise à jour de la colonne.
     *
     * @return false|int
     */
    public function touch(?string $column = null)
    {
        $time = $this->model->freshTimestamp();

        if ($column) {
            return $this->toBase()->update([$column => $time]);
        }

        $column = $this->model->getUpdatedAtColumn();

        if (! $this->model->usesTimestamps() || null === $column) {
            return false;
        }

        return $this->toBase()->update([$column => $time]);
    }

    /**
     * Incrémente la valeur d'une colonne d'un montant donné.
     */
    public function increment(string $column, float|int $amount = 1, array $extra = []): int
    {
        return $this->toBase()->increment($column, $amount, $this->addUpdatedAtColumn($extra));
    }

    /**
     * Décrémente la valeur d'une colonne d'un montant donné.
     */
    public function decrement(string $column, float|int $amount = 1, array $extra = []): bool
    {
        return $this->toBase()->decrement($column, $amount, $this->addUpdatedAtColumn($extra));
    }

    /**
     * Ajoute la colonne "updated at" à un tableau de valeurs.
     */
    protected function addUpdatedAtColumn(array $values): array
    {
        if (
            ! $this->model->usesTimestamps()
            || null === $this->model->getUpdatedAtColumn()
        ) {
            return $values;
        }

        $column = $this->model->getUpdatedAtColumn();

        if (! array_key_exists($column, $values)) {
            $timestamp = $this->model->freshTimestampString();

            if (
                $this->model->hasSetMutator($column)
                || $this->model->hasAttributeSetMutator($column)
                || $this->model->hasCast($column)
            ) {
                $timestamp = $this->model->newInstance()
                    ->forceFill([$column => $timestamp])
                    ->getAttributes()[$column] ?? $timestamp;
            }

            $values = array_merge([$column => $timestamp], $values);
        }

        $segments = preg_split('/\s+as\s+/i', $this->query->getTable());

        $qualifiedColumn = end($segments) . '.' . $column;

        $values[$qualifiedColumn] = Arr::get($values, $qualifiedColumn, $values[$column]);

        unset($values[$column]);

        return $values;
    }

    /**
     * Ajoute des IDs uniques aux valeurs insérées.
     */
    protected function addUniqueIdsToUpsertValues(array $values): array
    {
        if (! $this->model->usesUniqueIds()) {
            return $values;
        }

        foreach ($this->model->uniqueIds() as $uniqueIdAttribute) {
            foreach ($values as &$row) {
                if (! array_key_exists($uniqueIdAttribute, $row)) {
                    $row = array_merge([$uniqueIdAttribute => $this->model->newUniqueId()], $row);
                }
            }
        }

        return $values;
    }

    /**
     * Ajoute des horodatages aux valeurs insérées.
     */
    protected function addTimestampsToUpsertValues(array $values): array
    {
        if (! $this->model->usesTimestamps()) {
            return $values;
        }

        $timestamp = $this->model->freshTimestampString();

        $columns = array_filter([
            $this->model->getCreatedAtColumn(),
            $this->model->getUpdatedAtColumn(),
        ]);

        foreach ($columns as $column) {
            foreach ($values as &$row) {
                $row = array_merge([$column => $timestamp], $row);
            }
        }

        return $values;
    }

    /**
     * Ajoute la colonne "updated at" aux colonnes mises à jour.
     */
    protected function addUpdatedAtToUpsertColumns(array $update): array
    {
        if (! $this->model->usesTimestamps()) {
            return $update;
        }

        $column = $this->model->getUpdatedAtColumn();

        if (
            null !== $column
            && ! array_key_exists($column, $update)
            && ! in_array($column, $update, true)
        ) {
            $update[] = $column;
        }

        return $update;
    }

    /**
     * Supprime les enregistrements de la base de données.
     */
    public function delete(): mixed
    {
        if (isset($this->onDelete)) {
            return call_user_func($this->onDelete, $this);
        }

        return $this->toBase()->delete();
    }

    /**
     * Exécute la fonction de suppression par défaut sur le constructeur.
     *
     * Puisque nous n'appliquons pas de portées ici, la ligne sera effectivement supprimée.
     *
     * @return int
     */
    public function forceDelete()
    {
        return $this->query->delete();
    }

    /**
     * Enregistre un remplacement pour la fonction de suppression par défaut.
     */
    public function onDelete(Closure $callback): void
    {
        $this->onDelete = $callback;
    }

    /**
     * Détermine si le modèle donné a une portée.
     */
    public function hasNamedScope(string $scope): bool
    {
        return $this->model && $this->model->hasNamedScope($scope);
    }

    /**
     * Appelle les portées de modèle locales données.
     *
     * @return mixed|static
     */
    public function scopes(array|string $scopes)
    {
        $builder = $this;

        foreach (Arr::wrap($scopes) as $scope => $parameters) {
            // Si la clé de portée est un entier, alors la portée a été passée comme valeur et
            // la liste des paramètres est vide, donc nous formaterons le nom de la portée et ces
            // paramètres ici. Ensuite, nous serons prêts à appeler la portée sur le modèle.
            if (is_int($scope)) {
                [$scope, $parameters] = [$parameters, []];
            }

            // Ensuite, nous passerons le rappel de portée à la méthode callScope qui prendra
            // en charge le regroupement des "wheres" correctement afin que l'ordre logique ne soit pas
            // perturbé lors de l'ajout de portées. Ensuite, nous retournerons le constructeur.
            $builder = $builder->callNamedScope($scope, Arr::wrap($parameters));
        }

        return $builder;
    }

    /**
     * Applique les portées à l'instance de constructeur Orm et la retourne.
     */
    public function applyScopes(): static
    {
        if (! $this->scopes) {
            return $this;
        }

        $builder = clone $this;

        foreach ($this->scopes as $identifier => $scope) {
            if (! isset($builder->scopes[$identifier])) {
                continue;
            }

            $builder->callScope(function (self $builder) use ($scope) {
                // Si la portée est une fermeture, nous allons simplement l'appeler avec l'instance
                // du constructeur. La méthode "callScope" regroupera correctement les clauses
                // qui sont ajoutées à cette requête afin que les clauses "where" maintiennent une logique appropriée.
                if ($scope instanceof Closure) {
                    $scope($builder);
                }

                // Si la portée est un objet Scope, nous appellerons la méthode apply sur cette portée
                // en passant le constructeur et l'instance du modèle. Après avoir exécuté toutes ces
                // portées, nous retournerons l'instance du constructeur à l'appelant externe.
                if ($scope instanceof Scope) {
                    $scope->apply($builder, $this->getModel());
                }
            });
        }

        return $builder;
    }

    /**
     * Applique la portée donnée sur l'instance de constructeur actuelle.
     */
    protected function callScope(callable $scope, array $parameters = []): mixed
    {
        array_unshift($parameters, $this);

        $query = $this->getQuery();

        // Nous garderons une trace du nombre de wheres sur la requête avant d'exécuter la
        // portée afin que nous puissions regrouper correctement les contraintes de portée ajoutées dans la
        // requête comme leur propre instruction where imbriquée isolée et éviter les problèmes.
        $originalWhereCount = count($query->wheres);

        $result = $scope(...$parameters) ?? $this;

        if (count($query->wheres) > $originalWhereCount) {
            $this->addNewWheresWithinGroup($query, $originalWhereCount);
        }

        return $result;
    }

    /**
     * Applique la portée nommée donnée sur l'instance de constructeur actuelle.
     */
    protected function callNamedScope(string $scope, array $parameters = []): mixed
    {
        return $this->callScope(fn (...$parameters) => $this->model->callNamedScope($scope, $parameters), $parameters);
    }

    /**
     * Imbrique les conditions where en les découpant au nombre de where donné.
     */
    protected function addNewWheresWithinGroup(BaseBuilder $query, int $originalWhereCount): void
    {
        // Ici, nous supprimons totalement toutes les clauses where puisque nous allons
        // les reconstruire comme des requêtes imbriquées en découpant les groupes de where dans
        // leurs propres sections. C'est pour éviter toute logique d'ordre confuse.
        $allWheres = $query->wheres;

        Invader::make($query)->wheres = [];

        $this->groupWhereSliceForScope(
            $query,
            array_slice($allWheres, 0, $originalWhereCount),
        );

        $this->groupWhereSliceForScope(
            $query,
            array_slice($allWheres, $originalWhereCount),
        );
    }

    /**
     * Découpe les conditions where au décalage donné et les ajoute à la requête comme condition imbriquée.
     */
    protected function groupWhereSliceForScope(BaseBuilder $query, array $whereSlice): void
    {
        $whereBooleans = (new IterableCollection($whereSlice))->pluck('boolean');

        // Ici, nous vérifierons si le sous-ensemble donné de clauses where contient des booléens "or"
        // et dans ce cas, créons une expression where imbriquée. De cette façon,
        // nous n'ajoutons pas d'imbrication inutile, gardant ainsi la requête propre.
        if ($whereBooleans->contains(static fn ($logicalOperator) => str_contains($logicalOperator, 'or'))) {
            $wheres = $query->wheres;

            $wheres[] = $this->createNestedWhere(
                $whereSlice,
                str_replace(' not', '', $whereBooleans->first()),
            );
            Invader::make($query)->wheres = $wheres;
        } else {
            Invader::make($query)->wheres = array_merge($query->wheres, $whereSlice);
        }
    }

    /**
     * Crée un tableau where avec des conditions where imbriquées.
     */
    protected function createNestedWhere(array $whereSlice, string $boolean = 'and'): array
    {
        $whereGroup = $this->getQuery()->reset()->from($this->model->getTable());

        Invader::make($whereGroup)->wheres = $whereSlice;

        return ['type' => 'nested', 'query' => $whereGroup, 'boolean' => $boolean];
    }

    /**
     * Définit les relations qui doivent être chargées avec empressement.
     *
     * @param array<array-key, array|(Closure(Relation<*,*,*>): mixed)|string>|string  $relations
     * @param (Closure(Relation<*,*,*>): mixed)|string|null  $callback
     */
    public function with($relations, Closure|string|null $callback = null): static
    {
        if ($callback instanceof Closure) {
            $eagerLoad = $this->parseWithRelations([$relations => $callback]);
        } else {
            $eagerLoad = $this->parseWithRelations(is_string($relations) ? func_get_args() : $relations);
        }

        $this->eagerLoad = array_merge($this->eagerLoad, $eagerLoad);

        return $this;
    }

    /**
     * Empêche les relations spécifiées d'être chargées avec empressement.
     */
    public function without(mixed $relations): static
    {
        $this->eagerLoad = array_diff_key($this->eagerLoad, array_flip(
            is_string($relations) ? func_get_args() : $relations,
        ));

        return $this;
    }

    /**
     * Définit les relations qui doivent être chargées avec empressement tout en supprimant toute spécification de chargement empressé précédemment ajoutée.
     *
     * @param  array<array-key, array|(Closure(Relation<*,*,*>): mixed)|string>|string  $relations
     */
    public function withOnly($relations): static
    {
        $this->eagerLoad = [];

        return $this->with($relations);
    }

    /**
     * Crée une nouvelle instance du modèle en cours d'interrogation.
     *
     * @return TModel
     */
    public function newModelInstance(array $attributes = []): Model
    {
        $attributes = array_merge($this->pendingAttributes, $attributes);

        return $this->model->newInstance($attributes)->setConnection(
            $this->query->getConnection()->getName(),
        );
    }

    /**
     * Analyse une liste de relations en individuelles.
     */
    protected function parseWithRelations(array $relations): array
    {
        if ($relations === []) {
            return [];
        }

        $results = [];

        foreach ($this->prepareNestedWithRelationships($relations) as $name => $constraints) {
            // Nous devons séparer toutes les inclusions imbriquées, ce qui permet aux développeurs
            // de charger des relations profondes en utilisant des "points" sans indiquer chaque niveau de
            // la relation avec sa propre clé dans le tableau des noms de chargement empressé.
            $results = $this->addNestedWiths($name, $results);

            $results[$name] = $constraints;
        }

        return $results;
    }

    /**
     * Prépare les relations avec chargement empressé imbriquées.
     */
    protected function prepareNestedWithRelationships(array $relations, string $prefix = ''): array
    {
        $preparedRelationships = [];

        if ($prefix !== '') {
            $prefix .= '.';
        }

        // Si l'une des relations est formatée avec la syntaxe [$attribute => array()],
        // nous allons boucler sur les relations imbriquées et préfixer chaque clé de
        // ce tableau tout en aplatissant dans le format de notation par points traditionnel.
        foreach ($relations as $key => $value) {
            if (! is_string($key) || ! is_array($value)) {
                continue;
            }

            [$attribute, $attributeSelectConstraint] = $this->parseNameAndAttributeSelectionConstraint($key);

            $preparedRelationships = array_merge(
                $preparedRelationships,
                ["{$prefix}{$attribute}" => $attributeSelectConstraint],
                $this->prepareNestedWithRelationships($value, "{$prefix}{$attribute}"),
            );

            unset($relations[$key]);
        }

        // Nous savons maintenant que les relations restantes sont dans un format de notation par points
        // et peuvent être une chaîne ou une fermeture. Nous allons boucler sur elles et nous assurer que toutes
        // les fermetures présentes sont fusionnées + les chaînes sont transformées en contraintes.
        foreach ($relations as $key => $value) {
            if (is_numeric($key) && is_string($value)) {
                [$key, $value] = $this->parseNameAndAttributeSelectionConstraint($value);
            }

            $preparedRelationships[$prefix . $key] = $this->combineConstraints([
                $value,
                $preparedRelationships[$prefix . $key] ?? static function () {
                },
            ]);
        }

        return $preparedRelationships;
    }

    /**
     * Combine un tableau de contraintes en une seule contrainte.
     */
    protected function combineConstraints(array $constraints): Closure
    {
        return static function ($builder) use ($constraints) {
            foreach ($constraints as $constraint) {
                $builder = $constraint($builder) ?? $builder;
            }

            return $builder;
        };
    }

    /**
     * Analyse les contraintes de sélection d'attribut à partir du nom.
     */
    protected function parseNameAndAttributeSelectionConstraint(string $name): array
    {
        return str_contains($name, ':')
            ? $this->createSelectWithConstraint($name)
            : [$name, static function () {
            }];
    }

    /**
     * Crée une contrainte pour sélectionner les colonnes données pour la relation.
     */
    protected function createSelectWithConstraint(string $name): array
    {
        return [explode(':', $name)[0], static function ($query) use ($name) {
            $query->select(array_map(static fn ($column) => $query instanceof BelongsToMany
                    ? $query->getRelated()->qualifyColumn($column)
                    : $column, explode(',', explode(':', $name)[1])));
        }];
    }

    /**
     * Analyse les relations imbriquées dans une relation.
     */
    protected function addNestedWiths(string $name, array $results): array
    {
        $progress = [];

        // Si la relation a déjà été définie dans le tableau de résultats, nous ne la définirons pas
        // à nouveau, car cela remplacerait toutes les contraintes qui étaient déjà placées
        // sur les relations. Nous ne définirons que celles qui ne sont pas spécifiées.
        foreach (explode('.', $name) as $segment) {
            $progress[] = $segment;

            if (! isset($results[$last = implode('.', $progress)])) {
                $results[$last] = static function () {
                };
            }
        }

        return $results;
    }

    /**
     * Spécifie les attributs qui doivent être ajoutés à tous les nouveaux modèles créés par ce constructeur.
     *
     * Les paires clé/valeur données seront également ajoutées comme conditions where à la requête.
     */
    public function withAttributes(array|Expression|string $attributes, mixed $value = null, bool $asConditions = true): static
    {
        if (! is_array($attributes)) {
            $attributes = [$attributes => $value];
        }

        if ($asConditions) {
            foreach ($attributes as $column => $value) {
                $this->where($this->qualifyColumn($column), $value);
            }
        }

        $this->pendingAttributes = array_merge($this->pendingAttributes, $attributes);

        return $this;
    }

    /**
     * Applique des casts au moment de la requête à l'instance du modèle.
     */
    public function withCasts(array $casts): static
    {
        $this->model->mergeCasts($casts);

        return $this;
    }

    /**
     * Exécute la fermeture donnée dans un point de sauvegarde de transaction si nécessaire.
     *
     * @template TModelValue
     *
     * @param Closure(): TModelValue $scope
     *
     * @return TModelValue
     */
    public function withSavepointIfNeeded(Closure $scope): mixed
    {
        return $this->getQuery()->db()->transactionLevel() > 0
            ? $this->getQuery()->db()->transaction($scope)
            : $scope();
    }

    /**
     * Obtient les instances de constructeur Wolke qui sont utilisées dans l'union de la requête.
     */
    protected function getUnionBuilders(): IterableCollection
    {
        return $this->query->unions !== []
            ? (new IterableCollection($this->query->unions))->pluck('query')
            : new IterableCollection();
    }

    /**
     * Obtient l'instance de constructeur de requête sous-jacente.
     */
    public function getQuery(): BaseBuilder
    {
        return $this->query;
    }

    /**
     * Définit l'instance de constructeur de requête sous-jacente.
     */
    public function setQuery(BaseBuilder $query): static
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Obtient une instance de constructeur de requête de base.
     */
    public function toBase(): BaseBuilder
    {
        return $this->applyScopes()->getQuery();
    }

    /**
     * Obtient les relations en cours de chargement empressé.
     */
    public function getEagerLoads(): array
    {
        return $this->eagerLoad;
    }

    /**
     * Définit les relations en cours de chargement empressé.
     */
    public function setEagerLoads(array $eagerLoad): static
    {
        $this->eagerLoad = $eagerLoad;

        return $this;
    }

    /**
     * Indique que les relations données ne doivent pas être chargées avec empressement.
     */
    public function withoutEagerLoad(array $relations): static
    {
        $relations = array_diff(array_keys($this->model->getRelations()), $relations);

        return $this->with($relations);
    }

    /**
     * Vide les relations en cours de chargement empressé.
     */
    public function withoutEagerLoads(): static
    {
        return $this->setEagerLoads([]);
    }

    /**
     * Obtient la valeur "limit" de la requête ou null si elle n'est pas définie.
     */
    public function getLimit(): ?int
    {
        return $this->query->limit;
    }

    /**
     * Obtient la valeur "offset" de la requête ou null si elle n'est pas définie.
     */
    public function getOffset(): ?int
    {
        return $this->query->offset;
    }

    /**
     * Obtient le nom de clé par défaut de la table.
     */
    protected function defaultKeyName(): string
    {
        return $this->getModel()->getKeyName();
    }

    /**
     * Obtient l'instance de modèle en cours d'interrogation.
     *
     * @return TModel
     */
    public function getModel(): Model
    {
        return $this->model;
    }

    /**
     * Définit une instance de modèle pour le modèle en cours d'interrogation.
     *
     * @template TModelNew of Model
     *
     * @param TModelNew $model
     *
     * @return static<TModelNew>
     */
    public function setModel(Model $model): static
    {
        $this->model = $model;

        $this->query->from($model->getTable(), true);

        return $this;
    }

    /**
     * Qualifie le nom de colonne donné par la table du modèle.
     */
    public function qualifyColumn(Expression|string $column): string
    {
        $column = $column instanceof Expression ? $column->getValue() : $column;

        return $this->model->qualifyColumn($column);
    }

    /**
     * Qualifie les colonnes données avec la table du modèle.
     */
    public function qualifyColumns(array|Expression $columns): array
    {
        return $this->model->qualifyColumns($columns);
    }

    /**
     * Obtient la macro donnée par son nom.
     */
    public function getMacro(string $name): Closure
    {
        return Arr::get($this->localMacros, $name);
    }

    /**
     * Vérifie si une macro est enregistrée.
     */
    public function hasMacro(string $name): bool
    {
        return isset($this->localMacros[$name]);
    }

    /**
     * Obtient la macro globale donnée par son nom.
     */
    public static function getGlobalMacro(string $name): Closure
    {
        return Arr::get(static::$macros, $name);
    }

    /**
     * Vérifie si une macro globale est enregistrée.
     */
    public static function hasGlobalMacro(string $name): bool
    {
        return isset(static::$macros[$name]);
    }

    /**
     * Accède dynamiquement aux proxys du constructeur.
     *
     * @throws Exception
     */
    public function __get(string $key): mixed
    {
        if (in_array($key, ['orWhere', 'whereNot', 'orWhereNot'], true)) {
            return new HigherOrderBuilderProxy($this, $key);
        }

        if (in_array($key, $this->propertyPassthru, true)) {
            return $this->toBase()->{$key};
        }

        throw new Exception("La propriété [{$key}] n'existe pas sur l'instance du constructeur Wolke.");
    }

    /**
     * Gère dynamiquement les appels vers l'instance de requête.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        if ($method === 'macro') {
            $this->localMacros[$parameters[0]] = $parameters[1];

            return null;
        }

        if ($this->hasMacro($method)) {
            array_unshift($parameters, $this);

            return $this->localMacros[$method](...$parameters);
        }

        if (static::hasGlobalMacro($method)) {
            $callable = static::$macros[$method];

            if ($callable instanceof Closure) {
                $callable = $callable->bindTo($this, static::class);
            }

            return $callable(...$parameters);
        }

        if ($this->hasNamedScope($method)) {
            return $this->callNamedScope($method, $parameters);
        }

        if (in_array($method, $this->passthru, true)) {
            return (clone $this->toBase())->{$method}(...$parameters);
        }

        $result = $this->forwardCallTo($this->query, $method, $parameters);

        if ($result instanceof BaseBuilder) {
            $this->query = $result;
        }

        return $result;
    }

    /**
     * Gère dynamiquement les appels vers l'instance de requête.
     *
     * @throws BadMethodCallException
     */
    public static function __callStatic(string $method, array $parameters = []): mixed
    {
        if ($method === 'macro') {
            static::$macros[$parameters[0]] = $parameters[1];

            return null;
        }

        if ($method === 'mixin') {
            static::registerMixin($parameters[0], $parameters[1] ?? true);

            return null;
        }

        if (! static::hasGlobalMacro($method)) {
            static::throwBadMethodCallException($method);
        }

        $callable = static::$macros[$method];

        if ($callable instanceof Closure) {
            $callable = $callable->bindTo(null, static::class);
        }

        return $callable(...$parameters);
    }

    /**
     * Enregistre le mixin donné avec le constructeur.
     */
    protected static function registerMixin(string $mixin, bool $replace): void
    {
        $methods = (new ReflectionClass($mixin))->getMethods(
            ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED,
        );

        foreach ($methods as $method) {
            if ($replace || ! static::hasGlobalMacro($method->name)) {
                static::macro($method->name, $method->invoke($mixin));
            }
        }
    }

    /**
     * Clone le constructeur de requête Wolke.
     */
    public function clone(): static
    {
        return clone $this;
    }

    /**
     * Enregistre une fermeture à invoquer lors d'un clone.
     *
     * @var Closure(static): void
     */
    public function onClone(Closure $callback): static
    {
        $this->onCloneCallbacks[] = $callback;

        return $this;
    }

    /**
     * Force un clone du constructeur de requête sous-jacent lors du clonage.
     */
    public function __clone(): void
    {
        $this->query = clone $this->query;

        foreach ($this->onCloneCallbacks as $onCloneCallback) {
            $onCloneCallback($this);
        }
    }
}
