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

use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Pivot;

trait InteractsWithPivotTable
{
    /**
     * Toggles a model (or models) from the parent.
     *
     * Each existing model is detached, and non existing ones are attached.
     */
    public function toggle(mixed $ids, bool $touch = true): array
    {
        $changes = [
            'attached' => [], 'detached' => [],
        ];

        $records = $this->formatRecordsList($this->parseIds($ids));

        // Next, we will determine which IDs should get removed from the join table by
        // checking which of the given ID/records is in the list of current records
        // and removing all of those rows from this "intermediate" joining table.
        $detach = array_values(array_intersect(
            $this->newPivotQuery()->values($this->relatedPivotKey),
            array_keys($records)
        ));

        if (count($detach) > 0) {
            $this->detach($detach, false);

            $changes['detached'] = $this->castKeys($detach);
        }

        // Finally, for all of the records which were not "detached", we'll attach the
        // records into the intermediate table. Then, we will add those attaches to
        // this change list and get ready to return these results to the callers.
        $attach = array_diff_key($records, array_flip($detach));

        if (count($attach) > 0) {
            $this->attach($attach, [], false);

            $changes['attached'] = array_keys($attach);
        }

        // Once we have finished attaching or detaching the records, we will see if we
        // have done any attaching or detaching, and if we have we will touch these
        // relationships if they are configured to touch on any database updates.
        if (
            $touch && (count($changes['attached'])
                       || count($changes['detached']))
        ) {
            $this->touchIfTouching();
        }

        return $changes;
    }

    /**
     * Sync the intermediate tables with a list of IDs without detaching.
     *
     * @param array|IterableCollection|Model $ids
     */
    public function syncWithoutDetaching($ids): array
    {
        return $this->sync($ids, false);
    }

    /**
     * Sync the intermediate tables with a list of IDs or collection of models.
     *
     * @param array|IterableCollection|Model $ids
     */
    public function sync($ids, bool $detaching = true): array
    {
        $changes = [
            'attached' => [], 'detached' => [], 'updated' => [],
        ];

        // First we need to attach any of the associated models that are not currently
        // in this joining table. We'll spin through the given IDs, checking to see
        // if they exist in the array of current ones, and if not we will insert.
        $current = $this->getCurrentlyAttachedPivots()
            ->pluck($this->relatedPivotKey)->all();

        $detach = array_diff($current, array_keys(
            $records = $this->formatRecordsList($this->parseIds($ids))
        ));

        // Next, we will take the differences of the currents and given IDs and detach
        // all of the entities that exist in the "current" array but are not in the
        // array of the new IDs given to the method which will complete the sync.
        if ($detaching && count($detach) > 0) {
            $this->detach($detach);

            $changes['detached'] = $this->castKeys($detach);
        }

        // Now we are finally ready to attach the new records. Note that we'll disable
        // touching until after the entire operation is complete so we don't fire a
        // ton of touch operations until we are totally done syncing the records.
        $changes = array_merge(
            $changes,
            $this->attachNew($records, $current, false)
        );

        // Once we have finished attaching or detaching the records, we will see if we
        // have done any attaching or detaching, and if we have we will touch these
        // relationships if they are configured to touch on any database updates.
        if (
            count($changes['attached'])
            || count($changes['updated'])
        ) {
            $this->touchIfTouching();
        }

        return $changes;
    }

    /**
     * Sync the intermediate tables with a list of IDs or collection of models with the given pivot values.
     *
     * @param array|IterableCollection|Model $ids
     */
    public function syncWithPivotValues($ids, array $values, bool $detaching = true): array
    {
        return $this->sync(Helpers::collect($this->parseIds($ids))->mapWithKeys(static fn ($id) => [$id => $values]), $detaching);
    }

    /**
     * Format the sync / toggle record list so that it is keyed by ID.
     */
    protected function formatRecordsList(array $records): array
    {
        return Helpers::collect($records)->mapWithKeys(static function ($attributes, $id) {
            if (! is_array($attributes)) {
                [$id, $attributes] = [$attributes, []];
            }

            return [$id => $attributes];
        })->all();
    }

