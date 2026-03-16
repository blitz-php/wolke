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

use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Wolke\Contracts\Dispatcher as DispatcherContract;
use Closure;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Events\NullDispatcher</a>
 */
class NullDispatcher implements DispatcherContract
{
    use ForwardsCalls;

    /**
     * Crée une nouvelle instance de répartiteur d'événements qui ne déclenche rien.
     *
     * @param DispatcherContract $dispatcher L'instance de répartiteur d'événements sous-jacente.
     */
    public function __construct(protected DispatcherContract $dispatcher)
    {
    }

    /**
     * Ne déclenche pas d'événement.
     *
     * @return null
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): ?array
    {
        return null;
    }

    /**
     * N'enregistre pas un événement et sa charge utile pour être déclenchés plus tard.
     */
    public function push(string $event, array $payload = []): void
    {
    }

    /**
     * Ne distribue pas d'événement.
     *
     * @return null
     */
    public function until(object|string $event, mixed $payload = []): ?array
    {
        return null;
    }

    /**
     * Enregistre un écouteur d'événement avec le répartiteur.
     */
    public function listen(array|Closure|string $events, array|Closure|string|null $listener = null): void
    {
        $this->dispatcher->listen($events, $listener);
    }

    /**
     * Détermine si un événement donné a des écouteurs.
     */
    public function hasListeners(string $eventName): bool
    {
        return $this->dispatcher->hasListeners($eventName);
    }

    /**
     * Enregistre un abonné aux événements avec le répartiteur.
     */
    public function subscribe(object|string $subscriber): void
    {
        $this->dispatcher->subscribe($subscriber);
    }

    /**
     * Vide un ensemble d'événements mis en attente.
     */
    public function flush(string $event): void
    {
        $this->dispatcher->flush($event);
    }

    /**
     * Supprime un ensemble d'écouteurs du répartiteur.
     */
    public function forget(string $event): void
    {
        $this->dispatcher->forget($event);
    }

    /**
     * Oublie tous les écouteurs en file d'attente.
     */
    public function forgetPushed(): void
    {
        $this->dispatcher->forgetPushed();
    }

    /**
     * Passe dynamiquement les appels de méthode au répartiteur sous-jacent.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        return $this->forwardCallTo($this->dispatcher, $method, $parameters);
    }
}
