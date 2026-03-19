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

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\HidesAttributes</a>
 */
trait HidesAttributes
{
    /**
     * Les attributs qui doivent être cachés pour la sérialisation.
     *
     * @var list<string>
     */
    protected array $hidden = [];

    /**
     * Les attributs qui doivent être visibles dans la sérialisation.
     *
     * @var list<string>
     */
    protected array $visible = [];

    /**
     * Obtient les attributs cachés pour le modèle.
     *
     * @return list<string>
     */
    public function getHidden(): array
    {
        return $this->hidden;
    }

    /**
     * Définit les attributs cachés pour le modèle.
     *
     * @param list<string> $hidden
     */
    public function setHidden(array $hidden): static
    {
        $this->hidden = $hidden;

        return $this;
    }

    /**
     * Fusionne de nouveaux attributs cachés avec les attributs cachés existants sur le modèle.
     *
     * @param list<string> $hidden
     */
    public function mergeHidden(array $hidden): static
    {
        $this->hidden = array_values(array_unique(array_merge($this->hidden, $hidden)));

        return $this;
    }

    /**
     * Obtient les attributs visibles pour le modèle.
     *
     * @return list<string>
     */
    public function getVisible(): array
    {
        return $this->visible;
    }

    /**
     * Définit les attributs visibles pour le modèle.
     *
     * @param list<string> $visible
     */
    public function setVisible(array $visible): static
    {
        $this->visible = $visible;

        return $this;
    }

    /**
     * Fusionne de nouveaux attributs visibles avec les attributs visibles existants sur le modèle.
     *
     * @param list<string> $visible
     */
    public function mergeVisible(array $visible): static
    {
        $this->visible = array_values(array_unique(array_merge($this->visible, $visible)));

        return $this;
    }

    /**
     * Rend les attributs donnés, typiquement cachés, visibles.
     *
     * @param list<string>|string|null $attributes
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
     * Rend les attributs donnés, typiquement cachés, visibles si le test de vérité donné réussit.
     *
     * @param list<string>|string|null $attributes
     */
    public function makeVisibleIf(bool|Closure $condition, array|string|null $attributes): static
    {
        return Helpers::value($condition, $this) ? $this->makeVisible($attributes) : $this;
    }

    /**
     * Rend les attributs donnés, typiquement visibles, cachés.
     *
     * @param list<string>|string|null $attributes
     */
    public function makeHidden($attributes): static
    {
        $this->hidden = array_values(array_unique(array_merge(
            $this->hidden,
            is_array($attributes) ? $attributes : func_get_args(),
        )));

        return $this;
    }

    /**
     * Rend les attributs donnés, typiquement visibles, cachés si le test de vérité donné réussit.
     *
     * @param list<string>|string|null $attributes
     */
    public function makeHiddenIf(bool|Closure $condition, array|string|null $attributes): static
    {
        return Helpers::value($condition, $this) ? $this->makeHidden($attributes) : $this;
    }
}
