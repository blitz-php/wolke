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
 *
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\HigherOrderBuilderProxy</a>
 */
class HigherOrderBuilderProxy
{
    /**
     * Crée une nouvelle instance de proxy.
     *
     * @param Builder<*> $builder La collection sur laquelle on opère.
     * @param string $method La méthode étant proxifiée.
     */
    public function __construct(protected Builder $builder, protected string $method)
    {
    }

    /**
     * Proxifie un appel de portée vers le constructeur de requête.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        return $this->builder->{$this->method}(static fn ($value) => $value->{$method}(...$parameters));
    }
}
