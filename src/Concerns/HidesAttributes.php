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

use BlitzPHP\Utilities\Helpers;
use Closure;

trait HidesAttributes
{
    /**
     * The attributes that should be hidden for serialization.
     * 
     * @var list<string>
     */
    protected array $hidden = [];

    /**
     * The attributes that should be visible in serialization.
     * 
     * @var list<string>
     */
    protected array $visible = [];

    /**
     * Get the hidden attributes for the model.
     * 
     * @return list<string>
     */
    public function getHidden(): array
    {
        return $this->hidden;
    }

    /**
     * Set the hidden attributes for the model.
     * 
     * @param list<string> $hidden
     */
    public function setHidden(array $hidden): static
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * Merge new hidden attributes with existing hidden attributes on the model.
     *
     * @param  list<string>  $hidden
     */
    public function mergeHidden(array $hidden): static
    {
        $this->hidden = array_values(array_unique(array_merge($this->hidden, $hidden)));

        return $this;
    }

    /**
     * Get the visible attributes for the model.
     * 
     * @return list<string>
     */
    public function getVisible(): array
    {
        return $this->visible;
    }

    /**
     * Set the visible attributes for the model.
     * 
     * @param list<string> $visible
     */
    public function setVisible(array $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    /**
     * Merge new visible attributes with existing visible attributes on the model.
     *
     * @param  list<string>  $visible
     */
    public function mergeVisible(array $visible): static
    {
        $this->visible = array_values(array_unique(array_merge($this->visible, $visible)));

        return $this;
    }

    /**
     * Make the given, typically hidden, attributes visible.
     *
     * @param  list<string>|string|null  $attributes
     */
    public function makeVisible(array|string|null $attributes): static
    {
        $attributes = is_array($attributes) ? $attributes : func_get_args();

        $this->hidden = array_diff($this->hidden, $attributes);

        if (! empty($this->visible)) {
            $this->visible = array_values(array_unique(array_merge($this->visible, $attributes)));
        }

        return $this;
    }

    /**
     * Make the given, typically hidden, attributes visible if the given truth test passes.
     *
     * @param  list<string>|string|null  $attributes
     */
    public function makeVisibleIf(bool|Closure $condition, array|string|null $attributes): static
    {
        return Helpers::value($condition, $this) ? $this->makeVisible($attributes) : $this;
    }

    /**
     * Make the given, typically visible, attributes hidden.
     *
     * @param  list<string>|string|null  $attributes
     */
    public function makeHidden($attributes): static
    {
        $this->hidden = array_values(array_unique(array_merge(
            $this->hidden,
            is_array($attributes) ? $attributes : func_get_args()
        )));

        return $this;
    }

    /**
     * Make the given, typically visible, attributes hidden if the given truth test passes.
     *
     * @param  list<string>|string|null  $attributes
     */
    public function makeHiddenIf(bool|Closure $condition, array|string|null $attributes): static
    {
        return Helpers::value($condition, $this) ? $this->makeHidden($attributes) : $this;
    }
}
