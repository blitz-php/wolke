<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke;

/**
 * @mixin Builder
 */
class HigherOrderBuilderProxy
{
    /**
     * Create a new proxy instance.
     *
     * @param Builder $builder The collection being operated on.
     * @param string  $method  The method being proxied.
     */
    public function __construct(protected Builder $builder, protected string $method)
    {
    }

    /**
     * Proxy a scope call onto the query builder.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        return $this->builder->{$this->method}(static fn ($value) => $value->{$method}(...$parameters));
    }
}
