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
     * L'accesseur d'attribut.
     *
     * @var callable
     */
    public $get;

    /**
     * Le mutateur d'attribut.
     *
     * @var callable
     */
    public $set;

    /**
     * Indique si la mise en cache est activée pour cet attribut.
     */
    public bool $withCaching = false;

    /**
     * Indique si la mise en cache des objets est activée pour cet attribut.
     */
    public bool $withObjectCaching = true;

    /**
     * Crée un nouvel accesseur/mutateur d'attribut.
     */
    public function __construct(?callable $get = null, ?callable $set = null)
    {
        $this->get = $get;
        $this->set = $set;
    }

    /**
     * Crée un nouvel accesseur/mutateur d'attribut.
     */
    public static function make(?callable $get = null, ?callable $set = null): static
    {
        return new static($get, $set);
    }

    /**
     * Crée un nouvel accesseur d'attribut.
     */
    public static function get(callable $get): static
    {
        return new static($get);
    }

    /**
     * Crée un nouveau mutateur d'attribut.
     */
    public static function set(callable $set): static
    {
        return new static(null, $set);
    }

    /**
     * Désactive la mise en cache des objets pour l'attribut.
     */
    public function withoutObjectCaching(): self
    {
        $this->withObjectCaching = false;

        return $this;
    }

    /**
     * Active la mise en cache pour l'attribut.
     */
    public function shouldCache(): self
    {
        $this->withCaching = true;

        return $this;
    }
}
