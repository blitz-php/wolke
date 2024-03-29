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

class InvalidCastException extends RuntimeException
{
    /**
     * The name of the affected Eloquent model.
     *
     * @var string
     */
    public $model;

    /**
     * Create a new exception instance.
     *
     * @param string $column   The name of the column.
     * @param string $castType The name of the cast type.
     *
     * @return static
     */
    public function __construct(object $model, public string $column, public string $castType)
    {
        $class = get_class($model);

        parent::__construct("Call to undefined cast [{$castType}] on column [{$column}] in model [{$class}].");

        $this->model = $class;
    }
}
