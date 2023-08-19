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

use BlitzPHP\Traits\Macroable;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Contracts\Dispatcher as DispatcherContract;
use BlitzPHP\Wolke\Support\ReflectsClosures;
use Closure;

class Dispatcher implements DispatcherContract
{
    use Macroable;
    use ReflectsClosures;

    /**
     * The registered event listeners.
     */
    protected array $listeners = [];

    /**
     * The wildcard listeners.
     */
    protected array $wildcards = [];

    /**
     * The cached wildcard listeners.
     */
    protected array $wildcardsCache = [];

    /**
     * Register an event listener with the dispatcher.
     */
    public function listen(array|Closure|string $events, null|array|Closure|string $listener = null): void
    {
        if ($events instanceof Closure) {
            $this->listen($this->firstClosureParameterType($events), $events);

            return;
        }

        foreach ((array) $events as $event) {
            if (Text::contains($event, '*')) {
                $this->setupWildcardListen($event, $listener);
            } else {
                $this->listeners[$event][] = $this->makeListener($listener);
            }
        }
    }

    /**
     * Setup a wildcard listener callback.
     *
     * @param string         $event
     * @param Closure|string $listener
     *
     * @return void
     */
    protected function setupWildcardListen($event, $listener)
    {
        $this->wildcards[$event][] = $this->makeListener($listener, true);

        $this->wildcardsCache = [];
    }

    /**
     * Determine if a given event has listeners.
     */
    public function hasListeners(string $eventName): bool
    {
        return isset($this->listeners[$eventName])
               || isset($this->wildcards[$eventName])
               || $this->hasWildcardListeners($eventName);
    }

    /**
     * Determine if the given event has any wildcard listeners.
     */
    public function hasWildcardListeners(string $eventName): bool
    {
        foreach ($this->wildcards as $key => $listeners) {
            if (Text::is($key, $eventName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register an event and payload to be fired later.
     */
    public function push(string $event, array $payload = []): void
    {
        $this->listen($event . '_pushed', function () use ($event, $payload) {
            $this->dispatch($event, $payload);
        });
    }

    /**
     * Flush a set of pushed events.
     */
    public function flush(string $event): void
    {
        $this->dispatch($event . '_pushed');
    }

    /**
     * Register an event subscriber with the dispatcher.
     */
    public function subscribe(object|string $subscriber): void
    {
        $subscriber = $this->resolveSubscriber($subscriber);

        $events = $subscriber->subscribe($this);

        if (is_array($events)) {
            foreach ($events as $event => $listeners) {
                foreach ($listeners as $listener) {
                    $this->listen($event, $listener);
                }
            }
        }
    }

    /**
     * Resolve the subscriber instance.
     */
    protected function resolveSubscriber(object|string $subscriber): object
    {
        if (is_string($subscriber)) {
            return new $subscriber();
        }

        return $subscriber;
    }

    /**
     * Fire an event until the first non-null response is returned.
     */
    public function until(object|string $event, mixed $payload = []): ?array
    {
        return $this->dispatch($event, $payload, true);
    }

    /**
     * Fire an event and call the listeners.
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): ?array
    {
        // When the given "event" is actually an object we will assume it is an event
        // object and use the class as the event name and this event itself as the
        // payload to the handler, which makes object based events quite simple.
        [$event, $payload] = $this->parseEventAndPayload(
            $event,
            $payload
        );

        $responses = [];

        foreach ($this->getListeners($event) as $listener) {
            $response = $listener($event, $payload);

            // If a response is returned from the listener and event halting is enabled
            // we will just return this response, and not call the rest of the event
            // listeners. Otherwise we will add the response on the response list.
            if ($halt && null !== $response) {
                return (array) $response;
            }

            // If a boolean false is returned from a listener, we will stop propagating
            // the event to any further listeners down in the chain, else we keep on
            // looping through the listeners and firing every one in our sequence.
            if ($response === false) {
                break;
            }

            $responses[] = $response;
        }

        return $halt ? null : $responses;
    }

    /**
     * Parse the given event and payload and prepare them for dispatching.
     */
    protected function parseEventAndPayload(mixed $event, mixed $payload): array
    {
        if (is_object($event)) {
            [$payload, $event] = [[$event], get_class($event)];
        }

        return [$event, Arr::wrap($payload)];
    }

    /**
     * Get all of the listeners for a given event name.
     */
    public function getListeners(string $eventName): array
    {
        $listeners = $this->listeners[$eventName] ?? [];

        $listeners = array_merge(
            $listeners,
            $this->wildcardsCache[$eventName] ?? $this->getWildcardListeners($eventName)
        );

        return class_exists($eventName, false)
            ? $this->addInterfaceListeners($eventName, $listeners)
            : $listeners;
    }

    /**
     * Get the wildcard listeners for the event.
     */
    protected function getWildcardListeners(string $eventName): array
    {
        $wildcards = [];

        foreach ($this->wildcards as $key => $listeners) {
            if (Text::is($key, $eventName)) {
                $wildcards = array_merge($wildcards, $listeners);
            }
        }

        return $this->wildcardsCache[$eventName] = $wildcards;
    }

    /**
     * Add the listeners for the event's interfaces to the given array.
     */
    protected function addInterfaceListeners(string $eventName, array $listeners = []): array
    {
        foreach (class_implements($eventName) as $interface) {
            if (isset($this->listeners[$interface])) {
                foreach ($this->listeners[$interface] as $names) {
                    $listeners = array_merge($listeners, (array) $names);
                }
            }
        }

        return $listeners;
    }

    /**
     * Register an event listener with the dispatcher.
     */
    public function makeListener(Closure|string $listener, bool $wildcard = false): Closure
    {
        if (is_string($listener)) {
            return $this->createClassListener($listener, $wildcard);
        }

        if (is_array($listener) && isset($listener[0]) && is_string($listener[0])) {
            return $this->createClassListener($listener, $wildcard);
        }

        return static function ($event, $payload) use ($listener, $wildcard) {
            if ($wildcard) {
                return $listener($event, $payload);
            }

            return $listener(...array_values($payload));
        };
    }

    /**
     * Create a class based listener using the IoC container.
     */
    public function createClassListener(string $listener, bool $wildcard = false): Closure
    {
        return function ($event, $payload) use ($listener, $wildcard) {
            if ($wildcard) {
                return ($this->createClassCallable($listener))($event, $payload);
            }

            $callable = $this->createClassCallable($listener);

            return $callable(...array_values($payload));
        };
    }

    /**
     * Create the class based event callable.
     */
    protected function createClassCallable(array|string $listener): callable
    {
        [$class, $method] = is_array($listener)
            ? $listener
            : $this->parseClassCallable($listener);

        if (! method_exists($class, $method)) {
            $method = '__invoke';
        }

        $listener = new $class();

        return [$listener, $method];
    }

    /**
     * Parse the class listener into class and method.
     */
    protected function parseClassCallable(string $listener): array
    {
        return Text::parseCallback($listener, 'handle');
    }

    /**
     * Remove a set of listeners from the dispatcher.
     */
    public function forget(string $event): void
    {
        if (Text::contains($event, '*')) {
            unset($this->wildcards[$event]);
        } else {
            unset($this->listeners[$event]);
        }

        foreach ($this->wildcardsCache as $key => $listeners) {
            if (Text::is($event, $key)) {
                unset($this->wildcardsCache[$key]);
            }
        }
    }

    /**
     * Forget all of the pushed listeners.
     */
    public function forgetPushed(): void
    {
        foreach ($this->listeners as $key => $value) {
            if (Text::endsWith($key, '_pushed')) {
                $this->forget($key);
            }
        }
    }
}
