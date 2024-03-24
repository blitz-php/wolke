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
use BlitzPHP\Utilities\Support\Invader;
use BlitzPHP\Wolke\Contracts\Scope;

class SoftDeletingScope implements Scope
{
    /**
     * All of the extensions to be added to the builder.
     *
     * @var string[]
     */
    protected array $extensions = ['Restore', 'RestoreOrCreate', 'CreateOrRestore', 'WithTrashed', 'WithoutTrashed', 'OnlyTrashed'];

    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getQualifiedDeletedAtColumn());
    }

    /**
     * Extend the query builder with the needed functions.
     */
    public function extend(Builder $builder): void
    {
        foreach ($this->extensions as $extension) {
            $this->{"add{$extension}"}($builder);
        }

        $builder->onDelete(function (Builder $builder) {
            $column = $this->getDeletedAtColumn($builder);

            return $builder->update([
                $column => $builder->getModel()->freshTimestampString(),
            ]);
        });
    }

    /**
     * Get the "deleted at" column for the builder.
     */
    protected function getDeletedAtColumn(Builder $builder): string
    {
        if (count((array) Invader::make($builder->getQuery())->joins) > 0) {
            return $builder->getModel()->getQualifiedDeletedAtColumn();
        }

        return $builder->getModel()->getDeletedAtColumn();
    }

    /**
     * Add the restore extension to the builder.
     */
    protected function addRestore(Builder $builder): void
    {
        $builder->macro('restore', static function (Builder $builder) {
            $builder->withTrashed();

            return $builder->update([$builder->getModel()->getDeletedAtColumn() => null]);
        });
    }

    /**
     * Add the restore-or-create extension to the builder.
     */
    protected function addRestoreOrCreate(Builder $builder): void
    {
        $builder->macro('restoreOrCreate', static function (Builder $builder, array $attributes = [], array $values = []) {
            $builder->withTrashed();

            return Helpers::tap($builder->firstOrCreate($attributes, $values), static function ($instance) {
                $instance->restore();
            });
        });
    }

    /**
     * Add the create-or-restore extension to the builder.
     */
    protected function addCreateOrRestore(Builder $builder): void
    {
        $builder->macro('createOrRestore', static function (Builder $builder, array $attributes = [], array $values = []) {
            $builder->withTrashed();

            return Helpers::tap($builder->createOrFirst($attributes, $values), static function ($instance) {
                $instance->restore();
            });
        });
    }

    /**
     * Add the with-trashed extension to the builder.
     */
    protected function addWithTrashed(Builder $builder): void
    {
        $builder->macro('withTrashed', function (Builder $builder, $withTrashed = true) {
            if (! $withTrashed) {
                return $builder->withoutTrashed();
            }

            return $builder->withoutGlobalScope($this);
        });
    }

    /**
     * Add the without-trashed extension to the builder.
     */
    protected function addWithoutTrashed(Builder $builder): void
    {
        $builder->macro('withoutTrashed', function (Builder $builder) {
            $model = $builder->getModel();

            $builder->withoutGlobalScope($this)->whereNull(
                $model->getQualifiedDeletedAtColumn()
            );

            return $builder;
        });
    }

    /**
     * Add the only-trashed extension to the builder.
     */
    protected function addOnlyTrashed(Builder $builder): void
    {
        $builder->macro('onlyTrashed', function (Builder $builder) {
            $model = $builder->getModel();

            $builder->withoutGlobalScope($this)->whereNotNull(
                $model->getQualifiedDeletedAtColumn()
            );

            return $builder;
        });
    }
}
