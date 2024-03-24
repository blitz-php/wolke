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

class LazyLoadingViolationException extends RuntimeException
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
     * @param string $relation The name of the relation.
     */
    public function __construct(object $model, public string $relation)
    {
        $class = get_class($model);

        parent::__construct("Attempted to lazy load [{$relation}] on model [{$class}] but lazy loading is disabled.");

        $this->model = $class;
    }
}
