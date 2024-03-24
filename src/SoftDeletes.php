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
use Closure;

/**
 * @method static static|\BlitzPHP\Wolke\Builder|\BlitzPHP\Database\Builder\BaseBuilder withTrashed(bool $withTrashed = true)
 * @method static static|\BlitzPHP\Wolke\Builder|\BlitzPHP\Database\Builder\BaseBuilder onlyTrashed()
 * @method static static|\BlitzPHP\Wolke\Builder|\BlitzPHP\Database\Builder\BaseBuilder withoutTrashed()
 */
trait SoftDeletes
{
    /**
     * Indicates if the model is currently force deleting.
     */
    protected bool $forceDeleting = false;

    /**
     * Boot the soft deleting trait for a model.
     */
    public static function bootSoftDeletes(): void
    {
        static::addGlobalScope(new SoftDeletingScope());
    }

    /**
     * Initialize the soft deleting trait for an instance.
     */
    public function initializeSoftDeletes(): void
    {
        if (! isset($this->casts[$this->getDeletedAtColumn()])) {
            $this->casts[$this->getDeletedAtColumn()] = 'datetime';
        }
    }

    /**
     * Force a hard delete on a soft deleted model.
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
     * Force a hard delete on a soft deleted model without raising any events.
     */
    public function forceDeleteQuietly(): ?bool
    {
        return static::withoutEvents(fn () => $this->forceDelete());
    }

    /**
     * Perform the actual delete query on this model instance.
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
     * Perform the actual delete query on this model instance.
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
     * Restore a soft-deleted model instance.
     */
    public function restore(): bool
    {
        // If the restoring event does not return false, we will proceed with this
        // restore operation. Otherwise, we bail out so the developer will stop
        // the restore totally. We will clear the deleted timestamp and save.
        if ($this->fireModelEvent('restoring') === false) {
            return false;
        }

        $this->{$this->getDeletedAtColumn()} = null;

        // Once we have saved the model, we will fire the "restored" event so this
        // developer will do anything they need to after a restore operation is
        // totally finished. Then we will return the result of the save call.
        $this->exists = true;

        $result = $this->save();

        $this->fireModelEvent('restored', false);

        return $result;
    }

    /**
     * Restore a soft-deleted model instance without raising any events.
     */
    public function restoreQuietly(): bool
    {
        return static::withoutEvents(fn () => $this->restore());
    }

    /**
     * Determine if the model instance has been soft-deleted.
     */
    public function trashed(): bool
    {
        return null !== $this->{$this->getDeletedAtColumn()};
    }

    /**
     * Register a "softDeleted" model event callback with the dispatcher.
     */
    public static function softDeleted(Closure|string $callback)
    {
        static::registerModelEvent('trashed', $callback);
    }

    /**
     * Register a "restoring" model event callback with the dispatcher.
     */
    public static function restoring(Closure|string $callback): void
    {
        static::registerModelEvent('restoring', $callback);
    }

    /**
     * Register a "restored" model event callback with the dispatcher.
     */
    public static function restored(Closure|string $callback): void
    {
        static::registerModelEvent('restored', $callback);
    }

    /**
     * Register a "forceDeleting" model event callback with the dispatcher.
     */
    public static function forceDeleting(Closure|string $callback): void
    {
        static::registerModelEvent('forceDeleting', $callback);
    }

    /**
     * Register a "forceDeleted" model event callback with the dispatcher.
     */
    public static function forceDeleted(Closure|string $callback): void
    {
        static::registerModelEvent('forceDeleted', $callback);
    }

    /**
     * Determine if the model is currently force deleting.
     */
    public function isForceDeleting(): bool
    {
        return $this->forceDeleting;
    }

    /**
     * Get the name of the "deleted at" column.
     */
    public function getDeletedAtColumn(): string
    {
        return defined(static::class . '::DELETED_AT') ? static::DELETED_AT : 'deleted_at';
    }

    /**
     * Get the fully qualified "deleted at" column.
     */
    public function getQualifiedDeletedAtColumn(): string
    {
        return $this->qualifyColumn($this->getDeletedAtColumn());
    }
}
