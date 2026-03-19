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

use ArrayAccess;
use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Contracts\Queue\QueueableCollection;
use BlitzPHP\Contracts\Queue\QueueableEntity;
use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Contracts\Support\Jsonable;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Database\Connection\BaseConnection;
use BlitzPHP\Database\Exceptions\DatabaseException;
use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Attributes\Scope as LocalScope;
use BlitzPHP\Wolke\Attributes\UseWolkeBuilder;
use BlitzPHP\Wolke\Concerns\GuardsAttributes;
use BlitzPHP\Wolke\Concerns\HasAttributes;
use BlitzPHP\Wolke\Concerns\HasEvents;
use BlitzPHP\Wolke\Concerns\HasGlobalScopes;
use BlitzPHP\Wolke\Concerns\HasRelationships;
use BlitzPHP\Wolke\Concerns\HasTimestamps;
use BlitzPHP\Wolke\Concerns\HasUniqueIds;
use BlitzPHP\Wolke\Concerns\HidesAttributes;
use BlitzPHP\Wolke\Concerns\PreventsCircularRecursion;
use BlitzPHP\Wolke\Contracts\Dispatcher;
use BlitzPHP\Wolke\Contracts\Scope;
use BlitzPHP\Wolke\Exceptions\JsonEncodingException;
use BlitzPHP\Wolke\Exceptions\MassAssignmentException;
use BlitzPHP\Wolke\Relations\Concerns\AsPivot;
use BlitzPHP\Wolke\Relations\Pivot;
use Closure;
use JsonException;
use JsonSerializable;
use LogicException;
use ReflectionClass;
use ReflectionMethod;
use Stringable;
use Throwable;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Model</a>
 */
class Model implements Arrayable, ArrayAccess, Jsonable, JsonSerializable, QueueableEntity, Stringable
{
    use ForwardsCalls;
    use GuardsAttributes;
    use HasAttributes;
    use HasCollection;
    use HasEvents;
    use HasGlobalScopes;
    use HasRelationships;
    use HasTimestamps;
    use HasUniqueIds;
    use HidesAttributes;
    use PreventsCircularRecursion;

    /**
     * Le nom de la colonne "created at".
     *
     * @var string|null
     */
    public const CREATED_AT = 'created_at';

    /**
     * Le nom de la colonne "updated at".
     *
     * @var string|null
     */
    public const UPDATED_AT = 'updated_at';

    /**
     * Le nom de la connexion pour le modèle.
     */
    protected ?string $connection = null;

    /**
     * La table associée au modèle.
     */
    protected string $table = '';

    /**
     * La clé primaire pour le modèle.
     */
    protected string $primaryKey = 'id';

    /**
     * Le "type" de l'ID de clé primaire.
     */
    protected string $keyType = 'int';

    /**
     * Indique si les IDs sont auto-incrémentés.
     */
    public bool $incrementing = true;

    /**
     * Les relations à charger avec empressement sur chaque requête.
     */
    protected array $with = [];

    /**
     * Les compteurs de relations qui doivent être chargés avec empressement sur chaque requête.
     */
    protected array $withCount = [];

    /**
     * Indique si le chargement paresseux sera empêché sur ce modèle.
     */
    public bool $preventsLazyLoading = false;

    /**
     * Le nombre de modèles à retourner pour la pagination.
     */
    protected int $perPage = 15;

    /**
     * Indique si le modèle existe.
     */
    public bool $exists = false;

    /**
     * Indique si le modèle a été inséré pendant le cycle de vie de la requête actuelle.
     */
    public bool $wasRecentlyCreated = false;

    /**
     * Indique que la représentation sous forme de chaîne de l'objet doit être échappée lorsque __toString est invoqué.
     */
    protected bool $escapeWhenCastingToString = false;

    /**
     * L'instance de résolveur de connexion.
     *
     * @var ConnectionResolverInterface
     */
    protected static $resolver;

    /**
     * L'instance de répartiteur d'événements.
     */
    protected static ?Dispatcher $dispatcher = null;

    /**
     * Le tableau des modèles démarrés.
     */
    protected static array $booted = [];

    /**
     * Les rappels qui doivent être exécutés après le démarrage du modèle.
     */
    protected static array $bootedCallbacks = [];

    /**
     * Le tableau des initialiseurs de traits qui seront appelés sur chaque nouvelle instance.
     */
    protected static array $traitInitializers = [];

    /**
     * Le tableau des portées globales sur le modèle.
     */
    protected static array $globalScopes = [];

    /**
     * La liste des classes de modèles qui ne doivent pas être affectées par le touch.
     */
    protected static array $ignoreOnTouch = [];

    /**
     * Indique si le chargement paresseux doit être restreint sur tous les modèles.
     */
    protected static bool $modelsShouldPreventLazyLoading = false;

    /**
     * Indique si les relations doivent être automatiquement chargées sur tous les modèles lorsqu'elles sont accédées.
     */
    protected static bool $modelsShouldAutomaticallyEagerLoadRelationships = false;

    /**
     * Le callback responsable de la gestion des violations de chargement paresseux.
     *
     * @var (callable(self, string))|null
     */
    protected static $lazyLoadingViolationCallback;

    /**
     * Indique si une exception doit être levée au lieu de supprimer silencieusement les attributs non remplissables.
     */
    protected static bool $modelsShouldPreventSilentlyDiscardingAttributes = false;

    /**
     * Le callback responsable de la gestion des violations d'attributs supprimés.
     *
     * @var (callable(self, array))|null
     */
    protected static $discardedAttributeViolationCallback;

    /**
     * Indique si une exception doit être levée lors de la tentative d'accès à un attribut manquant sur un modèle récupéré.
     */
    protected static bool $modelsShouldPreventAccessingMissingAttributes = false;

    /**
     * Le callback responsable de la gestion des violations d'attributs manquants.
     *
     * @var (callable(self, array))|null
     */
    protected static $missingAttributeViolationCallback;

    /**
     * La classe de constructeur de requête Wolke à utiliser pour le modèle.
     *
     * @var class-string<Builder<*>>
     */
    protected static string $builder = Builder::class;

    /**
     * La classe de collection Wolke à utiliser pour le modèle.
     *
     * @var class-string<Collection<*, *>>
     */
    protected static string $collectionClass = Collection::class;

    /**
     * Cache des modèles soft deletable.
     *
     * @var array<class-string<self>, bool>
     */
    protected static array $isSoftDeletable = [];

