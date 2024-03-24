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

class JsonEncodingException extends RuntimeException
{
    /**
     * Create a new JSON encoding exception for the model.
     *
     * @return static
     */
    public static function forModel(object $model, string $message)
    {
        return new static('Error encoding model [' . get_class($model) . '] with ID [' . $model->getKey() . '] to JSON: ' . $message);
    }

    /**
     * Create a new JSON encoding exception for the resource.
     *
     * @param \Illuminate\Http\Resources\Json\JsonResource $resource
     *
     * @return static
     */
    public static function forResource($resource, string $message)
    {
        $model = $resource->resource;

        return new static('Error encoding resource [' . get_class($resource) . '] with model [' . get_class($model) . '] with ID [' . $model->getKey() . '] to JSON: ' . $message);
    }

    /**
     * Create a new JSON encoding exception for an attribute.
     *
     * @return static
     */
    public static function forAttribute(object $model, mixed $key, string $message)
    {
        $class = get_class($model);

        return new static("Unable to encode attribute [{$key}] for model [{$class}] to JSON: {$message}.");
    }
}
