<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Relations;

use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Wolke\Builder;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\MorphPivot</a>
 */
class MorphPivot extends Pivot
{
    /**
     * Le type de la relation polymorphe.
     *
     * Défini explicitement pour ne pas être inclus dans les attributs sauvegardés.
     *
     * @var string
     */
    protected $morphType;

    /**
     * La valeur de la relation polymorphe.
     *
     * Défini explicitement pour ne pas être inclus dans les attributs sauvegardés.
     *
     * @var string
     */
    protected $morphClass;

    /**
     * Définit les clés pour une requête de sauvegarde de mise à jour.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery(Builder $query): Builder
    {
        $query->where($this->morphType, $this->morphClass);

        return parent::setKeysForSaveQuery($query);
    }

    /**
     * Définit les clés pour une requête de sélection.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    protected function setKeysForSelectQuery(Builder $query): Builder
    {
        $query->where($this->morphType, $this->morphClass);

        return parent::setKeysForSelectQuery($query);
    }

    /**
     * Supprime l'enregistrement du modèle pivot de la base de données.
     *
     * @return int
     */
    public function delete()
    {
        if (isset($this->attributes[$this->getKeyName()])) {
            return (int) parent::delete();
        }

        if ($this->fireModelEvent('deleting') === false) {
            return 0;
        }

        $query = $this->getDeleteQuery();

        $query->where($this->morphType, $this->morphClass);

        return Helpers::tap($query->delete(), function () {
            $this->exists = false;

            $this->fireModelEvent('deleted', false);
        });
    }

    /**
     * Obtient le type morph pour le pivot.
     */
    public function getMorphType(): string
    {
        return $this->morphType;
    }

    /**
     * Définit le type morph pour le pivot.
     */
    public function setMorphType(string $morphType): static
    {
        $this->morphType = $morphType;

        return $this;
    }

    /**
     * Définit la classe morph pour le pivot.
     *
     * @param class-string $morphClass
     */
    public function setMorphClass(string $morphClass): static
    {
        $this->morphClass = $morphClass;

        return $this;
    }

    /**
     * Obtient l'identité mise en file d'attente pour l'entité.
     */
    public function getQueueableId(): mixed
    {
        if (isset($this->attributes[$this->getKeyName()])) {
            return $this->getKey();
        }

        return sprintf(
            '%s:%s:%s:%s:%s:%s',
            $this->foreignKey,
            $this->getAttribute($this->foreignKey),
            $this->relatedKey,
            $this->getAttribute($this->relatedKey),
            $this->morphType,
            $this->morphClass,
        );
    }

    /**
     * Obtient une nouvelle requête pour restaurer un ou plusieurs modèles par leurs IDs de file d'attente.
     *
     * @param list<int>|list<string>|string $ids
     *
     * @return Builder<static>
     */
    public function newQueryForRestoration($ids): Builder
    {
        if (is_array($ids)) {
            return $this->newQueryForCollectionRestoration($ids);
        }

        if (! str_contains($ids, ':')) {
            return parent::newQueryForRestoration($ids);
        }

        $segments = explode(':', $ids);

        return $this->newQueryWithoutScopes()
            ->where($segments[0], $segments[1])
            ->where($segments[2], $segments[3])
            ->where($segments[4], $segments[5]);
    }

    /**
     * Obtient une nouvelle requête pour restaurer plusieurs modèles par leurs IDs de file d'attente.
     *
     * @return Builder<static>
     */
    protected function newQueryForCollectionRestoration(array $ids): Builder
    {
        $ids = array_values($ids);

        if (! str_contains($ids[0], ':')) {
            return parent::newQueryForRestoration($ids);
        }

        $query = $this->newQueryWithoutScopes();

        foreach ($ids as $id) {
            $segments = explode(':', $id);

            $query->orWhere(static fn ($query) => $query->where($segments[0], $segments[1])
                ->where($segments[2], $segments[3])
                ->where($segments[4], $segments[5]));
        }

        return $query;
    }
}
