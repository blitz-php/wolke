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
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\RelationNotFoundException</a>
 */
class RelationNotFoundException extends RuntimeException
{
    /**
     * Le nom du modèle Eloquent concerné.
     *
     * @var string
     */
    public $model;

    /**
     * Le nom de la relation.
     *
     * @var string
     */
    public $relation;

    /**
     * Crée une nouvelle instance d'exception.
     *
     * @return static
     */
    public static function make(object $model, string $relation, ?string $type = null)
    {
        $class = get_class($model);

        $instance = new static(
            null === $type
                ? "Appel à la relation [{$relation}] non définie sur le modèle [{$class}]."
                : "Appel à la relation [{$relation}] non définie sur le modèle [{$class}] de type [{$type}].",
        );

        $instance->model    = $class;
        $instance->relation = $relation;

        return $instance;
    }
}
