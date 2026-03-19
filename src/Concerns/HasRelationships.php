<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Concerns;

use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Exceptions\ClassMorphViolationException;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\PendingHasThroughRelationship;
use BlitzPHP\Wolke\Relations\BelongsTo;
use BlitzPHP\Wolke\Relations\BelongsToMany;
use BlitzPHP\Wolke\Relations\HasMany;
use BlitzPHP\Wolke\Relations\HasManyThrough;
use BlitzPHP\Wolke\Relations\HasOne;
use BlitzPHP\Wolke\Relations\HasOneThrough;
use BlitzPHP\Wolke\Relations\MorphMany;
use BlitzPHP\Wolke\Relations\MorphOne;
use BlitzPHP\Wolke\Relations\MorphTo;
use BlitzPHP\Wolke\Relations\MorphToMany;
use BlitzPHP\Wolke\Relations\Pivot;
use BlitzPHP\Wolke\Relations\Relation;
use Closure;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\HasRelationships</a>
 */
trait HasRelationships
{
    /**
     * Les relations chargées pour le modèle.
     */
    protected array $relations = [];

    /**
     * Les relations qui doivent être touchées lors de la sauvegarde.
     */
    protected array $touches = [];

    /**
     * Le rappel d'autochargement des relations.
     */
    protected ?Closure $relationAutoloadCallback = null;

    /**
     * Le contexte du rappel d'autochargement des relations.
     */
    protected mixed $relationAutoloadContext = null;

    /**
     * Les méthodes de relation many-to-many.
     *
     * @var list<string>
     */
    public static array $manyMethods = [
        'belongsToMany', 'morphToMany', 'morphedByMany',
    ];

    /**
     * Les rappels de résolution de relation.
     */
    protected static array $relationResolvers = [];

    /**
     * Obtient le résolveur de relation dynamique s'il est défini ou hérité, ou retourne null.
     *
     * @template TRelatedModel of Model
     *
     * @param class-string<TRelatedModel> $class
     */
    public function relationResolver(string $class, string $key): ?Closure
    {
        if ($resolver = static::$relationResolvers[$class][$key] ?? null) {
            return $resolver;
        }

        if ($parent = get_parent_class($class)) {
            return $this->relationResolver($parent, $key);
        }

        return null;
    }

    /**
     * Définit un résolveur de relation dynamique.
     */
    public static function resolveRelationUsing(string $name, Closure $callback): void
    {
        static::$relationResolvers = array_replace_recursive(
            static::$relationResolvers,
            [static::class => [$name => $callback]],
        );
    }

    /**
     * Détermine si un rappel d'autochargement de relation a été défini.
     */
    public function hasRelationAutoloadCallback(): bool
    {
        return $this->relationAutoloadCallback !== null;
    }

    /**
     * Définit un rappel d'autochargement de relation automatique pour ce modèle et ses relations.
     */
    public function autoloadRelationsUsing(Closure $callback, mixed $context = null): static
    {
        // Empêche l'autochargement circulaire de relations...
        if ($context && $this->relationAutoloadContext === $context) {
            return $this;
        }

        $this->relationAutoloadCallback = $callback;
        $this->relationAutoloadContext  = $context;

        foreach ($this->relations as $key => $value) {
            $this->propagateRelationAutoloadCallbackToRelation($key, $value);
        }

        return $this;
    }

    /**
     * Tente d'autocharger la relation donnée en utilisant le rappel d'autochargement.
     */
    protected function attemptToAutoloadRelation(string $key): bool
    {
        if (! $this->hasRelationAutoloadCallback()) {
            return false;
        }

        $this->invokeRelationAutoloadCallbackFor($key, []);

        return $this->relationLoaded($key);
    }

    /**
     * Invoque le rappel d'autochargement de relation pour les relations données.
     */
    protected function invokeRelationAutoloadCallbackFor(string $key, array $tuples): void
    {
        $tuples = array_merge([[$key, static::class]], $tuples);

        ($this->relationAutoloadCallback)($tuples);
    }

    /**
     * Propage le rappel d'autochargement de relation aux modèles liés donnés.
     */
    protected function propagateRelationAutoloadCallbackToRelation(string $key, mixed $models): void
    {
        if (! $this->hasRelationAutoloadCallback() || ! $models) {
            return;
        }

        if ($models instanceof Model) {
            $models = [$models];
        }

        if (! is_iterable($models)) {
            return;
        }

        $callback = fn (array $tuples) => $this->invokeRelationAutoloadCallbackFor($key, $tuples);

        foreach ($models as $model) {
            $model->autoloadRelationsUsing($callback, $this->relationAutoloadContext);
        }
    }

