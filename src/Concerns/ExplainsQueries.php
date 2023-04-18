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

use BlitzPHP\Utilities\Iterable\Collection;

trait ExplainsQueries
{
    /**
     * Explains the query.
     */
    public function explain(): Collection
    {
        $sql = $this->toSql();

        $bindings = $this->query->getBinds();

        $explanation = $this->fromQuery('EXPLAIN ' . $sql, $bindings);

        return new Collection($explanation);
    }
}
