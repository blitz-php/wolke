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
use BlitzPHP\Wolke\Contracts\Scope;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\SoftDeletingScope</a>
 */
class SoftDeletingScope implements Scope
{
    /**
     * Toutes les extensions à ajouter au constructeur de requête.
     *
     * @var list<string>
     */
    protected array $extensions = ['Restore', 'RestoreOrCreate', 'CreateOrRestore', 'WithTrashed', 'WithoutTrashed', 'OnlyTrashed'];

    /**
     * Applique la portée à un constructeur de requête Eloquent donné.
     *
     * @template TModel of Model
     *
     * @param Builder<TModel>  $builder
     * @param TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->whereNull($model->getQualifiedDeletedAtColumn());
    }

    /**
     * Étend le constructeur de requête avec les fonctions nécessaires.
     * 
     * @param Builder<*> $builder
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
     * Obtient la colonne "deleted at" pour le constructeur.
     * 
     * @param Builder<*> $builder
     */
    protected function getDeletedAtColumn(Builder $builder): string
    {
        if ($builder->getQuery()->joins !== []) {
            return $builder->getModel()->getQualifiedDeletedAtColumn();
        }

        return $builder->getModel()->getDeletedAtColumn();
    }

    /**
     * Ajoute l'extension restore au constructeur.
     * 
     * @param Builder<*> $builder
     */
    protected function addRestore(Builder $builder): void
    {
        $builder->macro('restore', static function (Builder $builder) {
            $builder->withTrashed();

            return $builder->update([$builder->getModel()->getDeletedAtColumn() => null]);
        });
    }

    /**
     * Ajoute l'extension restore-or-create au constructeur.
     * 
     * @param Builder<*> $builder
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
     * Ajoute l'extension create-or-restore au constructeur.
     * 
     * @param Builder<*> $builder
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
     * Ajoute l'extension with-trashed au constructeur.
     * 
     * @param Builder<*> $builder
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
     * Ajoute l'extension without-trashed au constructeur.
     * 
     * @param Builder<*> $builder
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
     * Ajoute l'extension only-trashed au constructeur.
     * 
     * @param Builder<*> $builder
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
