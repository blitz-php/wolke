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
 *
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\HasBuilder</a>
 */
trait HasBuilder
{
    /**
     * Commence une requête sur le modèle.
     *
     * @return TBuilder
     */
    public static function query()
    {
        return parent::query();
    }

    /**
     * Crée un nouveau constructeur de requête Wolke pour le modèle.
     *
     * @return TBuilder
     */
    public function newWolkeBuilder(BaseBuilder $query)
    {
        return parent::newWolkeBuilder($query);
    }

    /**
     * Obtient un nouveau constructeur de requête pour la table du modèle.
     *
     * @return TBuilder
     */
    public function newQuery()
    {
        return parent::newQuery();
    }

    /**
     * Obtient un nouveau constructeur de requête qui n'a pas de portées globales ni de chargements empressés.
     *
     * @return TBuilder
     */
    public function newModelQuery()
    {
        return parent::newModelQuery();
    }

    /**
     * Obtient un nouveau constructeur de requête sans relations chargées.
     *
     * @return TBuilder
     */
    public function newQueryWithoutRelationships()
    {
        return parent::newQueryWithoutRelationships();
    }

    /**
     * Obtient un nouveau constructeur de requête qui n'a pas de portées globales.
     *
     * @return TBuilder
     */
    public function newQueryWithoutScopes()
    {
        return parent::newQueryWithoutScopes();
    }

    /**
     * Obtient une nouvelle instance de requête sans une portée donnée.
     *
     * @return TBuilder
     */
    public function newQueryWithoutScope(Scope|string $scope)
    {
        return parent::newQueryWithoutScope($scope);
    }

    /**
     * Obtient une nouvelle requête pour restaurer un ou plusieurs modèles par leurs IDs de file d'attente.
     *
     * @return TBuilder
     */
    public function newQueryForRestoration(array|int $ids)
    {
        return parent::newQueryForRestoration($ids);
    }

    /**
     * Commence une requête sur le modèle sur une connexion donnée.
     *
     * @return TBuilder
     */
    public static function on(?string $connection = null)
    {
        return parent::on($connection);
    }

    /**
     * Commence une requête sur le modèle sur la connexion d'écriture.
     *
     * @return TBuilder
     */
    public static function onWriteConnection()
    {
        return parent::onWriteConnection();
    }

    /**
     * Commence une requête sur un modèle avec chargement empressé.
     *
     * @return TBuilder
     */
    public static function with(array|string $relations)
    {
        return parent::with($relations);
    }
}
