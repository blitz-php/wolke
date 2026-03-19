<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Attributes;

use Attribute;
use BlitzPHP\Wolke\Builder;

#[Attribute(Attribute::TARGET_CLASS)]
class UseWolkeBuilder
{
    /**
     * Crée une nouvelle instance d'attribut.
     *
     * @param class-string<Builder> $builderClass Le query builder à utiliser
     */
    public function __construct(public string $builderClass)
    {
    }
}
