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
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\JsonEncodingException</a>
 */
class JsonEncodingException extends RuntimeException
{
    /**
     * Crée une nouvelle exception d'encodage JSON pour le modèle.
     *
     * @return static
     */
    public static function forModel(object $model, string $message)
    {
        return new static('Erreur d\'encodage du modèle [' . $model::class . '] avec l\'ID [' . $model->getKey() . '] en JSON : ' . $message);
    }

    /**
     * Crée une nouvelle exception d'encodage JSON pour la ressource.
     *
     * @return static
     */
    public static function forResource($resource, string $message)
    {
        $model = $resource->resource;

        return new static('Erreur d\'encodage de la ressource [' . $resource::class . '] avec le modèle [' . $model::class . '] avec l\'ID [' . $model->getKey() . '] en JSON : ' . $message);
    }

    /**
     * Crée une nouvelle exception d'encodage JSON pour un attribut.
     *
     * @return static
     */
    public static function forAttribute(object $model, mixed $key, string $message)
    {
        $class = $model::class;

        return new static("Impossible d'encoder l'attribut [{$key}] pour le modèle [{$class}] en JSON : {$message}.");
    }
}
