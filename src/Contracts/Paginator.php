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

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Contracts\Pagination\Paginator</a>
 *
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @method $this through(callable(TValue): mixed $callback)
 */
interface Paginator
{
    /**
     * Obtient l'URL pour une page donnée.
     */
    public function url(int $page): string;

    /**
     * Ajoute un ensemble de valeurs de chaîne de requête au paginateur.
     *
     * @return $this
     */
    public function appends(array|string|null $key, ?string $value = null);

    /**
     * Obtient/définit le fragment d'URL à ajouter aux URL.
     *
     * @return $this|string
     */
    public function fragment(?string $fragment = null);

    /**
     * Ajoute toutes les valeurs de chaîne de requête actuelles au paginateur.
     *
     * @return $this
     */
    public function withQueryString();

    /**
     * L'URL de la page suivante, ou null.
     */
    public function nextPageUrl(): ?string;

    /**
     * Obtient l'URL de la page précédente, ou null.
     */
    public function previousPageUrl(): ?string;

    /**
     * Obtient tous les éléments paginés.
     *
     * @return array<TKey, TValue>
     */
    public function items(): array;

    /**
     * Obtient "l'index" du premier élément paginé.
     */
    public function firstItem(): ?int;

    /**
     * Obtient "l'index" du dernier élément paginé.
     */
    public function lastItem(): ?int;

    /**
     * Détermine combien d'éléments sont affichés par page.
     */
    public function perPage(): int;

    /**
     * Détermine la page actuelle en cours de pagination.
     */
    public function currentPage(): ?int;

    /**
     * Détermine s'il y a assez d'éléments pour diviser en plusieurs pages.
     */
    public function hasPages(): bool;

    /**
     * Détermine s'il y a plus d'éléments dans le magasin de données.
     */
    public function hasMorePages(): bool;

    /**
     * Obtient le chemin de base pour les URL générées par le paginateur.
     */
    public function path(): ?string;

    /**
     * Détermine si la liste des éléments est vide ou non.
     */
    public function isEmpty(): bool;

    /**
     * Détermine si la liste des éléments n'est pas vide.
     */
    public function isNotEmpty(): bool;

    /**
     * Affiche le paginateur en utilisant une vue donnée.
     */
    public function render(?string $view = null, array $data = []): string;
}
