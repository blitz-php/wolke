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
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\LazyLoadingViolationException</a>
 */
class LazyLoadingViolationException extends RuntimeException
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
     * @param string $relation Le nom de la relation.
     */
    public function __construct(object $model, public string $relation)
    {
        $class = get_class($model);

        parent::__construct("Tentative de chargement paresseux de [{$relation}] sur le modèle [{$class}] mais le chargement paresseux est désactivé.");

        $this->model = $class;
    }
}
