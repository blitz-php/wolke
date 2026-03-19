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

use BlitzPHP\Wolke\Pagination\Cursor;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Contracts\Pagination\CursorPaginator</a>
 *
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @method $this through(callable(TValue): mixed $callback)
 */
interface CursorPaginator
{
    /**
     * Obtient l'URL pour un curseur donné.
     */
    public function url(?Cursor $cursor): string;

    /**
     * Ajoute un ensemble de valeurs de chaîne de requête au paginateur.
     *
     * @return $this
     */
    public function appends(array|string|null $key, ?string $value = null);

    /**
     * Obtient/définit le fragment d'URL à ajouter aux URL.
     *
     * @return $this|string|null
     */
    public function fragment(?string $fragment = null);

    /**
     * Ajoute toutes les valeurs de chaîne de requête actuelles au paginateur.
     *
     * @return $this
     */
    public function withQueryString();

    /**
     * Obtient l'URL de la page précédente, ou null.
     */
    public function previousPageUrl(): ?string;

    /**
     * L'URL de la page suivante, ou null.
     */
    public function nextPageUrl(): ?string;

    /**
     * Obtient tous les éléments paginés.
     *
     * @return array<TKey, TValue>
     */
    public function items(): array;

    /**
     * Obtient le "curseur" de l'ensemble d'éléments précédent.
     */
    public function previousCursor(): ?Cursor;

    /**
     * Obtient le "curseur" de l'ensemble d'éléments suivant.
     */
    public function nextCursor(): ?Cursor;

    /**
     * Détermine combien d'éléments sont affichés par page.
     */
    public function perPage(): int;

    /**
     * Obtient le curseur actuel en cours de pagination.
     */
    public function cursor(): ?Cursor;

    /**
     * Détermine s'il y a assez d'éléments pour diviser en plusieurs pages.
     */
    public function hasPages(): bool;

    /**
     * Détermine s'il y a plus d'éléments dans la source de données.
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
