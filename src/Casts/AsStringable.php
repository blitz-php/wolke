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

use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Contracts\Castable;
use BlitzPHP\Wolke\Contracts\CastsAttributes;
use BlitzPHP\Wolke\Model;

class AsStringable implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class () implements CastsAttributes {
            public function get(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                return isset($value) ? Text::of($value) : null;
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                return isset($value) ? (string) $value : null;
            }
        };
    }
}
