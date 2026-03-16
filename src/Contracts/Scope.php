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

use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Model;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Scope</a>
 */
interface Scope
{
    /**
     * Applique la portée à un constructeur de requête Wolke donné.
     *
     * @template TModel of Model
     *
     * @param Builder<TModel>  $builder
     * @param TModel $model
     */
    public function apply(Builder $builder, Model $model): void;
}
