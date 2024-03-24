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
use BlitzPHP\Traits\Conditionable;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\Iterable\LazyCollection;
use BlitzPHP\Utilities\Support\Invader;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Exceptions\MultipleRecordsFoundException;
use BlitzPHP\Wolke\Exceptions\RecordsNotFoundException;
use BlitzPHP\Wolke\Pagination\Cursor;
use BlitzPHP\Wolke\Pagination\CursorPaginator;
use BlitzPHP\Wolke\Pagination\LengthAwarePaginator;
use BlitzPHP\Wolke\Pagination\Paginator;
use InvalidArgumentException;
use RuntimeException;

trait BuildsQueries
{
    use Conditionable;

    /**
     * All of the available clause operators.
     *
     * @var string[]
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
     * Merge an array of where clauses and bindings.
     */
    public function mergeWheres(array $wheres, array $bindings): static
    {
        Invader::make($this->query)->query_keys = array_merge(Invader::make($this->query)->query_keys, (array) $wheres);

        Invader::make($this->query)->query_values = array_merge(Invader::make($this->query)->query_values, (array) $bindings);

        return $this;
    }

    /**
     * Add an exists clause to the query.
     */
    public function addWhereExistsQuery(BaseBuilder $query, string $boolean = 'and', bool $not = false): self
    {
        $type   = $not ? 'NOT EXISTS' : 'EXISTS';
        $fields = implode(', ', Invader::make($query)->fields);

        if ($boolean === 'and') {
            $query->where("{$type} ({$fields})", null, false);
        } else {
            $query->orWhere("{$type} ({$fields})", null, false);
        }

        return $this;
    }

    /**
     * Get a lazy collection for the given query.
     */
    public function cursor(): LazyCollection
    {
        return new LazyCollection(function () {
            yield from $this->query->db()->query($this->toSql())->getResult();
        });
    }

    /**
     * Determine if any rows exist for the current query.
     */
    public function exists(): bool
    {
        $results = $this->query
            ->db()
            ->query("select exists({$this->toSql()}) as 'exists'")
            ->resultArray();

        // If the results has rows, we will get the row and see if the exists column is a
        // boolean true. If there is no results for this query we will return false as
        // there are no rows for this query at all and we can return that info here.
        if (isset($results[0])) {
            $results = (array) $results[0];

            return (bool) $results['exists'];
        }

        return false;
    }

    /**
     * Determine if no rows exist for the current query.
     */
    public function doesntExist(): bool
    {
        return ! $this->exists();
    }

    /**
     * Retrieve the "count" result of the query.
     */
    public function count(string $columns = '*'): int
    {
        return $this->query->count($columns);
    }

    /**
     * Prepare the value and operator for a where clause.
     *
     * @throws InvalidArgumentException
     */
    public function prepareValueAndOperator(string $value, ?string $operator, bool $useDefault = false): array
    {
        if ($useDefault) {
            return [$operator, '='];
        }
        if ($this->invalidOperatorAndValue($operator, $value)) {
            throw new InvalidArgumentException('Illegal operator and value combination.');
        }

        return [$value, $operator];
    }

    /**
     * Determine if the given operator and value combination is legal.
     *
     * Prevents using Null values with invalid operators.
     */
    protected function invalidOperatorAndValue(string $operator, mixed $value): bool
    {
        return null === $value && in_array($operator, $this->operators, true)
             && ! in_array($operator, ['=', '<>', '!='], true);
    }

    /**
     * Get the SQL representation of the query.
     */
    public function toSql(): string
    {
        return $this->toBase()->sql();
    }

    /**
     * Set the limit and offset for a given page.
     */
    public function forPage(int $page, int $perPage = 15): self
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /**
     * Constrain the query to the next "page" of results after a given ID.
     */
    public function forPageAfterId(int $perPage = 15, ?int $lastId = 0, string $column = 'id'): self
    {
        if (null !== $lastId) {
            $this->where($column, '>', $lastId);
        }

        return $this->orderBy($column, 'asc')
            ->limit($perPage);
    }

