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

trait ReflectsClosures
{
    /**
     * Get the class names / types of the parameters of the given Closure.
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
     * Get the class name of the first parameter of the given Closure.
     *
     * @throws ReflectionException
     * @throws RuntimeException
     */
    protected function firstClosureParameterType(Closure $closure): string
    {
        $types = array_values($this->closureParameterTypes($closure));

        if (! $types) {
            throw new RuntimeException('The given Closure has no parameters.');
        }

        if ($types[0] === null) {
            throw new RuntimeException('The first parameter of the given Closure is missing a type hint.');
        }

        return $types[0];
    }
}
