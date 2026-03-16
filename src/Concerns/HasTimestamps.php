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

use BlitzPHP\Utilities\DateTime\Date;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Concerns\HasTimestamps</a>
 */
trait HasTimestamps
{
    /**
     * Indique si le modèle doit être horodaté.
     */
    public array|bool $timestamps = true;

    /**
     * La liste des classes de modèles qui ont temporairement désactivé les horodatages.
     */
    protected static array $ignoreTimestampsOn = [];

    /**
     * Met à jour l'horodatage de mise à jour du modèle.
     */
    public function touch(?string $attribute = null): bool
    {
        if ($attribute) {
            $this->{$attribute} = $this->freshTimestamp();

            return $this->save();
        }

        if (! $this->usesTimestamps()) {
            return false;
        }

        $this->updateTimestamps();

        return $this->save();
    }

    /**
     * Met à jour l'horodatage de mise à jour du modèle sans déclencher d'événements.
     */
    public function touchQuietly(?string $attribute = null): bool
    {
        return static::withoutEvents(fn () => $this->touch($attribute));
    }

    /**
     * Met à jour les horodatages de création et de mise à jour.
     */
    public function updateTimestamps(): static
    {
        $time = $this->freshTimestamp();

        $updatedAtColumn = $this->getUpdatedAtColumn();

        if (null !== $updatedAtColumn && ! $this->isDirty($updatedAtColumn)) {
            $this->setUpdatedAt($time);
        }

        $createdAtColumn = $this->getCreatedAtColumn();

        if (! $this->exists && null !== $createdAtColumn && ! $this->isDirty($createdAtColumn)) {
            $this->setCreatedAt($time);
        }

        return $this;
    }

    /**
     * Définit la valeur de l'attribut "created at".
     */
    public function setCreatedAt(mixed $value): static
    {
        $this->{$this->getCreatedAtColumn()} = $value;

        return $this;
    }

    /**
     * Définit la valeur de l'attribut "updated at".
     */
    public function setUpdatedAt(mixed $value): static
    {
        $this->{$this->getUpdatedAtColumn()} = $value;

        return $this;
    }

    /**
     * Obtient un horodatage frais pour le modèle.
     */
    public function freshTimestamp(): Date
    {
        return Date::now();
    }

    /**
     * Obtient un horodatage frais pour le modèle sous forme de chaîne.
     */
    public function freshTimestampString(): string
    {
        return $this->fromDateTime($this->freshTimestamp());
    }

    /**
     * Détermine si le modèle utilise des horodatages.
     */
    public function usesTimestamps(): bool
    {
        return $this->timestamps && ! static::isIgnoringTimestamps($this::class);
    }

    /**
     * Obtient le nom de la colonne "created at".
     */
    public function getCreatedAtColumn(): ?string
    {
        return static::CREATED_AT;
    }

    /**
     * Obtient le nom de la colonne "updated at".
     */
    public function getUpdatedAtColumn(): ?string
    {
        return static::UPDATED_AT;
    }

    /**
     * Obtient la colonne "created at" complètement qualifiée.
     */
    public function getQualifiedCreatedAtColumn(): ?string
    {
        return $this->qualifyColumn($this->getCreatedAtColumn());
    }

    /**
     * Obtient la colonne "updated at" complètement qualifiée.
     */
    public function getQualifiedUpdatedAtColumn(): ?string
    {
        return $this->qualifyColumn($this->getUpdatedAtColumn());
    }

    /**
     * Désactive les horodatages pour la classe actuelle pendant la portée de rappel donnée.
     */
    public static function withoutTimestamps(callable $callback): mixed
    {
        return static::withoutTimestampsOn([static::class], $callback);
    }

    /**
     * Désactive les horodatages pour les classes de modèle données pendant la portée de rappel donnée.
     */
    public static function withoutTimestampsOn(array $models, callable $callback): mixed
    {
        static::$ignoreTimestampsOn = array_values(array_merge(static::$ignoreTimestampsOn, $models));

        try {
            return $callback();
        } finally {
            foreach ($models as $model) {
                if (($key = array_search($model, static::$ignoreTimestampsOn, true)) !== false) {
                    unset(static::$ignoreTimestampsOn[$key]);
                }
            }
        }
    }

    /**
     * Détermine si le modèle donné ignore les horodatages / touches.
     */
    public static function isIgnoringTimestamps(?string $class = null): bool
    {
        $class ??= static::class;

        foreach (static::$ignoreTimestampsOn as $ignoredClass) {
            if ($class === $ignoredClass || is_subclass_of($class, $ignoredClass)) {
                return true;
            }
        }

        return false;
    }
}