    /**
     * Crée une nouvelle instance de modèle Wolke.
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->bootIfNotBooted();

        $this->initializeTraits();

        $this->syncOriginal();

        $this->fill($attributes);
    }

    /**
     * Vérifie si le modèle doit être démarré et si oui, le fait.
     */
    protected function bootIfNotBooted(): void
    {
        if (! isset(static::$booted[static::class])) {
            static::$booted[static::class] = true;

            $this->fireModelEvent('booting', false);

            static::booting();
            static::boot();
            static::booted();

            static::$bootedCallbacks[static::class] ??= [];

            foreach (static::$bootedCallbacks[static::class] as $callback) {
                $callback();
            }

            $this->fireModelEvent('booted', false);
        }
    }

    /**
     * Effectue toutes les actions nécessaires avant le démarrage du modèle.
     */
    protected static function booting(): void
    {
    }

    /**
     * Démarre le modèle et ses traits.
     */
    protected static function boot(): void
    {
        static::bootTraits();
    }

    /**
     * Démarre tous les traits démarrables sur le modèle.
     */
    protected static function bootTraits(): void
    {
        $class = static::class;

        $booted = [];

        static::$traitInitializers[$class] = [];

        $uses = Helpers::classUsesRecursive($class);

        $conventionalBootMethods = array_map(static fn ($trait) => 'boot' . Helpers::classBasename($trait), $uses);
        $conventionalInitMethods = array_map(static fn ($trait) => 'initialize' . Helpers::classBasename($trait), $uses);

        foreach ((new ReflectionClass($class))->getMethods() as $method) {
            if (! in_array($method->getName(), $booted, true)
                && $method->isStatic() && in_array($method->getName(), $conventionalBootMethods, true)) {
                $method->invoke(null);

                $booted[] = $method->getName();
            }

            if (in_array($method->getName(), $conventionalInitMethods, true)) {
                static::$traitInitializers[$class][] = $method->getName();
            }
        }

        static::$traitInitializers[$class] = array_unique(static::$traitInitializers[$class]);
    }

    /**
     * Initialise tous les traits initialisables sur le modèle.
     */
    protected function initializeTraits(): void
    {
        foreach (static::$traitInitializers[static::class] as $method) {
            $this->{$method}();
        }
    }

    /**
     * Effectue toutes les actions nécessaires après le démarrage du modèle.
     */
    protected static function booted(): void
    {
    }

    /**
     * Enregistre une fermeture à exécuter après le démarrage du modèle.
     */
    protected static function whenBooted(Closure $callback)
    {
        static::$bootedCallbacks[static::class] ??= [];

        static::$bootedCallbacks[static::class][] = $callback;
    }

    /**
     * Efface la liste des modèles démarrés afin qu'ils soient redémarrés.
     */
    public static function clearBootedModels(): void
    {
        static::$booted          = [];
        static::$bootedCallbacks = [];

        static::$globalScopes = [];
    }

    /**
     * Désactive le touch des modèles de relation pour la classe actuelle pendant la portée de rappel donnée.
     */
    public static function withoutTouching(callable $callback): void
    {
        static::withoutTouchingOn([static::class], $callback);
    }

    /**
     * Désactive le touch des modèles de relation pour les classes de modèle données pendant la portée de rappel donnée.
     */
    public static function withoutTouchingOn(array $models, callable $callback): void
    {
        static::$ignoreOnTouch = array_values(array_merge(static::$ignoreOnTouch, $models));

        try {
            $callback();
        } finally {
            static::$ignoreOnTouch = array_values(array_diff(static::$ignoreOnTouch, $models));
        }
    }

