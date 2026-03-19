<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Pagination;

use ArrayAccess;
use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Contracts\Support\Jsonable;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Wolke\Contracts\CursorPaginator as PaginatorContract;
use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @extends AbstractCursorPaginator<TKey, TValue>
 *
 * @implements Arrayable<TKey, TValue>
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 * @implements PaginatorContract<TKey, TValue>
 *
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Pagination\CursorPaginator</a>
 */
class CursorPaginator extends AbstractCursorPaginator implements Arrayable, ArrayAccess, Countable, IteratorAggregate, Jsonable, JsonSerializable, PaginatorContract
{
    /**
     * Crée une nouvelle instance de paginateur.
     *
     * @param Arrayable<TKey, TValue>|Collection<TKey, TValue>|iterable<TKey, TValue>|null $items
     * @param array{path: string, query: array, fragment: ?string, pageName: string}       $options
     */
    public function __construct(mixed $items, int $perPage, ?Cursor $cursor = null, array $options = [])
    {
        $this->options = $options;

        foreach ($options as $key => $value) {
            $this->{$key} = $value;
        }

        $this->perPage = $perPage;
        $this->cursor  = $cursor;
        $this->path    = $this->path !== '/' ? rtrim($this->path, '/') : $this->path;

        $this->setItems($items);
    }

    /**
     * Définit les éléments pour le paginateur.
     */
    protected function setItems(mixed $items): void
    {
        $this->items = $items instanceof Collection ? $items : new Collection($items);

        $this->hasMore = $this->items->count() > $this->perPage;

        $this->items = $this->items->slice(0, $this->perPage);

        if (null !== $this->cursor && $this->cursor->pointsToPreviousItems()) {
            $this->items = $this->items->reverse()->values();
        }
    }

    /**
     * Affiche le paginateur en utilisant la vue donnée.
     */
    public function links(?string $view = null, array $data = []): string
    {
        return $this->render($view, $data);
    }

    /**
     * Affiche le paginateur en utilisant la vue donnée.
     */
    public function render(?string $view = null, array $data = []): string
    {
        return static::viewFactory()
            ->addData(array_merge($data, ['paginator' => $this]))
            ->render($view ?: Paginator::$defaultSimpleView);
    }

    /**
     * Détermine s'il y a plus d'éléments dans la source de données.
     */
    public function hasMorePages(): bool
    {
        return (null === $this->cursor && $this->hasMore)
            || (null !== $this->cursor && $this->cursor->pointsToNextItems() && $this->hasMore)
            || (null !== $this->cursor && $this->cursor->pointsToPreviousItems());
    }

    /**
     * Détermine s'il y a assez d'éléments pour diviser en plusieurs pages.
     */
    public function hasPages(): bool
    {
        return ! $this->onFirstPage() || $this->hasMorePages();
    }

    /**
     * Détermine si le paginateur est sur la première page.
     */
    public function onFirstPage(): bool
    {
        return null === $this->cursor || ($this->cursor->pointsToPreviousItems() && ! $this->hasMore);
    }

    /**
     * Détermine si le paginateur est sur la dernière page.
     */
    public function onLastPage(): bool
    {
        return ! $this->hasMorePages();
    }

    /**
     * Obtient l'instance sous forme de tableau.
     */
    public function toArray(): array
    {
        return [
            'data'          => $this->items->toArray(),
            'path'          => $this->path(),
            'per_page'      => $this->perPage(),
            'next_cursor'   => $this->nextCursor()?->encode(),
            'next_page_url' => $this->nextPageUrl(),
            'prev_cursor'   => $this->previousCursor()?->encode(),
            'prev_page_url' => $this->previousPageUrl(),
        ];
    }

    /**
     * Convertit l'objet en quelque chose de sérialisable en JSON.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convertit l'objet en sa représentation JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Convertit l'objet en JSON formaté de façon jolie.
     */
    public function toPrettyJson(int $options = 0): string
    {
        return $this->toJson(JSON_PRETTY_PRINT | $options);
    }
}
