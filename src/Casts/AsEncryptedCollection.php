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
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Wolke\Contracts\Castable;
use BlitzPHP\Wolke\Contracts\CastsAttributes;
use BlitzPHP\Wolke\Model;
use InvalidArgumentException;

class AsEncryptedCollection implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class ($arguments) implements CastsAttributes {
            public function __construct(protected array $arguments)
            {
            }

            public function get(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                $collectionClass = $this->arguments[0] ?? Collection::class;

                if (! is_a($collectionClass, Collection::class, true)) {
                    throw new InvalidArgumentException('La classe fournie doit étendre [' . Collection::class . '].');
                }

                if (isset($attributes[$key])) {
                    return new $collectionClass(Json::decode(Services::encrypter()->decrypt($attributes[$key])));
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
        };
    }
}
