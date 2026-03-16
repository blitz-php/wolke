<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Concerns;

use BlitzPHP\Utilities\String\Text;

trait HasUuids
{
    use HasUniqueStringIds;

    /**
     * Generate a new UUID for the model.
     */
    public function newUniqueId(): string
    {
        return Text::uuid7();
    }

    /**
     * Determine if given key is valid.
     */
    protected function isValidUniqueId(mixed $value): bool
    {
        return Text::isUuid($value);
    }
}
