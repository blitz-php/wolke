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

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\Concerns\SupportsDefaultModels</a>
 */
trait SupportsDefaultModels
{
    /**
     * Indique si une instance de modèle par défaut doit être utilisée.
     *
     * Alternativement, peut être une Closure ou un tableau.
     *
     * @var array|bool|Closure
     */
    protected $withDefault;

    /**
     * Crée une nouvelle instance liée pour le modèle donné.
     */
    abstract protected function newRelatedInstanceFor(Model $parent): Model;

    /**
     * Retourne une nouvelle instance de modèle dans le cas où la relation n'existe pas.
     */
    public function withDefault(array|bool|Closure $callback = true): static
    {
        $this->withDefault = $callback;

        return $this;
    }

    /**
     * Obtient la valeur par défaut pour cette relation.
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
