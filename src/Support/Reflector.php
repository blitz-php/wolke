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

use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Support\Reflector</a>
 */
class Reflector
{
    /**
     * Ceci est une implémentation compatible PHP 7.4 de is_callable.
     */
    public static function isCallable(mixed $var, bool $syntaxOnly = false): bool
    {
        if (! is_array($var)) {
            return is_callable($var, $syntaxOnly);
        }

        if (
            (! isset($var[0]) || ! isset($var[1]))
            || ! is_string($var[1] ?? null)
        ) {
            return false;
        }

        if (
            $syntaxOnly
            && (is_string($var[0]) || is_object($var[0]))
            && is_string($var[1])
        ) {
            return true;
        }

        $class = is_object($var[0]) ? get_class($var[0]) : $var[0];

        $method = $var[1];

        if (! class_exists($class)) {
            return false;
        }

        if (method_exists($class, $method)) {
            return (new ReflectionMethod($class, $method))->isPublic();
        }

        if (is_object($var[0]) && method_exists($class, '__call')) {
            return (new ReflectionMethod($class, '__call'))->isPublic();
        }

        if (! is_object($var[0]) && method_exists($class, '__callStatic')) {
            return (new ReflectionMethod($class, '__callStatic'))->isPublic();
        }

        return false;
    }

    /**
     * Obtient le nom de classe du type du paramètre donné, si possible.
     */
    public static function getParameterClassName(ReflectionParameter $parameter): ?string
    {
        $type = $parameter->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }

        $name = $type->getName();

        if (null !== ($class = $parameter->getDeclaringClass())) {
            if ($name === 'self') {
                return $class->getName();
            }

            if ($name === 'parent' && $parent = $class->getParentClass()) {
                return $parent->getName();
            }
        }

        return $name;
    }

    /**
     * Détermine si le type du paramètre est une sous-classe du type donné.
     */
    public static function isParameterSubclassOf(ReflectionParameter $parameter, string $className): bool
    {
        $paramClassName = static::getParameterClassName($parameter);

        return ($paramClassName && class_exists($paramClassName))
            ? (new ReflectionClass($paramClassName))->isSubclassOf($className)
            : false;
    }
}
