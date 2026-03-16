<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Concerns;

use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Support\Onceable;
use WeakMap;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\PreventsCircularRecursion</a>
 */
trait PreventsCircularRecursion
{
    /**
     * Le cache des objets traités pour éviter la récursion infinie.
     *
     * @var WeakMap<static, array<string, mixed>>
     */
    protected static $recursionCache;

    /**
     * Empêche qu'une méthode soit appelée plusieurs fois sur le même objet dans la même pile d'appels.
     */
    protected function withoutRecursion(callable $callback, mixed $default = null): mixed
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);

        $onceable = Onceable::tryFromTrace($trace, $callback);

        if (is_null($onceable)) {
            return call_user_func($callback);
        }

        $stack = static::getRecursiveCallStack($this);

        if (array_key_exists($onceable->hash, $stack)) {
            return is_callable($stack[$onceable->hash])
                ? static::setRecursiveCallValue($this, $onceable->hash, call_user_func($stack[$onceable->hash]))
                : $stack[$onceable->hash];
        }

        try {
            static::setRecursiveCallValue($this, $onceable->hash, $default);

            return call_user_func($onceable->callable);
        } finally {
            static::clearRecursiveCallValue($this, $onceable->hash);
        }
    }

    /**
     * Supprime une entrée du cache de récursion pour un objet.
     */
    protected static function clearRecursiveCallValue(object $object, string $hash): void
    {
        if ($stack = Arr::except(static::getRecursiveCallStack($object), $hash)) {
            static::getRecursionCache()->offsetSet($object, $stack);
        } elseif (static::getRecursionCache()->offsetExists($object)) {
            static::getRecursionCache()->offsetUnset($object);
        }
    }

    /**
     * Obtient la pile des méthodes appelées récursivement pour l'objet courant.
     */
    protected static function getRecursiveCallStack(object $object): array
    {
        return static::getRecursionCache()->offsetExists($object)
            ? static::getRecursionCache()->offsetGet($object)
            : [];
    }

    /**
     * Obtient le cache de récursion actuel utilisé par le modèle.
     */
    protected static function getRecursionCache(): WeakMap
    {
        return static::$recursionCache ??= new WeakMap();
    }

    /**
     * Définit une valeur dans le cache de récursion pour l'objet et la méthode donnés.
     */
    protected static function setRecursiveCallValue(object $object, string $hash, mixed $value): mixed
    {
        static::getRecursionCache()->offsetSet(
            $object,
            Helpers::tap(static::getRecursiveCallStack($object), fn (&$stack) => $stack[$hash] = $value),
        );

        return static::getRecursiveCallStack($object)[$hash];
    }
}
