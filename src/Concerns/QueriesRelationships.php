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

use BadMethodCallException;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Database\Query\Expression;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Invade\Invader;
use BlitzPHP\Utilities\Iterable\Collection as BaseCollection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Exceptions\RelationNotFoundException;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\BelongsTo;
use BlitzPHP\Wolke\Relations\BelongsToMany;
use BlitzPHP\Wolke\Relations\MorphTo;
use BlitzPHP\Wolke\Relations\Relation;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\QueriesRelationships</a>
 *
 * @mixin Builder
 */
trait QueriesRelationships
{
    /**
     * Ajoute une condition de comptage/existence de relation à la requête.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param (Closure(Builder<TRelatedModel>): mixed)|null $callback
     *
     * @throws RuntimeException
     */
    public function has(Relation|string $relation, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and', ?Closure $callback = null): static
    {
        if (is_string($relation)) {
            if (str_contains($relation, '.')) {
                return $this->hasNested($relation, $operator, $count, $boolean, $callback);
            }

            $relation = $this->getRelationWithoutConstraints($relation);
        }

        if ($relation instanceof MorphTo) {
            return $this->hasMorph($relation, ['*'], $operator, $count, $boolean, $callback);
        }

        // Si nous avons seulement besoin de vérifier l'existence de la relation, nous pouvons optimiser
        // la sous-requête pour n'exécuter qu'une clause "where exists" au lieu de cette clause complète "count".
        // Cela fera fonctionner ces requêtes beaucoup plus rapidement qu'avec un comptage.
        $method = $this->canUseExistsForExistenceCheck($operator, $count)
                        ? 'getRelationExistenceQuery'
                        : 'getRelationExistenceCountQuery';

        $hasQuery = $relation->{$method}(
            $relation->getRelated()->newQueryWithoutRelationships(),
            $this
        );

        // Ensuite, nous appellerons tout rappel donné comme une portée "anonyme" afin qu'ils puissent obtenir
        // le bon regroupement logique des clauses where si nécessaire pour ce constructeur de requête Eloquent.
        // Ensuite, nous serons prêts à finaliser et à retourner cette instance de requête.
        if ($callback) {
            $hasQuery->callScope($callback);
        }

        return $this->addHasWhere(
            $hasQuery,
            $relation,
            $operator,
            $count,
            $boolean,
        );
    }

    /**
     * Ajoute des conditions de comptage/existence de relation imbriquées à la requête.
     *
     * Configure un appel récursif à whereHas jusqu'à ce que nous terminions la relation imbriquée.
     *
     * @param  (Closure(Builder<*>): mixed)|null  $callback
     */
    protected function hasNested(string $relations, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and', ?Closure $callback = null): static
    {
        $relations = explode('.', $relations);

        $initialRelations = [...$relations];

        $doesntHave = $operator === '<' && $count === 1;

        if ($doesntHave) {
            $operator = '>=';
            $count    = 1;
        }

        $closure = static function ($q) use (&$closure, &$relations, $operator, $count, $callback, $initialRelations) {
            // Si la même fermeture est appelée plusieurs fois, réinitialisez le tableau de relations pour les parcourir à nouveau...
            if ($count === 1 && empty($relations)) {
                $relations = [...$initialRelations];

                array_shift($relations);
            }

            // Afin d'imbriquer "has", nous devons ajouter des contraintes de relation de comptage sur la
            // fermeture de rappel. Nous ferons cela en passant simplement à la fermeture sa propre
            // référence à elle-même afin qu'elle s'appelle récursivement sur chaque segment.
            count($relations) > 1
                ? $q->whereHas(array_shift($relations), $closure)
                : $q->has(array_shift($relations), $operator, $count, 'and', $callback);
        };

        return $this->has(array_shift($relations), $doesntHave ? '<' : '>=', 1, $boolean, $closure);
    }

    /**
     * Ajoute une condition de comptage/existence de relation à la requête avec un "ou".
     *
     * @param Relation<*, *, *>|string  $relation
     */
    public function orHas(Relation|string $relation, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'or');
    }

    /**
     * Ajoute une condition de comptage/existence de relation à la requête.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param (Closure(Builder<TRelatedModel>): mixed)|null $callback
     */
    public function doesntHave(Relation|string $relation, string $boolean = 'and', ?Closure $callback = null): static
    {
        return $this->has($relation, '<', 1, $boolean, $callback);
    }

    /**
     * Ajoute une condition de comptage/existence de relation à la requête avec un "ou".
     *
     * @param  Relation<*, *, *>|string  $relation
     */
    public function orDoesntHave(Relation|string $relation): static
    {
        return $this->doesntHave($relation, 'or');
    }

    /**
     * Ajoute une condition de comptage/existence de relation à la requête avec des clauses where.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param (Closure(Builder<TRelatedModel>): mixed)|null $callback
     */
    public function whereHas(Relation|string $relation, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'and', $callback);
    }