    /**
     * Attach all of the records that aren't in the given current records.
     */
    protected function attachNew(array $records, array $current, bool $touch = true): array
    {
        $changes = ['attached' => [], 'updated' => []];

        foreach ($records as $id => $attributes) {
            // If the ID is not in the list of existing pivot IDs, we will insert a new pivot
            // record, otherwise, we will just update this existing record on this joining
            // table, so that the developers will easily update these records pain free.
            if (! in_array($id, $current, true)) {
                $this->attach($id, $attributes, $touch);

                $changes['attached'][] = $this->castKey($id);
            }

            // Now we'll try to update an existing pivot record with the attributes that were
            // given to the method. If the model is actually updated we will add it to the
            // list of updated pivot records so we return them back out to the consumer.
            elseif (
                count($attributes) > 0
                && $this->updateExistingPivot($id, $attributes, $touch)
            ) {
                $changes['updated'][] = $this->castKey($id);
            }
        }

        return $changes;
    }

    /**
     * Update an existing pivot record on the table.
     */
    public function updateExistingPivot(mixed $id, array $attributes, bool $touch = true): int
    {
        if (
            $this->using
            && empty($this->pivotWheres)
            && empty($this->pivotWhereIns)
            && empty($this->pivotWhereNulls)
        ) {
            return $this->updateExistingPivotUsingCustomClass($id, $attributes, $touch);
        }

        if (in_array($this->updatedAt(), $this->pivotColumns, true)) {
            $attributes = $this->addTimestampsToAttachment($attributes, true);
        }

        $updated = $this->newPivotStatementForId($this->parseId($id))->update(
            $this->castAttributes($attributes)
        );

        if ($touch) {
            $this->touchIfTouching();
        }

        return $updated;
    }

    /**
     * Update an existing pivot record on the table via a custom class.
     */
    protected function updateExistingPivotUsingCustomClass(mixed $id, array $attributes, bool $touch): int
    {
        $pivot = $this->getCurrentlyAttachedPivots()
            ->where($this->foreignPivotKey, $this->parent->{$this->parentKey})
            ->where($this->relatedPivotKey, $this->parseId($id))
            ->first();

        $updated = $pivot ? $pivot->fill($attributes)->isDirty() : false;

        if ($updated) {
            $pivot->save();
        }

        if ($touch) {
            $this->touchIfTouching();
        }

        return (int) $updated;
    }

    /**
     * Attach a model to the parent.
     */
    public function attach(mixed $id, array $attributes = [], bool $touch = true): void
    {
        if ($this->using) {
            $this->attachUsingCustomClass($id, $attributes);
        } else {
            // Here we will insert the attachment records into the pivot table. Once we have
            // inserted the records, we will touch the relationships if necessary and the
            // function will return. We can parse the IDs before inserting the records.
            $this->newPivotStatement()->bulckInsert($this->formatAttachRecords(
                $this->parseIds($id),
                $attributes
            ));
        }

        if ($touch) {
            $this->touchIfTouching();
        }
    }

    /**
     * Attach a model to the parent using a custom class.
     */
    protected function attachUsingCustomClass(mixed $id, array $attributes): void
    {
        $records = $this->formatAttachRecords(
            $this->parseIds($id),
            $attributes
        );

        foreach ($records as $record) {
            $this->newPivot($record, false)->save();
        }
    }

    /**
     * Create an array of records to insert into the pivot table.
     */
    protected function formatAttachRecords(array $ids, array $attributes): array
    {
        $records = [];

        $hasTimestamps = ($this->hasPivotColumn($this->createdAt())
                  || $this->hasPivotColumn($this->updatedAt()));

        // To create the attachment records, we will simply spin through the IDs given
        // and create a new record to insert for each ID. Each ID may actually be a
        // key in the array, with extra attributes to be placed in other columns.
        foreach ($ids as $key => $value) {
            $records[] = $this->formatAttachRecord(
                $key,
                $value,
                $attributes,
                $hasTimestamps
            );
        }

        return $records;
    }

