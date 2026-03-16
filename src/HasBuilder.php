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

use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Wolke\Contracts\Scope;

/**
 * @template TBuilder of Builder
 */
trait HasBuilder
{
    /**
     * Begin querying the model.
     *
     * @return TBuilder
     */
    public static function query()
    {
        return parent::query();
    }

    /**
     * Create a new Wolke query builder for the model.
     *
     * @return TBuilder
     */
    public function newWolkeBuilder(BaseBuilder $query)
    {
        return parent::newWolkeBuilder($query);
    }

    /**
     * Get a new query builder for the model's table.
     *
     * @return TBuilder
     */
    public function newQuery()
    {
        return parent::newQuery();
    }

    /**
     * Get a new query builder that doesn't have any global scopes or eager loading.
     *
     * @return TBuilder
     */
    public function newModelQuery()
    {
        return parent::newModelQuery();
    }

    /**
     * Get a new query builder with no relationships loaded.
     *
     * @return TBuilder
     */
    public function newQueryWithoutRelationships()
    {
        return parent::newQueryWithoutRelationships();
    }

    /**
     * Get a new query builder that doesn't have any global scopes.
     *
     * @return TBuilder
     */
    public function newQueryWithoutScopes()
    {
        return parent::newQueryWithoutScopes();
    }

    /**
     * Get a new query instance without a given scope.
     *
     * @return TBuilder
     */
    public function newQueryWithoutScope(Scope|string $scope)
    {
        return parent::newQueryWithoutScope($scope);
    }

    /**
     * Get a new query to restore one or more models by their queueable IDs.
     *
     * @return TBuilder
     */
    public function newQueryForRestoration(array|int $ids)
    {
        return parent::newQueryForRestoration($ids);
    }

    /**
     * Begin querying the model on a given connection.
     *
     * @return TBuilder
     */
    public static function on(?string $connection = null)
    {
        return parent::on($connection);
    }

    /**
     * Begin querying the model on the write connection.
     *
     * @return TBuilder
     */
    public static function onWriteConnection()
    {
        return parent::onWriteConnection();
    }

    /**
     * Begin querying a model with eager loading.
     *
     * @return TBuilder
     */
    public static function with(array|string $relations)
    {
        return parent::with($relations);
    }
}
