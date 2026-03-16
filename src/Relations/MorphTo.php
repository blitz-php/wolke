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

use BadMethodCallException;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithDictionary;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BelongsTo<TRelatedModel, TDeclaringModel>
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\MorphTo</a>
 */
class MorphTo extends BelongsTo
{
    use InteractsWithDictionary;

    /**
     * Le type de la relation polymorphe.
     *
     * @var string
     */
    protected $morphType;

    /**
     * Les modèles dont les relations sont chargées avec empressement.
     *
     * @var Collection<int, TDeclaringModel>
     */
    protected $models;

    /**
     * Tous les modèles indexés par ID.
     */
    protected array $dictionary = [];

    /**
     * Un tampon d'appels dynamiques aux macros de requête.
     */
    protected array $macroBuffer = [];

    /**
     * Une carte des relations à charger pour chaque type morph individuel.
     */
    protected array $morphableEagerLoads = [];

    /**
     * Une carte des compteurs de relations à charger pour chaque type morph individuel.
     */
    protected array $morphableEagerLoadCounts = [];

    /**
     * Une carte des contraintes à appliquer pour chaque type morph individuel.
     */
    protected array $morphableConstraints = [];

    /**
     * Crée une nouvelle instance de relation morph to.
     *
     * @param Builder<TRelatedModel>  $query
     * @param TDeclaringModel  $parent
     */
    public function __construct(Builder $query, Model $parent, string $foreignKey, ?string $ownerKey, string $type, string $relation)
    {
        $this->morphType = $type;

        parent::__construct($query, $parent, $foreignKey, $ownerKey, $relation);
    }

    /**
     * {@inheritDoc}
     */
    public function addEagerConstraints(array $models): void
    {
        $this->buildDictionary($this->models = new Collection($models));
    }

    /**
     * Construit un dictionnaire avec les modèles.
     * 
     * @param Collection<int, TRelatedModel>  $models
     */
    protected function buildDictionary(Collection $models): void
    {
        $isAssociative = Arr::isAssoc($models->all());
        
        foreach ($models as $key => $model) {
            if ($model->{$this->morphType}) {
                $morphTypeKey  = $this->getDictionaryKey($model->{$this->morphType});
                $foreignKeyKey = $this->getDictionaryKey($model->{$this->foreignKey});

                if ($isAssociative) {
                    $this->dictionary[$morphTypeKey][$foreignKeyKey][$key] = $model;
                } else {
                    $this->dictionary[$morphTypeKey][$foreignKeyKey][] = $model;
                }
            }
        }
    }

    /**
     * Obtient les résultats de la relation.
     *
     * Appelée via la méthode de chargement empressé du constructeur de requête Wolke.
     * 
     * @return Collection<int, TDeclaringModel>
     */
    public function getEager(): Collection
    {
        foreach (array_keys($this->dictionary) as $type) {
            $this->matchToMorphParents($type, $this->getResultsByType($type));
        }

        return $this->models;
    }

    /**
     * Obtient tous les résultats de relation pour un type.
     * 
     * @return Collection<int, TRelatedModel>
     */
    protected function getResultsByType(string $type): Collection
    {
        $instance = $this->createModelByType($type);

        $ownerKey = $this->ownerKey ?? $instance->getKeyName();

        $query = $this->replayMacros($instance->newQuery())
            ->mergeConstraintsFrom($this->getQuery())
            ->with(array_merge(
                $this->getQuery()->getEagerLoads(),
                (array) ($this->morphableEagerLoads[get_class($instance)] ?? [])
            ))
            ->withCount(
                (array) ($this->morphableEagerLoadCounts[get_class($instance)] ?? [])
            );

        if ($callback = ($this->morphableConstraints[get_class($instance)] ?? null)) {
            $callback($query);
        }

        $whereIn = $this->whereInMethod($instance, $ownerKey);

        return $query->{$whereIn}(
            $instance->qualifyColumn($ownerKey),
            $this->gatherKeysByType($type, $instance->getKeyType())
        )->get();
    }

    /**
     * Rassemble toutes les clés étrangères pour un type donné.
     */
    protected function gatherKeysByType(string $type, string $keyType): array
    {
        return $keyType !== 'string'
                    ? array_keys($this->dictionary[$type])
                    : array_map(static fn ($modelId) => (string) $modelId, array_filter(array_keys($this->dictionary[$type])));
    }

    /**
     * Crée une nouvelle instance de modèle par type.
     * 
     * @return TRelatedModel
     */
    public function createModelByType(string $type): Model
    {
        $class = Model::getActualClassNameForMorph($type);

        return Helpers::tap(new $class(), function ($instance) {
            if (! $instance->getConnectionName()) {
                $instance->setConnection($this->getConnection()->getName());
            }
        });
    }

    /**
     * {@inheritDoc}
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        return $models;
    }

    /**
     * Fait correspondre les résultats pour un type donné à leurs parents.
     * 
     * @param Collection<int, TRelatedModel>  $results
     */
    protected function matchToMorphParents(string $type, Collection $results): void
    {
        foreach ($results as $result) {
            $ownerKey = null !== $this->ownerKey ? $this->getDictionaryKey($result->{$this->ownerKey}) : $result->getKey();

            if (isset($this->dictionary[$type][$ownerKey])) {
                foreach ($this->dictionary[$type][$ownerKey] as $model) {
                    $model->setRelation($this->relationName, $result);
                }
            }
        }
    }

