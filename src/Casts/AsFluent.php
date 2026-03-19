<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Casts;

use BlitzPHP\Utilities\Support\Fluent;
use BlitzPHP\Wolke\Contracts\Castable;
use BlitzPHP\Wolke\Contracts\CastsAttributes;

class AsFluent implements Castable
{
    /**
     * Obtient la classe de cast à utiliser pour le casting depuis/vers cette cible de cast.
     *
     * @return CastsAttributes<Fluent, string>
     */
    public static function castUsing(array $arguments)
    {
        return new class () implements CastsAttributes {
            public function get($model, $key, $value, $attributes): mixed
            {
                return isset($value) ? new Fluent(Json::decode($value)) : null;
            }

            public function set($model, $key, $value, $attributes): mixed
            {
                return isset($value) ? [$key => Json::encode($value)] : null;
            }
        };
    }
}
