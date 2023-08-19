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
use Throwable;

class MultipleRecordsFoundException extends RuntimeException
{
    /**
     * Create a new exception instance.
     */
    public function __construct(public int $count, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct("{$count} records were found.", $code, $previous);
    }

    /**
     * Get the number of records found.
     */
    public function getCount(): int
    {
        return $this->count;
    }
}
