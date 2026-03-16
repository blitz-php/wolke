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

use BlitzPHP\Wolke\Model;
use OutOfBoundsException;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\MissingAttributeException</a>
 */
class MissingAttributeException extends OutOfBoundsException
{
    /**
     * Crée une nouvelle instance d'exception d'attribut manquant.
     */
    public function __construct(Model $model, string $key)
    {
        parent::__construct(sprintf(
            'L\'attribut [%s] n\'existe pas ou n\'a pas été récupéré pour le modèle [%s].',
            $key,
            get_class($model)
        ));
    }
}
