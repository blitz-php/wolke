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
use BlitzPHP\Wolke\Attributes\ScopedBy;
use BlitzPHP\Wolke\Contracts\Scope;
use Closure;
use InvalidArgumentException;
use ReflectionClass;

trait HasGlobalScopes
{
    /**
     * Boot the has global scopes trait for a model.
     */
    public static function bootHasGlobalScopes()
    {
        static::addGlobalScopes(static::resolveGlobalScopeAttributes());
    }

    /**
     * Resolve the global scope class names from the attributes.
     */
    public static function resolveGlobalScopeAttributes(): array
    {
        $reflectionClass = new ReflectionClass(static::class);

        return Helpers::collect($reflectionClass->getAttributes(ScopedBy::class))
            ->map(static fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->all();
    }

    /**
     * Register a new global scope on the model.
     *
     * @throws InvalidArgumentException
     */
    public static function addGlobalScope(Closure|Scope|string $scope, ?Closure $implementation = null): mixed
    {
        if (is_string($scope) && ($implementation instanceof Closure || $implementation instanceof Scope)) {
            return static::$globalScopes[static::class][$scope] = $implementation;
        }
        if ($scope instanceof Closure) {
            return static::$globalScopes[static::class][spl_object_hash($scope)] = $scope;
        }
        if ($scope instanceof Scope) {
            return static::$globalScopes[static::class][get_class($scope)] = $scope;
        }
        if (is_string($scope) && class_exists($scope) && is_subclass_of($scope, Scope::class)) {
            return static::$globalScopes[static::class][$scope] = new $scope();
        }

        throw new InvalidArgumentException('Global scope must be an instance of Closure or Scope or be a class name of a class extending ' . Scope::class);
    }

    /**
     * Register multiple global scopes on the model.
     */
    public static function addGlobalScopes(array $scopes): void
    {
        foreach ($scopes as $key => $scope) {
            if (is_string($key)) {
                static::addGlobalScope($key, $scope);
            } else {
                static::addGlobalScope($scope);
            }
        }
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
     * Get all of the global scopes that are currently registered.
     */
    public static function getAllGlobalScopes(): array
    {
        return static::$globalScopes;
    }

    /**
     * Set the current global scopes.
     */
    public static function setAllGlobalScopes(array $scopes): void
    {
        static::$globalScopes = $scopes;
    }

    /**
     * Get the global scopes for this class instance.
     */
    public function getGlobalScopes(): array
    {
        return Arr::get(static::$globalScopes, static::class, []);
    }
}
