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

interface Paginator
{
    /**
     * Get the URL for a given page.
     */
    public function url(int $page): string;

    /**
     * Add a set of query string values to the paginator.
     *
     * @return $this
     */
    public function appends(null|array|string $key, ?string $value = null);

    /**
     * Get / set the URL fragment to be appended to URLs.
     *
     * @return $this|string
     */
    public function fragment(?string $fragment = null);

    /**
     * The URL for the next page, or null.
     */
    public function nextPageUrl(): ?string;

    /**
     * Get the URL for the previous page, or null.
     */
    public function previousPageUrl(): ?string;

    /**
     * Get all of the items being paginated.
     */
    public function items(): array;

    /**
     * Get the "index" of the first item being paginated.
     */
    public function firstItem(): ?int;

    /**
     * Get the "index" of the last item being paginated.
     */
    public function lastItem(): ?int;

    /**
     * Determine how many items are being shown per page.
     */
    public function perPage(): int;

    /**
     * Determine the current page being paginated.
     */
    public function currentPage(): ?int;

    /**
     * Determine if there are enough items to split into multiple pages.
     */
    public function hasPages(): bool;

    /**
     * Determine if there are more items in the data store.
     */
    public function hasMorePages(): bool;

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
