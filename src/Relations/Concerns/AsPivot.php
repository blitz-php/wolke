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

trait AsPivot
{
    /**
     * The parent model of the relationship.
     *
     * @var \BlitzPHP\Wolke\Model
     */
    public $pivotParent;

    /**
     * The name of the foreign key column.
     */
    protected string $foreignKey = '';

    /**
     * The name of the "other key" column.
     */
    protected string $relatedKey = '';

    /**
     * Create a new pivot model instance.
     *
     * @return static
     */
    public static function fromAttributes(Model $parent, array $attributes, string $table, bool $exists = false)
    {
        $instance = new static();

        $instance->timestamps = $instance->hasTimestampAttributes($attributes);

        // The pivot model is a "dynamic" model since we will set the tables dynamically
        // for the instance. This allows it work for any intermediate tables for the
        // many to many relationship that are defined by this developer's classes.
        $instance->setConnection($parent->getConnectionName())
            ->setTable($table)
            ->forceFill($attributes)
            ->syncOriginal();

        // We store off the parent instance so we will access the timestamp column names
        // for the model, since the pivot model timestamps aren't easily configurable
        // from the developer's point of view. We can use the parents to get these.
        $instance->pivotParent = $parent;

        $instance->exists = $exists;

        return $instance;
    }

    /**
     * Create a new pivot model from raw values returned from a query.
     *
     * @return static
     */
    public static function fromRawAttributes(Model $parent, array $attributes, string $table, bool $exists = false)
    {
        $instance = static::fromAttributes($parent, [], $table, $exists);

        $instance->timestamps = $instance->hasTimestampAttributes($attributes);

        $instance->setRawAttributes(
            array_merge($instance->getRawOriginal(), $attributes),
            $exists
        );

        return $instance;
    }

    /**
     * Set the keys for a select query.
     */
    protected function setKeysForSelectQuery(Builder $query): Builder
    {
        if (isset($this->attributes[$this->getKeyName()])) {
            return parent::setKeysForSelectQuery($query);
        }

        $query->where($this->foreignKey, $this->getOriginal(
            $this->foreignKey,
            $this->getAttribute($this->foreignKey)
        ));

        return $query->where($this->relatedKey, $this->getOriginal(
            $this->relatedKey,
            $this->getAttribute($this->relatedKey)
        ));
    }

    /**
     * Set the keys for a save update query.
     */
    protected function setKeysForSaveQuery(Builder $query): Builder
    {
        return $this->setKeysForSelectQuery($query);
    }

    /**
     * Delete the pivot model record from the database.
     */
    public function delete(): int
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
     * Get the query builder for a delete operation on the pivot.
     */
    protected function getDeleteQuery(): Builder
    {
        return $this->newQueryWithoutRelationships()->where([
            $this->foreignKey => $this->getOriginal($this->foreignKey, $this->getAttribute($this->foreignKey)),
            $this->relatedKey => $this->getOriginal($this->relatedKey, $this->getAttribute($this->relatedKey)),
        ]);
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        if (! isset($this->table)) {
            $this->setTable(str_replace(
                '\\',
                '',
                Text::snake(Text::singular(Helpers::classBasename($this)))
            ));
        }

        return $this->table;
    }

    /**
     * Get the foreign key column name.
     */
    public function getForeignKey(): string
    {
        return $this->foreignKey;
    }

    /**
     * Get the "related key" column name.
     */
    public function getRelatedKey(): string
    {
        return $this->relatedKey;
    }

    /**
     * Get the "related key" column name.
     */
    public function getOtherKey(): string
    {
        return $this->getRelatedKey();
    }

    /**
     * Set the key names for the pivot model instance.
     */
    public function setPivotKeys(string $foreignKey, string $relatedKey): self
    {
        $this->foreignKey = $foreignKey;

        $this->relatedKey = $relatedKey;

        return $this;
    }

    /**
     * Determine if the pivot model or given attributes has timestamp attributes.
     */
    public function hasTimestampAttributes(?array $attributes = null): bool
    {
        return array_key_exists($this->getCreatedAtColumn(), $attributes ?? $this->attributes);
    }

    /**
     * Get the name of the "created at" column.
     */
    public function getCreatedAtColumn(): string
    {
        return $this->pivotParent
            ? $this->pivotParent->getCreatedAtColumn()
            : parent::getCreatedAtColumn();
    }

    /**
     * Get the name of the "updated at" column.
     */
    public function getUpdatedAtColumn(): string
    {
        return $this->pivotParent
            ? $this->pivotParent->getUpdatedAtColumn()
            : parent::getUpdatedAtColumn();
    }

    /**
     * Get the queueable identity for the entity.
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
            $this->getAttribute($this->relatedKey)
        );
    }

    /**
     * Get a new query to restore one or more models by their queueable IDs.
     *
     * @param int[]|string|string[] $ids
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
     * Get a new query to restore multiple models by their queueable IDs.
     *
     * @param int[]|string[] $ids
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

            $query->orWhere(static function ($query) use ($segments) {
                return $query->where($segments[0], $segments[1])
                    ->where($segments[2], $segments[3]);
            });
        }

        return $query;
    }

    /**
     * Unset all the loaded relations for the instance.
     */
    public function unsetRelations(): self
    {
        $this->pivotParent = null;
        $this->relations   = [];

        return $this;
    }
}
