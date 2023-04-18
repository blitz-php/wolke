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
use CodeIgniter\Config\Services;
use Tightenco\Collect\Support\Collection;

class AsEncryptedCollection implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class () implements CastsAttributes {
            public function get($model, $key, $value, $attributes)
            {
                return new Collection(json_decode(Services::encrypter()->decrypt($attributes[$key]), true));
            }

            public function set($model, $key, $value, $attributes)
            {
                return [$key => Services::encrypter()->encrypt(json_encode($value))];
            }
        };
    }
}