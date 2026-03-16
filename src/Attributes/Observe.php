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
use BlitzPHP\Wolke\Model;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
class Observe
{
    /**
     * @param class-string<Model> $class La classe en observation
     */
    public function __construct(public string $class)
    {
        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            throw new InvalidArgumentException(sprintf('La classe observée doit être une sous-classe de %s', Model::class));
        }
    }
}
