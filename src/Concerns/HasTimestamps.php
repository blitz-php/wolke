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

use BlitzPHP\Utilities\Date;

trait HasTimestamps
{
    /**
     * Indicates if the model should be timestamped.
     */
    public array|bool $timestamps = true;

    /**
     * The list of models classes that have timestamps temporarily disabled.
     */
    protected static array $ignoreTimestampsOn = [];

    /**
     * Update the model's update timestamp.
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
     * Update the model's update timestamp without raising any events.
     */
    public function touchQuietly(?string $attribute = null): bool
    {
        return static::withoutEvents(fn () => $this->touch($attribute));
    }

    /**
     * Update the creation and update timestamps.
     */
    public function updateTimestamps(): self
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
     * Set the value of the "created at" attribute.
     */
    public function setCreatedAt(mixed $value): self
    {
        $this->{$this->getCreatedAtColumn()} = $value;

        return $this;
    }

    /**
     * Set the value of the "updated at" attribute.
     */
    public function setUpdatedAt(mixed $value): self
    {
        $this->{$this->getUpdatedAtColumn()} = $value;

        return $this;
    }

    /**
     * Get a fresh timestamp for the model.
     */
    public function freshTimestamp(): Date
    {
        return Date::now();
    }

    /**
     * Get a fresh timestamp for the model.
     */
    public function freshTimestampString(): string
    {
        return $this->fromDateTime($this->freshTimestamp());
    }

    /**
     * Determine if the model uses timestamps.
     */
    public function usesTimestamps(): bool
    {
        return $this->timestamps && ! static::isIgnoringTimestamps($this::class);
    }

    /**
     * Get the name of the "created at" column.
     */
    public function getCreatedAtColumn(): ?string
    {
        return static::CREATED_AT;
    }

    /**
     * Get the name of the "updated at" column.
     */
    public function getUpdatedAtColumn(): ?string
    {
        return static::UPDATED_AT;
    }

    /**
     * Get the fully qualified "created at" column.
     */
    public function getQualifiedCreatedAtColumn(): ?string
    {
        return $this->qualifyColumn($this->getCreatedAtColumn());
    }

    /**
     * Get the fully qualified "updated at" column.
     */
    public function getQualifiedUpdatedAtColumn(): ?string
    {
        return $this->qualifyColumn($this->getUpdatedAtColumn());
    }

    /**
     * Disable timestamps for the current class during the given callback scope.
     */
    public static function withoutTimestamps(callable $callback): mixed
    {
        return static::withoutTimestampsOn([static::class], $callback);
    }

    /**
     * Disable timestamps for the given model classes during the given callback scope.
     */
    public static function withoutTimestampsOn(array $models, callable $callback): mixed
    {
        static::$ignoreTimestampsOn = array_values(array_merge(static::$ignoreTimestampsOn, $models));

        try {
            return $callback();
        } finally {
            static::$ignoreTimestampsOn = array_values(array_diff(static::$ignoreTimestampsOn, $models));
        }
    }

    /**
     * Determine if the given model is ignoring timestamps / touches.
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
