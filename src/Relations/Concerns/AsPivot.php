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

use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Model;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\Concerns\AsPivot</a>
 */
trait AsPivot
{
    /**
     * Le modèle parent de la relation.
     *
     * @var Model
     */
    public $pivotParent;

    /**
     * Le modèle lié de la relation.
     *
     * @var Model
     */
    public $pivotRelated;

    /**
     * Le nom de la colonne de clé étrangère.
     */
    protected string $foreignKey = '';

    /**
     * Le nom de la colonne de "l'autre clé".
     */
    protected string $relatedKey = '';

    /**
     * Crée une nouvelle instance de modèle pivot.
     */
    public static function fromAttributes(Model $parent, array $attributes, string $table, bool $exists = false): static
    {
        $instance = new static();

        $instance->timestamps = $instance->hasTimestampAttributes($attributes);

        // Le modèle pivot est un modèle "dynamique" car nous définirons les tables dynamiquement
        // pour l'instance. Cela lui permet de fonctionner pour toutes les tables intermédiaires pour
        // les relations many-to-many qui sont définies par les classes de ce développeur.
        $instance->setConnection($parent->getConnectionName())
            ->setTable($table)
            ->forceFill($attributes)
            ->syncOriginal();

        // Nous stockons l'instance parent afin d'accéder aux noms des colonnes d'horodatage
        // pour le modèle, car les horodatages du modèle pivot ne sont pas facilement configurables
        // du point de vue du développeur. Nous pouvons utiliser les parents pour les obtenir.
        $instance->pivotParent = $parent;

        $instance->exists = $exists;

        return $instance;
    }

    /**
     * Crée un nouveau modèle pivot à partir des valeurs brutes retournées par une requête.
     */
    public static function fromRawAttributes(Model $parent, array $attributes, string $table, bool $exists = false): static
    {
        $instance = static::fromAttributes($parent, [], $table, $exists);

        $instance->timestamps = $instance->hasTimestampAttributes($attributes);

        $instance->setRawAttributes(
            array_merge($instance->getRawOriginal(), $attributes),
            $exists,
        );

        return $instance;
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
        if (isset($this->attributes[$this->getKeyName()])) {
            return parent::setKeysForSelectQuery($query);
        }

        $query->where($this->foreignKey, $this->getOriginal(
            $this->foreignKey,
            $this->getAttribute($this->foreignKey),
        ));

        return $query->where($this->relatedKey, $this->getOriginal(
            $this->relatedKey,
            $this->getAttribute($this->relatedKey),
        ));
    }

    /**
     * Définit les clés pour une requête de sauvegarde de mise à jour.
     *
     * @param Builder<static> $query
     *
     * @return Builder<static>
     */
    protected function setKeysForSaveQuery(Builder $query): Builder
    {
        return $this->setKeysForSelectQuery($query);
    }

    /**
     * Supprime l'enregistrement du modèle pivot de la base de données.
     *
     * @return int|null
     */
    public function delete()
    {
        if (isset($this->attributes[$this->getKeyName()])) {
            return (int) parent::delete();
        }

        if ($this->fireModelEvent('deleting') === false) {
            return 0;
        }

        $this->touchOwners();

        return Helpers::tap($this->getDeleteQuery()->delete(), function () {
            $this->exists = false;

            $this->fireModelEvent('deleted', false);
        });
    }

    /**
     * Obtient le constructeur de requête pour une opération de suppression sur le pivot.
     *
     * @return Builder<static>
     */
    protected function getDeleteQuery(): Builder
    {
        return $this->newQueryWithoutRelationships()->where([
            $this->foreignKey => $this->getOriginal($this->foreignKey, $this->getAttribute($this->foreignKey)),
            $this->relatedKey => $this->getOriginal($this->relatedKey, $this->getAttribute($this->relatedKey)),
        ]);
    }

    /**
     * Obtient la table associée au modèle.
     */
    public function getTable(): string
    {
        if (! isset($this->table)) {
            $this->setTable(str_replace(
                '\\',
                '',
                Text::snake(Text::singular(Helpers::classBasename($this))),
            ));
        }

        return $this->table;
    }

    /**
     * Obtient le nom de la colonne de clé étrangère.
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Obtient le nom de la colonne de "clé liée".
     */
    public function getRelatedKey(): string
    {
        return $this->relatedKey;
    }

    /**
     * Obtient le nom de la colonne de "l'autre clé".
     */
    public function getOtherKey(): string
    {
        return $this->getRelatedKey();
    }

    /**
     * Définit les noms des clés pour l'instance de modèle pivot.
     */
    public function setPivotKeys(string $foreignKey, string $relatedKey): static
    {
        $this->foreignKey = $foreignKey;

        $this->relatedKey = $relatedKey;

        return $this;
    }

    /**
     * Définit le modèle lié de la relation.
     */
    public function setRelatedModel(?Model $related = null): static
    {
        $this->pivotRelated = $related;

        return $this;
    }

    /**
     * Détermine si le modèle pivot ou les attributs donnés ont des attributs d'horodatage.
     */
    public function hasTimestampAttributes(?array $attributes = null): bool
    {
        return ($createdAt = $this->getCreatedAtColumn()) !== null
            && array_key_exists($createdAt, $attributes ?? $this->attributes);
    }

    /**
     * Obtient le nom de la colonne "created at".
     */
    public function getCreatedAtColumn(): string
    {
        return $this->pivotParent
            ? $this->pivotParent->getCreatedAtColumn()
            : parent::getCreatedAtColumn();
    }

    /**
     * Obtient le nom de la colonne "updated at".
     */
    public function getUpdatedAtColumn(): string
    {
        return $this->pivotParent
            ? $this->pivotParent->getUpdatedAtColumn()
            : parent::getUpdatedAtColumn();
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
            '%s:%s:%s:%s',
            $this->foreignKey,
            $this->getAttribute($this->foreignKey),
            $this->relatedKey,
            $this->getAttribute($this->relatedKey),
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
            ->where($segments[2], $segments[3]);
    }

    /**
     * Obtient une nouvelle requête pour restaurer plusieurs modèles par leurs IDs de file d'attente.
     *
     * @param list<int>|list<string> $ids
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
                ->where($segments[2], $segments[3]));
        }

        return $query;
    }

    /**
     * Supprime toutes les relations chargées pour l'instance.
     */
    public function unsetRelations(): static
    {
        $this->pivotParent = null;
        $this->relations   = [];

        return $this;
    }
}
