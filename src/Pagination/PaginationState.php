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

class PaginationState
{
    /**
     * Bind the pagination state resolvers using the given application container as a base.
     */
    public static function resolveUsing(): void
    {
        Paginator::viewFactoryResolver(static fn () => new ViewBridge());

        Paginator::currentPathResolver(static fn () => current_url());

        Paginator::currentPageResolver(static function ($pageName = 'page') {
            $page = Services::request()->getVar($pageName);

            if (filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1) {
                return (int) $page;
            }

            return 1;
        });

        Paginator::queryStringResolver(static fn () => Services::uri()->getQuery());

        CursorPaginator::currentCursorResolver(static fn ($cursorName = 'cursor') => Cursor::fromEncoded(Services::request()->getVar($cursorName)));
    }
}