    /**
     * {@inheritDoc}
     *
     * @param  TRelatedModel|null  $model
     * 
     * @return TDeclaringModel
     */
    public function associate($model): Model
    {
        if ($model instanceof Model) {
            $foreignKey = $this->ownerKey && $model->{$this->ownerKey}
                            ? $this->ownerKey
                            : $model->getKeyName();
        }

        $this->parent->setAttribute(
            $this->foreignKey,
            $model instanceof Model ? $model->{$foreignKey} : null
        );

        $this->parent->setAttribute(
            $this->morphType,
            $model instanceof Model ? $model->getMorphClass() : null
        );

        return $this->parent->setRelation($this->relationName, $model);
    }

    /**
     * Dissocie le modèle précédemment associé du parent donné.
     *
     * @return TDeclaringModel
     */
    public function dissociate(): Model
    {
        $this->parent->setAttribute($this->foreignKey, null);

        $this->parent->setAttribute($this->morphType, null);

        return $this->parent->setRelation($this->relationName, null);
    }

    /**
     * {@inheritDoc}
     */
    public function touch(): void
    {
        if (null !== $this->child->{$this->foreignKey}) {
            parent::touch();
        }
    }

    /**
     * {@inheritDoc}
     */
    protected function newRelatedInstanceFor(Model $parent): Model
    {
        return $parent->{$this->getRelationName()}()->getRelated()->newInstance();
    }

    /**
     * Obtient le nom du "type" de clé étrangère.
     */
    public function getMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Obtient le dictionnaire utilisé par la relation.
     */
    public function getDictionary(): array
    {
        return $this->dictionary;
    }

    /**
     * Spécifie quelles relations charger pour un type morph donné.
     */
    public function morphWith(array $with): static
    {
        $this->morphableEagerLoads = array_merge(
            $this->morphableEagerLoads,
            $with
        );

        return $this;
    }

    /**
     * Spécifie quels compteurs de relations charger pour un type morph donné.
     */
    public function morphWithCount(array $withCount): static
    {
        $this->morphableEagerLoadCounts = array_merge(
            $this->morphableEagerLoadCounts,
            $withCount
        );

        return $this;
    }

    /**
     * Spécifie des contraintes sur la requête pour un type morph donné.
     */
    public function constrain(array $callbacks): static
    {
        $this->morphableConstraints = array_merge(
            $this->morphableConstraints,
            $callbacks
        );

        return $this;
    }

    /**
     * Indique que les modèles supprimés doivent être inclus dans les résultats.
     */
    public function withTrashed(): self
    {
        $callback = static fn ($query) => $query->hasMacro('withTrashed') ? $query->withTrashed() : $query;

        $this->macroBuffer[] = [
            'method'     => 'when',
            'parameters' => [true, $callback],
        ];

        return $this->when(true, $callback);
    }

    /**
     * Indique que les modèles supprimés ne doivent pas être inclus dans les résultats.
     */
    public function withoutTrashed(): static
    {
        $callback = static fn ($query) => $query->hasMacro('withoutTrashed') ? $query->withoutTrashed() : $query;

        $this->macroBuffer[] = [
            'method'     => 'when',
            'parameters' => [true, $callback],
        ];

        return $this->when(true, $callback);
    }

    /**
     * Indique que seuls les modèles supprimés doivent être inclus dans les résultats.
     */
    public function onlyTrashed(): static
    {
        $callback = static fn ($query) => $query->hasMacro('onlyTrashed') ? $query->onlyTrashed() : $query;

        $this->macroBuffer[] = [
            'method'     => 'when',
            'parameters' => [true, $callback],
        ];

        return $this->when(true, $callback);
    }

    /**
     * Rejoue les appels de macro stockés sur l'instance liée réelle.
     * 
     * @param Builder<TRelatedModel>  $query
     * 
     * @return Builder<TRelatedModel>
     */
    protected function replayMacros(Builder $query): Builder
    {
        foreach ($this->macroBuffer as $macro) {
            $query->{$macro['method']}(...$macro['parameters']);
        }

        return $query;
    }
    /** 
     * {@inheritDoc}
     */
    public function getQualifiedOwnerKeyName(): string
    {
        if (null === $this->ownerKey) {
            return '';
        }

        return parent::getQualifiedOwnerKeyName();
    }

    /**
     * Gère les appels de méthode dynamiques à la relation.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        try {
            $result = parent::__call($method, $parameters);

            if (in_array($method, ['select', 'selectRaw', 'selectSubquery', 'addSelect', 'withoutGlobalScopes'], true)) {
                $this->macroBuffer[] = compact('method', 'parameters');
            }

            return $result;
        }

        // Si nous avons essayé d'appeler une méthode qui n'existe pas sur l'instance parente Builder,
        // nous supposerons que nous voulons appeler une macro de requête (par exemple withTrashed) qui
        // n'existe que sur les modèles liés. Nous allons simplement stocker l'appel et le rejouer plus tard.
        catch (BadMethodCallException $e) {
            $this->macroBuffer[] = compact('method', 'parameters');

            return $this;
        }
    }
}
