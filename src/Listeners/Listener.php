<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Listeners;

use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Contracts\Event\EventListenerInterface;
use BlitzPHP\Contracts\Event\EventManagerInterface;
use BlitzPHP\Contracts\View\RendererInterface;
use BlitzPHP\Database\Connection\BaseConnection;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Pagination\AbstractPaginator;
use Psr\Http\Message\ServerRequestInterface;

class Listener implements EventListenerInterface
{
    /**
     * @var \BlitzPHP\Http\Request
     */
    protected ServerRequestInterface $request;

    public function __construct(protected ContainerInterface $container)
    {
        BaseConnection::$useHashedAliases = false;

        $this->request = $container->get(ServerRequestInterface::class);
    }

    /**
     * {@inheritDoc}
     */
    public function listen(EventManagerInterface $event): void
    {
        $event->on('pre_system', function () {
            AbstractPaginator::currentPathResolver(fn () => $this->request->fullUrl());
            AbstractPaginator::currentPageResolver(fn ($pageName) => Arr::get($this->request->getQueryParams(), $pageName, 1));
            AbstractPaginator::viewFactoryResolver(fn() => $this->container->get(RendererInterface::class));
            Model::setConnectionResolver($this->container->get(ConnectionResolverInterface::class));
        });
    }
}
