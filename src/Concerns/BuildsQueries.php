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

use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Database\Builder\Concerns\BuildsQueries as BaseBuildsQueries;
use BlitzPHP\Database\Query\Expression;
use BlitzPHP\Utilities\Invade\Invader;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\Iterable\LazyCollection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Pagination\Cursor;
use BlitzPHP\Wolke\Pagination\CursorPaginator;
use BlitzPHP\Wolke\Pagination\LengthAwarePaginator;
use BlitzPHP\Wolke\Pagination\Paginator;
use InvalidArgumentException;

/**
 * @template TValue of Model|object|static
 * 
 * @mixin \BlitzPHP\Wolke\Builder
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\BuildsQueries</a>
 */
trait BuildsQueries
{
    use BaseBuildsQueries;

    /**
     * Tous les opérateurs de clause disponibles.
     *
     * @var list<string>
     */
    public array $operators = [
        '=', '<', '>', '<=', '>=', '<>', '!=', '<=>',
        'like', 'like binary', 'not like', 'ilike',
        '&', '|', '^', '<<', '>>', '&~', 'is', 'is not',
        'rlike', 'not rlike', 'regexp', 'not regexp',
        '~', '~*', '!~', '!~*', 'similar to',
        'not similar to', 'not ilike', '~~*', '!~~*',
    ];

    /**
     * Fusionne un tableau de clauses where et de liaisons.
     */
    public function mergeWheres(array $wheres, array $bindings): static
    {
        $wheres = array_merge($wheres, $bindings);
        $keys   = array_keys($wheres);
        $values = array_values($wheres);

        foreach ($wheres as $key => $value) {
            $key   = is_string($key) ? trim($key) : $key;
            $value = is_string($value) ? trim($value) : $value;

            $this->query->where($key, $value);
        }

        Invader::make($this->query)->query_keys = array_merge(Invader::make($this->query)->query_keys, (array) $wheres);

        Invader::make($this->query)->query_values = array_merge(Invader::make($this->query)->query_values, (array) $bindings);

        return $this;
    }

    /**
     * Ajoute une clause exists à la requête.
     */
    public function addWhereExistsQuery(BaseBuilder $query, string $boolean = 'and', bool $not = false): static
    {
        $wheres = $this->query->wheres;
        $wheres[] = ['type' => 'exists'] + compact('query', 'boolean', 'not');

        Invader::make($this->query)->wheres = $wheres;
        $this->query->bindings->addMany($query->bindings->getValues());
        
        return $this;
    }

    /**
     * Obtient une collection paresseuse pour la requête donnée.
     */
    public function cursor(): LazyCollection
    {
        return new LazyCollection(function () {
            yield from $this->query->db()->query($this->toSql())->getResult();
        });
    }

    /**
     * Prépare la valeur et l'opérateur pour une clause where.
     *
     * @throws InvalidArgumentException
     */
    public function prepareValueAndOperator(string $value, ?string $operator, bool $useDefault = false): array
    {
        if ($useDefault) {
            return [$operator, '='];
        }
        if ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Combinaison opérateur et valeur illégale.');
        }

