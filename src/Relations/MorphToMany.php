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
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Model;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 * @template TPivotModel of Pivot = MorphPivot
 * @template TAccessor of string = 'pivot'
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel, TPivotModel, TAccessor>
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\MorphToMany</a>
 */
class MorphToMany extends BelongsToMany
{
    /**
     * Le type de la relation polymorphe.
     */
    protected string $morphType;

    /**
     * Le nom de classe de la contrainte de type morph.
     */
    protected string $morphClass;

    /**
     * Crée une nouvelle instance de relation morph to many.
     *
     * @param Builder<TRelatedModel>  $query
     * @param TDeclaringModel  $parent
     * @param bool $inverse Indique si nous connectons l'inverse de la relation.
     *                      Cela affecte principalement la contrainte morphClass.
     *
     * @return void
     */
    public function __construct(
        Builder $query,
        Model $parent,
        string $name,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $relationName = null,
        protected bool $inverse = false
    ) {
        $this->morphType  = $name . '_type';
        $this->morphClass = $inverse ? $query->getModel()->getMorphClass() : $parent->getMorphClass();

        parent::__construct(
            $query,
            $parent,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName
        );
    }

    /**
     * Définit la clause where pour la requête de relation.
     */
    protected function addWhereConstraints(): static
    {
        parent::addWhereConstraints();

        $this->query->where($this->qualifyPivotColumn($this->morphType), $this->morphClass);

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function addEagerConstraints(array $models): void
    {
        parent::addEagerConstraints($models);

        $this->query->where($this->qualifyPivotColumn($this->morphType), $this->morphClass);
    }

    /**
     * Crée un nouvel enregistrement d'attachement pivot.
     */
    protected function baseAttachRecord(int|string $id, bool $timed): array
    {
        return Arr::add(
            parent::baseAttachRecord($id, $timed),
            $this->morphType,
            $this->morphClass
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param array|mixed $columns
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        return parent::getRelationExistenceQuery($query, $parentQuery, $columns)->where(
            $this->qualifyPivotColumn($this->morphType),
            $this->morphClass
        );
    }

    /**
     * Obtient les modèles pivot actuellement attachés, filtrés par les clés du modèle lié.
     *
     * @return IterableCollection<int, TPivotModel>
     */
    protected function getCurrentlyAttachedPivotsForIds(mixed $ids = null): IterableCollection
    {
        return parent::getCurrentlyAttachedPivotsForIds($ids)->map(function ($record) {
            return $record instanceof MorphPivot
                ? $record->setMorphType($this->morphType)
                    ->setMorphClass($this->morphClass)
                : $record;
        });
    }

    /**
     * Crée un nouveau constructeur de requête pour la table pivot.
     */
    public function newPivotQuery(): BaseBuilder
    {
        return parent::newPivotQuery()->where($this->morphType, $this->morphClass);
    }

    /**
     * Crée une nouvelle instance de modèle pivot.
     * 
     * @return TPivotModel
     */
    public function newPivot(array $attributes = [], bool $exists = false): Pivot
    {
        $using = $this->using;

        $attributes = array_merge([$this->morphType => $this->morphClass], $attributes);

        $pivot = $using ? $using::fromRawAttributes($this->parent, $attributes, $this->table, $exists)
                        : MorphPivot::fromAttributes($this->parent, $attributes, $this->table, $exists);

        $pivot->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey)
            ->setRelatedModel($this->related)
            ->setMorphType($this->morphType)
            ->setMorphClass($this->morphClass);

        return $pivot;
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
            $this->morphType,
            ...$this->pivotColumns,
        ]))
            ->map(fn ($column) => $this->qualifyPivotColumn($column).' as pivot_' . $column)
            ->unique()
            ->all();
    }

    /**
     * Obtient le nom du "type" de clé étrangère.
     */
    public function getMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Obtient le type morph complètement qualifié pour la relation.
     */
    public function getQualifiedMorphTypeName(): string
    {
        return $this->qualifyPivotColumn($this->morphType);
    }

    /**
     * Obtient le nom de classe du modèle parent.
     *
     * @return class-string<TRelatedModel>
     */
    public function getMorphClass(): string
    {
        return $this->morphClass;
    }

    /**
     * Obtient l'indicateur pour une relation inverse.
     */
    public function getInverse(): bool
    {
        return $this->inverse;
    }
}
