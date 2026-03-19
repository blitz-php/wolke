<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Exceptions;

use RuntimeException;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\InvalidCastException</a>
 */
class InvalidCastException extends RuntimeException
{
    /**
     * Le nom du modèle Eloquent concerné.
     *
     * @var string
     */
    public $model;

    /**
     * Crée une nouvelle instance d'exception.
     *
     * @param string $column   Le nom de la colonne.
     * @param string $castType Le nom du type de cast.
     *
     * @return static
     */
    public function __construct(object $model, public string $column, public string $castType)
    {
        $class = $model::class;

        parent::__construct("Appel au cast [{$castType}] non défini sur la colonne [{$column}] dans le modèle [{$class}].");

        $this->model = $class;
    }
}
