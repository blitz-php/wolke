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
use BlitzPHP\Database\Exceptions\MultipleRecordsFoundException;
use BlitzPHP\Database\Exceptions\UniqueConstraintViolationException;
use BlitzPHP\Database\Query\Expression;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Utilities\Iterable\LazyCollection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Contracts\CursorPaginator;
use BlitzPHP\Wolke\Contracts\LengthAwarePaginator;
use BlitzPHP\Wolke\Contracts\Paginator;
use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\AsPivot;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithDictionary;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithPivotTable;
use Closure;
use InvalidArgumentException;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TPivotModel of Pivot = Pivot
 * @template TAccessor of string = 'pivot'
 *
 * @extends Relation<TRelatedModel, TDeclaringModel, Collection<int, object{pivot: TPivotModel}&TRelatedModel>>
 *
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\BelongsToMany</a>
 */
class BelongsToMany extends Relation
{
    use InteractsWithDictionary;
    use InteractsWithPivotTable;

    /**
     * La table intermédiaire pour la relation.
     */
    protected string $table;

    /**
     * Les colonnes de la table pivot à récupérer.
     *
     * @var list<Expression|string>
     */
    protected array $pivotColumns = [];

    /**
     * Toutes les restrictions de table pivot pour les clauses where.
     */
    protected array $pivotWheres = [];

    /**
     * Toutes les restrictions de table pivot pour les clauses whereIn.
     */
    protected array $pivotWhereIns = [];

    /**
     * Toutes les restrictions de table pivot pour les clauses whereNull.
     */
    protected array $pivotWhereNulls = [];

    /**
     * Les valeurs par défaut pour les colonnes pivot.
     */
    protected array $pivotValues = [];

    /**
     * Indique si les horodatages sont disponibles sur la table pivot.
     */
    public bool $withTimestamps = false;

    /**
     * La colonne de table pivot personnalisée pour l'horodatage created_at.
     */
    protected ?string $pivotCreatedAt = null;

    /**
     * La colonne de table pivot personnalisée pour l'horodatage updated_at.
     */
    protected ?string $pivotUpdatedAt = null;

    /**
     * Le nom de classe du modèle pivot personnalisé à utiliser pour la relation.
     *
     * @var class-string<TPivotModel>
     */
    protected string $using = null;

    /**
     * Le nom de l'accesseur à utiliser pour la relation "pivot".
     *
     * @var TAccessor
     */
    protected string $accessor = 'pivot';

    /**
     * Crée une nouvelle instance de relation belongs to many.
     *
     * @param Builder<TRelatedModel>             $query
     * @param TDeclaringModel                    $parent
     * @param class-string<TRelatedModel>|string $table
     * @param string                             $foreignPivotKey La clé étrangère du modèle parent.
     * @param string                             $relatedPivotKey La clé associée de la relation.
     * @param string                             $parentKey       Le nom de la clé du modèle parent.
     * @param string                             $relatedKey      Le nom de la clé du modèle lié.
     * @param ?string                            $relationName    Le "nom" de la relation.
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $table,
        protected string $foreignPivotKey,
        protected string $relatedPivotKey,
        protected string $parentKey,
        protected string $relatedKey,
        protected ?string $relationName = null,
    ) {
        $this->table = $this->resolveTableName($table);

        parent::__construct($query, $parent);
    }

    /**
     * Tente de résoudre le nom de la table intermédiaire à partir de la chaîne donnée.
     */
    protected function resolveTableName(string $table): string
    {
        if (! str_contains($table, '\\') || ! class_exists($table)) {
            return $table;
        }

        $model = new $table();

        if (! $model instanceof Model) {
            return $table;
        }

        if (in_array(AsPivot::class, Helpers::classUsesRecursive($model), true)) {
            $this->using($table);
        }

        return $model->getTable();
    }

    /**
     * {@inheritDoc}
     */
    public function addConstraints(): void
    {
        $this->performJoin();

        if (static::$constraints) {
            $this->addWhereConstraints();
        }
    }

    /**
     * Définit la clause de jointure pour la requête de relation.
     *
     * @param Builder<TRelatedModel>|null $query
     */
    protected function performJoin(?Builder $query = null): static
    {
        $query = $query ?: $this->query;

        // Nous devons joindre la table intermédiaire sur la colonne de clé primaire du modèle lié
        // avec la colonne de clé étrangère de la table intermédiaire pour l'instance
        // du modèle lié. Ensuite, nous pouvons définir le "where" pour les modèles parents.
        $query->join(
            $this->table,
            $this->getQualifiedRelatedKeyName(),
            '=',
            $this->getQualifiedRelatedPivotKeyName(),
        );

        return $this;
    }

