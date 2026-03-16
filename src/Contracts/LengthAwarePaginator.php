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
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Contracts\Pagination\LengthAwarePaginator</a>
 * 
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @extends Paginator<TKey, TValue>
 */
interface LengthAwarePaginator extends Paginator
{
    /**
     * Crée une plage d'URL de pagination.
     */
    public function getUrlRange(int $start, int $end): array;

    /**
     * Détermine le nombre total d'éléments dans le magasin de données.
     */
    public function total(): int;

    /**
     * Obtient le numéro de page de la dernière page disponible.
     */
    public function lastPage(): int;
}
