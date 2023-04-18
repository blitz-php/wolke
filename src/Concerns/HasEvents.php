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

use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Wolke\Contracts\Dispatcher;
use BlitzPHP\Wolke\Events\NullDispatcher;
use Closure;
use InvalidArgumentException;
use RuntimeException;

trait HasEvents
{
    /**
     * The event dispatcher instance.
     *
     * @var Dispatcher
     */
    protected static $dispatcher;

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
     * Register observers with the model.
     *
     * @throws RuntimeException
     */
    public static function observe(object|array|string $classes): void
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

            static::$dispatcher->listen("orm.{$event}: {$name}", $callback);
        }
    }

    /**
     * Fire the given event for the model.
     *
     * @param string $event
     * @param bool   $halt
     *
     * @return mixed
     */
    protected function fireModelEvent($event, $halt = true)
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
            : static::$dispatcher->{$method}("orm.{$event}: " . static::class, $this);
    }

    /**
     * Fire a custom model event for the given event.
     *
     * @param string $event
     * @param string $method
     *
     * @return mixed|null
     */
    protected function fireCustomModelEvent($event, $method)
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
     *
     * @param mixed $result
     *
     * @return mixed
     */
    protected function filterModelEventResults($result)
    {
        if (is_array($result)) {
            $result = array_filter($result, static fn ($response) => null !== $response);
        }

        return $result;
    }

    /**
     * Register a retrieved model event with the dispatcher.
     *
     * @param Closure|string $callback
     *
     * @return void
     */
    public static function retrieved($callback)
    {
        static::registerModelEvent('retrieved', $callback);
    }

    /**
     * Register a saving model event with the dispatcher.
     *
     * @param Closure|string $callback
     *
     * @return void
     */
    public static function saving($callback)
    {
        static::registerModelEvent('saving', $callback);
    }

    /**
     * Register a saved model event with the dispatcher.
     *
     * @param Closure|string $callback
     *
     * @return void
     */
    public static function saved($callback)
    {
        static::registerModelEvent('saved', $callback);
    }

    /**
     * Register an updating model event with the dispatcher.
     *
     * @param Closure|string $callback
     *
     * @return void
     */
    public static function updating($callback)
    {
        static::registerModelEvent('updating', $callback);
    }

    /**
     * Register an updated model event with the dispatcher.
     *
     * @param Closure|string $callback
     *
     * @return void
     */
    public static function updated($callback)
    {
        static::registerModelEvent('updated', $callback);
    }

    /**
     * Register a creating model event with the dispatcher.
     *
     * @param Closure|string $callback
     *
     * @return void
     */
    public static function creating($callback)
    {
        static::registerModelEvent('creating', $callback);
    }

    /**
     * Register a created model event with the dispatcher.
     *
     * @param Closure|string $callback
     *
     * @return void
     */
    public static function created($callback)
    {
        static::registerModelEvent('created', $callback);
    }

    /**
     * Register a replicating model event with the dispatcher.
     *
     * @param Closure|string $callback
     *
     * @return void
     */
    public static function replicating($callback)
    {
        static::registerModelEvent('replicating', $callback);
    }

    /**
     * Register a deleting model event with the dispatcher.
     *
     * @param Closure|string $callback
     *
     * @return void
     */
    public static function deleting($callback)
    {
        static::registerModelEvent('deleting', $callback);
    }

    /**
     * Register a deleted model event with the dispatcher.
     *
     * @param Closure|string $callback
     *
     * @return void
     */
    public static function deleted($callback)
    {
        static::registerModelEvent('deleted', $callback);
    }

    /**
     * Remove all of the event listeners for the model.
     *
     * @return void
     */
    public static function flushEventListeners()
    {
        if (! isset(static::$dispatcher)) {
            return;
        }

        $instance = new static();

        foreach ($instance->getObservableEvents() as $event) {
            static::$dispatcher->forget("orm.{$event}: " . static::class);
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
     *
     * @return void
     */
    public static function setEventDispatcher(Dispatcher $dispatcher)
    {
        static::$dispatcher = $dispatcher;
    }

    /**
     * Unset the event dispatcher for models.
     *
     * @return void
     */
    public static function unsetEventDispatcher()
    {
        static::$dispatcher = null;
    }

    /**
     * Execute a callback without firing any model events for any model type.
     *
     * @return mixed
     */
    public static function withoutEvents(callable $callback)
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
