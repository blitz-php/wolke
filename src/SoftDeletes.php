<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke;

use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;

/**
 * @method static Builder<static> withTrashed(bool $withTrashed = true)
 * @method static Builder<static> onlyTrashed()
 * @method static Builder<static> withoutTrashed()
 * @method static static          restoreOrCreate(array<string, mixed> $attributes = [], array<string, mixed> $values = [])
 * @method static static          createOrRestore(array<string, mixed> $attributes = [], array<string, mixed> $values = [])
 *
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\SoftDeletes</a>
 */
trait SoftDeletes
{
    /**
     * Indique si le modèle est actuellement en cours de suppression forcée.
     */
    protected bool $forceDeleting = false;

    /**
     * Initialise le trait de suppression douce pour un modèle.
     */
    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new SoftDeletingScope());
    }

    /**
     * Initialise le trait de suppression douce pour une instance.
     */
    public function initializeSoftDeletes(): void
    {
        if (! isset($this->casts[$this->getDeletedAtColumn()])) {
            $this->casts[$this->getDeletedAtColumn()] = 'datetime';
        }
    }

    /**
     * Effectue une suppression dure sur un modèle supprimé de façon douce.
     */
    public function forceDelete(): ?bool
    {
        if ($this->fireModelEvent('forceDeleting') === false) {
            return false;
        }

        $this->forceDeleting = true;

        return Helpers::tap($this->delete(), function ($deleted) {
            $this->forceDeleting = false;

            if ($deleted) {
                $this->fireModelEvent('forceDeleted', false);
            }
        });
    }

    /**
     * Effectue une suppression dure sur un modèle supprimé de façon douce sans déclencher d'événements.
     */
    public function forceDeleteQuietly(): ?bool
    {
        return static::withoutEvents(fn () => $this->forceDelete());
    }

    /**
     * Détruit les modèles pour les IDs donnés.
     *
     * @param array|int|IterableCollection|string $ids
     */
    public static function forceDestroy($ids): int
    {
        if ($ids instanceof Collection) {
            $ids = $ids->modelKeys();
        }

        if ($ids instanceof IterableCollection) {
            $ids = $ids->all();
        }

        $ids = is_array($ids) ? $ids : func_get_args();

        if (count($ids) === 0) {
            return 0;
        }

        // Nous allons en fait récupérer les modèles de la table de base de données et appeler delete sur
        // chacun d'eux individuellement afin que leurs événements soient déclenchés correctement avec
        // un ensemble correct d'attributs au cas où les développeurs voudraient vérifier cela.
        $key = ($instance = new static())->getKeyName();

        $count = 0;

        foreach ($instance->withTrashed()->whereIn($key, $ids)->get() as $model) {
            if ($model->forceDelete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Effectue la requête de suppression réelle sur cette instance de modèle.
     *
     * @return mixed
     */
    protected function performDeleteOnModel()
    {
        if ($this->forceDeleting) {
            return Helpers::tap($this->setKeysForSaveQuery($this->newModelQuery())->forceDelete(), function () {
                $this->exists = false;
            });
        }

        return $this->runSoftDelete();
    }

    /**
     * Effectue la requête de suppression réelle sur cette instance de modèle.
     */
    protected function runSoftDelete(): void
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());

        $time = $this->freshTimestamp();

        $columns = [$this->getDeletedAtColumn() => $this->fromDateTime($time)];

        $this->{$this->getDeletedAtColumn()} = $time;

        if ($this->usesTimestamps() && null !== $this->getUpdatedAtColumn()) {
            $this->{$this->getUpdatedAtColumn()} = $time;

            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        $query->update($columns);

        $this->syncOriginalAttributes(array_keys($columns));

        $this->fireModelEvent('trashed', false);
    }

    /**
     * Restaure une instance de modèle supprimée de façon douce.
     */
    public function restore(): bool
    {
        // Si l'événement restoring ne retourne pas false, nous procéderons à cette
        // opération de restauration. Sinon, nous abandonnons pour que le développeur arrête
        // complètement la restauration. Nous effacerons l'horodatage supprimé et sauvegarderons.
        if ($this->fireModelEvent('restoring') === false) {
            return false;
        }

        $this->{$this->getDeletedAtColumn()} = null;

        // Une fois que nous avons sauvegardé le modèle, nous déclencherons l'événement "restored" pour que ce
        // développeur fasse tout ce qu'il doit après qu'une opération de restauration soit
        // totalement terminée. Ensuite, nous retournerons le résultat de l'appel de sauvegarde.
        $this->exists = true;

        $result = $this->save();

        $this->fireModelEvent('restored', false);

        return $result;
    }

    /**
     * Restaure une instance de modèle supprimée de façon douce sans déclencher d'événements.
     */
    public function restoreQuietly(): bool
    {
        return static::withoutEvents(fn () => $this->restore());
    }

    /**
     * Détermine si l'instance de modèle a été supprimée de façon douce.
     */
    public function trashed(): bool
    {
        return null !== $this->{$this->getDeletedAtColumn()};
    }

    /**
     * Enregistre un rappel d'événement de modèle "softDeleted" avec le répartiteur.
     *
     * @param callable|class-string $callback
     */
    public static function softDeleted(callable|string $callback)
    {
        static::registerModelEvent('trashed', $callback);
    }

    /**
     * Enregistre un rappel d'événement de modèle "restoring" avec le répartiteur.
     *
     * @param callable|class-string $callback
     */
    public static function restoring(callable|string $callback): void
    {
        static::registerModelEvent('restoring', $callback);
    }

    /**
     * Enregistre un rappel d'événement de modèle "restored" avec le répartiteur.
     *
     * @param callable|class-string $callback
     */
    public static function restored(callable|string $callback): void
    {
        static::registerModelEvent('restored', $callback);
    }

    /**
     * Enregistre un rappel d'événement de modèle "forceDeleting" avec le répartiteur.
     *
     * @param callable|class-string $callback
     */
    public static function forceDeleting(callable|string $callback): void
    {
        static::registerModelEvent('forceDeleting', $callback);
    }

    /**
     * Enregistre un rappel d'événement de modèle "forceDeleted" avec le répartiteur.
     *
     * @param callable|class-string $callback
     */
    public static function forceDeleted(callable|string $callback): void
    {
        static::registerModelEvent('forceDeleted', $callback);
    }

    /**
     * Détermine si le modèle est actuellement en cours de suppression forcée.
     */
    public function isForceDeleting(): bool
    {
        return $this->forceDeleting;
    }

    /**
     * Obtient le nom de la colonne "deleted at".
     */
    public function getDeletedAtColumn(): string
    {
        return defined(static::class . '::DELETED_AT') ? static::DELETED_AT : 'deleted_at';
    }

    /**
     * Obtient la colonne "deleted at" complètement qualifiée.
     */
    public function getQualifiedDeletedAtColumn(): string
    {
        return $this->qualifyColumn($this->getDeletedAtColumn());
    }
}