    /**
     * Définit la clause where pour la requête de relation.
     */
    protected function addWhereConstraints(): static
    {
        $this->query->where(
            $this->getQualifiedForeignPivotKeyName(),
            '=',
            $this->parent->{$this->parentKey},
        );

        return $this;
    }

    /**
     * Définit les contraintes pour un chargement empressé de la relation.
     */
    public function addEagerConstraints(array $models): void
    {
        $whereIn = $this->whereInMethod($this->parent, $this->parentKey);

        $this->whereInEager(
            $whereIn,
            $this->getQualifiedForeignPivotKeyName(),
            $this->getKeys($models, $this->parentKey),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param list<Model> $models
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * {@inheritDoc}
     *
     * @param list<Model> $models
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        // Une fois que nous avons un dictionnaire d'objets enfants, nous pouvons facilement faire correspondre les
        // enfants à leurs parents en utilisant le dictionnaire et les clés sur
        // les modèles parents. Ensuite, nous retournerons les modèles hydratés.
        foreach ($models as $model) {
            $key = $this->getDictionaryKey($model->{$this->parentKey});

            if (isset($dictionary[$key])) {
                $model->setRelation(
                    $relation,
                    $this->related->newCollection($dictionary[$key]),
                );
            }
        }

        return $models;
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
        // Nous allons d'abord construire un dictionnaire des modèles enfants indexé par la clé étrangère
        // de la relation afin que nous puissions facilement et rapidement les faire correspondre à leurs
        // parents sans avoir des boucles internes potentiellement lentes pour chaque modèle.
        $dictionary = [];

        $isAssociative = Arr::isAssoc($results->all());

        foreach ($results as $key => $result) {
            $value = $this->getDictionaryKey($result->{$this->accessor}->{$this->foreignPivotKey});

            if ($isAssociative) {
                $dictionary[$value][$key] = $result;
            } else {
                $dictionary[$value][] = $result;
            }
        }

        return $dictionary;
    }

    /**
     * Obtient la classe utilisée pour les modèles pivot.
     *
     * @return class-string<TPivotModel>
     */
    public function getPivotClass(): string
    {
        return $this->using ?? Pivot::class;
    }

    /**
     * Spécifie le modèle pivot personnalisé à utiliser pour la relation.
     *
     * @template TNewPivotModel of Pivot
     *
     * @param class-string<TNewPivotModel> $class
     *
     * @phpstan-this-out static<TRelatedModel, TDeclaringModel, TNewPivotModel, TAccessor>
     */
    public function using(string $class): static
    {
        $this->using = $class;

        return $this;
    }

    /**
     * Spécifie l'accesseur pivot personnalisé à utiliser pour la relation.
     *
     * @phpstan-this-out static<TRelatedModel, TDeclaringModel, TPivotModel, TNewAccessor>
     */
    public function as(string $accessor): static
    {
        $this->accessor = $accessor;

        return $this;
    }

    /**
     * Définit une clause where pour une colonne de table pivot.
     */
    public function wherePivot(Expression|string $column, mixed $operator = null, mixed $value = null, string $boolean = 'and'): static
    {
        $this->pivotWheres[] = func_get_args();

        return $this->where($this->qualifyPivotColumn($column), $operator, $value, $boolean);
    }

    /**
     * Définit une clause "where between" pour une colonne de table pivot.
     */
    public function wherePivotBetween(Expression|string $column, array $values, string $boolean = 'and', bool $not = false): static
    {
        return $this->whereBetween($this->qualifyPivotColumn($column), $values, $boolean, $not);
    }

    /**
     * Définit une clause "or where between" pour une colonne de table pivot.
     */
    public function orWherePivotBetween(Expression|string $column, array $values): static
    {
        return $this->wherePivotBetween($column, $values, 'or');
    }

    /**
     * Définit une clause "where pivot not between" pour une colonne de table pivot.
     */
    public function wherePivotNotBetween(Expression|string $column, array $values, string $boolean = 'and'): static
    {
        return $this->wherePivotBetween($column, $values, $boolean, true);
    }

    /**
     * Définit une clause "or where not between" pour une colonne de table pivot.
     */
    public function orWherePivotNotBetween(Expression|string $column, array $values): static
    {
        return $this->wherePivotBetween($column, $values, 'or', true);
    }

    /**
     * Définit une clause "where in" pour une colonne de table pivot.
     */
    public function wherePivotIn(Expression|string $column, mixed $values, string $boolean = 'and', bool $not = false): static
    {
        $this->pivotWhereIns[] = func_get_args();

        return $this->whereIn($this->qualifyPivotColumn($column), $values, $boolean, $not);
    }

    /**
     * Définit une clause "or where" pour une colonne de table pivot.
     */
    public function orWherePivot(Expression|string $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->wherePivot($column, $operator, $value, 'or');
    }

    /**
     * Définit une clause where pour une colonne de table pivot.
     *
     * De plus, les nouveaux enregistrements pivot recevront cette valeur.
     *
     * @param array<string, string>|Expression|string $column
     *
     * @throws InvalidArgumentException
     */
    public function withPivotValue(array|Expression|string $column, mixed $value = null): static
    {
        if (is_array($column)) {
            foreach ($column as $name => $value) {
                $this->withPivotValue($name, $value);
            }

            return $this;
        }

        if (null === $value) {
            throw new InvalidArgumentException('La valeur fournie ne peut pas être nulle.');
        }

        $this->pivotValues[] = compact('column', 'value');

        return $this->wherePivot($column, '=', $value);
    }

    /**
     * Définit une clause "or where in" pour une colonne de table pivot.
     */
    public function orWherePivotIn(Expression|string $column, mixed $values): static
    {
        return $this->wherePivotIn($column, $values, 'or');
    }

    /**
     * Définit une clause "where not in" pour une colonne de table pivot.
     */
    public function wherePivotNotIn(Expression|string $column, mixed $values, string $boolean = 'and'): static
    {
        return $this->wherePivotIn($column, $values, $boolean, true);
    }

    /**
     * Définit une clause "or where not in" pour une colonne de table pivot.
     */
    public function orWherePivotNotIn(Expression|string $column, mixed $values): static
    {
        return $this->wherePivotNotIn($column, $values, 'or');
    }

    /**
     * Définit une clause "where null" pour une colonne de table pivot.
     */
    public function wherePivotNull(Expression|string $column, string $boolean = 'and', bool $not = false): static
    {
        $this->pivotWhereNulls[] = func_get_args();

        return $this->whereNull($this->qualifyPivotColumn($column), $boolean, $not);
    }

    /**
     * Définit une clause "where not null" pour une colonne de table pivot.
     */
    public function wherePivotNotNull(Expression|string $column, string $boolean = 'and'): static
    {
        return $this->wherePivotNull($column, $boolean, true);
    }

    /**
     * Définit une clause "or where null" pour une colonne de table pivot.
     */
    public function orWherePivotNull(Expression|string $column, bool $not = false): static
    {
        return $this->wherePivotNull($column, 'or', $not);
    }

    /**
     * Définit une clause "or where not null" pour une colonne de table pivot.
     */
    public function orWherePivotNotNull(Expression|string $column): static
    {
        return $this->orWherePivotNull($column, true);
    }

    /**
     * Ajoute une clause "order by" pour une colonne de table pivot.
     */
    public function orderByPivot(Expression|string $column, string $direction = 'asc'): static
    {
        return $this->orderBy($this->qualifyPivotColumn($column), $direction);
    }

    /**
     * Ajoute une clause "order by desc" pour une colonne de table pivot.
     */
    public function orderByPivotDesc(Expression|string $column): static
    {
        return $this->orderBy($this->qualifyPivotColumn($column), 'desc');
    }

    /**
     * Trouve un modèle lié par sa clé primaire ou retourne une nouvelle instance du modèle lié.
     *
     * @return (
     *     $id is (Arrayable<array-key, mixed>|array<mixed>)
     *     ? Collection<int, TRelatedModel&object{pivot: TPivotModel}>
     *     : TRelatedModel&object{pivot: TPivotModel}
     * )
     */
    public function findOrNew(mixed $id, array $columns = ['*'])
    {
        if (null === ($instance = $this->find($id, $columns))) {
            $instance = $this->related->newInstance();
        }

        return $instance;
    }

    /**
     * Obtient le premier modèle lié correspondant aux attributs ou l'instancie.
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function firstOrNew(array $attributes, array $values = []): Model
    {
        if (null === ($instance = $this->related->where($attributes)->first())) {
            $instance = $this->related->newInstance(array_merge($attributes, $values));
        }

        return $instance;
    }

    /**
     * Obtient le premier enregistrement lié correspondant aux attributs ou le crée.
     *
     * @param array|(Closure(): array) $values
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function firstOrCreate(array $attributes = [], array|Closure $values = [], array $joining = [], bool $touch = true): Model
    {
        if (null === ($instance = (clone $this)->where($attributes)->first())) {
            if (null === ($instance = $this->related->where($attributes)->first())) {
                $instance = $this->createOrFirst($attributes, $values, $joining, $touch);
            } else {
                try {
                    $this->getQuery()->withSavepointIfNeeded(fn () => $this->attach($instance, $joining, $touch));
                } catch (UniqueConstraintViolationException) {
                    // Rien à faire, le modèle était déjà attaché...
                }
            }
        }

        return $instance;
    }

    /**
     * Tente de créer l'enregistrement. Si une violation de contrainte unique se produit, tente de trouver l'enregistrement correspondant.
     *
     * @param array|(Closure(): array) $values
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function createOrFirst(array $attributes = [], array|Closure $values = [], array $joining = [], bool $touch = true): Model
    {
        try {
            return $this->getQuery()->withSavePointIfNeeded(fn () => $this->create(array_merge($attributes, Helpers::value($values)), $joining, $touch));
        } catch (UniqueConstraintViolationException $e) {
            // ...
        }

        try {
            return Helpers::tap($this->related->where($attributes)->first() ?? throw $e, function ($instance) use ($joining, $touch) {
                $this->getQuery()->withSavepointIfNeeded(fn () => $this->attach($instance, $joining, $touch));
            });
        } catch (UniqueConstraintViolationException $e) {
            return (clone $this)->where($attributes)->first() ?? throw $e;
        }
    }

    /**
     * Crée ou met à jour un enregistrement lié correspondant aux attributs, et le remplit avec des valeurs.
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function updateOrCreate(array $attributes, array $values = [], array $joining = [], bool $touch = true): Model
    {
        return Helpers::tap($this->firstOrCreate($attributes, $values, $joining, $touch), static function ($instance) use ($values) {
            if (! $instance->wasRecentlyCreated) {
                $instance->fill($values);

                $instance->save(['touch' => false]);
            }
        });
    }

    /**
     * Trouve un modèle lié par sa clé primaire.
     *
     * @return (
     *     $id is (Arrayable<array-key, mixed>|array<mixed>)
     *     ? Collection<int, TRelatedModel&object{pivot: TPivotModel}>
     *     : (TRelatedModel&object{pivot: TPivotModel})|null
     * )
     */
    public function find(mixed $id, array $columns = ['*'])
    {
        if (! $id instanceof Model && (is_array($id) || $id instanceof Arrayable)) {
            return $this->findMany($id, $columns);
        }

        return $this->where(
            $this->getRelated()->getQualifiedKeyName(),
            '=',
            $this->parseId($id),
        )->first($columns);
    }

    /**
     * Trouve un seul modèle lié par sa clé primaire.
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     *
     * @throws ModelNotFoundException<TRelatedModel>
     * @throws MultipleRecordsFoundException
     */
    public function findSole(mixed $id, array $columns = ['*'])
    {
        return $this->where(
            $this->getRelated()->getQualifiedKeyName(),
            '=',
            $this->parseId($id),
        )->sole($columns);
    }

    /**
     * Trouve plusieurs modèles liés par leurs clés primaires.
     *
     * @return Collection<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function findMany(array|Arrayable $ids, array $columns = ['*']): Collection
    {
        $ids = $ids instanceof Arrayable ? $ids->toArray() : $ids;

        if ($ids === []) {
            return $this->getRelated()->newCollection();
        }

        return $this->whereKey(
            $this->parseIds($ids),
        )->get($columns);
    }

    /**
     * Trouve un modèle lié par sa clé primaire ou lance une exception.
     *
     * @return (
     *     $id is (Arrayable<array-key, mixed>|array<mixed>)
     *     ? Collection<int, TRelatedModel&object{pivot: TPivotModel}>
     *     : TRelatedModel&object{pivot: TPivotModel}
     * )
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

        throw (new ModelNotFoundException())->setModel(get_class($this->related), $id);
    }

    /**
     * Trouve un modèle lié par sa clé primaire ou appelle un rappel.
     *
     * @template TValue
     *
     * @param (Closure(): TValue)|list<string>|string $columns
     * @param (Closure(): TValue)|null                $callback
     *
     * @return (
     *     $id is (Arrayable<array-key, mixed>|array<mixed>)
     *     ? Collection<int, TRelatedModel&object{pivot: TPivotModel}>|TValue
     *     : (TRelatedModel&object{pivot: TPivotModel})|TValue
     * )
     */
    public function findOr(mixed $id, array|Closure $columns = ['*'], ?Closure $callback = null)
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
     * Ajoute une clause where de base à la requête, et retourne le premier résultat.
     *
     * @param array|Closure|string $column
     *
     * @return (object{pivot: TPivotModel}&TRelatedModel)|null
     */
    public function firstWhere($column, mixed $operator = null, mixed $value = null, string $boolean = 'and')
    {
        return $this->where($column, $operator, $value, $boolean)->first();
    }

    /**
     * Exécute la requête et obtient le premier résultat.
     *
     * @return (object{pivot: TPivotModel}&TRelatedModel)|null
     */
    public function first(array $columns = ['*'])
    {
        $results = $this->limit(1)->get($columns);

        return count($results) > 0 ? $results->first() : null;
    }

    /**
     * Exécute la requête et obtient le premier résultat ou lance une exception.
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     *
     * @throws ModelNotFoundException
     */
    public function firstOrFail(array $columns = ['*'])
    {
        if (null !== ($model = $this->first($columns))) {
            return $model;
        }

        throw (new ModelNotFoundException())->setModel(get_class($this->related));
    }

    /**
     * Exécute la requête et obtient le premier résultat ou appelle un rappel.
     *
     * @template TValue
     *
     * @param (Closure(): TValue)|list<string> $columns
     * @param (Closure(): TValue)|null         $callback
     *
     * @return (object{pivot: TPivotModel}&TRelatedModel)|TValue
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
     * Obtient les résultats de la relation.
     */
    public function getResults(): mixed
    {
        return null !== $this->parent->{$this->parentKey}
                ? $this->get()
                : $this->related->newCollection();
    }

    /**
     * Exécute la requête en tant qu'instruction "select".
     */
    public function get(array $columns = ['*']): Collection
    {
        // Nous allons d'abord ajouter les colonnes de sélection appropriées à la requête afin qu'elle soit exécutée avec
        // les colonnes appropriées. Ensuite, nous obtiendrons les résultats et hydraterons les modèles pivot
        // avec le résultat de ces colonnes en tant que relation de modèle distincte.
        $builder = $this->query->applyScopes();

        $columns = $builder->getQuery()->columns !== [] ? [] : $columns;

        $models = $builder->select(
            $this->shouldSelect($columns),
        )->getModels();

        $this->hydratePivotRelation($models);

        // Si nous avons effectivement trouvé des modèles, nous chargerons également avec empressement toutes les relations qui
        // ont été spécifiées comme devant être chargées avec empressement. Cela résoudra le
        // problème de requête n + 1 pour le développeur et augmentera également les performances.
        if (count($models) > 0) {
            $models = $builder->eagerLoadRelations($models);
        }

        return $this->query->applyAfterQueryCallbacks(
            $this->related->newCollection($models),
        );
    }

    /**
     * Obtient les colonnes de sélection pour la requête de relation.
     */
    protected function shouldSelect(array $columns = ['*']): array
    {
        if ($columns === ['*']) {
            $columns = [$this->related->qualifyColumn('*')];
        }

        return array_merge($columns, $this->aliasedPivotColumns());
    }

    /**
     * Obtient les colonnes pivot pour la relation.
     *
     * "pivot_" est préfixé à chaque colonne pour une suppression facile plus tard.
     */
    protected function aliasedPivotColumns(): array
    {
        return (new IterableCollection([
            $this->foreignPivotKey,
            $this->relatedPivotKey,
            ...$this->pivotColumns,
        ]))
            ->map(fn ($column) => $this->qualifyPivotColumn($column) . ' as pivot_' . $column)
            ->unique()
            ->all();
    }

    /**
     * Obtient un paginateur pour l'instruction "select".
     *
     * @return LengthAwarePaginator<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function paginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): LengthAwarePaginator
    {
        $this->query->select($this->shouldSelect($columns));

        return Helpers::tap($this->query->paginate($perPage, $columns, $pageName, $page), function ($paginator) {
            $this->hydratePivotRelation($paginator->items());
        });
    }

    /**
     * Pagine la requête donnée dans un paginateur simple.
     *
     * @return Paginator<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function simplePaginate(?int $perPage = null, array $columns = ['*'], string $pageName = 'page', ?int $page = null): Paginator
    {
        $this->query->select($this->shouldSelect($columns));

        return Helpers::tap($this->query->simplePaginate($perPage, $columns, $pageName, $page), function ($paginator) {
            $this->hydratePivotRelation($paginator->items());
        });
    }

    /**
     * Pagine la requête donnée dans un paginateur à curseur.
     *
     * @return CursorPaginator<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function cursorPaginate(?int $perPage = null, array $columns = ['*'], string $cursorName = 'cursor', ?string $cursor = null): CursorPaginator
    {
        $this->query->select($this->shouldSelect($columns));

        return Helpers::tap($this->query->cursorPaginate($perPage, $columns, $cursorName, $cursor), function ($paginator) {
            $this->hydratePivotRelation($paginator->items());
        });
    }

    /**
     * Traite les résultats de la requête par lots.
     */
    public function chunk(int $count, callable $callback): bool
    {
        return $this->prepareQueryBuilder()->chunk($count, function ($results, $page) use ($callback) {
            $this->hydratePivotRelation($results->all());

            return $callback($results, $page);
        });
    }

    /**
     * Traite les résultats d'une requête par lots en comparant les ID numériques.
     */
    public function chunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        return $this->orderedChunkById($count, $callback, $column, $alias);
    }