    /**
     * Ajoute une condition de comptage/existence de relation à la requête avec des clauses where.
     *
     * Charge également la relation avec la même condition.
     *
     * @param  (Closure(Builder<*>|Relation<*, *, *>): mixed)|null  $callback
     */
    public function withWhereHas(string $relation, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->whereHas(Text::before($relation, ':'), $callback, $operator, $count)
            ->with($callback ? [$relation => static fn ($query) => $callback($query)] : $relation);
    }

    /**
     * Ajoute une condition de comptage/existence de relation à la requête avec des clauses where et un "ou".
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param (Closure(Builder<TRelatedModel>): mixed)|null $callback
     */
    public function orWhereHas(Relation|string $relation, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->has($relation, $operator, $count, 'or', $callback);
    }

    /**
     * Ajoute une condition de comptage/existence de relation à la requête avec des clauses where.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param (Closure(Builder<TRelatedModel>): mixed)|null $callback
     */
    public function whereDoesntHave(Relation|string $relation, ?Closure $callback = null): static
    {
        return $this->doesntHave($relation, 'and', $callback);
    }

    /**
     * Ajoute une condition de comptage/existence de relation à la requête avec des clauses where et un "ou".
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param (Closure(Builder<TRelatedModel>): mixed)|null $callback
     */
    public function orWhereDoesntHave(string $relation, ?Closure $callback = null): static
    {
        return $this->doesntHave($relation, 'or', $callback);
    }

