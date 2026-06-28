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

use BlitzPHP\Contracts\Autoloader\LocatorInterface;
use BlitzPHP\Contracts\Container\ContainerInterface;
use BlitzPHP\Contracts\Database\ConnectionResolverInterface;
use BlitzPHP\Contracts\Event\EventListenerInterface;
use BlitzPHP\Contracts\Event\EventManagerInterface;
use BlitzPHP\Utilities\Pagination\PaginationState;
use BlitzPHP\Wolke\Attributes\Observe;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Observers\Dispatcher;
use ReflectionClass;

class Listener implements EventListenerInterface
{
    public function __construct(protected ContainerInterface $container)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function listen(EventManagerInterface $event): void
    {
        $event->on('app:init', function () {
            Model::setConnectionResolver($this->container->get(ConnectionResolverInterface::class));
            PaginationState::resolveUsing($this->container);

            $this->bootObservables($this->container->get(LocatorInterface::class));
        });
    }

    private function bootObservables(LocatorInterface $locator)
    {
        Model::setEventDispatcher(new Dispatcher());

        foreach ($locator->listFiles('Observers/') as $file) {
            $className = $locator->getClassname($file);

            if ($className === '' || ! class_exists($className)) {
                continue;
            }

            $observable = (new ReflectionClass($className))->getAttributes(Observe::class);

            if ($observable === []) {
                continue;
            }

            $observable = $observable[0]->newInstance();

            $observable->class::observe($className);
        }
    }
}
