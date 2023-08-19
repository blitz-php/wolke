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

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Casts\Json</a>
 */
class Json
{
    /**
     * L'encodeur JSON personnalisé.
     *
     * @var callable|null
     */
    protected static $encoder;

    /**
     * Le décodeur JSON personnalisé.
     *
     * @var callable|null
     */
    protected static $decoder;

    /**
     * Encoder la valeur donnée.
     */
    public static function encode(mixed $value): mixed
    {
        return isset(static::$encoder) ? (static::$encoder)($value) : json_encode($value);
    }

    /**
     * Décodez la valeur donnée.
     */
    public static function decode(mixed $value, ?bool $associative = true): mixed
    {
        return isset(static::$decoder)
                ? (static::$decoder)($value, $associative)
                : json_decode($value, $associative);
    }

    /**
     * Encode toutes les valeurs à l'aide du callable donné.
     */
    public static function encodeUsing(?callable $encoder): void
    {
        static::$encoder = $encoder;
    }

    /**
     * Décode toutes les valeurs à l'aide du callable donné.
     */
    public static function decodeUsing(?callable $decoder): void
    {
        static::$decoder = $decoder;
    }
}