    /**
     * Ajoute une condition de comptage/existence de relation polymorphe à la requête.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string                      $relation
     * @param array<int, string>|string                             $types
     * @param (Closure(Builder<TRelatedModel>, string): mixed)|null $callback
     */
    public function hasMorph(MorphTo|string $relation, array|string $types, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and', ?Closure $callback = null): static
    {
        if (is_string($relation)) {
            $relation = $this->getRelationWithoutConstraints($relation);
        }

        $types = (array) $types;

        $checkMorphNull = $types === ['*']
            && (($operator === '<' && $count >= 1)
                || ($operator === '<=' && $count >= 0)
                || ($operator === '=' && $count === 0)
                || ($operator === '!=' && $count >= 1));

        if ($types === ['*']) {
            $types = $this->model->newModelQuery()->distinct()->pluck($relation->getMorphType())
                ->filter()
                ->map(static fn ($item) => Helpers::enumValue($item))
                ->all();
        }

        if (empty($types)) {
            return $this->where(new Expression('0'), $operator, $count, $boolean);
        }

        foreach ($types as &$type) {
            $type = Relation::getMorphedModel($type) ?? $type;
        }

        return $this->where(function ($query) use ($relation, $callback, $operator, $count, $types, $checkMorphNull) {
            foreach ($types as $type) {
                $query->orWhere(function ($query) use ($relation, $callback, $operator, $count, $type) {
                    $belongsTo = $this->getBelongsToRelation($relation, $type);

                    if ($callback) {
                        $callback = static fn ($query) => $callback($query, $type);
                    }

                    $query->where($this->qualifyColumn($relation->getMorphType()), '=', (new $type())->getMorphClass())
                        ->whereHas($belongsTo, $callback, $operator, $count);
                });
            }

            $query->when($checkMorphNull, static fn (self $query) => $query->orWhereMorphedTo($relation, null));
        }, null, null, $boolean);
    }

    /**
     * Obtient la relation BelongsTo pour un seul type polymorphe.
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param  MorphTo<*, TDeclaringModel>  $relation
     * @param class-string<TRelatedModel> $type
     *
     * @return BelongsTo<TRelatedModel, TDeclaringModel>
     */
    protected function getBelongsToRelation(MorphTo $relation, string $type): BelongsTo
    {
        $belongsTo = Relation::noConstraints(fn () => $this->model->belongsTo(
            $type,
            $relation->getForeignKeyName(),
            $relation->getOwnerKeyName(),
        ));

        $belongsTo->getQuery()->mergeConstraintsFrom($relation->getQuery());

        return $belongsTo;
    }

    /**
     * Ajoute une condition de comptage/existence de relation polymorphe à la requête avec un "ou".
     *
     * @param MorphTo<*, *>|string  $relation
     * @param array<int, string>|string $types
     */
    public function orHasMorph(MorphTo|string $relation, array|string $types, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->hasMorph($relation, $types, $operator, $count, 'or');
    }

    /**
     * Ajoute une condition de comptage/existence de relation polymorphe à la requête.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string                      $relation
     * @param array<int, string>|string                             $types
     * @param (Closure(Builder<TRelatedModel>, string): mixed)|null $callback
     */
    public function doesntHaveMorph(MorphTo|string $relation, array|string $types, string $boolean = 'and', ?Closure $callback = null): static
    {
        return $this->hasMorph($relation, $types, '<', 1, $boolean, $callback);
    }

    /**
     * Ajoute une condition de comptage/existence de relation polymorphe à la requête avec un "ou".
     *
     * @param MorphTo<*, *>|string  $relation
     * @param array<int, string>|string $types
     */
    public function orDoesntHaveMorph(MorphTo|string $relation, array|string $types): static
    {
        return $this->doesntHaveMorph($relation, $types, 'or');
    }

    /**
     * Ajoute une condition de comptage/existence de relation polymorphe à la requête avec des clauses where.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string                      $relation
     * @param array<int, string>|string                             $types
     * @param (Closure(Builder<TRelatedModel>, string): mixed)|null $callback
     */
    public function whereHasMorph(MorphTo|string $relation, array|string $types, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->hasMorph($relation, $types, $operator, $count, 'and', $callback);
    }

    /**
     * Ajoute une condition de comptage/existence de relation polymorphe à la requête avec des clauses where et un "ou".
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string                      $relation
     * @param array<int, string>|string                             $types
     * @param (Closure(Builder<TRelatedModel>, string): mixed)|null $callback
     */
    public function orWhereHasMorph(MorphTo|string $relation, array|string $types, ?Closure $callback = null, string $operator = '>=', Expression|int $count = 1): static
    {
        return $this->hasMorph($relation, $types, $operator, $count, 'or', $callback);
    }

    /**
     * Ajoute une condition de comptage/existence de relation polymorphe à la requête avec des clauses where.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string                      $relation
     * @param array<int, string>|string                             $types
     * @param (Closure(Builder<TRelatedModel>, string): mixed)|null $callback
     */
    public function whereDoesntHaveMorph(MorphTo|string $relation, array|string $types, ?Closure $callback = null): static
    {
        return $this->doesntHaveMorph($relation, $types, 'and', $callback);
    }

    /**
     * Ajoute une condition de comptage/existence de relation polymorphe à la requête avec des clauses where et un "ou".
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string                      $relation
     * @param array<int, string>|string                             $types
     * @param (Closure(Builder<TRelatedModel>, string): mixed)|null $callback
     */
    public function orWhereDoesntHaveMorph(MorphTo|string $relation, array|string $types, ?Closure $callback = null)
    {
        return $this->doesntHaveMorph($relation, $types, 'or', $callback);
    }

    /**
     * Ajoute une clause where de base à une requête de relation.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param array|(Closure(Builder<TRelatedModel>): mixed)|Expression|string $column
     */
    public function whereRelation(Relation|string $relation, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereHas($relation, static function ($query) use ($column, $operator, $value) {
            if ($column instanceof Closure) {
                $column($query);
            } else {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Ajoute une clause where de base à une requête de relation et charge la relation avec les mêmes conditions.
     *
     * @param Relation<*, *, *>|string  $relation
     * @param array|Closure|Expression|string $column
     */
    public function withWhereRelation(Relation|string $relation, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereRelation($relation, $column, $operator, $value)
            ->with([
                $relation => static fn ($query) => $column instanceof Closure
                    ? $column($query)
                    : $query->where($column, $operator, $value),
            ]);
    }

    /**
     * Ajoute une clause "ou where" à une requête de relation.
     *
     * @template TRelatedModel of Model
     *
     * @param Relation<TRelatedModel, *, *>|string  $relation
     * @param array|(Closure(Builder<TRelatedModel>): mixed)|Expression|string $column
     */
    public function orWhereRelation(Relation|string $relation, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->orWhereHas($relation, static function ($query) use ($column, $operator, $value) {
            if ($column instanceof Closure) {
                $column($query);
            } else {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Ajoute une condition de comptage/existence de base à une requête de relation.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param array|(Closure(Builder<TRelatedModel>): mixed)|Expression|string $column
     */
    public function whereDoesntHaveRelation(Relation|string $relation, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereDoesntHave($relation, static function ($query) use ($column, $operator, $value) {
            if ($column instanceof Closure) {
                $column($query);
            } else {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Ajoute une clause "ou where" à une requête de relation.
     *
     * @template TRelatedModel of Model
     *
     * @param  Relation<TRelatedModel, *, *>|string  $relation
     * @param array|(Closure(Builder<TRelatedModel>): mixed)|Expression|string $column
     */
    public function orWhereDoesntHaveRelation(Relation|string $relation, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->orWhereDoesntHave($relation, static function ($query) use ($column, $operator, $value) {
            if ($column instanceof Closure) {
                $column($query);
            } else {
                $query->where($column, $operator, $value);
            }
        });
    }

    /**
     * Ajoute une condition de relation polymorphe à la requête avec une clause where.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string                                 $relation
     * @param array<int, string>|string                                        $types
     * @param array|(Closure(Builder<TRelatedModel>): mixed)|Expression|string $column
     */
    public function whereMorphRelation(MorphTo|string $relation, array|string $types, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereHasMorph($relation, $types, static function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Ajoute une condition de relation polymorphe à la requête avec une clause "ou where".
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string                                 $relation
     * @param array<int, string>|string                                        $types
     * @param array|(Closure(Builder<TRelatedModel>): mixed)|Expression|string $column
     */
    public function orWhereMorphRelation(MorphTo|string $relation, array|string $types, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->orWhereHasMorph($relation, $types, static function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Ajoute une condition de relation polymorphe à la requête avec une clause doesn't have.
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string                                 $relation
     * @param array<int, string>|string                                        $types
     * @param array|(Closure(Builder<TRelatedModel>): mixed)|Expression|string $column
     */
    public function whereMorphDoesntHaveRelation(MorphTo|string $relation, array|string $types, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->whereDoesntHaveMorph($relation, $types, static function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Ajoute une condition de relation polymorphe à la requête avec une clause "ou doesn't have".
     *
     * @template TRelatedModel of Model
     *
     * @param MorphTo<TRelatedModel, *>|string                                 $relation
     * @param array<int, string>|string                                        $types
     * @param array|(Closure(Builder<TRelatedModel>): mixed)|Expression|string $column
     */
    public function orWhereMorphDoesntHaveRelation(MorphTo|string $relation, array|string $types, $column, mixed $operator = null, mixed $value = null): static
    {
        return $this->orWhereDoesntHaveMorph($relation, $types, static function ($query) use ($column, $operator, $value) {
            $query->where($column, $operator, $value);
        });
    }

    /**
     * Ajoute une condition de relation morph-to à la requête.
     *
     * @param MorphTo<*, *>|string  $relation
     * @param iterable<int, Model>|Model|string|null $model
     *
     * @throws InvalidArgumentException
     */
    public function whereMorphedTo(MorphTo|string $relation, iterable|Model|string|null $model, string $boolean = 'and'): static
    {
        if (is_string($relation)) {
            $relation = $this->getRelationWithoutConstraints($relation);
        }

        if (null === $model) {
            return $this->whereNull($relation->qualifyColumn($relation->getMorphType()), $boolean);
        }

        if (is_string($model)) {
            $morphMap = Relation::morphMap();

            if (! empty($morphMap) && in_array($model, $morphMap, true)) {
                $model = array_search($model, $morphMap, true);
            }

            return $this->where($relation->qualifyColumn($relation->getMorphType()), $model, null, $boolean);
        }

        $models = BaseCollection::wrap($model);

        if ($models->isEmpty()) {
            throw new InvalidArgumentException('La collection donnée à la méthode whereMorphedTo ne peut pas être vide.');
        }

        return $this->where(static function ($query) use ($relation, $models) {
            $models->groupBy(static fn ($model) => $model->getMorphClass())->each(static function ($models) use ($query, $relation) {
                $query->orWhere(static function ($query) use ($relation, $models) {
                    $query->where($relation->qualifyColumn($relation->getMorphType()), $models->first()->getMorphClass())
                        ->whereIn($relation->qualifyColumn($relation->getForeignKeyName()), $models->map->getKey());
                });
            });
        }, null, null, $boolean);
    }

    /**
     * Ajoute une condition de relation morph-to négative à la requête.
     *
     * @param MorphTo<*, *>|string  $relation
     * @param iterable<int, Model>|Model|string $model
     *
     * @throws InvalidArgumentException
     */
    public function whereNotMorphedTo(MorphTo|string $relation, iterable|Model|string $model, string $boolean = 'and'): static
    {
        if (is_string($relation)) {
            $relation = $this->getRelationWithoutConstraints($relation);
        }

        if (is_string($model)) {
            $morphMap = Relation::morphMap();

            if (! empty($morphMap) && in_array($model, $morphMap, true)) {
                $model = array_search($model, $morphMap, true);
            }

            return $this->whereNot(static fn ($query) => $query->whereNullSafeEquals(
                $relation->qualifyColumn($relation->getMorphType()),
                $model,
            ), null, null, $boolean);
        }

        $models = BaseCollection::wrap($model);

        if ($models->isEmpty()) {
            throw new InvalidArgumentException('La collection donnée à la méthode whereNotMorphedTo ne peut pas être vide.');
        }

        return $this->whereNot(static function ($query) use ($relation, $models) {
            $models->groupBy(static fn ($model) => $model->getMorphClass())->each(static function ($models) use ($query, $relation) {
                $query->orWhere(static function ($query) use ($relation, $models) {
                    $query->whereNullSafeEquals($relation->qualifyColumn($relation->getMorphType()), $models->first()->getMorphClass())
                        ->whereIn($relation->qualifyColumn($relation->getForeignKeyName()), $models->map->getKey());
                });
            });
        }, null, null, $boolean);
    }

    /**
     * Ajoute une condition de relation morph-to à la requête avec une clause "ou where".
     *
     * @param MorphTo<*, *>|string  $relation
     * @param iterable<int, Model>|Model|string|null $model
     */
    public function orWhereMorphedTo(MorphTo|string $relation, iterable|Model|string|null $model): static
    {
        return $this->whereMorphedTo($relation, $model, 'or');
    }

    /**
     * Ajoute une condition de relation morph-to négative à la requête avec une clause "ou where".
     *
     * @param MorphTo<*, *>|string  $relation
     * @param iterable<int, Model>|Model|string $model
     */
    public function orWhereNotMorphedTo(MorphTo|string $relation, iterable|Model|string $model): static
    {
        return $this->whereNotMorphedTo($relation, $model, 'or');
    }

    /**
     * Ajoute une clause where de relation "belongs to" à la requête.
     *
     * @param Collection<int, Model>|Model $related
     *
     * @throws RelationNotFoundException
     */
    public function whereBelongsTo(Collection|Model $related, ?string $relationshipName = null, string $boolean = 'and'): static
    {
        if (! $related instanceof Collection) {
            $relatedCollection = $related->newCollection([$related]);
        } else {
            $relatedCollection = $related;

            $related = $relatedCollection->first();
        }

        if ($relatedCollection->isEmpty()) {
            throw new InvalidArgumentException('La collection donnée à la méthode whereBelongsTo ne peut pas être vide.');
        }

        if ($relationshipName === null) {
            $relationshipName = Text::camel(Helpers::classBasename($related));
        }

        try {
            $relationship = $this->model->{$relationshipName}();
        } catch (BadMethodCallException) {
            throw RelationNotFoundException::make($this->model, $relationshipName);
        }

        if (! $relationship instanceof BelongsTo) {
            throw RelationNotFoundException::make($this->model, $relationshipName, BelongsTo::class);
        }

        $this->whereIn(
            $relationship->getQualifiedForeignKeyName(),
            $relatedCollection->pluck($relationship->getOwnerKeyName())->toArray(),
            $boolean,
        );

        return $this;
    }

    /**
     * Ajoute une clause where de relation "BelongsTo" avec un "ou" à la requête.
     *
     * @throws RuntimeException
     */
    public function orWhereBelongsTo(Model $related, ?string $relationshipName = null): static
    {
        return $this->whereBelongsTo($related, $relationshipName, 'or');
    }

    /**
     * Ajoute une clause where de relation "belongs to many" à la requête.
     *
     * @param Collection<int, Model>|Model $related
     *
     * @throws RelationNotFoundException
     */
    public function whereAttachedTo(Collection|Model $related, ?string $relationshipName = null, string $boolean = 'and'): static
    {
        $relatedCollection = $related instanceof Collection ? $related : $related->newCollection([$related]);

        $related = $relatedCollection->first();

        if ($relatedCollection->isEmpty()) {
            throw new InvalidArgumentException('La collection donnée à la méthode whereAttachedTo ne peut pas être vide.');
        }

        if ($relationshipName === null) {
            $relationshipName = Text::plural(Text::camel(Helpers::classBasename($related)));
        }

        try {
            $relationship = $this->model->{$relationshipName}();
        } catch (BadMethodCallException) {
            throw RelationNotFoundException::make($this->model, $relationshipName);
        }

        if (! $relationship instanceof BelongsToMany) {
            throw RelationNotFoundException::make($this->model, $relationshipName, BelongsToMany::class);
        }

        $this->has(
            $relationshipName,
            boolean: $boolean,
            callback: static fn (Builder $query) => $query->whereKey($relatedCollection->pluck($related->getKeyName())),
        );

        return $this;
    }

    /**
     * Ajoute une clause where de relation "belongs to many" avec un "ou" à la requête.
     *
     * @param Collection<int, Model>|Model $related
     *
     * @throws RuntimeException
     */
    public function orWhereAttachedTo(Collection|Model $related, ?string $relationshipName = null): static
    {
        return $this->whereAttachedTo($related, $relationshipName, 'or');
    }

    /**
     * Ajoute des sous-requêtes pour inclure une valeur agrégée pour une relation.
     */
    public function withAggregate(mixed $relations, Expression|string $column, ?string $function = null): static
    {
        if (empty($relations)) {
            return $this;
        }

        if ($this->query->columns === []) {
            $this->query->select("{$this->query->getTable()}.*");
        }

        $relations = is_array($relations) ? $relations : [$relations];

        foreach ($this->parseWithRelations($relations) as $name => $constraints) {
            // Nous déterminerons d'abord si le nom a été aliasé en utilisant une clause "as" sur le nom
            // et si c'est le cas, nous extrairons le nom de relation réel et le nom souhaité de
            // la colonne résultante. Cela permet plusieurs agrégats sur les mêmes relations.
            $segments = explode(' ', $name);

            unset($alias);

            if (count($segments) === 3 && Text::lower($segments[1]) === 'as') {
                [$name, $alias] = [$segments[0], $segments[2]];
            }

            $relation = $this->getRelationWithoutConstraints($name);

            if ($function) {
                if ($column instanceof Expression) {
                    $aggregateColumn = $column->getValue();
                } else {
                    $hashedColumn = $this->getRelationHashedColumn($column, $relation);

                    $aggregateColumn = $column === '*' ? $column : $relation->getRelated()->qualifyColumn($hashedColumn);
                }

                $expression = $function === 'exists' ? $aggregateColumn : sprintf('%s(%s)', $function, $aggregateColumn);
            } else {
                $expression = (string) $column;
            }

            // Ici, nous allons saisir la sous-requête de relation et préparer à l'ajouter à la requête principale
            // comme une sous-sélection. D'abord, nous obtiendrons la requête "has" et l'utiliserons pour obtenir la sous-requête
            // de relation. Nous formaterons ce nom de relation et ajouterons cette colonne si nécessaire.
            $query = $relation->getRelationExistenceQuery(
                $relation->getRelated()->newQuery(),
                $this,
                new Expression($expression),
            );

            $query->callScope($constraints);

            $query = $query->mergeConstraintsFrom($relation->getQuery())->toBase();

            // Si la requête contient certains éléments comme des tris / plus d'une colonne sélectionnée,
            // alors nous supprimerons ces éléments de la requête afin qu'elle s'exécute correctement
            // lorsqu'elle est donnée à la base de données. Sinon, nous pouvons recevoir des erreurs SQL ou une syntaxe incorrecte.
            Invader::make($query)->orders = [];
            $query->bindings->set([], 'order');

            $fields = $query->columns;

            if (count($fields) > 1) {
                $invader          = Invader::make($query);
                $invader->columns = [$fields[0]];
                $invader->wheres  = [];
                $query->bindings->set([], 'select');
            }

            // Enfin, nous ferons l'alias de colonne approprié à la requête et exécuterons cette sous-sélection sur
            // le constructeur de requête. Ensuite, nous retournerons l'instance du constructeur au développeur
            // pour un enchaînement de contraintes supplémentaire qui doit avoir lieu sur la requête si nécessaire.
            $alias ??= Text::snake(
                preg_replace(
                    '/[^[:alnum:][:space:]_]/u',
                    '',
                    sprintf('%s %s %s', $name, $function, strtolower((string) $column)),
                ),
            );

            if ($function === 'exists') {
                $this->selectRaw(
                    sprintf('exists(%s) as %s', $query->toSql(), $alias),
                    $query->getBindings(),
                )->withCasts([$alias => 'bool']);
            } else {
                $this->selectSubquery(
                    $function ? $query : $query->limit(1),
                    $alias,
                );
            }
        }

        return $this;
    }

    /**
     * Obtient le nom de colonne haché de relation pour la colonne et la relation données.
     *
     * @param Relation<*, *, *> $relation
     */
    protected function getRelationHashedColumn(string $column, Relation $relation): string
    {
        if (str_contains($column, '.')) {
            return $column;
        }

        return $this->getQuery()->getTable() === $relation->getQuery()->getQuery()->getTable()
            ? "{$relation->getRelationCountHash(false)}.{$column}"
            : $column;
    }

    /**
     * Ajoute des sous-requêtes pour compter les relations.
     */
    public function withCount(mixed $relations): static
    {
        return $this->withAggregate(is_array($relations) ? $relations : func_get_args(), '*', 'count');
    }

    /**
     * Ajoute des sous-requêtes pour inclure le maximum de la colonne de relation.
     */
    public function withMax(array|string $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'max');
    }

    /**
     * Ajoute des sous-requêtes pour inclure le minimum de la colonne de relation.
     */
    public function withMin(array|string $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'min');
    }

    /**
     * Ajoute des sous-requêtes pour inclure la somme de la colonne de relation.
     */
    public function withSum(array|string $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'sum');
    }

    /**
     * Ajoute des sous-requêtes pour inclure la moyenne de la colonne de relation.
     */
    public function withAvg(array|string $relation, Expression|string $column): static
    {
        return $this->withAggregate($relation, $column, 'avg');
    }

    /**
     * Ajoute des sous-requêtes pour inclure l'existence de modèles liés.
     */
    public function withExists(array|string $relation): static
    {
        return $this->withAggregate($relation, '*', 'exists');
    }

    /**
     * Ajoute la condition "has" where clause à la requête.
     *
     * @param Builder<*>  $hasQuery
     * @param Relation<*, *, *>  $relation
     */
    protected function addHasWhere(Builder $hasQuery, Relation $relation, string $operator, Expression|int $count, string $boolean): static
    {
        $hasQuery->mergeConstraintsFrom($relation->getQuery());

        return $this->canUseExistsForExistenceCheck($operator, $count)
                ? $this->addWhereExistsQuery($hasQuery->toBase(), $boolean, $operator === '<' && $count === 1)
                : $this->addWhereCountQuery($hasQuery->toBase(), $operator, $count, $boolean);
    }

    /**
     * Fusionne les contraintes where d'une autre requête dans la requête actuelle.
     */
    public function mergeConstraintsFrom(Builder $from)
    {
        $whereBindings = $from->getQuery()->bindings->getOrdered(['where']);

        $wheres = $from->getQuery()->getTable() !== $this->getQuery()->getTable()
            ? $this->requalifyWhereTables(
                $from->getQuery()->wheres,
                $from->getQuery()->getTable(),
                $this->getModel()->getTable(),
            ) : $from->getQuery()->wheres;

        // Ici, nous avons une autre requête dont nous voulons fusionner les contraintes where. Nous copierons
        // toutes les contraintes where de la requête ainsi que supprimerons toutes les portées globales que la
        // requête pourrait avoir supprimées. Ensuite, nous nous retournerons nous-mêmes avec la fusion terminée.
        return $this->withoutGlobalScopes(
            $from->removedScopes(),
        )->mergeWheres(
            $wheres,
            $whereBindings,
        );
    }

    /**
     * Met à jour le nom de la table pour toutes les colonnes avec un nouveau nom qualifié.
     */
    protected function requalifyWhereTables(array $wheres, string $from, string $to): array
    {
        return (new BaseCollection($wheres))->map(
            static fn ($where) => (new BaseCollection($where))->map(
                static fn ($value) => is_string($value) && str_starts_with($value, $from . '.')
                    ? $to . '.' . Text::afterLast($value, '.')
                    : $value,
            ),
        )->toArray();
    }

    /**
     * Ajoute une clause de sous-requête de comptage à cette requête.
     */
    protected function addWhereCountQuery(BaseBuilder $query, string $operator = '>=', Expression|int $count = 1, string $boolean = 'and'): static
    {
        $this->query->bindings->merge($query->bindings);

        return $this->where(
            new Expression('(' . $query->toSql() . ')'),
            $operator,
            is_numeric($count) ? new Expression($count) : $count,
            $boolean,
        );
    }

    /**
     * Obtient l'instance de requête de base "has relation".
     *
     * @return Relation<*, *, *>
     */
    protected function getRelationWithoutConstraints(string $relation): Relation
    {
        return Relation::noConstraints(fn () => $this->getModel()->{$relation}());
    }

    /**
     * Vérifie si nous pouvons exécuter une requête "exists" pour optimiser les performances.
     */
    protected function canUseExistsForExistenceCheck(string $operator, Expression|int $count): bool
    {
        return ($operator === '>=' || $operator === '<') && $count === 1;
    }
}
