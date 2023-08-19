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

use BlitzPHP\Wolke\Contracts\Castable;
use BlitzPHP\Wolke\Contracts\CastsAttributes;
use BlitzPHP\Wolke\Contracts\SerializesCastableAttributes;
use BlitzPHP\Wolke\Model;

class AsArrayObject implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class () implements CastsAttributes, SerializesCastableAttributes {
            public function get(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                if (! isset($attributes[$key])) {
                    return null;
                }

                $data = Json::decode($attributes[$key]);

                return is_array($data) ? new ArrayObject($data) : null;
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                return [$key => Json::encode($value)];
            }

            public function serialize($model, string $key, $value, array $attributes)
            {
                return $value->getArrayCopy();
            }
        };
    }
}
