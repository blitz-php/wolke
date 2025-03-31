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
use BlitzPHP\Contracts\Event\EventListenerInterface;
use BlitzPHP\Contracts\Event\EventManagerInterface;
use BlitzPHP\Wolke\Attributes\Observe;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Observers\Dispatcher;
use ReflectionClass;

class Observator implements EventListenerInterface
{
    public function __construct(protected LocatorInterface $locator)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function listen(EventManagerInterface $event): void
    {
        $event->on('app:init', function () {
            Model::setEventDispatcher(new Dispatcher());

            foreach ($this->locator->listFiles('Observers/') as $file) {
                $className = $this->locator->getClassname($file);

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
        });
    }
}
