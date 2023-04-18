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

use Closure;

interface Dispatcher
{
    /**
     * Register an event listener with the dispatcher.
     */
    public function listen(Closure|string|array $events, Closure|string|array|null $listener = null): void;

    /**
     * Determine if a given event has listeners.
     */
    public function hasListeners(string $eventName): bool;

    /**
     * Register an event subscriber with the dispatcher.
     */
    public function subscribe(object|string $subscriber): void;

    /**
     * Dispatch an event until the first non-null response is returned.
     */
    public function until(string|object $event, mixed $payload = []): ?array;

    /**
     * Dispatch an event and call the listeners.
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): ?array;

    /**
     * Register an event and payload to be fired later.
     */
    public function push(string $event, array $payload = []): void;

    /**
     * Flush a set of pushed events.
     */
    public function flush(string $event): void;

    /**
     * Remove a set of listeners from the dispatcher.
     */
    public function forget(string $event): void;

    /**
     * Forget all of the queued listeners.
     */
    public function forgetPushed(): void;
}
