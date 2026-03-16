<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Support;

use BlitzPHP\Utilities\Helpers;
use Closure;
use ReflectionFunction;
use RuntimeException;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Support\Traits\ReflectsClosures</a>
 */
trait ReflectsClosures
{
    /**
     * Obtient les noms de classe / types des paramètres de la fermeture donnée.
     *
     * @throws ReflectionException
     */
    protected function closureParameterTypes(Closure $closure): array
    {
        $reflection = new ReflectionFunction($closure);

        return Helpers::collect($reflection->getParameters())->mapWithKeys(static function ($parameter) {
            if ($parameter->isVariadic()) {
                return [$parameter->getName() => null];
            }

            return [$parameter->getName() => Reflector::getParameterClassName($parameter)];
        })->all();
    }

    /**
     * Obtient le nom de classe du premier paramètre de la fermeture donnée.
     *
     * @throws ReflectionException
     * @throws RuntimeException
     */
    protected function firstClosureParameterType(Closure $closure): string
    {
        $types = array_values($this->closureParameterTypes($closure));

        if (! $types) {
            throw new RuntimeException('La fermeture donnée n\'a pas de paramètres.');
        }

        if ($types[0] === null) {
            throw new RuntimeException('Le premier paramètre de la fermeture donnée n\'a pas d\'indication de type.');
        }

        return $types[0];
    }
}
