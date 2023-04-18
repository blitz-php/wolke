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

use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Wolke\Contracts\Dispatcher as DispatcherContract;
use Closure;

class NullDispatcher implements DispatcherContract
{
    use ForwardsCalls;

    /**
     * Create a new event dispatcher instance that does not fire.
     *
     * @param DispatcherContract $dispatcher The underlying event dispatcher instance.
     */
    public function __construct(protected DispatcherContract $dispatcher)
    {
    }

    /**
     * Don't fire an event.
     *
     * @return null
     */
    public function dispatch(string|object $event, mixed $payload = [], bool $halt = false): ?array
    {
        return null;
    }

    /**
     * Don't register an event and payload to be fired later.
     */
    public function push(string $event, array $payload = []): void
    {
    }

    /**
     * Don't dispatch an event.
     *
     * @return null
     */
    public function until(string|object $event, mixed $payload = []): ?array
    {
        return null;
    }

    /**
     * Register an event listener with the dispatcher.
     */
    public function listen(Closure|string|array $events, Closure|string|array|null $listener = null): void
    {
        $this->dispatcher->listen($events, $listener);
    }

    /**
     * Determine if a given event has listeners.
     */
    public function hasListeners(string $eventName): bool
    {
        return $this->dispatcher->hasListeners($eventName);
    }

    /**
     * Register an event subscriber with the dispatcher.
     */
    public function subscribe(object|string $subscriber): void
    {
        $this->dispatcher->subscribe($subscriber);
    }

    /**
     * Flush a set of pushed events.
     */
    public function flush(string $event): void
    {
        $this->dispatcher->flush($event);
    }

    /**
     * Remove a set of listeners from the dispatcher.
     */
    public function forget(string $event): void
    {
        $this->dispatcher->forget($event);
    }

    /**
     * Forget all of the queued listeners.
     */
    public function forgetPushed(): void
    {
        $this->dispatcher->forgetPushed();
    }

    /**
     * Dynamically pass method calls to the underlying dispatcher.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        return $this->forwardCallTo($this->dispatcher, $method, $parameters);
    }
}