    /**
     * Traite les résultats d'une requête par lots en comparant les ID dans l'ordre décroissant.
     */
    public function chunkByIdDesc(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        return $this->orderedChunkById($count, $callback, $column, $alias, descending: true);
    }

    /**
     * Exécute un rappel sur chaque élément tout en traitant par lots par ID.
     */
    public function eachById(callable $callback, int $count = 1000, ?string $column = null, ?string $alias = null): bool
    {
        return $this->chunkById($count, static function ($results, $page) use ($callback, $count) {
            foreach ($results as $key => $value) {
                if ($callback($value, (($page - 1) * $count) + $key) === false) {
                    return false;
                }
            }

            return true;
        }, $column, $alias);
    }

    /**
     * Traite les résultats d'une requête par lots en comparant les ID dans un ordre donné.
     */
    public function orderedChunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null, bool $descending = false): bool
    {
        $column ??= $this->getRelated()->qualifyColumn(
            $this->getRelatedKeyName(),
        );

        $alias ??= $this->getRelatedKeyName();

        return $this->prepareQueryBuilder()->orderedChunkById($count, function ($results, $page) use ($callback) {
            $this->hydratePivotRelation($results->all());

            return $callback($results, $page);
        }, $column, $alias, $descending);
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
     * @return LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        return $this->prepareQueryBuilder()->lazy($chunkSize)->map(function ($model) {
            $this->hydratePivotRelation([$model]);

            return $model;
        });
    }

    /**
     * Interroge paresseusement, en traitant les résultats d'une requête par lots en comparant les ID.
     *
     * @return LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return $this->orderedLazyById($chunkSize, $column, $alias);
    }

    /**
     * Interroge paresseusement, en traitant les résultats d'une requête par lots en comparant les ID dans l'ordre décroissant.
     *
     * @return LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return $this->orderedLazyById($chunkSize, $column, $alias, true);
    }

    /**
     * Interroge paresseusement, en traitant les résultats d'une requête par lots en comparant les ID dans un ordre donné.
     */
    public function orderedLazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null, bool $descending = false): LazyCollection
    {
        $column ??= $this->getRelated()->qualifyColumn(
            $this->getRelatedKeyName(),
        );

        $alias ??= $this->getRelatedKeyName();

        return $this->prepareQueryBuilder()->orderedLazyById($chunkSize, $column, $alias, $descending)->map(function ($model) {
            $this->hydratePivotRelation([$model]);

            return $model;
        });
    }

    /**
     * Obtient une collection paresseuse pour la requête donnée.
     *
     * @return LazyCollection<int, object{pivot: TPivotModel}&TRelatedModel>
     */
    public function cursor(): LazyCollection
    {
        return $this->prepareQueryBuilder()->cursor()->map(function ($model) {
            $this->hydratePivotRelation([$model]);

            return $model;
        });
    }

    /**
     * Prépare le constructeur de requête pour l'exécution de la requête.
     *
     * @return Builder<TRelatedModel>
     */
    protected function prepareQueryBuilder(): Builder
    {
        return $this->query->select($this->shouldSelect());
    }

    /**
     * Hydrate la relation de table pivot sur les modèles.
     *
     * @param array<int, TRelatedModel> $models
     */
    protected function hydratePivotRelation(array $models): void
    {
        // Pour hydrater la relation pivot, nous allons simplement rassembler les attributs pivot
        // et créer un nouveau modèle Pivot, qui est essentiellement un modèle dynamique que nous
        // définirons les attributs, la table et les connexions dessus pour qu'il fonctionne.
        foreach ($models as $model) {
            $model->setRelation($this->accessor, $this->newExistingPivot(
                $this->migratePivotAttributes($model),
            ));
        }
    }

    /**
     * Obtient les attributs pivot d'un modèle.
     *
     * @param TRelatedModel $model
     */
    protected function migratePivotAttributes(Model $model): array
    {
        $values = [];

        foreach ($model->getAttributes() as $key => $value) {
            // Pour obtenir les attributs pivot, nous prendrons simplement tous les attributs qui
            // commencent par "pivot_" et les ajouterons à ces tableaux, ainsi que les supprimer
            // des modèles parents puisqu'ils existent dans une table différente.
            if (str_starts_with($key, 'pivot_')) {
                $values[substr($key, 6)] = $value;

                unset($model->{$key});
            }
        }

        return $values;
    }

    /**
     * Si nous touchons le modèle parent, touche.
     */
    public function touchIfTouching(): void
    {
        if ($this->touchingParent()) {
            $this->getParent()->touch();
        }

        if ($this->getParent()->touches($this->relationName)) {
            $this->touch();
        }
    }

    /**
     * Détermine si nous devons toucher le parent lors de la synchronisation.
     */
    protected function touchingParent(): bool
    {
        return $this->getRelated()->touches($this->guessInverseRelation());
    }

    /**
     * Tente de deviner le nom de l'inverse de la relation.
     */
    protected function guessInverseRelation(): string
    {
        return Text::camel(Text::pluralStudly(Helpers::classBasename($this->getParent())));
    }

    /**
     * {@inheritDoc}
     */
    public function touch(): void
    {
        if ($this->related->isIgnoringTouch()) {
            return;
        }

        $columns = [
            $this->related->getUpdatedAtColumn() => $this->related->freshTimestampString(),
        ];

        // Si nous avons effectivement des IDs pour la relation, nous exécuterons la requête pour mettre à jour tous
        // les horodatages des modèles liés, pour nous assurer que tout cela reflète les changements
        // des modèles parents. Cela nous aidera à maintenir la synchronisation de tout cache ici.
        if (count($ids = $this->allRelatedIds()) > 0) {
            $this->getRelated()->newQueryWithoutRelationships()->whereKey($ids)->update($columns);
        }
    }

    /**
     * Obtient tous les IDs des modèles liés.
     *
     * @return IterableCollection<int, int|string>
     */
    public function allRelatedIds(): IterableCollection
    {
        return new IterableCollection($this->newPivotQuery()->value($this->relatedPivotKey));
    }

    /**
     * Sauvegarde un nouveau modèle et l'attache au modèle parent.
     *
     * @param TRelatedModel $model
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function save(Model $model, array $pivotAttributes = [], bool $touch = true): Model
    {
        $model->save(['touch' => false]);

        $this->attach($model, $pivotAttributes, $touch);

        return $model;
    }

    /**
     * Sauvegarde un nouveau modèle sans déclencher d'événements et l'attache au modèle parent.
     *
     * @param TRelatedModel $model
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function saveQuietly(Model $model, array $pivotAttributes = [], bool $touch = true): Model
    {
        return Model::withoutEvents(fn () => $this->save($model, $pivotAttributes, $touch));
    }

    /**
     * Sauvegarde un tableau de nouveaux modèles et les attache au modèle parent.
     *
     * @template TContainer of Collection<array-key, TRelatedModel>|array<array-key, TRelatedModel>
     *
     * @param TContainer $models
     *
     * @return TContainer
     */
    public function saveMany(array|Collection $models, array $pivotAttributes = [])
    {
        foreach ($models as $key => $model) {
            $this->save($model, (array) ($pivotAttributes[$key] ?? []), false);
        }

        $this->touchIfTouching();

        return $models;
    }

    /**
     * Sauvegarde un tableau de nouveaux modèles sans déclencher d'événements et les attache au modèle parent.
     *
     * @template TContainer of Collection<array-key, TRelatedModel>|array<array-key, TRelatedModel>
     *
     * @param TContainer $models
     *
     * @return TContainer
     */
    public function saveManyQuietly(array|Collection $models, array $pivotAttributes = [])
    {
        return Model::withoutEvents(fn () => $this->saveMany($models, $pivotAttributes));
    }

    /**
     * Crée une nouvelle instance du modèle lié.
     *
     * @return object{pivot: TPivotModel}&TRelatedModel
     */
    public function create(array $attributes = [], array $joining = [], bool $touch = true): Model
    {
        $attributes = array_merge($this->getQuery()->pendingAttributes, $attributes);

        $instance = $this->related->newInstance($attributes);

        // Une fois que nous avons sauvegardé le modèle lié, nous devons l'attacher au modèle de base via
        // la table intermédiaire, donc nous utiliserons la méthode "attach" existante pour
        // accomplir cela, ce qui insérera l'enregistrement et tous les attributs supplémentaires.
        $instance->save(['touch' => false]);

        $this->attach($instance, $joining, $touch);

        return $instance;
    }

    /**
     * Crée un tableau de nouvelles instances des modèles liés.
     *
     * @return list<object{pivot: TPivotModel}&TRelatedModel>
     */
    public function createMany(iterable $records, array $joinings = []): array
    {
        $instances = [];

        foreach ($records as $key => $record) {
            $instances[] = $this->create($record, (array) ($joinings[$key] ?? []), false);
        }

        $this->touchIfTouching();

        return $instances;
    }

    /**
     * Ajoute les contraintes pour une requête de relation.
     *
     * @param array|mixed $columns
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($parentQuery->getQuery()->from === $query->getQuery()->from) {
            return $this->getRelationExistenceQueryForSelfJoin($query, $parentQuery, $columns);
        }

        $this->performJoin($query);

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
    public function getRelationExistenceQueryForSelfJoin(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        $query->select($columns);

        $query->from($this->related->getTable() . ' as ' . $hash = $this->getRelationCountHash());

        $this->related->setTable($hash);

        $this->performJoin($query);

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /**
     * Obtient la clé pour la comparaison avec la clé parente dans la requête "has".
     */
    public function getExistenceCompareKey(): string
    {
        return $this->getQualifiedForeignPivotKeyName();
    }

    /**
     * Spécifie que la table pivot a des horodatages de création et de mise à jour.
     */
    public function withTimestamps(false|string|null $createdAt = null, false|string|null $updatedAt = null): static
    {
        $this->pivotCreatedAt = $createdAt !== false ? $createdAt : null;
        $this->pivotUpdatedAt = $updatedAt !== false ? $updatedAt : null;

        $pivots = array_filter([
            $createdAt !== false ? $this->createdAt() : null,
            $updatedAt !== false ? $this->updatedAt() : null,
        ]);

        $this->withTimestamps = $pivots !== [];

        return $this->withTimestamps ? $this->withPivot($pivots) : $this;
    }

    /**
     * Obtient le nom de la colonne "created at".
     */
    public function createdAt(): string
    {
        return $this->pivotCreatedAt ?? $this->parent->getCreatedAtColumn() ?? Model::CREATED_AT;
    }

    /**
     * Obtient le nom de la colonne "updated at".
     */
    public function updatedAt(): string
    {
        return $this->pivotUpdatedAt ?? $this->parent->getUpdatedAtColumn() ?? Model::UPDATED_AT;
    }

    /**
     * Obtient la clé étrangère pour la relation.
     */
    public function getForeignPivotKeyName(): string
    {
        return $this->foreignPivotKey;
    }

    /**
     * Obtient la clé étrangère complètement qualifiée pour la relation.
     */
    public function getQualifiedForeignPivotKeyName(): string
    {
        return $this->qualifyPivotColumn($this->foreignPivotKey);
    }

    /**
     * Obtient la "clé liée" pour la relation.
     */
    public function getRelatedPivotKeyName(): string
    {
        return $this->relatedPivotKey;
    }

    /**
     * Obtient la "clé liée" complètement qualifiée pour la relation.
     */
    public function getQualifiedRelatedPivotKeyName(): string
    {
        return $this->qualifyPivotColumn($this->relatedPivotKey);
    }

    /**
     * Obtient la clé parente pour la relation.
     */
    public function getParentKeyName(): string
    {
        return $this->parentKey;
    }

    /**
     * Obtient le nom de la clé parente complètement qualifié pour la relation.
     */
    public function getQualifiedParentKeyName(): string
    {
        return $this->parent->qualifyColumn($this->parentKey);
    }

    /**
     * Obtient la clé liée pour la relation.
     */
    public function getRelatedKeyName(): string
    {
        return $this->relatedKey;
    }

    /**
     * Obtient le nom de la clé liée complètement qualifié pour la relation.
     */
    public function getQualifiedRelatedKeyName(): string
    {
        return $this->related->qualifyColumn($this->relatedKey);
    }

    /**
     * Obtient la table intermédiaire pour la relation.
     */
    public function getTable(): string
    {
        return $this->table;
    }

    /**
     * Obtient le nom de la relation pour la relation.
     */
    public function getRelationName(): string
    {
        return $this->relationName;
    }

    /**
     * Obtient le nom de l'accesseur pivot pour cette relation.
     */
    public function getPivotAccessor(): string
    {
        return $this->accessor;
    }

    /**
     * Obtient les colonnes pivot pour cette relation.
     */
    public function getPivotColumns(): array
    {
        return $this->pivotColumns;
    }

    /**
     * Qualifie le nom de colonne donné par la table pivot.
     */
    public function qualifyPivotColumn(Expression|string $column): Expression|string
    {
        if ($column instanceof Expression) {
            return $column;
        }

        return str_contains($column, '.')
            ? $column
            : $this->table . '.' . $column;
    }
}
