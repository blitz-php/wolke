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
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\ClassMorphViolationException</a>
 */
class ClassMorphViolationException extends RuntimeException
{
    /**
     * Le nom du modèle Wolke concerné.
     */
    public string $model;

    /**
     * Crée une nouvelle instance d'exception.
     */
    public function __construct(object $model)
    {
        $class = get_class($model);

        parent::__construct("Aucune carte morph définie pour le modèle [{$class}].");

        $this->model = $class;
    }
}