    /**
     * Create a full attachment record payload.
     */
    protected function formatAttachRecord(int $key, mixed $value, array $attributes, bool $hasTimestamps): array
    {
        [$id, $attributes] = $this->extractAttachIdAndAttributes($key, $value, $attributes);

        return array_merge(
            $this->baseAttachRecord($id, $hasTimestamps),
            $this->castAttributes($attributes)
        );
    }

    /**
     * Get the attach record ID and extra attributes.
     */
    protected function extractAttachIdAndAttributes(mixed $key, mixed $value, array $attributes): array
    {
        return is_array($value)
                    ? [$key, array_merge($value, $attributes)]
                    : [$value, $attributes];
    }

    /**
     * Create a new pivot attachment record.
     */
    protected function baseAttachRecord(int|string $id, bool $timed): array
    {
        $record[$this->relatedPivotKey] = $id;

        $record[$this->foreignPivotKey] = $this->parent->{$this->parentKey};

        // If the record needs to have creation and update timestamps, we will make
        // them by calling the parent model's "freshTimestamp" method which will
        // provide us with a fresh timestamp in this model's preferred format.
        if ($timed) {
            $record = $this->addTimestampsToAttachment($record);
        }

        foreach ($this->pivotValues as $value) {
            $record[$value['column']] = $value['value'];
        }

        return $record;
    }

    /**
     * Set the creation and update timestamps on an attach record.
     */
    protected function addTimestampsToAttachment(array $record, bool $exists = false): array
    {
        $fresh = $this->parent->freshTimestamp();

        if ($this->using) {
            $pivotModel = new $this->using();

            $fresh = $fresh->format($pivotModel->getDateFormat());
        }

        if (! $exists && $this->hasPivotColumn($this->createdAt())) {
            $record[$this->createdAt()] = $fresh;
        }

        if ($this->hasPivotColumn($this->updatedAt())) {
            $record[$this->updatedAt()] = $fresh;
        }

        return $record;
    }

    /**
     * Determine whether the given column is defined as a pivot column.
     */
    public function hasPivotColumn(string $column): bool
    {
        return in_array($column, $this->pivotColumns, true);
    }

    /**
     * Detach models from the relationship.
     */
    public function detach(mixed $ids = null, bool $touch = true): int
    {
        if (
            $this->using
            && ! empty($ids)
            && empty($this->pivotWheres)
            && empty($this->pivotWhereIns)
            && empty($this->pivotWhereNulls)
        ) {
            $results = $this->detachUsingCustomClass($ids);
        } else {
            $query = $this->newPivotQuery();

            // If associated IDs were passed to the method we will only delete those
            // associations, otherwise all of the association ties will be broken.
            // We'll return the numbers of affected rows when we do the deletes.
            if (null !== $ids) {
                $ids = $this->parseIds($ids);

                if (empty($ids)) {
                    return 0;
                }

                $query->whereIn($this->getQualifiedRelatedPivotKeyName(), (array) $ids);
            }

            // Once we have all of the conditions set on the statement, we are ready
            // to run the delete on the pivot table. Then, if the touch parameter
            // is true, we will go ahead and touch all related models to sync.
            $results = $query->delete();
        }

        if ($touch) {
            $this->touchIfTouching();
        }

        return $results;
    }

    /**
     * Detach models from the relationship using a custom class.
     */
    protected function detachUsingCustomClass(mixed $ids): int
    {
        $results = 0;

        foreach ($this->parseIds($ids) as $id) {
            $results += $this->newPivot([
                $this->foreignPivotKey => $this->parent->{$this->parentKey},
                $this->relatedPivotKey => $id,
            ], true)->delete();
        }

        return $results;
    }

