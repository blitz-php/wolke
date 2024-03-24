<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Concerns;

use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Wolke\Attributes\ObservedBy;
use BlitzPHP\Wolke\Contracts\Dispatcher;
use BlitzPHP\Wolke\Events\NullDispatcher;
use Closure;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;

trait HasEvents
{
    /**
     * The event map for the model.
     *
     * Allows for object-based events for native Eloquent events.
     */
    protected array $dispatchesEvents = [];

    /**
     * User exposed observable events.
     *
     * These are extra user-defined events observers may subscribe to.
     */
    protected array $observables = [];

    /**
     * Boot the has event trait for a model.
     *
     * @return void
     */
    public static function bootHasEvents()
    {
        static::observe(static::resolveObserveAttributes());
    }

    /**
     * Resolve the observe class names from the attributes.
     */
    public static function resolveObserveAttributes(): array
    {
        $reflectionClass = new ReflectionClass(static::class);

        return Helpers::collect($reflectionClass->getAttributes(ObservedBy::class))
            ->map(static fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->all();
    }

    /**
     * Register observers with the model.
     *
     * @throws RuntimeException
     */
    public static function observe(array|object|string $classes): void
    {
        $instance = new static();

        foreach (Arr::wrap($classes) as $class) {
            $instance->registerObserver($class);
        }
    }

    /**
     * Register a single observer with the model.
     *
     * @throws RuntimeException
     */
    protected function registerObserver(object|string $class): void
    {
        $className = $this->resolveObserverClassName($class);

        // When registering a model observer, we will spin through the possible events
        // and determine if this observer has that method. If it does, we will hook
        // it into the model's event system, making it convenient to watch these.
        foreach ($this->getObservableEvents() as $event) {
            if (method_exists($class, $event)) {
                static::registerModelEvent($event, $className . '@' . $event);
            }
        }
    }

    /**
     * Resolve the observer's class name from an object or string.
     *
     * @throws InvalidArgumentException
     */
    private function resolveObserverClassName(object|string $class): string
    {
        if (is_object($class)) {
            return get_class($class);
        }

        if (class_exists($class)) {
            return $class;
        }

        throw new InvalidArgumentException('Unable to find observer: ' . $class);
    }

    /**
     * Get the observable event names.
     */
    public function getObservableEvents(): array
    {
        return array_merge(
            [
                'retrieved', 'creating', 'created', 'updating', 'updated',
                'saving', 'saved', 'restoring', 'restored', 'replicating',
                'deleting', 'deleted', 'forceDeleted',
            ],
            $this->observables
        );
    }

    /**
     * Set the observable event names.
     */
    public function setObservableEvents(array $observables): self
    {
        $this->observables = $observables;

        return $this;
    }

    /**
     * Add an observable event name.
     *
     * @param array|mixed $observables
     */
    public function addObservableEvents($observables): void
    {
        $this->observables = array_unique(array_merge(
            $this->observables,
            is_array($observables) ? $observables : func_get_args()
        ));
    }

    /**
     * Remove an observable event name.
     *
     * @param array|mixed $observables
     */
    public function removeObservableEvents($observables): void
    {
        $this->observables = array_diff(
            $this->observables,
            is_array($observables) ? $observables : func_get_args()
        );
    }

    /**
     * Register a model event with the dispatcher.
     */
    protected static function registerModelEvent(string $event, Closure|string $callback): void
    {
        if (isset(static::$dispatcher)) {
            $name = static::class;

            static::$dispatcher->listen("wolke.{$event}: {$name}", $callback);
        }
    }

    /**
     * Fire the given event for the model.
     */
    protected function fireModelEvent(string $event, bool $halt = true): mixed
    {
        if (! isset(static::$dispatcher)) {
            return true;
        }

        // First, we will get the proper method to call on the event dispatcher, and then we
        // will attempt to fire a custom, object based event for the given event. If that
        // returns a result we can return that result, or we'll call the string events.
        $method = $halt ? 'until' : 'dispatch';

        $result = $this->filterModelEventResults(
            $this->fireCustomModelEvent($event, $method)
        );

        if ($result === false) {
            return false;
        }

        return ! empty($result)
            ? $result
            : static::$dispatcher->{$method}("wolke.{$event}: " . static::class, $this);
    }

    /**
     * Fire a custom model event for the given event.
     *
     * @return mixed|null
     */
    protected function fireCustomModelEvent(string $event, string $method)
    {
        if (! isset($this->dispatchesEvents[$event])) {
            return;
        }

        $result = static::$dispatcher->{$method}(new $this->dispatchesEvents[$event]($this));

        if (null !== $result) {
            return $result;
        }
    }

    /**
     * Filter the model event results.
     */
    protected function filterModelEventResults(mixed $result): mixed
    {
        if (is_array($result)) {
            $result = array_filter($result, static fn ($response) => null !== $response);
        }

        return $result;
    }

    /**
     * Register a retrieved model event with the dispatcher.
     */
    public static function retrieved(Closure|string $callback): void
    {
        static::registerModelEvent('retrieved', $callback);
    }

    /**
     * Register a saving model event with the dispatcher.
     */
    public static function saving(Closure|string $callback): void
    {
        static::registerModelEvent('saving', $callback);
    }

    /**
     * Register a saved model event with the dispatcher.
     */
    public static function saved(Closure|string $callback): void
    {
        static::registerModelEvent('saved', $callback);
    }

    /**
     * Register an updating model event with the dispatcher.
     */
    public static function updating(Closure|string $callback): void
    {
        static::registerModelEvent('updating', $callback);
    }

    /**
     * Register an updated model event with the dispatcher.
     */
    public static function updated(Closure|string $callback): void
    {
        static::registerModelEvent('updated', $callback);
    }

    /**
     * Register a creating model event with the dispatcher.
     */
    public static function creating(Closure|string $callback): void
    {
        static::registerModelEvent('creating', $callback);
    }

    /**
     * Register a created model event with the dispatcher.
     */
    public static function created(Closure|string $callback): void
    {
        static::registerModelEvent('created', $callback);
    }

    /**
     * Register a replicating model event with the dispatcher.
     */
    public static function replicating(Closure|string $callback): void
    {
        static::registerModelEvent('replicating', $callback);
    }

    /**
     * Register a deleting model event with the dispatcher.
     */
    public static function deleting(Closure|string $callback): void
    {
        static::registerModelEvent('deleting', $callback);
    }

    /**
     * Register a deleted model event with the dispatcher.
     */
    public static function deleted(Closure|string $callback): void
    {
        static::registerModelEvent('deleted', $callback);
    }

    /**
     * Remove all of the event listeners for the model.
     */
    public static function flushEventListeners(): void
    {
        if (! isset(static::$dispatcher)) {
            return;
        }

        $instance = new static();

        foreach ($instance->getObservableEvents() as $event) {
            static::$dispatcher->forget("wolke.{$event}: " . static::class);
        }

        foreach (array_values($instance->dispatchesEvents) as $event) {
            static::$dispatcher->forget($event);
        }
    }

    /**
     * Get the event dispatcher instance.
     *
     * @return \BlitzPHP\Wolke\Contracts\Dispatcher
     */
    public static function getEventDispatcher()
    {
        return static::$dispatcher;
    }

    /**
     * Set the event dispatcher instance.
     */
    public static function setEventDispatcher(Dispatcher $dispatcher): void
    {
        static::$dispatcher = $dispatcher;
    }

    /**
     * Unset the event dispatcher for models.
     */
    public static function unsetEventDispatcher(): void
    {
        static::$dispatcher = null;
    }

    /**
     * Execute a callback without firing any model events for any model type.
     */
    public static function withoutEvents(callable $callback): mixed
    {
        $dispatcher = static::getEventDispatcher();

        if ($dispatcher) {
            static::setEventDispatcher(new NullDispatcher($dispatcher));
        }

        try {
            return $callback();
        } finally {
            if ($dispatcher) {
                static::setEventDispatcher($dispatcher);
            }
        }
    }
}
