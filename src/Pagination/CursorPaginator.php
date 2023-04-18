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

class CursorPaginator extends AbstractCursorPaginator implements Arrayable, ArrayAccess, Countable, IteratorAggregate, Jsonable, JsonSerializable, PaginatorContract
{
    /**
     * Create a new paginator instance.
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
     * Set the items for the paginator.
     */
    protected function setItems(mixed $items): void
    {
        $this->items = $items instanceof Collection ? $items : Collection::make($items);

        $this->hasMore = $this->items->count() > $this->perPage;

        $this->items = $this->items->slice(0, $this->perPage);

        if (null !== $this->cursor && $this->cursor->pointsToPreviousItems()) {
            $this->items = $this->items->reverse()->values();
        }
    }

    /**
     * Render the paginator using the given view.
     */
    public function links(?string $view = null, array $data = []): string
    {
        return $this->render($view, $data);
    }

    /**
     * Render the paginator using the given view.
     */
    public function render(?string $view = null, array $data = []): string
    {
        return static::viewFactory()->make($view ?: Paginator::$defaultSimpleView, array_merge($data, [
            'paginator' => $this,
        ]));
    }

    /**
     * Determine if there are more items in the data source.
     */
    public function hasMorePages(): bool
    {
        return (null === $this->cursor && $this->hasMore)
            || (null !== $this->cursor && $this->cursor->pointsToNextItems() && $this->hasMore)
            || (null !== $this->cursor && $this->cursor->pointsToPreviousItems());
    }

    /**
     * Determine if there are enough items to split into multiple pages.
     */
    public function hasPages(): bool
    {
        return ! $this->onFirstPage() || $this->hasMorePages();
    }

    /**
     * Determine if the paginator is on the first page.
     */
    public function onFirstPage(): bool
    {
        return null === $this->cursor || ($this->cursor->pointsToPreviousItems() && ! $this->hasMore);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'data'          => $this->items->toArray(),
            'path'          => $this->path(),
            'per_page'      => $this->perPage(),
            'next_page_url' => $this->nextPageUrl(),
            'prev_page_url' => $this->previousPageUrl(),
        ];
    }

    /**
     * Convert the object into something JSON serializable.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the object to its JSON representation.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }
}
