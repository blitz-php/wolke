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

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Casts\Attribute</a>
 */
class Attribute
{
    /**
     * The attribute accessor.
     *
     * @var callable
     */
    public $get;

    /**
     * The attribute mutator.
     *
     * @var callable
     */
    public $set;

    /**
     * Indicates if caching is enabled for this attribute.
     */
    public bool $withCaching = false;

    /**
     * Indicates if caching of objects is enabled for this attribute.
     */
    public bool $withObjectCaching = true;

    /**
     * Create a new attribute accessor / mutator.
     *
     * @return void
     */
    public function __construct(?callable $get = null, ?callable $set = null)
    {
        $this->get = $get;
        $this->set = $set;
    }

    /**
     * Create a new attribute accessor / mutator.
     */
    public static function make(?callable $get = null, ?callable $set = null): static
    {
        return new static($get, $set);
    }

    /**
     * Create a new attribute accessor.
     */
    public static function get(callable $get): static
    {
        return new static($get);
    }

    /**
     * Create a new attribute mutator.
     */
    public static function set(callable $set): static
    {
        return new static(null, $set);
    }

    /**
     * Disable object caching for the attribute.
     */
    public function withoutObjectCaching(): self
    {
        $this->withObjectCaching = false;

        return $this;
    }

    /**
     * Enable caching for the attribute.
     */
    public function shouldCache(): self
    {
        $this->withCaching = true;

        return $this;
    }
}