    /**
     * Constrain the query to the previous "page" of results before a given ID.
     */
    public function forPageBeforeId(int $perPage = 15, ?int $lastId = 0, string $column = 'id'): self
    {
        if (null !== $lastId) {
            $this->where($column, '<', $lastId);
        }

        return $this->orderBy($column, 'desc')
            ->limit($perPage);
    }

    /**
     * Chunk the results of the query.
     */
    public function chunk(int $count, callable $callback): bool
    {
        $this->enforceOrderBy();

        $page = 1;

        do {
            // We'll execute the query for the given page and get the results. If there are
            // no results we can just break and return from here. When there are results
            // we will call the callback with the current chunk of these results here.
            $results = $this->forPage($page, $count)->get();

            $countResults = $results->count();

            if ($countResults === 0) {
                break;
            }

            // On each chunk result set, we will pass them to the callback and then let the
            // developer take care of everything within the callback, which allows us to
            // keep the memory low for spinning through large result sets for working.
            if ($callback($results, $page) === false) {
                return false;
            }

            unset($results);

            $page++;
        } while ($countResults === $count);

        return true;
    }

    /**
     * Run a map over each item while chunking.
     */
    public function chunkMap(callable $callback, int $count = 1000): Collection
    {
        $collection = Collection::make();

        $this->chunk($count, static function ($items) use ($collection, $callback) {
            $items->each(static function ($item) use ($collection, $callback) {
                $collection->push($callback($item));
            });
        });

        return $collection;
    }

