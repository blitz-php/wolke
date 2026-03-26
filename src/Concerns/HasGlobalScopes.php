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
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Wolke\Attributes\ScopedBy;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Contracts\Scope;
use Closure;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\HasGlobalScopes</a>
 */
trait HasGlobalScopes
{
    /**
     * Initialise le trait des portées globales pour un modèle.
     */
    public static function bootHasGlobalScopes(): void
    {
        static::addGlobalScopes(static::resolveGlobalScopeAttributes());
    }

    /**
     * Résout les noms de classe de portée globale à partir des attributs.
     */
    public static function resolveGlobalScopeAttributes(): array
    {
        $reflectionClass = new ReflectionClass(static::class);

        $attributes = (new Collection($reflectionClass->getAttributes(ScopedBy::class, ReflectionAttribute::IS_INSTANCEOF)));

        foreach ($reflectionClass->getTraits() as $trait) {
            $attributes->push(...$trait->getAttributes(ScopedBy::class, ReflectionAttribute::IS_INSTANCEOF));
        }

        return $attributes->map(static fn ($attribute) => $attribute->getArguments())
            ->flatten()
            ->all();
    }

    /**
     * Enregistre une nouvelle portée globale sur le modèle.
     *
     * @param (Closure(Builder<static>): mixed)|Scope|string $scope
     * @param (Closure(Builder<static>): mixed)|Scope|null   $implementation
     *
     * @throws InvalidArgumentException
     */
    public static function addGlobalScope(Closure|Scope|string $scope, Closure|Scope|null $implementation = null): mixed
    {
        if (is_string($scope) && ($implementation instanceof Closure || $implementation instanceof Scope)) {
            return static::$globalScopes[static::class][$scope] = $implementation;
        }
        if ($scope instanceof Closure) {
            return static::$globalScopes[static::class][spl_object_hash($scope)] = $scope;
        }
        if ($scope instanceof Scope) {
            return static::$globalScopes[static::class][$scope::class] = $scope;
        }
        if (is_string($scope) && class_exists($scope) && is_subclass_of($scope, Scope::class)) {
            return static::$globalScopes[static::class][$scope] = new $scope();
        }

        throw new InvalidArgumentException('La portée globale doit être une instance de Closure ou Scope ou un nom de classe d\'une classe étendant ' . Scope::class);
    }

    /**
     * Enregistre plusieurs portées globales sur le modèle.
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
     * Détermine si un modèle a une portée globale.
     */
    public static function hasGlobalScope(Scope|string $scope): bool
    {
        return null !== static::getGlobalScope($scope);
    }

    /**
     * Obtient une portée globale enregistrée avec le modèle.
     *
     * @return (Closure(Builder<static>): mixed)|Scope|null
     */
    public static function getGlobalScope(Scope|string $scope)
    {
        if (is_string($scope)) {
            return Arr::get(static::$globalScopes, static::class . '.' . $scope);
        }

        return Arr::get(
            static::$globalScopes,
            static::class . '.' . $scope::class,
        );
    }

    /**
     * Obtient toutes les portées globales actuellement enregistrées.
     */
    public static function getAllGlobalScopes(): array
    {
        return static::$globalScopes;
    }

    /**
     * Définit les portées globales actuelles.
     */
    public static function setAllGlobalScopes(array $scopes): void
    {
        static::$globalScopes = $scopes;
    }

    /**
     * Obtient les portées globales pour cette instance de classe.
     */
    public function getGlobalScopes(): array
    {
        return Arr::get(static::$globalScopes, static::class, []);
    }
}
