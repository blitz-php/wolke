<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke;

use BlitzPHP\Wolke\Attributes\CollectedBy;
use ReflectionClass;

/**
 * @template TCollection of Collection
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\HasCollection</a>
 */
trait HasCollection
{
    /**
     * Les noms de classe de collection résolus par modèle.
     *
     * @var array<class-string<static>, class-string<TCollection>>
     */
    protected static array $resolvedCollectionClasses = [];

    /**
     * Crée une nouvelle instance de Collection Eloquent.
     *
     * @param  array<array-key, Model>  $models
     * 
     * @return TCollection
     */
    public function newCollection(array $models = [])
    {
        static::$resolvedCollectionClasses[static::class] ??= ($this->resolveCollectionFromAttribute() ?? static::$collectionClass);

        $collection = new static::$resolvedCollectionClasses[static::class]($models);

        if (Model::isAutomaticallyEagerLoadingRelationships()) {
            $collection->withRelationshipAutoloading();
        }

        return $collection;
    }

    /**
     * Résout le nom de classe de collection à partir de l'attribut CollectedBy.
     *
     * @return class-string<TCollection>|null
     */
    public function resolveCollectionFromAttribute()
    {
        $reflectionClass = new ReflectionClass(static::class);

        $attributes = $reflectionClass->getAttributes(CollectedBy::class);

        if (! isset($attributes[0]) || ! isset($attributes[0]->getArguments()[0])) {
            return;
        }

        return $attributes[0]->getArguments()[0];
    }
}
