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

interface SupportsPartialRelations
{
    /**
     * Indicate that the relation is a single result of a larger one-to-many relationship.
     *
     * @return static
     */
    public function ofMany(null|array|Closure|string $column = 'id', null|Closure|string $aggregate = 'MAX', ?string $relation = null);

    /**
     * Determine whether the relationship is a one-of-many relationship.
     */
    public function isOneOfMany(): bool;

    /**
     * Get the one of many inner join subselect query builder instance.
     *
     * @return \BlitzPHP\Wolke\Builder|void
     */
    public function getOneOfManySubQuery();
}
