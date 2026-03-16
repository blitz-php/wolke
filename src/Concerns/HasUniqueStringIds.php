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

use BlitzPHP\Wolke\Exceptions\ModelNotFoundException;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Relation;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds</a>
 */
trait HasUniqueStringIds
{
    /**
     * Génère une nouvelle clé unique pour le modèle.
     */
    abstract public function newUniqueId(): mixed;

    /**
     * Détermine si la clé donnée est valide.
     */
    abstract protected function isValidUniqueId(mixed $value): bool;

    /**
     * Initialise le trait.
     */
    public function initializeHasUniqueStringIds(): void
    {
        $this->usesUniqueIds = true;
    }

    /**
     * Obtient les colonnes qui doivent recevoir un identifiant unique.
     */
    public function uniqueIds(): array
    {
        return $this->usesUniqueIds() ? [$this->getKeyName()] : parent::uniqueIds();
    }

    /**
     * Récupère le modèle pour une valeur liée.
     *
     * @throws ModelNotFoundException
     */
    public function resolveRouteBindingQuery(Model|Relation $query, mixed $value, ?string $field = null): Relation
    {
        if ($field && in_array($field, $this->uniqueIds(), true) && ! $this->isValidUniqueId($value)) {
            $this->handleInvalidUniqueId($value, $field);
        }
            
        if (! $field && in_array($this->getRouteKeyName(), $this->uniqueIds(), true) && ! $this->isValidUniqueId($value)) {
            $this->handleInvalidUniqueId($value, $field);
        }

        return parent::resolveRouteBindingQuery($query, $value, $field);
    }

    /**
     * Obtient le type de clé auto-incrémentée.
     */
    public function getKeyType(): string
    {
        if (in_array($this->getKeyName(), $this->uniqueIds(), true)) {
            return 'string';
        }

        return parent::getKeyType();
    }

    /**
     * Obtient la valeur indiquant si les ID sont incrémentés.
     */
    public function getIncrementing(): bool
    {
        if (in_array($this->getKeyName(), $this->uniqueIds(), true)) {
            return false;
        }

        return parent::getIncrementing();
    }

    /**
     * Lance une exception pour l'identifiant unique invalide donné.
     * 
     * @return never
     *
     * @throws ModelNotFoundException
     */
    protected function handleInvalidUniqueId(mixed $value, ?string $field)
    {
        throw (new ModelNotFoundException())->setModel(static::class, $value);
    }
}
