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

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\HasUniqueIds</a>
 */
trait HasUniqueIds
{
    /**
     * Indique si le modèle utilise des identifiants uniques.
     */
    public bool $usesUniqueIds = false;

    /**
     * Détermine si le modèle utilise des identifiants uniques.
     */
    public function usesUniqueIds(): bool
    {
        return $this->usesUniqueIds;
    }

    /**
     * Génère des clés uniques pour le modèle.
     */
    public function setUniqueIds(): void
    {
        foreach ($this->uniqueIds() as $column) {
            if (empty($this->{$column})) {
                $this->{$column} = $this->newUniqueId();
            }
        }
    }

    /**
     * Génère une nouvelle clé pour le modèle.
     */
    public function newUniqueId(): ?string
    {
        return null;
    }

    /**
     * Obtient les colonnes qui doivent recevoir un identifiant unique.
     */
    public function uniqueIds(): array
    {
        return [];
    }
}
