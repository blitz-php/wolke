<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Casts;

use ArrayObject as BaseArrayObject;
use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Collection;
use JsonSerializable;

class ArrayObject extends BaseArrayObject implements Arrayable, JsonSerializable
{
    /**
     * Get a collection containing the underlying array.
     */
    public function collect(): Collection
    {
        return Helpers::collect($this->getArrayCopy());
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return $this->getArrayCopy();
    }

    /**
     * Get the array that should be JSON serialized.
     */
    public function jsonSerialize(): array
    {
        return $this->getArrayCopy();
    }
}
