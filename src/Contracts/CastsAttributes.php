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

use BlitzPHP\Wolke\Model;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Contracts\Database\Eloquent\CastsAttributes</a>
 * 
 * @template TGet
 * @template TSet
 */
interface CastsAttributes
{
    /**
     * Transforme l'attribut à partir des valeurs sous-jacentes du modèle.
     *
     * @param array<string, mixed> $attributes
     *
     * @return TGet|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed;

    /**
     * Transforme l'attribut vers ses valeurs sous-jacentes du modèle.
     *
     * @param TSet|null            $value
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed;
}
