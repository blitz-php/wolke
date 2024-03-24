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
     */
    protected array $hidden = [];

    /**
     * The attributes that should be visible in serialization.
     */
    protected array $visible = [];

    /**
     * Get the hidden attributes for the model.
     */
    public function getHidden(): array
    {
        return $this->hidden;
    }

    /**
     * Set the hidden attributes for the model.
     */
    public function setHidden(array $hidden): self
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * Get the visible attributes for the model.
     */
    public function getVisible(): array
    {
        return $this->visible;
    }

    /**
     * Set the visible attributes for the model.
     */
    public function setVisible(array $visible): self
    {
        $this->visible = $visible;

        return $this;
    }

    /**
     * Make the given, typically hidden, attributes visible.
     *
     * @param  array|...string|null  $attributes
     */
    public function makeVisible($attributes): self
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
     */
    public function makeVisibleIf(bool|Closure $condition, null|array|string $attributes): self
    {
        return Helpers::value($condition, $this) ? $this->makeVisible($attributes) : $this;
    }

    /**
     * Make the given, typically visible, attributes hidden.
     *
     * @param  array|...string|null  $attributes
     */
    public function makeHidden($attributes): self
    {
        $this->hidden = array_values(array_unique(array_merge(
            $this->hidden,
            is_array($attributes) ? $attributes : func_get_args()
        )));

        return $this;
    }

    /**
     * Make the given, typically visible, attributes hidden if the given truth test passes.
     */
    public function makeHiddenIf(bool|Closure $condition, null|array|string $attributes): self
    {
        return Helpers::value($condition, $this) ? $this->makeHidden($attributes) : $this;
    }
}
