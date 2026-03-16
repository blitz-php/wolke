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

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Contracts\Events\Dispatcher</a>
 */
interface Dispatcher
{
    /**
     * Enregistre un écouteur d'événement avec le répartiteur.
     */
    public function listen(array|Closure|string $events, array|Closure|string|null $listener = null): void;

    /**
     * Détermine si un événement donné a des écouteurs.
     */
    public function hasListeners(string $eventName): bool;

    /**
     * Enregistre un abonné aux événements avec le répartiteur.
     */
    public function subscribe(object|string $subscriber): void;

    /**
     * Distribue un événement jusqu'à ce que la première réponse non nulle soit retournée.
     */
    public function until(object|string $event, mixed $payload = []): mixed;

    /**
     * Distribue un événement et appelle les écouteurs.
     */
    public function dispatch(object|string $event, mixed $payload = [], bool $halt = false): ?array;

    /**
     * Enregistre un événement et sa charge utile pour être déclenchés plus tard.
     */
    public function push(string $event, array $payload = []): void;

    /**
     * Vide un ensemble d'événements mis en attente.
     */
    public function flush(string $event): void;

    /**
     * Supprime un ensemble d'écouteurs du répartiteur.
     */
    public function forget(string $event): void;

    /**
     * Oublie tous les écouteurs en file d'attente.
     */
    public function forgetPushed(): void;
}
