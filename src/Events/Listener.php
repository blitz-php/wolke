<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Events;

use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Contracts\Event\EventListenerInterface;
use BlitzPHP\Contracts\Event\EventManagerInterface;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Pagination\AbstractPaginator;
use Psr\Http\Message\ServerRequestInterface;

class Listener implements EventListenerInterface
{
    public function __construct(protected ConnectionResolverInterface $resolver, protected ServerRequestInterface $request)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function listen(EventManagerInterface $event): void
    {
        $event->attach('pre_system', function () {
            AbstractPaginator::currentPathResolver(fn () => $this->request->getUri()->getPath());
            AbstractPaginator::currentPageResolver(fn ($pageName) => Arr::get($this->request->getQueryParams(), $pageName, 1));
            Model::setConnectionResolver($this->resolver);
        });
    }
}
