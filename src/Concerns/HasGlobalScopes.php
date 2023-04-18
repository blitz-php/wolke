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

use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Wolke\Contracts\Scope;
use Closure;
use InvalidArgumentException;

trait HasGlobalScopes
{
    /**
     * The array of global scopes on the model.
     */
    protected static array $globalScopes = [];

    /**
     * Register a new global scope on the model.
     *
     * @throws InvalidArgumentException
     */
    public static function addGlobalScope(Scope|Closure|string $scope, ?Closure $implementation = null): mixed
    {
        if (is_string($scope) && null !== $implementation) {
            return static::$globalScopes[static::class][$scope] = $implementation;
        }
        if ($scope instanceof Closure) {
            return static::$globalScopes[static::class][spl_object_hash($scope)] = $scope;
        }
        if ($scope instanceof Scope) {
            return static::$globalScopes[static::class][get_class($scope)] = $scope;
        }

        throw new InvalidArgumentException('Global scope must be an instance of Closure or Scope.');
    }

    /**
     * Determine if a model has a global scope.
     */
    public static function hasGlobalScope(Scope|string $scope): bool
    {
        return null !== static::getGlobalScope($scope);
    }

    /**
     * Get a global scope registered with the model.
     *
     * @return Closure|Scope|null
     */
    public static function getGlobalScope(Scope|string $scope)
    {
        if (is_string($scope)) {
            return Arr::get(static::$globalScopes, static::class . '.' . $scope);
        }

        return Arr::get(
            static::$globalScopes,
            static::class . '.' . get_class($scope)
        );
    }

    /**
     * Get the global scopes for this class instance.
     */
    public function getGlobalScopes(): array
    {
        return Arr::get(static::$globalScopes, static::class, []);
    }
}
