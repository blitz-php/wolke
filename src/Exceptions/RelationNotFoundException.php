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

class RelationNotFoundException extends RuntimeException
{
    /**
     * The name of the affected Eloquent model.
     *
     * @var string
     */
    public $model;

    /**
     * The name of the relation.
     *
     * @var string
     */
    public $relation;

    /**
     * Create a new exception instance.
     *
     * @return static
     */
    public static function make(object $model, string $relation, ?string $type = null)
    {
        $class = get_class($model);

        $instance = new static(
            null === $type
                ? "Call to undefined relationship [{$relation}] on model [{$class}]."
                : "Call to undefined relationship [{$relation}] on model [{$class}] of type [{$type}].",
        );

        $instance->model    = $class;
        $instance->relation = $relation;

        return $instance;
    }
}
