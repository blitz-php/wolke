<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Contracts;

use BlitzPHP\Wolke\Model;

interface CastsInboundAttributes
{
    /**
     * Transform the attribute to its underlying model values.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed;
}
