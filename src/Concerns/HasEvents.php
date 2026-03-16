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
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Wolke\Attributes\ObservedBy;
use BlitzPHP\Wolke\Contracts\Dispatcher;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Observers\NullDispatcher;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\HasEvents</a>
 */
trait HasEvents
{
    /**
     * La carte des événements pour le modèle.
     *
     * Permet des événements basés sur des objets pour les événements natifs de Wolke.
     *
     * @var array<string, class-string>
     */
    protected array $dispatchesEvents = [];

    /**
     * Événements observables exposés par l'utilisateur.
     *
     * Ce sont des événements supplémentaires définis par l'utilisateur auxquels les observateurs peuvent s'abonner.
     *
     * @var list<string>
     */
    protected array $observables = [];

    /**
     * Initialise le trait d'événements pour un modèle.
     */
    public static function bootHasEvents(): void
    {
        static::whenBooted(fn () => static::observe(static::resolveObserveAttributes()));
    }

    /**
     * Résout les noms de classe d'observation à partir des attributs.
     */
    public static function resolveObserveAttributes(): array
    {
        $reflectionClass = new ReflectionClass(static::class);

        $isWolkeGrandchild = is_subclass_of(static::class, Model::class)
            && get_parent_class(static::class) !== Model::class;

        return (new Collection($reflectionClass->getAttributes(ObservedBy::class)))
            ->map(fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->when($isWolkeGrandchild, function (Collection $attributes) {
                return (new Collection(get_parent_class(static::class)::resolveObserveAttributes()))
                    ->merge($attributes);
            })
            ->all();
    }

    /**
     * Enregistre des observateurs avec le modèle.
     *
     * @param  object|list<string>|string  $classes
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
     * Enregistre un seul observateur avec le modèle.
     *
     * @throws RuntimeException
     */
    protected function registerObserver(object|string $class): void
    {
        $className = $this->resolveObserverClassName($class);

        // Lors de l'enregistrement d'un observateur de modèle, nous parcourrons les événements possibles
        // et déterminerons si cet observateur a cette méthode. Si c'est le cas, nous l'accrocherons
        // dans le système d'événements du modèle, ce qui facilite la surveillance de ceux-ci.
        foreach ($this->getObservableEvents() as $event) {
            if (method_exists($class, $event)) {
                static::registerModelEvent($event, $className . '@' . $event);
            }
        }
    }

    /**
     * Résout le nom de classe de l'observateur à partir d'un objet ou d'une chaîne.
     * 
     * @return class-string
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

        throw new InvalidArgumentException('Impossible de trouver l\'observateur : ' . $class);
    }

    /**
     * Obtient les noms des événements observables.
     *
     * @return list<string>
     */
    public function getObservableEvents(): array
    {
        return array_merge(
            [
                'retrieved', 'creating', 'created', 'updating', 'updated',
                'saving', 'saved', 'restoring', 'restored', 'replicating',
                'trashed', 'deleting', 'deleted', 'forceDeleted',
            ],
            $this->observables
        );
    }

    /**
     * Définit les noms des événements observables.
     *
     * @param  list<string>  $observables
     */
    public function setObservableEvents(array $observables): self
    {
        $this->observables = $observables;

        return $this;
    }

    /**
     * Ajoute un nom d'événement observable.
     *
     * @param  list<string>|string  $observables
     */
    public function addObservableEvents($observables): void
    {
        $this->observables = array_unique(array_merge(
            $this->observables,
            is_array($observables) ? $observables : func_get_args()
        ));
    }

    /**
     * Supprime un nom d'événement observable.
     *
     * @param  list<string>|string  $observables
     */
    public function removeObservableEvents($observables): void
    {
        $this->observables = array_diff(
            $this->observables,
            is_array($observables) ? $observables : func_get_args()
        );
    }

    /**
     * Enregistre un événement de modèle avec le répartiteur.
     * 
     * @param callable|array|class-string  $callback
     */
    protected static function registerModelEvent(string $event, $callback): void
    {
        if (isset(static::$dispatcher)) {
            $name = static::class;

            static::$dispatcher->listen("wolke.{$event}: {$name}", $callback);
        }
    }

    /**
     * Déclenche l'événement donné pour le modèle.
     */
    protected function fireModelEvent(string $event, bool $halt = true): mixed
    {
        if (! isset(static::$dispatcher)) {
            return true;
        }

        // D'abord, nous obtiendrons la méthode appropriée à appeler sur le répartiteur d'événements, puis nous
        // tenterons de déclencher un événement personnalisé basé sur un objet pour l'événement donné. Si cela
        // retourne un résultat, nous pouvons retourner ce résultat, ou nous appellerons les événements de chaîne.
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
     * Déclenche un événement de modèle personnalisé pour l'événement donné.
     * 
     * @param  'until'|'dispatch'  $method
     * 
     * @return array|null|void
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
     * Filtre les résultats de l'événement de modèle.
     */
    protected function filterModelEventResults(mixed $result): mixed
    {
        if (is_array($result)) {
            $result = array_filter($result, static fn ($response) => null !== $response);
        }

        return $result;
    }

    /**
     * Enregistre un événement de modèle "retrieved" avec le répartiteur.
     *
     * @param callable|array|class-string  $callback
     */
    public static function retrieved(array|callable|string $callback): void
    {
        static::registerModelEvent('retrieved', $callback);
    }

    /**
     * Enregistre un événement de modèle "saving" avec le répartiteur.
     *
     * @param callable|array|class-string  $callback
     */
    public static function saving(array|callable|string $callback): void
    {
        static::registerModelEvent('saving', $callback);
    }

    /**
     * Enregistre un événement de modèle "saved" avec le répartiteur.
     *
     * @param callable|array|class-string  $callback
     */
    public static function saved(array|callable|string $callback): void
    {
        static::registerModelEvent('saved', $callback);
    }

    /**
     * Enregistre un événement de modèle "updating" avec le répartiteur.
     *
     * @param callable|array|class-string  $callback
     */
    public static function updating(array|callable|string $callback): void
    {
        static::registerModelEvent('updating', $callback);
    }

    /**
     * Enregistre un événement de modèle "updated" avec le répartiteur.
     *
     * @param callable|array|class-string  $callback
     */
    public static function updated(array|callable|string $callback): void
    {
        static::registerModelEvent('updated', $callback);
    }

    /**
     * Enregistre un événement de modèle "creating" avec le répartiteur.
     *
     * @param callable|array|class-string  $callback
     */
    public static function creating(array|callable|string $callback): void
    {
        static::registerModelEvent('creating', $callback);
    }

    /**
     * Enregistre un événement de modèle "created" avec le répartiteur.
     *
     * @param callable|array|class-string  $callback
     */
    public static function created(array|callable|string $callback): void
    {
        static::registerModelEvent('created', $callback);
    }

    /**
     * Enregistre un événement de modèle "replicating" avec le répartiteur.
     *
     * @param callable|array|class-string  $callback
     */
    public static function replicating(array|callable|string $callback): void
    {
        static::registerModelEvent('replicating', $callback);
    }

    /**
     * Enregistre un événement de modèle "deleting" avec le répartiteur.
     *
     * @param callable|array|class-string  $callback
     */
    public static function deleting(array|callable|string $callback): void
    {
        static::registerModelEvent('deleting', $callback);
    }

    /**
     * Enregistre un événement de modèle "deleted" avec le répartiteur.
     *
     * @param callable|array|class-string  $callback
     */
    public static function deleted(array|callable|string $callback): void
    {
        static::registerModelEvent('deleted', $callback);
    }

    /**
     * Supprime tous les écouteurs d'événements pour le modèle.
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
     * Obtient la carte des événements pour le modèle.
     */
    public function dispatchesEvents(): array
    {
        return $this->dispatchesEvents;
    }

    /**
     * Obtient l'instance du répartiteur d'événements.
     */
    public static function getEventDispatcher(): ?Dispatcher
    {
        return static::$dispatcher;
    }

    /**
     * Définit l'instance du répartiteur d'événements.
     */
    public static function setEventDispatcher(Dispatcher $dispatcher): void
    {
        static::$dispatcher = $dispatcher;
    }

    /**
     * Supprime le répartiteur d'événements pour les modèles.
     */
    public static function unsetEventDispatcher(): void
    {
        static::$dispatcher = null;
    }

    /**
     * Exécute un rappel sans déclencher d'événements de modèle pour aucun type de modèle.
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