    /**
     * Définit une relation un-à-un.
     *
     * @template TRelatedModel of Model
     *
     * @param class-string<TRelatedModel> $related
     *
     * @return HasOne<TRelatedModel, $this>
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasOne($instance->newQuery(), $this, $instance->qualifyColumn($foreignKey), $localKey);
    }

    /**
     * Instancie une nouvelle relation HasOne.
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel        $parent
     *
     * @return HasOne<TRelatedModel, TDeclaringModel>
     */
    protected function newHasOne(Builder $query, Model $parent, string $foreignKey, string $localKey): HasOne
    {
        return new HasOne($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Définit une relation has-one-through.
     *
     * @template TRelatedModel of Model
     * @template TIntermediateModel of Model
     *
     * @param class-string<TRelatedModel>      $related
     * @param class-string<TIntermediateModel> $through
     *
     * @return HasOneThrough<TRelatedModel, TIntermediateModel, $this>
     */
    public function hasOneThrough(string $related, string $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null): HasOneThrough
    {
        $through = $this->newRelatedThroughInstance($through);

        $firstKey = $firstKey ?: $this->getForeignKey();

        $secondKey = $secondKey ?: $through->getForeignKey();

        return $this->newHasOneThrough(
            $this->newRelatedInstance($related)->newQuery(),
            $this,
            $through,
            $firstKey,
            $secondKey,
            $localKey ?: $this->getKeyName(),
            $secondLocalKey ?: $through->getKeyName(),
        );
    }

    /**
     * Instancie une nouvelle relation HasOneThrough.
     *
     * @template TRelatedModel of Model
     * @template TIntermediateModel of Model
     * @template TDeclaringModel of Model
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel        $farParent
     * @param TIntermediateModel     $throughParent
     *
     * @return HasOneThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     */
    protected function newHasOneThrough(Builder $query, Model $farParent, Model $throughParent, string $firstKey, string $secondKey, string $localKey, string $secondLocalKey): HasOneThrough
    {
        return new HasOneThrough($query, $farParent, $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey);
    }

    /**
     * Définit une relation polymorphe un-à-un.
     *
     * @template TRelatedModel of Model
     *
     * @param class-string<TRelatedModel> $related
     *
     * @return MorphOne<TRelatedModel, $this>
     */
    public function morphOne(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphOne
    {
        $instance = $this->newRelatedInstance($related);

        [$type, $id] = $this->getMorphs($name, $type, $id);

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newMorphOne($instance->newQuery(), $this, $instance->qualifyColumn($type), $instance->qualifyColumn($id), $localKey);
    }

    /**
     * Instancie une nouvelle relation MorphOne.
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel        $parent
     *
     * @return MorphOne<TRelatedModel, TDeclaringModel>
     */
    protected function newMorphOne(Builder $query, Model $parent, string $type, string $id, string $localKey): MorphOne
    {
        return new MorphOne($query, $parent, $type, $id, $localKey);
    }

    /**
     * Définit une relation inverse un-à-un ou plusieurs.
     *
     * @template TRelatedModel of Model
     *
     * @param class-string<TRelatedModel> $related
     *
     * @return BelongsTo<TRelatedModel, $this>
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null, ?string $relation = null): BelongsTo
    {
        // Si aucun nom de relation n'a été donné, nous utiliserons cette trace de débogage pour extraire
        // le nom de la méthode appelante et l'utiliser comme nom de relation car la plupart
        // du temps, ce sera ce que nous souhaitons utiliser pour les relations.
        if (null === $relation) {
            $relation = $this->guessBelongsToRelation();
        }

        $instance = $this->newRelatedInstance($related);

        // Si aucune clé étrangère n'a été fournie, nous pouvons utiliser une trace pour deviner le bon
        // nom de clé étrangère en utilisant le nom de la fonction de relation, qui
        // lorsqu'il est combiné avec "_id" devrait conventionnellement correspondre aux colonnes.
        if (null === $foreignKey) {
            $foreignKey = Text::snake($relation) . '_' . $instance->getKeyName();
        }

        // Une fois que nous avons les noms des clés étrangères, nous allons simplement créer une nouvelle requête Eloquent
        // pour les modèles liés et retourner l'instance de relation qui sera
        // en fait responsable de la récupération et de l'hydratation de toutes les relations.
        $ownerKey = $ownerKey ?: $instance->getKeyName();

        return $this->newBelongsTo(
            $instance->newQuery(),
            $this,
            $foreignKey,
            $ownerKey,
            $relation,
        );
    }

    /**
     * Instancie une nouvelle relation BelongsTo.
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel        $child
     *
     * @return BelongsTo<TRelatedModel, TDeclaringModel>
     */
    protected function newBelongsTo(Builder $query, Model $child, string $foreignKey, string $ownerKey, string $relation): BelongsTo
    {
        return new BelongsTo($query, $child, $foreignKey, $ownerKey, $relation);
    }

    /**
     * Définit une relation polymorphe inverse un-à-un ou plusieurs.
     *
     * @return MorphTo<Model, $this>
     */
    public function morphTo(?string $name = null, ?string $type = null, ?string $id = null, ?string $ownerKey = null): MorphTo
    {
        // Si aucun nom n'est fourni, nous utiliserons la trace pour obtenir le nom de la fonction
        // car c'est très probablement le nom de l'interface polymorphe. Nous pouvons
        // l'utiliser pour obtenir à la fois la classe et la clé étrangère qui seront utilisées.
        $name = $name ?: $this->guessBelongsToRelation();

        [$type, $id] = $this->getMorphs(
            Text::snake($name),
            $type,
            $id,
        );

        // Si la valeur du type est nulle, il est probablement sûr de supposer que nous chargeons avec empressement
        // la relation. Dans ce cas, nous passerons simplement une requête factice où nous
        // devons supprimer tous les chargements empressés qui pourraient déjà être définis sur un modèle.
        return null === ($class = $this->getAttributeFromArray($type)) || $class === ''
                    ? $this->morphEagerTo($name, $type, $id, $ownerKey)
                    : $this->morphInstanceTo($class, $name, $type, $id, $ownerKey);
    }

    /**
     * Définit une relation polymorphe inverse un-à-un ou plusieurs avec chargement empressé.
     *
     * @return MorphTo<Model, $this>
     */
    protected function morphEagerTo(string $name, string $type, string $id, string $ownerKey): MorphTo
    {
        return $this->newMorphTo(
            $this->newQuery()->setEagerLoads([]),
            $this,
            $id,
            $ownerKey,
            $type,
            $name,
        );
    }

    /**
     * Définit une relation polymorphe inverse un-à-un ou plusieurs avec instance.
     *
     * @return MorphTo<Model, $this>
     */
    protected function morphInstanceTo(string $target, string $name, string $type, string $id, string $ownerKey): MorphTo
    {
        $instance = $this->newRelatedInstance(
            static::getActualClassNameForMorph($target),
        );

        return $this->newMorphTo(
            $instance->newQuery(),
            $this,
            $id,
            $ownerKey ?? $instance->getKeyName(),
            $type,
            $name,
        );
    }

    /**
     * Instancie une nouvelle relation MorphTo.
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel        $parent
     *
     * @return MorphTo<TRelatedModel, TDeclaringModel>
     */
    protected function newMorphTo(Builder $query, Model $parent, string $foreignKey, string $ownerKey, string $type, string $relation): MorphTo
    {
        return new MorphTo($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }

    /**
     * Récupère le nom de classe réel pour une classe morph donnée.
     */
    public static function getActualClassNameForMorph(string $class): string
    {
        return Arr::get(Relation::morphMap() ?: [], $class, $class);
    }

    /**
     * Devine le nom de la relation "belongs to".
     */
    protected function guessBelongsToRelation(): string
    {
        [, , $caller] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        return $caller['function'];
    }

    /**
     * Crée une relation has-many-through ou has-one-through en attente.
     *
     * @template TIntermediateModel of Model
     *
     * @param HasMany<TIntermediateModel, covariant $this>|HasOne<TIntermediateModel, covariant $this>|string $relationship
     * @param mixed                                                                                           $relationship
     *
     * @return (
     *     $relationship is string
     *     ? PendingHasThroughRelationship<Model, $this>
     *     : (
     *          $relationship is HasMany<TIntermediateModel, $this>
     *          ? PendingHasThroughRelationship<TIntermediateModel, $this, HasMany<TIntermediateModel, $this>>
     *          : PendingHasThroughRelationship<TIntermediateModel, $this, HasOne<TIntermediateModel, $this>>
     *     )
     * )
     */
    public function through($relationship)
    {
        if (is_string($relationship)) {
            $relationship = $this->{$relationship}();
        }

        return new PendingHasThroughRelationship($this, $relationship);
    }

    /**
     * Définit une relation un-à-plusieurs.
     *
     * @template TRelatedModel of Model
     *
     * @param class-string<TRelatedModel> $related
     *
     * @return HasMany<TRelatedModel, $this>
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasMany(
            $instance->newQuery(),
            $this,
            $instance->qualifyColumn($foreignKey),
            $localKey,
        );
    }

    /**
     * Instancie une nouvelle relation HasMany.
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel        $parent
     *
     * @return HasMany<TRelatedModel, TDeclaringModel>
     */
    protected function newHasMany(Builder $query, Model $parent, string $foreignKey, string $localKey): HasMany
    {
        return new HasMany($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Définit une relation has-many-through.
     *
     * @template TRelatedModel of Model
     * @template TIntermediateModel of Model
     *
     * @param class-string<TRelatedModel>      $related
     * @param class-string<TIntermediateModel> $through
     *
     * @return HasManyThrough<TRelatedModel, TIntermediateModel, $this>
     */
    public function hasManyThrough(string $related, string $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null): HasManyThrough
    {
        $through = $this->newRelatedThroughInstance($through);

        $firstKey = $firstKey ?: $this->getForeignKey();

        $secondKey = $secondKey ?: $through->getForeignKey();

        return $this->newHasManyThrough(
            $this->newRelatedInstance($related)->newQuery(),
            $this,
            $through,
            $firstKey,
            $secondKey,
            $localKey ?: $this->getKeyName(),
            $secondLocalKey ?: $through->getKeyName(),
        );
    }

    /**
     * Instancie une nouvelle relation HasManyThrough.
     *
     * @template TRelatedModel of Model
     * @template TIntermediateModel of Model
     * @template TDeclaringModel of Model
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel        $farParent
     * @param TIntermediateModel     $throughParent
     *
     * @return HasManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     */
    protected function newHasManyThrough(Builder $query, Model $farParent, Model $throughParent, string $firstKey, string $secondKey, string $localKey, string $secondLocalKey): HasManyThrough
    {
        return new HasManyThrough($query, $farParent, $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey);
    }

    /**
     * Définit une relation polymorphe un-à-plusieurs.
     *
     * @template TRelatedModel of Model
     *
     * @param class-string<TRelatedModel> $related
     *
     * @return MorphMany<TRelatedModel, $this>
     */
    public function morphMany(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphMany
    {
        $instance = $this->newRelatedInstance($related);

        // Ici, nous allons rassembler le type morph et l'ID pour la relation afin que nous
        // puissions interroger correctement la table intermédiaire d'une relation. Enfin, nous
        // obtiendrons la table et créerons les instances de relation pour les développeurs.
        [$type, $id] = $this->getMorphs($name, $type, $id);

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newMorphMany(
            $instance->newQuery(),
            $this,
            $instance->qualifyColumn($type),
            $instance->qualifyColumn($id),
            $localKey,
        );
    }

    /**
     * Instancie une nouvelle relation MorphMany.
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel        $parent
     *
     * @return MorphMany<TRelatedModel, TDeclaringModel>
     */
    protected function newMorphMany(Builder $query, Model $parent, string $type, string $id, string $localKey): MorphMany
    {
        return new MorphMany($query, $parent, $type, $id, $localKey);
    }

    /**
     * Définit une relation many-to-many.
     *
     * @template TRelatedModel of Model
     *
     * @param class-string<TRelatedModel>     $related
     * @param class-string<Model>|string|null $table
     *
     * @return BelongsToMany<TRelatedModel, $this, Pivot>
     */
    public function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relation = null,
    ): BelongsToMany {
        // Si aucun nom de relation n'a été passé, nous allons récupérer les traces pour obtenir le
        // nom de la fonction appelante. Nous utiliserons ce nom de fonction comme
        // titre de cette relation car c'est une excellente convention à appliquer.
        if (null === $relation) {
            $relation = $this->guessBelongsToManyRelation();
        }

        // D'abord, nous devrons déterminer la clé étrangère et "l'autre clé" pour la
        // relation. Une fois que nous avons déterminé les clés, nous ferons les instances de requête
        // ainsi que les instances de relation dont nous avons besoin pour cela.
        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();

        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        // Si aucun nom de table n'a été fourni, nous pouvons le deviner en concaténant les deux
        // modèles en utilisant des traits de soulignement dans l'ordre alphabétique. Les deux noms de modèles
        // sont transformés en snake case à partir de leur CamelCase par défaut également.
        if (null === $table) {
            $table = $this->joiningTable($related, $instance);
        }

        return $this->newBelongsToMany(
            $instance->newQuery(),
            $this,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
            $relation,
        );
    }

    /**
     * Instancie une nouvelle relation BelongsToMany.
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param Builder<TRelatedModel>     $query
     * @param TDeclaringModel            $parent
     * @param class-string<Model>|string $table
     *
     * @return BelongsToMany<TRelatedModel, TDeclaringModel, Pivot>
     */
    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $relationName = null,
    ): BelongsToMany {
        return new BelongsToMany($query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }

    /**
     * Définit une relation polymorphe many-to-many.
     *
     * @template TRelatedModel of Model
     *
     * @param class-string<TRelatedModel> $related
     *
     * @return MorphToMany<TRelatedModel, $this>
     */
    public function morphToMany(
        string $related,
        string $name,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relation = null,
        bool $inverse = false,
    ): MorphToMany {
        $relation = $relation ?: $this->guessBelongsToManyRelation();

        // D'abord, nous devrons déterminer la clé étrangère et "l'autre clé" pour la
        // relation. Une fois que nous avons déterminé les clés, nous ferons les instances de requête,
        // ainsi que les instances de relation dont nous avons besoin pour celles-ci.
        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $name . '_id';

        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        // Maintenant, nous sommes prêts à créer un nouveau constructeur de requête pour ce modèle lié et
        // les instances de relation pour cette relation. Cette relation définira
        // des contraintes de requête appropriées puis gérera entièrement les hydratations.
        if (! $table) {
            $words = preg_split('/(_)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE);

            $lastWord = array_pop($words);

            $table = implode('', $words) . Text::plural($lastWord);
        }

        return $this->newMorphToMany(
            $instance->newQuery(),
            $this,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
            $relation,
            $inverse,
        );
    }

    /**
     * Instancie une nouvelle relation MorphToMany.
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param Builder<TRelatedModel> $query
     * @param TDeclaringModel        $parent
     *
     * @return MorphToMany<TRelatedModel, TDeclaringModel>
     */
    protected function newMorphToMany(
        Builder $query,
        Model $parent,
        string $name,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $relationName = null,
        bool $inverse = false,
    ): MorphToMany {
        return new MorphToMany(
            $query,
            $parent,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
            $inverse,
        );
    }

    /**
     * Définit une relation polymorphe inverse many-to-many.
     *
     * @template TRelatedModel of Model
     *
     * @param class-string<TRelatedModel> $related
     *
     * @return MorphToMany<TRelatedModel, $this>
     */
    public function morphedByMany(
        string $related,
        string $name,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relation = null,
    ): MorphToMany {
        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();

        // Pour l'inverse des relations polymorphes many-to-many, nous changerons
        // la façon dont nous déterminons les clés étrangères et autres, car c'est l'opposé
        // de la méthode morph-to-many puisque nous déterminons ces inverses.
        $relatedPivotKey = $relatedPivotKey ?: $name . '_id';

        return $this->morphToMany(
            $related,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relation,
            true,
        );
    }

    /**
     * Obtient le nom de la relation de la méthode belongsToMany.
     */
    protected function guessBelongsToManyRelation(): ?string
    {
        $caller = Arr::first(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), static fn ($trace) => ! in_array(
            $trace['function'],
            array_merge(static::$manyMethods, ['guessBelongsToManyRelation']),
            true,
        ));

        return null !== $caller ? $caller['function'] : null;
    }

    /**
     * Obtient le nom de la table de jonction pour une relation many-to-many.
     */
    public function joiningTable(string $related, ?Model $instance = null): string
    {
        // Le nom de la table de jonction, par convention, est simplement les noms des modèles en snake case
        // triés alphabétiquement et concaténés avec un trait de soulignement, donc nous pouvons
        // simplement trier les modèles et les joindre ensemble pour obtenir le nom de la table.
        $segments = [
            $instance ? $instance->joiningTableSegment()
                      : Text::snake(Helpers::classBasename($related)),
            $this->joiningTableSegment(),
        ];

        // Maintenant que nous avons les noms des modèles dans un tableau, nous pouvons simplement les trier et
        // utiliser la fonction implode pour les joindre avec un trait de soulignement,
        // ce qui est typiquement utilisé par convention dans le système de base de données.
        sort($segments);

        return strtolower(implode('_', $segments));
    }

    /**
     * Obtient la moitié de ce modèle du nom de la table intermédiaire pour les relations belongsToMany.
     */
    public function joiningTableSegment(): string
    {
        return Text::snake(Helpers::classBasename($this));
    }

    /**
     * Détermine si le modèle touche une relation donnée.
     */
    public function touches(string $relation): bool
    {
        return in_array($relation, $this->getTouchedRelations(), true);
    }

    /**
     * Touche les relations propriétaires du modèle.
     */
    public function touchOwners(): void
    {
        $this->withoutRecursion(function () {
            foreach ($this->getTouchedRelations() as $relation) {
                $this->{$relation}()->touch();

                if ($this->{$relation} instanceof self) {
                    $this->{$relation}->fireModelEvent('saved', false);

                    $this->{$relation}->touchOwners();
                } elseif ($this->{$relation} instanceof Collection) {
                    $this->{$relation}->each->touchOwners();
                }
            }
        });
    }

    /**
     * Obtient les colonnes de relation polymorphe.
     */
    protected function getMorphs(string $name, ?string $type = null, ?string $id = null): array
    {
        return [$type ?: $name . '_type', $id ?: $name . '_id'];
    }

    /**
     * Obtient le nom de classe pour les relations polymorphes.
     */
    public function getMorphClass(): string
    {
        $morphMap = Relation::morphMap();

        if (! empty($morphMap) && in_array(static::class, $morphMap, true)) {
            return array_search(static::class, $morphMap, true);
        }

        if (static::class === Pivot::class) {
            return static::class;
        }

        if (Relation::requiresMorphMap()) {
            throw new ClassMorphViolationException($this);
        }

        return static::class;
    }

    /**
     * Crée une nouvelle instance de modèle pour un modèle lié.
     *
     * @template TRelatedModel of Model
     *
     * @param class-string<TRelatedModel> $class
     *
     * @return TRelatedModel
     */
    protected function newRelatedInstance(string $class): object
    {
        return Helpers::tap(new $class(), function ($instance) {
            if (! $instance->getConnectionName()) {
                $instance->setConnection($this->connection);
            }
        });
    }

    /**
     * Crée une nouvelle instance de modèle pour un modèle "through" lié.
     *
     * @template TRelatedModel of Model
     *
     * @param class-string<TRelatedModel> $class
     *
     * @return TRelatedModel
     */
    protected function newRelatedThroughInstance(string $class): object
    {
        return new $class();
    }

    /**
     * Obtient toutes les relations chargées pour l'instance.
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * Obtient une relation spécifiée.
     */
    public function getRelation(string $relation): mixed
    {
        return $this->relations[$relation];
    }

    /**
     * Détermine si la relation donnée est chargée.
     */
    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    /**
     * Définit la relation donnée sur le modèle.
     */
    public function setRelation(string $relation, mixed $value): static
    {
        $this->relations[$relation] = $value;

        $this->propagateRelationAutoloadCallbackToRelation($relation, $value);

        return $this;
    }

    /**
     * Supprime une relation chargée.
     */
    public function unsetRelation(string $relation): static
    {
        unset($this->relations[$relation]);

        return $this;
    }

    /**
     * Définit tout le tableau des relations sur le modèle.
     */
    public function setRelations(array $relations): static
    {
        $this->relations = $relations;

        return $this;
    }

    /**
     * Active l'autochargement des relations pour ce modèle.
     */
    public function withRelationshipAutoloading(): static
    {
        $this->newCollection([$this])->withRelationshipAutoloading();

        return $this;
    }

    /**
     * Duplique l'instance et supprime toutes les relations chargées.
     */
    public function withoutRelations(): static
    {
        $model = clone $this;

        return $model->unsetRelations();
    }

    /**
     * Supprime toutes les relations chargées pour l'instance.
     */
    public function unsetRelations(): static
    {
        $this->relations = [];

        return $this;
    }

    /**
     * Obtient les relations qui sont touchées lors de la sauvegarde.
     */
    public function getTouchedRelations(): array
    {
        return $this->touches;
    }

    /**
     * Définit les relations qui sont touchées lors de la sauvegarde.
     */
    public function setTouchedRelations(array $touches): static
    {
        $this->touches = $touches;

        return $this;
    }
}