    /**
     * Détermine si le modèle donné ignore les touches.
     */
    public static function isIgnoringTouch(?string $class = null): bool
    {
        $class = $class ?: static::class;

        if (! get_class_vars($class)['timestamps'] || ! $class::UPDATED_AT) {
            return true;
        }

        foreach (static::$ignoreOnTouch as $ignoredClass) {
            if ($class === $ignoredClass || is_subclass_of($class, $ignoredClass)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Indique que les modèles doivent empêcher le chargement paresseux, la suppression silencieuse d'attributs et l'accès aux attributs manquants.
     */
    public static function shouldBeStrict(bool $shouldBeStrict = true): void
    {
        static::preventLazyLoading($shouldBeStrict);
        static::preventSilentlyDiscardingAttributes($shouldBeStrict);
        static::preventAccessingMissingAttributes($shouldBeStrict);
    }

    /**
     * Empêche les relations de modèle d'être chargées paresseusement.
     */
    public static function preventLazyLoading(bool $value = true): void
    {
        static::$modelsShouldPreventLazyLoading = $value;
    }

    /**
     * Détermine si les relations de modèle doivent être automatiquement chargées avec empressement lorsqu'elles sont accédées.
     */
    public static function automaticallyEagerLoadRelationships(bool $value = true): void
    {
        static::$modelsShouldAutomaticallyEagerLoadRelationships = $value;
    }

    /**
     * Enregistre un rappel responsable de la gestion des violations de chargement paresseux.
     *
     * @param (callable(self, string))|null $callback
     */
    public static function handleLazyLoadingViolationUsing(?callable $callback): void
    {
        static::$lazyLoadingViolationCallback = $callback;
    }

    /**
     * Empêche les attributs non remplissables d'être supprimés silencieusement.
     */
    public static function preventSilentlyDiscardingAttributes(bool $value = true): void
    {
        static::$modelsShouldPreventSilentlyDiscardingAttributes = $value;
    }

    /**
     * Enregistre un rappel responsable de la gestion des violations d'attributs supprimés.
     *
     * @param (callable(self, array))|null $callback
     */
    public static function handleDiscardedAttributeViolationUsing(?callable $callback): void
    {
        static::$discardedAttributeViolationCallback = $callback;
    }

    /**
     * Empêche l'accès aux attributs manquants sur les modèles récupérés.
     */
    public static function preventAccessingMissingAttributes(bool $value = true): void
    {
        static::$modelsShouldPreventAccessingMissingAttributes = $value;
    }

    /**
     * Enregistre un rappel responsable de la gestion des violations d'attributs manquants.
     *
     * @param (callable(self, string))|null $callback
     */
    public static function handleMissingAttributeViolationUsing(?callable $callback): void
    {
        static::$missingAttributeViolationCallback = $callback;
    }

    /**
     * Remplit le modèle avec un tableau d'attributs.
     *
     * @throws MassAssignmentException
     */
    public function fill(array $attributes): static
    {
        $totallyGuarded = $this->totallyGuarded();
        $fillable       = $this->fillableFromArray($attributes);

        foreach ($fillable as $key => $value) {
            // Les développeurs peuvent choisir de placer certains attributs dans le tableau "fillable",
            // ce qui signifie que seuls ces attributs peuvent être définis par assignation en masse sur
            // le modèle, et tous les autres seront simplement ignorés pour des raisons de sécurité.
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } elseif ($totallyGuarded || static::preventsSilentlyDiscardingAttributes()) {
                if (isset(static::$discardedAttributeViolationCallback)) {
                    call_user_func(static::$discardedAttributeViolationCallback, $this, [$key]);
                } else {
                    throw new MassAssignmentException(sprintf(
                        'Ajoutez [%s] à la propriété $fillable pour permettre l\'affectation en masse sur [%s].',
                        $key,
                        static::class,
                    ));
                }
            }
        }

        if (count($attributes) !== count($fillable)
            && static::preventsSilentlyDiscardingAttributes()) {
            $keys = array_diff(array_keys($attributes), array_keys($fillable));

            if (isset(static::$discardedAttributeViolationCallback)) {
                call_user_func(static::$discardedAttributeViolationCallback, $this, $keys);
            } else {
                throw new MassAssignmentException(sprintf(
                    'Ajoutez [%s] à la propriété $fillable pour permettre l\'affectation en masse sur [%s].',
                    implode(', ', $keys),
                    static::class,
                ));
            }
        }

        return $this;
    }

    /**
     * Remplit le modèle avec un tableau d'attributs. Force l'assignation en masse.
     */
    public function forceFill(array $attributes): static
    {
        return static::unguarded(fn () => $this->fill($attributes));
    }

    /**
     * Qualifie le nom de colonne donné par la table du modèle.
     */
    public function qualifyColumn(string $column): string
    {
        if (str_contains($column, '.')) {
            return $column;
        }

        return $this->getTable() . '.' . $column;
    }

    /**
     * Qualifie les colonnes données avec la table du modèle.
     */
    public function qualifyColumns(array $columns): array
    {
        return (new IterableCollection($columns))
            ->map(fn ($column) => $this->qualifyColumn($column))
            ->all();
    }

    /**
     * Crée une nouvelle instance du modèle donné.
     *
     * @param array<string, mixed> $attributes
     */
    public function newInstance(array $attributes = [], bool $exists = false): static
    {
        // Cette méthode fournit simplement un moyen pratique pour nous de générer de nouvelles instances
        // de modèle de ce modèle actuel. Elle est particulièrement utile lors de
        // l'hydratation de nouveaux objets via les instances de constructeur de requête Eloquent.
        $model = new static();

        $model->exists = $exists;

        $model->setConnection(
            $this->getConnectionName(),
        );

        $model->setTable($this->getTable());

        $model->mergeCasts($this->casts);

        $model->fill($attributes);

        return $model;
    }

    /**
     * Crée une nouvelle instance de modèle existante.
     */
    public function newFromBuilder(array $attributes = [], ?string $connection = null): static
    {
        $model = $this->newInstance([], true);

        $model->setRawAttributes($attributes, true);

        $model->setConnection($connection ?: $this->getConnectionName());

        $model->fireModelEvent('retrieved', false);

        return $model;
    }

    /**
     * Commence une requête sur le modèle sur une connexion donnée.
     *
     * @return Builder<static>
     */
    public static function on(?string $connection = null): Builder
    {
        // Nous allons d'abord créer une nouvelle instance de ce modèle, puis nous pouvons définir la
        // connexion sur le modèle afin qu'elle soit utilisée pour les requêtes que nous exécutons, ainsi
        // qu'être définie sur chaque relation que nous récupérons sans nom de connexion personnalisé.
        return (new static())->setConnection($connection)->newQuery();
    }

    /**
     * Commence une requête sur le modèle sur la connexion d'écriture.
     *
     * @return Builder<static>
     */
    public static function onWriteConnection(): BaseBuilder
    {
        return static::query()->getQuery();
    }

    /**
     * Obtient tous les modèles de la base de données.
     *
     * @return Collection<int, static>
     */
    public static function all(array|string $columns = ['*']): Collection
    {
        return static::query()->get(
            is_array($columns) ? $columns : func_get_args(),
        );
    }

    /**
     * Commence une requête sur un modèle avec chargement empressé.
     *
     * @return Builder<static>
     */
    public static function with(array|string $relations): Builder
    {
        return static::query()->with(
            is_string($relations) ? func_get_args() : $relations,
        );
    }

    /**
     * Charge avec empressement des relations sur le modèle.
     */
    public function load(array|string $relations): static
    {
        $query = $this->newQueryWithoutRelationships()->with(
            is_string($relations) ? func_get_args() : $relations,
        );

        $query->eagerLoadRelations([$this]);

        return $this;
    }

    /**
     * Charge avec empressement des relations sur la relation polymorphe d'un modèle.
     */
    public function loadMorph(string $relation, array $relations): static
    {
        if (! $this->{$relation}) {
            return $this;
        }

        $className = get_class($this->{$relation});

        $this->{$relation}->load($relations[$className] ?? []);

        return $this;
    }

    /**
     * Charge avec empressement des relations sur le modèle si elles ne sont pas déjà chargées avec empressement.
     */
    public function loadMissing(array|string $relations): static
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        $this->newCollection([$this])->loadMissing($relations);

        return $this;
    }

    /**
     * Charge avec empressement les agrégations de colonnes de relation sur le modèle.
     */
    public function loadAggregate(array|string $relations, string $column, ?string $function = null): self
    {
        $this->newCollection([$this])->loadAggregate($relations, $column, $function);

        return $this;
    }

    /**
     * Charge avec empressement les compteurs de relations sur le modèle.
     */
    public function loadCount(array|string $relations): static
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        return $this->loadAggregate($relations, '*', 'count');
    }

    /**
     * Charge avec empressement les valeurs maximales de colonne de relation sur le modèle.
     */
    public function loadMax(array|string $relations, string $column): static
    {
        return $this->loadAggregate($relations, $column, 'max');
    }

    /**
     * Charge avec empressement les valeurs minimales de colonne de relation sur le modèle.
     */
    public function loadMin(array|string $relations, string $column): static
    {
        return $this->loadAggregate($relations, $column, 'min');
    }

    /**
     * Charge avec empressement les sommes de colonne de relation sur le modèle.
     */
    public function loadSum(array|string $relations, string $column): static
    {
        return $this->loadAggregate($relations, $column, 'sum');
    }

    /**
     * Charge avec empressement les valeurs moyennes de colonne de relation sur le modèle.
     */
    public function loadAvg(array|string $relations, string $column): static
    {
        return $this->loadAggregate($relations, $column, 'avg');
    }

