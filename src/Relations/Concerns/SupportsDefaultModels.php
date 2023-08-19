<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Relations\Concerns;

use BlitzPHP\Wolke\Model;
use Closure;

trait SupportsDefaultModels
{
    /**
     * Indicates if a default model instance should be used.
     *
     * Alternatively, may be a Closure or array.
     *
     * @var array|bool|Closure
     */
    protected $withDefault;

    /**
     * Make a new related instance for the given model.
     */
    abstract protected function newRelatedInstanceFor(Model $parent): Model;

    /**
     * Return a new model instance in case the relationship does not exist.
     */
    public function withDefault(array|bool|Closure $callback = true): self
    {
        $this->withDefault = $callback;

        return $this;
    }

    /**
     * Get the default value for this relation.
     */
    protected function getDefaultFor(Model $parent): ?Model
    {
        if (! $this->withDefault) {
            return null;
        }

        $instance = $this->newRelatedInstanceFor($parent);

        if (is_callable($this->withDefault)) {
            return ($this->withDefault)($instance, $parent) ?: $instance;
        }

        if (is_array($this->withDefault)) {
            $instance->forceFill($this->withDefault);
        }

        return $instance;
    }
}