    /**
     * Get the pivot models that are currently attached.
     */
    protected function getCurrentlyAttachedPivots(): IterableCollection
    {
        return Helpers::collect($this->newPivotQuery()->result())->map(function ($record) {
            $class = $this->using ?: Pivot::class;

            $pivot = $class::fromRawAttributes($this->parent, (array) $record, $this->getTable(), true);

            return $pivot->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey);
        });
    }

    /**
     * Create a new pivot model instance.
     */
    public function newPivot(array $attributes = [], bool $exists = false): Pivot
    {
        $pivot = $this->related->newPivot(
            $this->parent,
            $attributes,
            $this->table,
            $exists,
            $this->using
        );

        return $pivot->setPivotKeys($this->foreignPivotKey, $this->relatedPivotKey);
    }

    /**
     * Create a new existing pivot model instance.
     */
    public function newExistingPivot(array $attributes = []): Pivot
    {
        return $this->newPivot($attributes, true);
    }

    /**
     * Get a new plain query builder for the pivot table.
     */
    public function newPivotStatement(): BaseBuilder
    {
        return (fn () => $this->reset())->call($this->query->getQuery())->from($this->table);
    }

    /**
     * Get a new pivot statement for a given "other" ID.
     */
    public function newPivotStatementForId(mixed $id): BaseBuilder
    {
        return $this->newPivotQuery()->whereIn($this->relatedPivotKey, $this->parseIds($id));
    }

    /**
     * Create a new query builder for the pivot table.
     */
    public function newPivotQuery(): BaseBuilder
    {
        $query = $this->newPivotStatement();

        foreach ($this->pivotWheres as $arguments) {
            $query->where(...$arguments);
        }

        foreach ($this->pivotWhereIns as $arguments) {
            $query->whereIn(...$arguments);
        }

        foreach ($this->pivotWhereNulls as $arguments) {
            $query->whereNull(...$arguments);
        }

        return $query->where($this->getQualifiedForeignPivotKeyName(), $this->parent->{$this->parentKey});
    }

    /**
     * Set the columns on the pivot table to retrieve.
     *
     * @param array|mixed $columns
     */
    public function withPivot(mixed $columns): self
    {
        $this->pivotColumns = array_merge(
            $this->pivotColumns,
            is_array($columns) ? $columns : func_get_args()
        );

        return $this;
    }

    /**
     * Get all of the IDs from the given mixed value.
     */
    protected function parseIds(mixed $value): array
    {
        if ($value instanceof Model) {
            return [$value->{$this->relatedKey}];
        }

        if ($value instanceof Collection) {
            return $value->pluck($this->relatedKey)->all();
        }

        if ($value instanceof IterableCollection) {
            return $value->toArray();
        }

        return (array) $value;
    }

    /**
     * Get the ID from the given mixed value.
     */
    protected function parseId(mixed $value): mixed
    {
        return $value instanceof Model ? $value->{$this->relatedKey} : $value;
    }

    /**
     * Cast the given keys to integers if they are numeric and string otherwise.
     */
    protected function castKeys(array $keys): array
    {
        return array_map(fn ($v) => $this->castKey($v), $keys);
    }

    /**
     * Cast the given key to convert to primary key type.
     */
    protected function castKey(mixed $key): mixed
    {
        return $this->getTypeSwapValue(
            $this->related->getKeyType(),
            $key
        );
    }

    /**
     * Cast the given pivot attributes.
     */
    protected function castAttributes(array $attributes): array
    {
        return $this->using
                    ? $this->newPivot()->fill($attributes)->getAttributes()
                    : $attributes;
    }

    /**
     * Converts a given value to a given type value.
     */
    protected function getTypeSwapValue(string $type, mixed $value): mixed
    {
        switch (strtolower($type)) {
            case 'int':
            case 'integer':
                return (int) $value;

            case 'real':
            case 'float':
            case 'double':
                return (float) $value;

            case 'string':
                return (string) $value;

            default:
                return $value;
        }
    }
}
