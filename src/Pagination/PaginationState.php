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

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\View\RendererInterface;
use Psr\Http\Message\ServerRequestInterface;

class PaginationState
{
    /**
     * Bind the pagination state resolvers using the given application container as a base.
     */
    public static function resolveUsing(ContainerInterface $container): void
    {
        /** @var \BlitzPHP\Http\Request */
        $request = $container->get(ServerRequestInterface::class);
        
        Paginator::viewFactoryResolver(fn () => $container->get(RendererInterface::class));
       
        Paginator::currentPathResolver(fn () => $request->fullUrl());
       
        Paginator::currentPageResolver(function ($pageName) use($request) {
            $page = $request->input($pageName);

            if (filter_var($page, FILTER_VALIDATE_INT) !== false && (int) $page >= 1) {
                return (int) $page;
            }

            return 1;
        });

        Paginator::queryStringResolver(static fn () => $request->query());

        CursorPaginator::currentCursorResolver(static fn ($cursorName = 'cursor') => Cursor::fromEncoded($request->input($cursorName)));
    }
}
