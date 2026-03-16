<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Observers;

use BlitzPHP\Traits\Macroable;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Contracts\Dispatcher as DispatcherContract;
use BlitzPHP\Wolke\Support\ReflectsClosures;
use Closure;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Events\Dispatcher</a>
 */
class Dispatcher implements DispatcherContract
{
    use Macroable;
    use ReflectsClosures;

    /**
     * Les écouteurs d'événements enregistrés.
     */
    protected array $listeners = [];

    /**
     * Les écouteurs génériques (wildcard).
     */
    protected array $wildcards = [];

    /**
     * Le cache des écouteurs génériques.
     */
    protected array $wildcardsCache = [];

    /**
     * Enregistre un écouteur d'événement avec le répartiteur.
     */
    public function listen(array|Closure|string $events, array|Closure|string|null $listener = null): void
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
     * Configure un rappel d'écouteur générique (wildcard).
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
     * Détermine si un événement donné a des écouteurs.
     */
    public function hasListeners(string $eventName): bool
    {
        return isset($this->listeners[$eventName])
               || isset($this->wildcards[$eventName])
               || $this->hasWildcardListeners($eventName);
    }

    /**
     * Détermine si l'événement donné a des écouteurs génériques.
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
     * Enregistre un événement et sa charge utile pour être déclenchés plus tard.
     */
    public function push(string $event, array $payload = []): void
    {
        $this->listen($event . '_pushed', function () use ($event, $payload) {
            $this->dispatch($event, $payload);
        });
    }

    /**
     * Vide un ensemble d'événements mis en attente.
     */
    public function flush(string $event): void
    {
        $this->dispatch($event . '_pushed');
    }

    /**
     * Enregistre un abonné aux événements avec le répartiteur.
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
     * Résout l'instance d'abonné.
     */
    protected function resolveSubscriber(object|string $subscriber): object
    {
        if (is_string($subscriber)) {
            return new $subscriber();
        }

        return $subscriber;
    }

    /**
     * Déclenche un événement jusqu'à ce que la première réponse non nulle soit retournée.
     */
    public function until(object|string $event, mixed $payload = []): ?array
    {
        return $this->dispatch($event, $payload, true);
    }

    /**
     * Déclenche un événement et appelle les écouteurs.
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): ?array
    {
        // Lorsque "l'événement" donné est en fait un objet, nous supposerons qu'il s'agit d'un objet événement
        // et utiliserons la classe comme nom d'événement et cet événement lui-même comme
        // charge utile pour le gestionnaire, ce qui rend les événements basés sur des objets assez simples.
        [$event, $payload] = $this->parseEventAndPayload(
            $event,
            $payload
        );

        $responses = [];

        foreach ($this->getListeners($event) as $listener) {
            $response = $listener($event, $payload);

            // Si une réponse est retournée par l'écouteur et que l'arrêt d'événement est activé,
            // nous retournerons simplement cette réponse, et n'appellerons pas le reste des écouteurs
            // d'événement. Sinon, nous ajouterons la réponse à la liste des réponses.
            if ($halt && null !== $response) {
                return (array) $response;
            }

            // Si un booléen false est retourné par un écouteur, nous arrêterons de propager
            // l'événement vers d'autres écouteurs plus bas dans la chaîne, sinon nous continuerons
            // à parcourir les écouteurs et à déclencher chacun dans notre séquence.
            if ($response === false) {
                break;
            }

            $responses[] = $response;
        }

        return $halt ? null : $responses;
    }

    /**
     * Analyse l'événement et la charge utile donnés et les prépare pour la distribution.
     */
    protected function parseEventAndPayload(mixed $event, mixed $payload): array
    {
        if (is_object($event)) {
            [$payload, $event] = [[$event], get_class($event)];
        }

        return [$event, Arr::wrap($payload)];
    }

    /**
     * Obtient tous les écouteurs pour un nom d'événement donné.
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
     * Obtient les écouteurs génériques pour l'événement.
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
     * Ajoute les écouteurs pour les interfaces de l'événement au tableau donné.
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
     * Enregistre un écouteur d'événement avec le répartiteur.
     */
    public function makeListener(array|Closure|string $listener, bool $wildcard = false): Closure
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
     * Crée un écouteur basé sur une classe en utilisant le conteneur IoC.
     */
    public function createClassListener(array|string $listener, bool $wildcard = false): Closure
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
     * Crée l'appelable d'événement basé sur une classe.
     *
     * @return callable
     */
    protected function createClassCallable(array|string $listener)
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
     * Analyse l'écouteur de classe en classe et méthode.
     */
    protected function parseClassCallable(string $listener): array
    {
        return Text::parseCallback($listener, 'handle');
    }

    /**
     * Supprime un ensemble d'écouteurs du répartiteur.
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
     * Oublie tous les écouteurs mis en attente.
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
