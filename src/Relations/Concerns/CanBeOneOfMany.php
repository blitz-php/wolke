<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Relations\Concerns;

use BlitzPHP\Database\Builder\JoinClause;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Invade\Invader;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Wolke\Builder;
use Closure;
use InvalidArgumentException;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\Concerns\CanBeOneOfMany</a>
 */
trait CanBeOneOfMany
{
    /**
     * Détermine si la relation est une relation one-of-many.
     */
    protected bool $isOneOfMany = false;

    /**
     * Le nom de la relation.
     */
    protected string $relationName = '';

    /**
     * L'instance de constructeur de sous-requête de jointure interne one of many.
     */
    protected ?Builder $oneOfManySubQuery = null;

    /**
     * Ajoute des contraintes pour la sous-requête de jointure interne pour les relations one of many.
     *
     * @param Builder<*>  $query
     * @param array|string|null $aggregate
     */
    abstract public function addOneOfManySubQueryConstraints(Builder $query, ?string $column = null, $aggregate = null): void;

    /**
     * Obtient les colonnes qui déterminent les groupes de relations.
     *
     * @return array|string
     */
    abstract public function getOneOfManySubQuerySelectColumns();

    /**
     * Ajoute des contraintes de requête de jointure pour les relations one of many.
     */
    abstract public function addOneOfManyJoinSubQueryConstraints(JoinClause $join): void;

    /**
     * Indique que la relation est un résultat unique d'une relation un-à-plusieurs plus large.
     *
     * @throws InvalidArgumentException
     */
    public function ofMany(array|Closure|string|null $column = 'id', Closure|string|null $aggregate = 'MAX', ?string $relation = null): static
    {
        $this->isOneOfMany = true;

        $this->relationName = $relation ?: $this->getDefaultOneOfManyJoinAlias(
            $this->guessRelationship(),
        );

        $keyName = $this->query->getModel()->getKeyName();

        $columns = is_string($columns = $column) ? [
            $column  => $aggregate,
            $keyName => $aggregate,
        ] : $column;

        if (! array_key_exists($keyName, $columns)) {
            $columns[$keyName] = 'MAX';
        }

        if ($aggregate instanceof Closure) {
            $closure = $aggregate;
        }

        foreach ($columns as $column => $aggregate) {
            if (! in_array(strtolower($aggregate), ['min', 'max'], true)) {
                throw new InvalidArgumentException("Agrégat [{$aggregate}] invalide utilisé dans la relation ofMany. Agrégats disponibles : MIN, MAX");
            }

            $subQuery = $this->newOneOfManySubQuery(
                $this->getOneOfManySubQuerySelectColumns(),
                array_merge([$column], $previous['columns'] ?? []),
                $aggregate,
            );

            if (isset($previous)) {
                $this->addOneOfManyJoinSubQuery($subQuery, $previous['subQuery'], $previous['column']);
            }
            if (isset($closure)) {
                $closure($subQuery);
            }
            if (! isset($previous)) {
                $this->oneOfManySubQuery = $subQuery;
            }

            if (array_key_last($columns) === $column) {
                $this->addOneOfManyJoinSubQuery(
                    $this->query,
                    $subQuery,
                    array_merge([$column], $previous['columns'] ?? []),
                );
            }

            $previous = [
                'subQuery' => $subQuery,
                'columns'  => array_merge([$column], $previous['columns'] ?? []),
            ];
        }

        $this->addConstraints();

        $columns = $this->query->getQuery()->columns;

        if ([] === $columns || $columns === ['*']) {
            $this->select([$this->qualifyColumn('*')]);
        }

        return $this;
    }

    /**
     * Indique que la relation est le dernier résultat unique d'une relation un-à-plusieurs plus large.
     */
    public function latestOfMany(array|string|null $column = 'id', ?string $relation = null): static
    {
        return $this->ofMany(
            Collection::wrap($column)->mapWithKeys(static fn ($column) => [$column => 'MAX'])->all(),
            'MAX',
            $relation,
        );
    }

    /**
     * Indique que la relation est le premier résultat unique d'une relation un-à-plusieurs plus large.
     */
    public function oldestOfMany(array|string|null $column = 'id', ?string $relation = null): self
    {
        return $this->ofMany(
            Collection::wrap($column)->mapWithKeys(static fn ($column) => [$column => 'MIN'])->all(),
            'MIN',
            $relation,
        );
    }