        return [$value, $operator];
    }

    /**
     * Détermine si la combinaison opérateur et valeur donnée est légale.
     *
     * Empêche l'utilisation de valeurs Null avec des opérateurs invalides.
     */
    protected function invalidOperatorAndValue(?string $operator, mixed $value): bool
    {
        return null === $value && in_array($operator, $this->operators, true)
             && ! in_array($operator, ['=', '<>', '!='], true);
    }

    /**
     * Traite les résultats d'une requête par lots en comparant les ID dans un ordre donné.
     */
    public function orderedChunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null, bool $descending = false): bool
    {
        $column ??= $this->defaultKeyName();

        return $this->query->orderedChunkById($count, $callback, $column, $alias, $descending);
    }

    /**
     * Interroge paresseusement, en traitant les résultats d'une requête par lots en comparant les ID dans un ordre donné.
     *
     * @throws InvalidArgumentException
     */
    protected function orderedLazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null, bool $descending = false): LazyCollection
    {
        $column ??= $this->defaultKeyName();

        return $this->query->orderedLazyById($chunkSize, $column, $alias, $descending);
    }

    /**
     * Exécute la requête et obtient le premier résultat.
     *
     * @return TValue|null
     */
    public function first(array|string $columns = ['*'])
    {
        return $this->limit(1)->get($columns)->first();
    }

    /**
     * Pagine la requête donnée en utilisant un curseur.
     */
    protected function paginateUsingCursor(int $perPage, array|string $columns = ['*'], string $cursorName = 'cursor', Cursor|string|null $cursor = null): CursorPaginator
    {
        if (! $cursor instanceof Cursor) {
            $cursor = is_string($cursor)
                ? Cursor::fromEncoded($cursor)
                : CursorPaginator::resolveCurrentCursor($cursorName, $cursor);
        }

        $orders = $this->ensureOrderForCursorPagination(null !== $cursor && $cursor->pointsToPreviousItems());

        if (null !== $cursor) {
            // Réinitialise les liaisons union pour pouvoir ajouter le curseur where à la bonne position...
            // Invader::make($this->query)->unions = [];    

            $addCursorConditions = function (self $builder, $previousColumn, $originalColumn, $i) use (&$addCursorConditions, $cursor, $orders) {
                $unionBuilders = $builder->query->unions !== [] 
                    ? (new Collection($builder->query->unions))->pluck('query') 
                    : new Collection();

                if (null !== $previousColumn) {
                    $originalColumn = $this->getOriginalColumnNameForCursorPagination($this, $previousColumn);

                    $builder->where(
                        Text::contains($originalColumn, ['(', ')']) ? new Expression($originalColumn) : $originalColumn,
                        '=',
                        $cursor->parameter($previousColumn)
                    );

                    $unionBuilders->each(function ($unionBuilder) use ($previousColumn, $cursor) {
                        $unionBuilder->where(
                            $this->getOriginalColumnNameForCursorPagination($unionBuilder, $previousColumn),
                            '=',
                            $cursor->parameter($previousColumn)
                        );

                        $this->query->bindings->addMany($unionBuilder->bindings->getValues());
                    });
                }

                $builder->where(function (self $secondBuilder) use ($addCursorConditions, $cursor, $orders, $i, $originalColumn, $unionBuilders) {
                    ['column' => $column, 'direction' => $direction] = $orders[$i];

                    $originalColumn = $this->getOriginalColumnNameForCursorPagination($this, $column);

                    $secondBuilder->where(
                        Text::contains($originalColumn, ['(', ')']) ? new Expression($originalColumn) : $originalColumn,
                        $direction === 'asc' ? '>' : '<',
                        $cursor->parameter($column)
                    );

                    if ($i < $orders->count() - 1) {
                        $secondBuilder->orWhere(static function (self $thirdBuilder) use ($addCursorConditions, $column, $originalColumn, $i) {
                            $addCursorConditions($thirdBuilder, $column, $originalColumn, $i + 1);
                        });
                    }

                    $unionBuilders->each(function ($unionBuilder) use ($column, $direction, $cursor, $i, $orders, $addCursorConditions, $originalColumn) {
                        $unionWheres = $unionBuilder->bindings->getValues();
                        $originalColumn = $this->getOriginalColumnNameForCursorPagination($unionBuilder, $column);
                        
                        $unionBuilder->where(function ($unionBuilder) use ($column, $direction, $cursor, $i, $orders, $addCursorConditions, $originalColumn, $unionWheres) {
                            $unionBuilder->where(
                                $originalColumn,
                                $direction === 'asc' ? '>' : '<',
                                $cursor->parameter($column)
                            );

                            if ($i < $orders->count() - 1) {
                                $unionBuilder->orWhere(static function (self $fourthBuilder) use ($addCursorConditions, $column, $originalColumn, $i) {
                                    $addCursorConditions($fourthBuilder, $column, $originalColumn, $i + 1);
                                });
                            }

                            $this->query->bindings->addMany(array_merge($unionWheres, $unionBuilder->bindings->getValues()));
                        });
                    });
                });
            };

            $addCursorConditions($this, null, null, 0);
        }

        $this->limit($perPage + 1);

        return $this->cursorPaginator($this->get($columns), $perPage, $cursor, [
            'path'       => Paginator::resolveCurrentPath(),
            'cursorName' => $cursorName,
            'parameters' => $orders->pluck('column')->toArray(),
        ]);
    }

    /**
     * Obtient le nom de colonne original de la colonne donnée, sans alias.
     */
    protected function getOriginalColumnNameForCursorPagination(BaseBuilder|Builder $builder, string $parameter): string
    {
        $columns = $builder instanceof Builder 
            ? $builder->getQuery()->columns 
            : $builder->columns;

        foreach ($columns as $column) {
            if (($position = strripos($column, ' as ')) !== false) {
                $original = substr($column, 0, $position);

                $alias = substr($column, $position + 4);

                if ($parameter === $alias) {
                    return $original;
                }
            }
        }

        return $parameter;
    }

    /**
     * Crée une nouvelle instance de paginateur aware de la longueur.
     */
    protected function paginator(Collection $items, int $total, int $perPage, int $currentPage, array $options): LengthAwarePaginator
    {
        return new LengthAwarePaginator($items, $total, $perPage, $currentPage, $options);
    }

    /**
     * Crée une nouvelle instance de paginateur simple.
     */
    protected function simplePaginator(Collection $items, int $perPage, int $currentPage, array $options): Paginator
    {
        return new Paginator($items, $perPage, $currentPage, $options);
    }

    /**
     * Crée une nouvelle instance de paginateur à curseur.
     */
    protected function cursorPaginator(Collection $items, int $perPage, Cursor $cursor, array $options): CursorPaginator
    {
        return new CursorPaginator($items, $perPage, $cursor, $options);
    }
}
