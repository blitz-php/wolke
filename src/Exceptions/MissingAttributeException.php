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

class MissingAttributeException extends OutOfBoundsException
{
    /**
     * Create a new missing attribute exception instance.
     */
    public function __construct(Model $model, string $key)
    {
        parent::__construct(sprintf(
            'The attribute [%s] either does not exist or was not retrieved for model [%s].',
            $key,
            get_class($model)
        ));
    }
}
