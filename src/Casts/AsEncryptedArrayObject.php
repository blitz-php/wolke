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

use BlitzPHP\Container\Services;
use BlitzPHP\Wolke\Contracts\Castable;
use BlitzPHP\Wolke\Contracts\CastsAttributes;
use BlitzPHP\Wolke\Contracts\SerializesCastableAttributes;
use BlitzPHP\Wolke\Model;

class AsEncryptedArrayObject implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class () implements CastsAttributes, SerializesCastableAttributes {
            public function get(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                if (isset($attributes[$key])) {
                    return new ArrayObject(Json::decode(Services::encrypter()->decrypt($attributes[$key])));
                }

                return null;
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                if (null !== $value) {
                    return [$key => Services::encrypter()->encrypt(Json::encode($value))];
                }

                return null;
            }

            public function serialize($model, string $key, $value, array $attributes)
            {
                return null !== $value ? $value->getArrayCopy() : null;
            }
        };
    }
}