    /**
     * Obtient l'alias par défaut pour la clause de jointure interne one of many.
     */
    protected function getDefaultOneOfManyJoinAlias(string $relation): string
    {
        return $relation === $this->query->getModel()->getTable()
            ? $relation . '_of_many'
            : $relation;
    }

    /**
     * Obtient une nouvelle requête pour le modèle lié, regroupant la requête par la colonne donnée, souvent la clé étrangère de la relation.
     *
     * @param list<string>|null $columns
     *
     * @return Builder<*>
     */
    protected function newOneOfManySubQuery(array|string $groupBy, ?array $columns = null, ?string $aggregate = null): Builder
    {
        $subQuery = $this->query->getModel()
            ->newQuery()
            ->withoutGlobalScopes($this->removedScopes());

        foreach (Arr::wrap($groupBy) as $group) {
            $subQuery->groupBy($this->qualifyRelatedColumn($group));
        }

        if (null !== $columns) {
            foreach ($columns as $key => $column) {
                $aggregatedColumn = $subQuery->qualifyColumn($column);

                if ($key === 0) {
                    $aggregatedColumn = "{$aggregate}({$aggregatedColumn})";
                } else {
                    $aggregatedColumn = "min({$aggregatedColumn})";
                }

                $subQuery->selectRaw($aggregatedColumn . ' as ' . $column . '_aggregate');
            }
        }

        $this->addOneOfManySubQueryConstraints($subQuery, column: null, aggregate: $aggregate);

        return $subQuery;
    }

    /**
     * Ajoute la sous-requête de jointure à la requête donnée sur la colonne donnée et la clé étrangère de la relation.
     *
     * @param Builder<*> $parent
     * @param Builder<*> $subQuery
     * @param list<string> $on
     */
    protected function addOneOfManyJoinSubQuery(Builder $parent, Builder $subQuery, array $on): void
    {
        $parent->beforeQuery(function ($parent) use ($subQuery, $on) {
            $subQuery->applyBeforeQueryCallbacks();

            $parent->joinSub($subQuery, $this->relationName, function ($join) use ($on) {
                foreach ($on as $onColumn) {
                    $join->on($this->qualifySubSelectColumn($onColumn . '_aggregate'), '=', $this->qualifyRelatedColumn($onColumn));
                }

                $this->addOneOfManyJoinSubQueryConstraints($join, $on);
            });
        });
    }

    /**
     * Fusionne les jointures de la requête de relation dans le constructeur de requête donné.
     */
    protected function mergeOneOfManyJoinsTo(Builder $query): void
    {
        Invader::make($query->getQuery())->beforeQueryCallbacks = $this->query->getQuery()->beforeQueryCallbacks;

        $query->applyBeforeQueryCallbacks();
    }

    /**
     * Obtient le constructeur de requête qui contiendra les contraintes de relation.
     *
     * @return Builder<*>
     */
    protected function getRelationQuery(): Builder
    {
        return $this->isOneOfMany()
            ? $this->oneOfManySubQuery
            : $this->query;
    }

    /**
     * Obtient l'instance de constructeur de sous-requête de jointure interne one of many.
     *
     * @return Builder<*>|null
     */
    public function getOneOfManySubQuery(): ?Builder
    {
        return $this->oneOfManySubQuery;
    }

    /**
     * Obtient le nom de colonne qualifié pour la relation one-of-many en utilisant l'alias de la requête de jointure sous-sélectionnée.
     */
    public function qualifySubSelectColumn(string $column): string
    {
        return $this->getRelationName() . '.' . Helpers::last(explode('.', $column));
    }

    /**
     * Qualifie la colonne liée en utilisant le nom de la table liée si elle n'est pas déjà qualifiée.
     */
    protected function qualifyRelatedColumn(string $column): string
    {
        return $this->query->getModel()->qualifyColumn($column);
    }

    /**
     * Devine le nom de la relation "hasOne" via la trace.
     */
    protected function guessRelationship(): string
    {
        return debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2]['function'];
    }

    /**
     * Détermine si la relation est une relation one-of-many.
     */
    public function isOneOfMany(): bool
    {
        return $this->isOneOfMany;
    }

    /**
     * Obtient le nom de la relation.
     */
    public function getRelationName(): string
    {
        return $this->relationName;
    }
}