    /**
     * Charge avec empressement les valeurs d'existence de modèle lié sur le modèle.
     */
    public function loadExists(array|string $relations): static
    {
        return $this->loadAggregate($relations, '*', 'exists');
    }

    /**
     * Charge avec empressement l'agrégation de colonne de relation sur la relation polymorphe d'un modèle.
     */
    public function loadMorphAggregate(string $relation, array $relations, string $column, ?string $function = null): static
    {
        if (! $this->{$relation}) {
            return $this;
        }

        $className = get_class($this->{$relation});

        $this->{$relation}->loadAggregate($relations[$className] ?? [], $column, $function);

        return $this;
    }

    /**
     * Charge avec empressement les compteurs de relations sur la relation polymorphe d'un modèle.
     */
    public function loadMorphCount(string $relation, array $relations): static
    {
        return $this->loadMorphAggregate($relation, $relations, '*', 'count');
    }

    /**
     * Charge avec empressement les valeurs maximales de colonne de relation sur la relation polymorphe d'un modèle.
     */
    public function loadMorphMax(string $relation, array $relations, string $column): static
    {
        return $this->loadMorphAggregate($relation, $relations, $column, 'max');
    }

    /**
     * Charge avec empressement les valeurs minimales de colonne de relation sur la relation polymorphe d'un modèle.
     */
    public function loadMorphMin(string $relation, array $relations, string $column): static
    {
        return $this->loadMorphAggregate($relation, $relations, $column, 'min');
    }

    /**
     * Charge avec empressement les sommes de colonne de relation sur la relation polymorphe d'un modèle.
     */
    public function loadMorphSum(string $relation, array $relations, string $column): static
    {
        return $this->loadMorphAggregate($relation, $relations, $column, 'sum');
    }

    /**
     * Charge avec empressement les valeurs moyennes de colonne de relation sur la relation polymorphe d'un modèle.
     */
    public function loadMorphAvg(string $relation, array $relations, string $column): static
    {
        return $this->loadMorphAggregate($relation, $relations, $column, 'avg');
    }

    /**
     * Incrémente la valeur d'une colonne d'un montant donné.
     *
     * @return false|float|int
     */
    protected function increment(string $column, float|int $amount = 1, array $extra = [])
    {
        return $this->incrementOrDecrement($column, $amount, $extra, 'increment');
    }

    /**
     * Décrémente la valeur d'une colonne d'un montant donné.
     *
     * @return false|float|int
     */
    protected function decrement(string $column, float|int $amount = 1, array $extra = [])
    {
        return $this->incrementOrDecrement($column, $amount, $extra, 'decrement');
    }

    /**
     * Exécute la méthode d'incrémentation ou de décrémentation sur le modèle.
     *
     * @return false|float|int
     */
    protected function incrementOrDecrement(string $column, float|int $amount, array $extra, string $method)
    {
        if (! $this->exists) {
            return $this->newQueryWithoutRelationships()->{$method}($column, $amount, $extra);
        }

        $this->{$column} = $this->isClassDeviable($column)
            ? $this->deviateClassCastableAttribute($method, $column, $amount)
            : $this->{$column} + ($method === 'increment' ? $amount : $amount * -1);

        $this->forceFill($extra);

        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        if ($this->isClassDeviable($column)) {
            $amount = (clone $this)->setAttribute($column, $amount)->getAttributeFromArray($column);
        }

        return Helpers::tap($this->setKeysForSaveQuery($this->newQueryWithoutScopes())->{$method}($column, $amount, $extra), function () use ($column) {
            $this->syncChanges();

            $this->fireModelEvent('updated', false);

            $this->syncOriginalAttribute($column);
        });
    }

