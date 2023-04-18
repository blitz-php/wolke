<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Contracts;

use BlitzPHP\Wolke\Pagination\Cursor;

interface CursorPaginator
{
    /**
     * Get the URL for a given cursor.
     */
    public function url(?Cursor $cursor): string;

    /**
     * Add a set of query string values to the paginator.
     *
     * @return $this
     */
    public function appends(array|string|null $key, ?string $value = null);

    /**
     * Get / set the URL fragment to be appended to URLs.
     *
     * @return $this|string|null
     */
    public function fragment(?string $fragment = null);

    /**
     * Get the URL for the previous page, or null.
     */
    public function previousPageUrl(): ?string;

    /**
     * The URL for the next page, or null.
     */
    public function nextPageUrl(): ?string;

    /**
     * Get all of the items being paginated.
     */
    public function items(): array;

    /**
     * Get the "cursor" of the previous set of items.
     */
    public function previousCursor(): ?Cursor;

    /**
     * Get the "cursor" of the next set of items.
     */
    public function nextCursor(): ?Cursor;

    /**
     * Determine how many items are being shown per page.
     */
    public function perPage(): int;

    /**
     * Get the current cursor being paginated.
     */
    public function cursor(): ?Cursor;

    /**
     * Determine if there are enough items to split into multiple pages.
     */
    public function hasPages(): bool;

    /**
     * Get the base path for paginator generated URLs.
     */
    public function path(): ?string;

    /**
     * Determine if the list of items is empty or not.
     */
    public function isEmpty(): bool;

    /**
     * Determine if the list of items is not empty.
     */
    public function isNotEmpty(): bool;

    /**
     * Render the paginator using a given view.
     */
    public function render(?string $view = null, array $data = []): string;
}