    /**
     * Execute a callback over each item while chunking.
     *
     * @throws RuntimeException
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
     * Chunk the results of a query by comparing IDs.
     */
    public function chunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        return $this->orderedChunkById($count, $callback, $column, $alias);
    }

    /**
     * Chunk the results of a query by comparing IDs in descending order.
     */
    public function chunkByIdDesc(int $count, callable $callback, ?string $column = null, ?string $alias = null): bool
    {
        return $this->orderedChunkById($count, $callback, $column, $alias, descending: true);
    }

    /**
     * Chunk the results of a query by comparing IDs in a given order.
     */
    public function orderedChunkById(int $count, callable $callback, ?string $column = null, ?string $alias = null, bool $descending = false): bool
    {
        $column ??= $this->defaultKeyName();

        $alias ??= $column;

        $lastId = null;

        $page = 1;

        do {
            $clone = clone $this;

            // We'll execute the query for the given page and get the results. If there are
            // no results we can just break and return from here. When there are results
            // we will call the callback with the current chunk of these results here.
            if ($descending) {
                $results = $clone->forPageBeforeId($count, $lastId, $column)->get();
            } else {
                $results = $clone->forPageAfterId($count, $lastId, $column)->get();
            }

            $countResults = $results->count();

            if ($countResults === 0) {
                break;
            }

            // On each chunk result set, we will pass them to the callback and then let the
            // developer take care of everything within the callback, which allows us to
            // keep the memory low for spinning through large result sets for working.
            if ($callback($results, $page) === false) {
                return false;
            }

            $lastId = Arr::dataGet($results->last(), $alias);

            if ($lastId === null) {
                throw new RuntimeException("The chunkById operation was aborted because the [{$alias}] column is not present in the query result.");
            }

            unset($results);

            $page++;
        } while ($countResults === $count);

        return true;
    }

    /**
     * Execute a callback over each item while chunking by ID.
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
     * Query lazily, by chunks of the given size.
     *
     * @throws InvalidArgumentException
     */
    public function lazy(int $chunkSize = 1000): LazyCollection
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size should be at least 1');
        }

        $this->enforceOrderBy();

        return LazyCollection::make(function () use ($chunkSize) {
            $page = 1;

            while (true) {
                $results = $this->forPage($page++, $chunkSize)->get();

                foreach ($results as $result) {
                    yield $result;
                }

                if ($results->count() < $chunkSize) {
                    return;
                }
            }
        });
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs.
     *
     * @throws InvalidArgumentException
     */
    public function lazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return $this->orderedLazyById($chunkSize, $column, $alias);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs in descending order.
     *
     * @throws InvalidArgumentException
     */
    public function lazyByIdDesc(int $chunkSize = 1000, ?string $column = null, ?string $alias = null): LazyCollection
    {
        return $this->orderedLazyById($chunkSize, $column, $alias, true);
    }

    /**
     * Query lazily, by chunking the results of a query by comparing IDs in a given order.
     *
     * @throws InvalidArgumentException
     */
    protected function orderedLazyById(int $chunkSize = 1000, ?string $column = null, ?string $alias = null, bool $descending = false): LazyCollection
    {
        if ($chunkSize < 1) {
            throw new InvalidArgumentException('The chunk size should be at least 1');
        }

        $column ??= $this->defaultKeyName();

        $alias ??= $column;

        return LazyCollection::make(function () use ($chunkSize, $column, $alias, $descending) {
            $lastId = null;

            while (true) {
                $clone = clone $this;

                if ($descending) {
                    $results = $clone->forPageBeforeId($chunkSize, $lastId, $column)->get();
                } else {
                    $results = $clone->forPageAfterId($chunkSize, $lastId, $column)->get();
                }

                foreach ($results as $result) {
                    yield $result;
                }

                if ($results->count() < $chunkSize) {
                    return;
                }

                $lastId = $results->last()->{$alias};

                if ($lastId === null) {
                    throw new RuntimeException("The lazyById operation was aborted because the [{$alias}] column is not present in the query result.");
                }
            }
        });
    }

    /**
     * Execute the query and get the first result.
     *
     * @return Model|object|static|null
     */
    public function first(array|string $columns = ['*'])
    {
        return $this->limit(1)->get($columns)->first();
    }

    /**
     * Execute the query and get the first result if it's the sole matching record.
     *
     * @return Model|object|static|null
     *
     * @throws RecordsNotFoundException
     * @throws MultipleRecordsFoundException
     */
    public function sole(array|string $columns = ['*'])
    {
        $result = $this->limit(2)->get($columns);

        $count = $result->count();

        if ($count === 0) {
            throw new RecordsNotFoundException();
        }

        if ($count > 1) {
            throw new MultipleRecordsFoundException($count);
        }

        return $result->first();
    }

    /**
     * Paginate the given query using a cursor paginator.
     */
    protected function paginateUsingCursor(int $perPage, array|string $columns = ['*'], string $cursorName = 'cursor', null|Cursor|string $cursor = null): CursorPaginator
    {
        if (! $cursor instanceof Cursor) {
            $cursor = is_string($cursor)
                ? Cursor::fromEncoded($cursor)
                : CursorPaginator::resolveCurrentCursor($cursorName, $cursor);
        }

        $orders = $this->ensureOrderForCursorPagination(null !== $cursor && $cursor->pointsToPreviousItems());

        if (null !== $cursor) {
            $addCursorConditions = function (self $builder, $previousColumn, $i) use (&$addCursorConditions, $cursor, $orders) {
                $unionBuilders = isset($builder->unions) ? Helpers::collect($builder->unions)->pluck('query') : Helpers::collect();

                if (null !== $previousColumn) {
                    $originalColumn = $this->getOriginalColumnNameForCursorPagination($this, $previousColumn);

                    $builder->where(
                        $originalColumn,
                        '=',
                        $cursor->parameter($previousColumn)
                    );

                    $unionBuilders->each(function ($unionBuilder) use ($previousColumn, $cursor) {
                        $unionBuilder->where(
                            $this->getOriginalColumnNameForCursorPagination($this, $previousColumn),
                            '=',
                            $cursor->parameter($previousColumn)
                        );

                        $this->addBinding($unionBuilder->getRawBindings()['where'], 'union');
                    });
                }

                $builder->where(function (self $builder) use ($addCursorConditions, $cursor, $orders, $i, $unionBuilders) {
                    ['column' => $column, 'direction' => $direction] = $orders[$i];

                    $originalColumn = $this->getOriginalColumnNameForCursorPagination($this, $column);

                    $builder->where(
                        $originalColumn,
                        $direction === 'asc' ? '>' : '<',
                        $cursor->parameter($column)
                    );

                    if ($i < $orders->count() - 1) {
                        $builder->orWhere(static function (self $builder) use ($addCursorConditions, $column, $i) {
                            $addCursorConditions($builder, $column, $i + 1);
                        });
                    }

                    $unionBuilders->each(function ($unionBuilder) use ($column, $direction, $cursor, $i, $orders, $addCursorConditions) {
                        $unionBuilder->where(function ($unionBuilder) use ($column, $direction, $cursor, $i, $orders, $addCursorConditions) {
                            $unionBuilder->where(
                                $this->getOriginalColumnNameForCursorPagination($this, $column),
                                $direction === 'asc' ? '>' : '<',
                                $cursor->parameter($column)
                            );

                            if ($i < $orders->count() - 1) {
                                $unionBuilder->orWhere(static function (self $builder) use ($addCursorConditions, $column, $i) {
                                    $addCursorConditions($builder, $column, $i + 1);
                                });
                            }

                            $this->addBinding($unionBuilder->getRawBindings()['where'], 'union');
                        });
                    });
                });
            };

            $addCursorConditions($this, null, 0);
        }

        $this->limit($perPage + 1);

        return $this->cursorPaginator($this->get($columns), $perPage, $cursor, [
            'path'       => Paginator::resolveCurrentPath(),
            'cursorName' => $cursorName,
            'parameters' => $orders->pluck('column')->toArray(),
        ]);
    }

    /**
     * Get the original column name of the given column, without any aliasing.
     */
    protected function getOriginalColumnNameForCursorPagination(BaseBuilder|Builder $builder, string $parameter): string
    {
        $columns = $builder instanceof Builder ? Invader::make($builder->getQuery())->fields : Invader::make($builder)->fields;

        if (! empty($columns)) {
            foreach ($columns as $column) {
                if (($position = strripos($column, ' as ')) !== false) {
                    $original = substr($column, 0, $position);

                    $alias = substr($column, $position + 4);

                    if ($parameter === $alias) {
                        return $original;
                    }
                }
            }
        }

        return $parameter;
    }

    /**
     * Pass the query to a given callback.
     *
     * @return self
     */
    public function tap(callable $callback)
    {
        $callback($this);

        return $this;
    }

    /**
     * Apply the callback's query changes if the given "value" is false.
     *
     * @return mixed|self
     */
    public function unless(mixed $value, callable $callback, ?callable $default = null)
    {
        if (! $value) {
            return $callback($this, $value) ?: $this;
        }
        if ($default) {
            return $default($this, $value) ?: $this;
        }

        return $this;
    }

    /**
     * Create a new length-aware paginator instance.
     */
    protected function paginator(Collection $items, int $total, int $perPage, int $currentPage, array $options): LengthAwarePaginator
    {
        return new LengthAwarePaginator($items, $total, $perPage, $currentPage, $options);
    }

    /**
     * Create a new simple paginator instance.
     */
    protected function simplePaginator(Collection $items, int $perPage, int $currentPage, array $options): Paginator
    {
        return new Paginator($items, $perPage, $currentPage, $options);
    }

    /**
     * Create a new cursor paginator instance.
     */
    protected function cursorPaginator(Collection $items, int $perPage, Cursor $cursor, array $options): CursorPaginator
    {
        return new CursorPaginator($items, $perPage, $cursor, $options);
    }
}