    /**
     * Met à jour le modèle dans la base de données.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->fill($attributes)->save($options);
    }

    /**
     * Met à jour le modèle dans la base de données dans une transaction.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     *
     * @throws Throwable
     */
    public function updateOrFail(array $attributes = [], array $options = []): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->fill($attributes)->saveOrFail($options);
    }

    /**
     * Met à jour le modèle dans la base de données sans déclencher d'événements.
     *
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     */
    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->fill($attributes)->saveQuietly($options);
    }

    /**
     * Incrémente la valeur d'une colonne d'un montant donné sans déclencher d'événements.
     *
     * @return false|float|int
     */
    protected function incrementQuietly(string $column, float|int $amount = 1, array $extra = [])
    {
        return static::withoutEvents(fn () => $this->incrementOrDecrement($column, $amount, $extra, 'increment'));
    }

    /**
     * Décrémente la valeur d'une colonne d'un montant donné sans déclencher d'événements.
     *
     * @return false|float|int
     */
    protected function decrementQuietly(string $column, float|int $amount = 1, array $extra = [])
    {
        return static::withoutEvents(fn () => $this->incrementOrDecrement($column, $amount, $extra, 'decrement'));
    }

    /**
     * Sauvegarde le modèle et toutes ses relations.
     */
    public function push(): bool
    {
        return $this->withoutRecursion(function () {
            if (! $this->save()) {
                return false;
            }

            // Pour synchroniser toutes les relations avec la base de données, nous allons simplement parcourir
            // les relations et sauvegarder chaque modèle via cette méthode "push", qui nous permet
            // de récurser dans toutes ces relations imbriquées pour l'instance de modèle.
            foreach ($this->relations as $models) {
                $models = $models instanceof Collection
                    ? $models->all()
                    : [$models];

                foreach (array_filter($models) as $model) {
                    if (! $model->push()) {
                        return false;
                    }
                }
            }

            return true;
        }, true);
    }

    /**
     * Sauvegarde le modèle et toutes ses relations sans déclencher d'événements.
     */
    public function pushQuietly(): bool
    {
        return static::withoutEvents(fn () => $this->push());
    }

    /**
     * Sauvegarde le modèle dans la base de données sans déclencher d'événements.
     */
    public function saveQuietly(array $options = []): bool
    {
        return static::withoutEvents(fn () => $this->save($options));
    }

    /**
     * Sauvegarde le modèle dans la base de données.
     */
    public function save(array $options = []): bool
    {
        $this->mergeAttributesFromClassCasts();

        $query = $this->newModelQuery();

        // Si l'événement "saving" retourne false, nous abandonnerons la sauvegarde et retournerons
        // false, indiquant que la sauvegarde a échoué. Cela donne une chance à tous les
        // écouteurs d'annuler les opérations de sauvegarde si les validations échouent ou autre.
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        // Si le modèle existe déjà dans la base de données, nous pouvons simplement mettre à jour notre enregistrement
        // qui est déjà dans cette base de données en utilisant les IDs actuels dans cette clause "where"
        // pour ne mettre à jour que ce modèle. Sinon, nous allons simplement les insérer.
        if ($this->exists) {
            $saved = $this->isDirty() ?
                        $this->performUpdate($query) : true;
        }

        // Si le modèle est tout nouveau, nous l'insérerons dans notre base de données et définirons
        // l'attribut ID sur le modèle à la valeur de l'ID de la ligne nouvellement insérée
        // qui est généralement une valeur auto-incrémentée gérée par la base de données.
        else {
            $saved = $this->performInsert($query);

            if (! $this->getConnectionName() && $connection = $query->getConnection()) {
                $this->setConnection($connection->getName());
            }
        }

        // Si le modèle est sauvegardé avec succès, nous devons faire quelques choses supplémentaires une fois
        // que c'est fait. Nous appellerons la méthode "saved" ici pour exécuter toutes les actions
        // que nous devons faire après qu'un modèle soit sauvegardé avec succès.
        if ($saved) {
            $this->finishSave($options);
        }

        return $saved;
    }

    /**
     * Sauvegarde le modèle dans la base de données en utilisant une transaction.
     *
     * @throws DatabaseException
     */
    public function saveOrFail(array $options = []): bool
    {
        return $this->getConnection()->transaction(fn () => $this->save($options));
    }

    /**
     * Effectue toutes les actions nécessaires après la sauvegarde du modèle.
     */
    protected function finishSave(array $options): void
    {
        $this->fireModelEvent('saved', false);

        if ($this->isDirty() && ($options['touch'] ?? true)) {
            $this->touchOwners();
        }

        $this->syncOriginal();
    }

    /**
     * Effectue une opération de mise à jour du modèle.
     *
     * @param Builder<static>
     */
    protected function performUpdate(Builder $query): bool
    {
        // Si l'événement updating retourne false, nous annulerons l'opération de mise à jour afin que les
        // développeurs puissent intégrer des systèmes de validation dans leurs modèles et annuler cette
        // opération si le modèle ne passe pas la validation. Sinon, nous mettons à jour.
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        // Nous devons d'abord créer une nouvelle instance de requête et toucher les horodatages de création et
        // de mise à jour sur le modèle, qui sont maintenus par nous pour la commodité du développeur.
        // Ensuite, nous continuerons simplement à sauvegarder les instances du modèle.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // Une fois que nous avons exécuté l'opération de mise à jour, nous déclencherons l'événement "updated" pour
        // cette instance de modèle. Cela permettra aux développeurs de s'intégrer après que les
        // modèles soient mis à jour, leur donnant une chance de faire tout traitement spécial.
        $dirty = $this->getDirtyForUpdate();

        if (method_exists($this, 'beforeUpdate')) {
            $dirty = call_user_func([$this, 'beforeUpdate'], $dirty);
        }

        if (count($dirty) > 0) {
            $this->setKeysForSaveQuery($query)->update($dirty);

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * Définit les clés pour une requête de sélection.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery(Builder $query): Builder
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSelectQuery());

        return $query;
    }

    /**
     * Obtient la valeur de la clé primaire pour une requête de sélection.
     */
    protected function getKeyForSelectQuery(): mixed
    {
        return $this->original[$this->getKeyName()] ?? $this->getKey();
    }

    /**
     * Définit les clés pour une requête de sauvegarde de mise à jour.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery(Builder $query): Builder
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());

        return $query;
    }

    /**
     * Obtient la valeur de la clé primaire pour une requête de sauvegarde.
     */
    protected function getKeyForSaveQuery(): mixed
    {
        return $this->original[$this->getKeyName()] ?? $this->getKey();
    }

    /**
     * Effectue une opération d'insertion de modèle.
     *
     * @param Builder<static> $query
     */
    protected function performInsert(Builder $query): bool
    {
        if ($this->usesUniqueIds()) {
            $this->setUniqueIds();
        }

        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        // Nous devons d'abord créer une nouvelle instance de requête et toucher les horodatages de création et
        // de mise à jour sur ce modèle, qui sont maintenus par nous pour la commodité du développeur.
        // Après cela, nous continuerons simplement à sauvegarder ces instances de modèle.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // Si le modèle a une clé incrémentale, nous pouvons utiliser la méthode "insertGetId" sur
        // le constructeur de requête, qui nous redonnera l'ID final inséré pour cette
        // table de la base de données. Cependant, toutes les tables ne doivent pas être incrémentales.
        $attributes = $this->getAttributesForInsert();

        if (method_exists($this, 'beforeCreate')) {
            $attributes = call_user_func([$this, 'beforeCreate'], $attributes);
        }

        if ($this->getIncrementing()) {
            $this->insertAndSetId($query, $attributes);
        }

        // Si la table n'est pas incrémentale, nous allons simplement insérer ces attributs tels qu'ils
        // sont. Ces tableaux d'attributs doivent contenir une colonne "id" précédemment placée
        // là par le développeur comme clé déterminée manuellement pour ces modèles.
        else {
            if (empty($attributes)) {
                return true;
            }

            $query->insert($attributes);
        }

        // Nous allons définir la propriété exists sur true, afin qu'elle soit définie lorsque
        // l'événement created est déclenché, juste au cas où le développeur essaierait de le mettre à jour
        // pendant l'événement. Cela lui permettra de le faire et d'exécuter une mise à jour ici.
        $this->exists = true;

        $this->wasRecentlyCreated = true;

        $this->fireModelEvent('created', false);

        return true;
    }

    /**
     * Insère les attributs donnés et définit l'ID sur le modèle.
     *
     * @param Builder<static>      $query
     * @param array<string, mixed> $attributes
     */
    protected function insertAndSetId(Builder $query, array $attributes): void
    {
        $id = $query->insertGetId($attributes, $keyName = $this->getKeyName());

        $this->setAttribute($keyName, $id);
    }

    /**
     * Détruit les modèles pour les IDs donnés.
     *
     * @param array|int|IterableCollection|string $ids
     */
    public static function destroy($ids): int
    {
        if ($ids instanceof Collection) {
            $ids = $ids->modelKeys();
        }

        if ($ids instanceof IterableCollection) {
            $ids = $ids->all();
        }

        $ids = is_array($ids) ? $ids : func_get_args();

        if (count($ids) === 0) {
            return 0;
        }

        // Nous allons en fait récupérer les modèles de la table de base de données et appeler delete sur
        // chacun d'eux individuellement afin que leurs événements soient déclenchés correctement avec
        // un ensemble correct d'attributs au cas où les développeurs voudraient vérifier cela.
        $key = ($instance = new static())->getKeyName();

        $count = 0;

        foreach ($instance->whereIn($key, $ids)->get() as $model) {
            if ($model->delete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Supprime le modèle de la base de données.
     *
     * @return bool|null
     *
     * @throws LogicException
     */
    public function delete()
    {
        $this->mergeAttributesFromCachedCasts();

        if (null === $this->getKeyName()) {
            throw new LogicException('Aucune clé primaire définie sur le modèle.');
        }

        // Si le modèle n'existe pas, il n'y a rien à supprimer, donc nous allons simplement retourner
        // immédiatement et ne rien faire d'autre. Sinon, nous continuerons avec un
        // processus de suppression sur le modèle, en déclenchant les événements appropriés, etc.
        if (! $this->exists) {
            return null;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        // Ici, nous allons toucher les modèles propriétaires, en vérifiant que ces horodatages soient mis à jour
        // pour les modèles. Cela permettra de briser tout cache sur les parents
        // par l'horodatage. Ensuite, nous procéderons à la suppression de l'instance du modèle.
        $this->touchOwners();

        $this->performDeleteOnModel();

        // Une fois que le modèle a été supprimé, nous déclencherons l'événement deleted afin que
        // les développeurs puissent s'intégrer aux opérations post-suppression. Nous retournerons ensuite
        // un booléen true car la suppression est vraisemblablement réussie sur la base de données.
        $this->fireModelEvent('deleted', false);

        return true;
    }

    /**
     * Supprime le modèle de la base de données sans déclencher d'événements.
     */
    public function deleteQuietly(): ?bool
    {
        return static::withoutEvents(fn () => $this->delete());
    }

    /**
     * Supprime le modèle de la base de données dans une transaction.
     *
     * @throws Throwable
     */
    public function deleteOrFail(): ?bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->getConnection()->transaction(fn () => $this->delete());
    }

    /**
     * Effectue une suppression dure sur un modèle supprimé de façon douce.
     *
     * Cette méthode protège les développeurs de l'exécution de forceDelete lorsque le trait est manquant.
     */
    public function forceDelete(): ?bool
    {
        return $this->delete();
    }

    /**
     * Effectue une destruction dure sur un modèle supprimé de façon douce.
     *
     * Cette méthode protège les développeurs de l'exécution de forceDestroy lorsque le trait est manquant.
     *
     * @param array|int|IterableCollection|string $ids
     */
    public static function forceDestroy($ids): ?bool
    {
        return static::destroy($ids);
    }

    /**
     * Effectue la requête de suppression réelle sur cette instance de modèle.
     *
     * @return void
     */
    protected function performDeleteOnModel()
    {
        $this->setKeysForSaveQuery($this->newModelQuery())->delete();

        $this->exists = false;
    }

    /**
     * Commence une requête sur le modèle.
     *
     * @return Builder<static>
     */
    public static function query(): Builder
    {
        return (new static())->newQuery();
    }

    /**
     * Obtient un nouveau constructeur de requête pour la table du modèle.
     *
     * @return Builder<static>
     */
    public function newQuery(): Builder
    {
        return $this->registerGlobalScopes($this->newQueryWithoutScopes());
    }

    /**
     * Obtient un nouveau constructeur de requête qui n'a pas de portées globales ni de chargements empressés.
     *
     * @return Builder<static>
     */
    public function newModelQuery(): Builder
    {
        return $this->newWolkeBuilder($this->newBaseQueryBuilder())->setModel($this);
    }

    /**
     * Obtient un nouveau constructeur de requête sans relations chargées.
     *
     * @return Builder<static>
     */
    public function newQueryWithoutRelationships(): Builder
    {
        return $this->registerGlobalScopes($this->newModelQuery());
    }

    /**
     * Enregistre les portées globales pour cette instance de constructeur.
     *
     * @param Builder<static> $builder
     *
     * @return Builder<static>
     */
    public function registerGlobalScopes(Builder $builder): Builder
    {
        foreach ($this->getGlobalScopes() as $identifier => $scope) {
            $builder->withGlobalScope($identifier, $scope);
        }

        return $builder;
    }

    /**
     * Obtient un nouveau constructeur de requête qui n'a pas de portées globales.
     *
     * @return Builder<static>
     */
    public function newQueryWithoutScopes(): Builder
    {
        return $this->newModelQuery()
            ->with($this->with)
            ->withCount($this->withCount);
    }

    /**
     * Obtient une nouvelle instance de requête sans une portée donnée.
     *
     * @return Builder<static>
     */
    public function newQueryWithoutScope(Scope|string $scope): Builder
    {
        return $this->newQuery()->withoutGlobalScope($scope);
    }

    /**
     * Obtient une nouvelle requête pour restaurer un ou plusieurs modèles par leurs IDs de file d'attente.
     *
     * @return Builder<static>
     */
    public function newQueryForRestoration(array|int $ids): Builder
    {
        return $this->newQueryWithoutScopes()->whereKey($ids);
    }

    /**
     * Crée un nouveau constructeur de requête Wolke pour le modèle.
     *
     * @return Builder<*>
     */
    public function newWolkeBuilder(BaseBuilder $query): Builder
    {
        $builderClass = $this->resolveCustomBuilderClass();

        if ($builderClass && is_subclass_of($builderClass, Builder::class)) {
            return new $builderClass($query);
        }

        return new static::$builder($query);
    }

    /**
     * Résout la classe de constructeur Eloquent personnalisée à partir des attributs du modèle.
     *
     * @return class-string<Builder>|false
     */
    protected function resolveCustomBuilderClass()
    {
        $attributes = (new ReflectionClass($this))->getAttributes(UseWolkeBuilder::class);

        return ! empty($attributes)
            ? $attributes[0]->newInstance()->builderClass
            : false;
    }

    /**
     * Obtient une nouvelle instance de constructeur de requête pour la connexion.
     */
    protected function newBaseQueryBuilder(): BaseBuilder
    {
        return $this->getConnection()->newQuery();
    }

    /**
     * Crée une nouvelle instance de modèle pivot.
     *
     * @param array<string, mixed> $attributes
     */
    public function newPivot(self $parent, array $attributes, string $table, bool $exists, ?string $using = null): Pivot
    {
        return $using ? $using::fromRawAttributes($parent, $attributes, $table, $exists)
                      : Pivot::fromAttributes($parent, $attributes, $table, $exists);
    }

    /**
     * Détermine si le modèle a une portée donnée.
     */
    public function hasNamedScope(string $scope): bool
    {
        return method_exists($this, 'scope' . ucfirst($scope))
            || static::isScopeMethodWithAttribute($scope);
    }

    /**
     * Applique la portée nommée donnée si possible.
     */
    public function callNamedScope(string $scope, array $parameters = []): mixed
    {
        if ($this->isScopeMethodWithAttribute($scope)) {
            return $this->{$scope}(...$parameters);
        }

        return $this->{'scope' . ucfirst($scope)}(...$parameters);
    }

    /**
     * Détermine si la méthode donnée a un attribut de portée.
     */
    protected static function isScopeMethodWithAttribute(string $method): bool
    {
        return method_exists(static::class, $method)
            && (new ReflectionMethod(static::class, $method))
                ->getAttributes(LocalScope::class) !== [];
    }

    /**
     * Convertit l'instance de modèle en un tableau.
     */
    public function toArray(): array
    {
        return $this->withoutRecursion(
            fn () => array_merge($this->attributesToArray(), $this->relationsToArray()),
            fn () => $this->attributesToArray(),
        );
    }

    /**
     * Convertit l'instance de modèle en JSON.
     *
     * @throws JsonEncodingException
     */
    public function toJson(int $options = 0): string
    {
        try {
            $json = json_encode($this->jsonSerialize(), $options | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw JsonEncodingException::forModel($this, $e->getMessage());
        }

        return $json;
    }

    /**
     * Convertit l'instance de modèle en JSON formaté de façon jolie.
     *
     * @throws JsonEncodingException
     */
    public function toPrettyJson(int $options = 0): string
    {
        return $this->toJson(JSON_PRETTY_PRINT | $options);
    }

    /**
     * Convertit l'objet en quelque chose de sérialisable en JSON.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Recharge une nouvelle instance de modèle fraîche depuis la base de données.
     */
    public function fresh(array|string $with = []): ?static
    {
        if (! $this->exists) {
            return null;
        }

        return $this->setKeysForSelectQuery($this->newQueryWithoutScopes())
            ->with(is_string($with) ? func_get_args() : $with)
            ->first();
    }

    /**
     * Recharge l'instance de modèle actuelle avec des attributs frais de la base de données.
     */
    public function refresh(): static
    {
        if (! $this->exists) {
            return $this;
        }

        $this->setRawAttributes(
            $this->setKeysForSelectQuery($this->newQueryWithoutScopes())
                ->firstOrFail()
                ->attributes,
        );

        $this->load((new IterableCollection($this->relations))->reject(
            static fn ($relation) => $relation instanceof Pivot
                || (is_object($relation) && in_array(AsPivot::class, Helpers::classUsesRecursive($relation), true)),
        )->keys()->all());

        $this->syncOriginal();

        return $this;
    }

    /**
     * Clone le modèle dans une nouvelle instance non existante.
     */
    public function replicate(?array $except = null): static
    {
        $defaults = array_values(array_filter([
            $this->getKeyName(),
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
            ...$this->uniqueIds(),
            'blitz_through_key',
        ]));

        $attributes = Arr::except(
            $this->getAttributes(),
            $except ? array_unique(array_merge($except, $defaults)) : $defaults,
        );

        return Helpers::tap(new static(), function ($instance) use ($attributes) {
            $instance->setRawAttributes($attributes);

            $instance->setRelations($this->relations);

            $instance->fireModelEvent('replicating', false);
        });
    }

    /**
     * Clone le modèle dans une nouvelle instance non existante sans déclencher d'événements.
     */
    public function replicateQuietly(?array $except = null): static
    {
        return static::withoutEvents(fn () => $this->replicate($except));
    }

    /**
     * Détermine si deux modèles ont le même ID et appartiennent à la même table.
     */
    public function is(?self $model): bool
    {
        return null !== $model
               && $this->getKey() === $model->getKey()
               && $this->getTable() === $model->getTable()
               && $this->getConnectionName() === $model->getConnectionName();
    }

    /**
     * Détermine si deux modèles ne sont pas les mêmes.
     */
    public function isNot(?self $model): bool
    {
        return ! $this->is($model);
    }

    /**
     * Obtient la connexion à la base de données pour le modèle.
     */
    public function getConnection(): BaseConnection
    {
        return static::resolveConnection($this->getConnectionName());
    }

    /**
     * Obtient le nom de la connexion actuelle pour le modèle.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection;
    }

    /**
     * Définit la connexion associée au modèle.
     */
    public function setConnection(?string $name): static
    {
        $this->connection = $name;

        return $this;
    }

    /**
     * Résout une instance de connexion.
     */
    public static function resolveConnection(?string $connection = null): BaseConnection
    {
        return static::$resolver->connection($connection);
    }

    /**
     * Obtient l'instance de résolveur de connexion.
     */
    public static function getConnectionResolver(): ConnectionResolverInterface
    {
        return static::$resolver;
    }

    /**
     * Définit l'instance de résolveur de connexion.
     */
    public static function setConnectionResolver(ConnectionResolverInterface $resolver): void
    {
        static::$resolver = $resolver;
    }

    /**
     * Supprime le résolveur de connexion pour les modèles.
     */
    public static function unsetConnectionResolver(): void
    {
        static::$resolver = null;
    }

    /**
     * Obtient la table associée au modèle.
     */
    public function getTable(): string
    {
        return $this->table ?: Text::snake(Text::pluralStudly(Helpers::classBasename($this)));
    }

    /**
     * Définit la table associée au modèle.
     */
    public function setTable(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Obtient la clé primaire pour le modèle.
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Définit la clé primaire du modèle.
     */
    public function setKeyName(string $key): self
    {
        $this->primaryKey = $key;

        return $this;
    }

    /**
     * Obtient le nom de la clé qualifiée par la table.
     */
    public function getQualifiedKeyName(): string
    {
        return $this->qualifyColumn($this->getKeyName());
    }

    /**
     * Obtient le type de clé auto-incrémentée.
     */
    public function getKeyType(): string
    {
        return $this->keyType;
    }

    /**
     * Définit le type de données pour la clé primaire.
     */
    public function setKeyType(string $type): self
    {
        $this->keyType = $type;

        return $this;
    }

    /**
     * Obtient la valeur indiquant si les ID sont incrémentés.
     */
    public function getIncrementing(): bool
    {
        return $this->incrementing;
    }

    /**
     * Définit si les ID sont incrémentés.
     */
    public function setIncrementing(bool $value): self
    {
        $this->incrementing = $value;

        return $this;
    }

    /**
     * Obtient la valeur de la clé primaire du modèle.
     */
    public function getKey(): mixed
    {
        return $this->getAttribute($this->getKeyName());
    }

    /**
     * Obtient l'identité de file d'attente pour l'entité.
     */
    public function getQueueableId(): mixed
    {
        return $this->getKey();
    }

    /**
     * Obtient les relations de file d'attente pour l'entité.
     */
    public function getQueueableRelations(): array
    {
        return $this->withoutRecursion(function () {
            $relations = [];

            foreach ($this->getRelations() as $key => $relation) {
                if (! method_exists($this, $key)) {
                    continue;
                }

                $relations[] = $key;

                if ($relation instanceof QueueableCollection) {
                    foreach ($relation->getQueueableRelations() as $collectionValue) {
                        $relations[] = $key . '.' . $collectionValue;
                    }
                }

                if ($relation instanceof QueueableEntity) {
                    foreach ($relation->getQueueableRelations() as $entityValue) {
                        $relations[] = $key . '.' . $entityValue;
                    }
                }
            }

            return array_unique($relations);
        }, []);
    }

    /**
     * Obtient la connexion de file d'attente pour l'entité.
     */
    public function getQueueableConnection(): ?string
    {
        return $this->getConnectionName();
    }

    /**
     * Obtient le nom de clé étrangère par défaut pour le modèle.
     */
    public function getForeignKey(): string
    {
        return Text::snake(Helpers::classBasename($this)) . '_' . $this->getKeyName();
    }

    /**
     * Obtient le nombre de modèles à retourner par page.
     */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * Définit le nombre de modèles à retourner par page.
     */
    public function setPerPage(int $perPage): self
    {
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * Détermine si le modèle est soft deletable.
     */
    public static function isSoftDeletable(): bool
    {
        return static::$isSoftDeletable[static::class] ??= in_array(SoftDeletes::class, Helpers::classUsesRecursive(static::class), true);
    }

    /**
     * Détermine si le chargement paresseux est désactivé.
     */
    public static function preventsLazyLoading(): bool
    {
        return static::$modelsShouldPreventLazyLoading;
    }

    /**
     * Détermine si les relations sont automatiquement chargées avec empressement lorsqu'elles sont accédées.
     */
    public static function isAutomaticallyEagerLoadingRelationships(): bool
    {
        return static::$modelsShouldAutomaticallyEagerLoadRelationships;
    }

    /**
     * Détermine si la suppression silencieuse des attributs fillable est désactivée.
     */
    public static function preventsSilentlyDiscardingAttributes(): bool
    {
        return static::$modelsShouldPreventSilentlyDiscardingAttributes;
    }

    /**
     * Détermine si l'accès aux attributs manquants est désactivé.
     */
    public static function preventsAccessingMissingAttributes(): bool
    {
        return static::$modelsShouldPreventAccessingMissingAttributes;
    }

    /**
     * Récupère dynamiquement les attributs sur le modèle.
     */
    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    /**
     * Définit dynamiquement les attributs sur le modèle.
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Détermine si l'attribut donné existe.
     */
    public function offsetExists(mixed $offset): bool
    {
        $shouldPrevent = static::$modelsShouldPreventAccessingMissingAttributes;

        static::$modelsShouldPreventAccessingMissingAttributes = false;

        try {
            return null !== $this->getAttribute($offset);
        } finally {
            static::$modelsShouldPreventAccessingMissingAttributes = $shouldPrevent;
        }
    }

    /**
     * Obtient la valeur pour un offset donné.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute($offset);
    }

    /**
     * Définit la valeur pour un offset donné.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->setAttribute($offset, $value);
    }

    /**
     * Supprime la valeur pour un offset donné.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset(
            $this->attributes[$offset],
            $this->relations[$offset],
            $this->attributeCastCache[$offset],
            $this->classCastCache[$offset],
        );
    }

    /**
     * Détermine si un attribut ou une relation existe sur le modèle.
     */
    public function __isset(string $key): bool
    {
        return $this->offsetExists($key);
    }

    /**
     * Supprime un attribut sur le modèle.
     */
    public function __unset(string $key): void
    {
        $this->offsetUnset($key);
    }

    /**
     * Gère les appels de méthode dynamiques vers le modèle.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        if (in_array($method, ['increment', 'decrement', 'incrementQuietly', 'decrementQuietly'], true)) {
            return $this->{$method}(...$parameters);
        }

        if ($resolver = $this->relationResolver(static::class, $method)) {
            return $resolver($this);
        }

        if (Text::startsWith($method, 'through')
            && method_exists($this, $relationMethod = Text::of($method)->after('through')->lcfirst()->toString())) {
            return $this->through($relationMethod);
        }

        return $this->forwardCallTo($this->newQuery(), $method, $parameters);
    }

    /**
     * Gère les appels de méthode statiques dynamiques vers le modèle.
     */
    public static function __callStatic(string $method, array $parameters = []): mixed
    {
        if (static::isScopeMethodWithAttribute($method)) {
            return static::query()->{$method}(...$parameters);
        }

        return (new static())->{$method}(...$parameters);
    }

    /**
     * Convertit le modèle en sa représentation sous forme de chaîne.
     */
    public function __toString(): string
    {
        return $this->escapeWhenCastingToString
            ? Helpers::esc($this->toJson())
            : $this->toJson();
    }

    /**
     * Indique que la représentation sous forme de chaîne de l'objet doit être échappée lorsque __toString est invoqué.
     */
    public function escapeWhenCastingToString(bool $escape = true): static
    {
        $this->escapeWhenCastingToString = $escape;

        return $this;
    }

    /**
     * Prépare l'objet pour la sérialisation.
     */
    public function __serialize(): array
    {
        $this->mergeAttributesFromClassCasts();

        $this->classCastCache           = [];
        $this->attributeCastCache       = [];
        $this->relationAutoloadCallback = null;
        $this->relationAutoloadContext  = null;

        $keys = get_object_vars($this);

        if (version_compare(PHP_VERSION, '8.4.0', '>=')) {
            foreach ((new ReflectionClass($this))->getProperties() as $property) {
                if ($property->hasHooks()) {
                    unset($keys[$property->getName()]);
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * Lorsqu'un modèle est désérialisé, vérifie s'il doit être démarré.
     */
    public function __unserialize(array $data): void
    {
        $this->bootIfNotBooted();
        $this->initializeTraits();

        if (static::isAutomaticallyEagerLoadingRelationships()) {
            $this->withRelationshipAutoloading();
        }
    }
}
