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
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Contracts\Database\Eloquent\SupportsPartialRelations</a>
 */
interface SupportsPartialRelations
{
    /**
     * Indique que la relation est un résultat unique d'une relation un-à-plusieurs plus large.
     *
     * @return static
     */
    public function ofMany(array|Closure|string|null $column = 'id', Closure|string|null $aggregate = 'MAX', ?string $relation = null);

    /**
     * Détermine si la relation est une relation "one-of-many".
     */
    public function isOneOfMany(): bool;

    /**
     * Obtient l'instance de constructeur de sous-requête de jointure interne one of many.
     *
     * @return \BlitzPHP\Wolke\Builder|void
     */
    public function getOneOfManySubQuery();
}
