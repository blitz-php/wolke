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

trait GuardsAttributes
{
    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected array $fillable = [];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var bool|string[]
     */
    protected array|bool $guarded = ['*'];

    /**
     * Indicates if all mass assignment is enabled.
     */
    protected static bool $unguarded = false;

    /**
     * The actual columns that exist on the database and can be guarded.
     */
    protected static array $guardableColumns = [];

    /**
     * Get the fillable attributes for the model.
     */
    public function getFillable(): array
    {
        return $this->fillable;
    }

    /**
     * Set the fillable attributes for the model.
     */
    public function fillable(array $fillable): self
    {
        $this->fillable = $fillable;

        return $this;
    }

    /**
     * Merge new fillable attributes with existing fillable attributes on the model.
     */
    public function mergeFillable(array $fillable): self
    {
        $this->fillable = array_values(array_unique(array_merge($this->fillable, $fillable)));

        return $this;
    }

    /**
     * Get the guarded attributes for the model.
     *
     * @return string[]
     */
    public function getGuarded(): array
    {
        return ! is_array($this->guarded)
                    ? []
                    : $this->guarded;
    }

    /**
     * Set the guarded attributes for the model.
     *
     * @param string[] $guarded
     */
    public function guard(array $guarded): self
    {
        $this->guarded = $guarded;

        return $this;
    }

    /**
     * Merge new guarded attributes with existing guarded attributes on the model.
     *
     * @param string[] $guarded
     */
    public function mergeGuarded(array $guarded): self
    {
        $this->guarded = array_values(array_unique(array_merge($this->guarded, $guarded)));

        return $this;
    }

    /**
     * Disable all mass assignable restrictions.
     */
    public static function unguard(bool $state = true)
    {
        static::$unguarded = $state;
    }

    /**
     * Enable the mass assignment restrictions.
     */
    public static function reguard()
    {
        static::$unguarded = false;
    }

    /**
     * Determine if the current state is "unguarded".
     */
    public static function isUnguarded(): bool
    {
        return static::$unguarded;
    }

    /**
     * Run the given callable while being unguarded.
     */
    public static function unguarded(callable $callback): mixed
    {
        if (static::$unguarded) {
            return $callback();
        }

        static::unguard();

        try {
            return $callback();
        } finally {
            static::reguard();
        }
    }

    /**
     * Determine if the given attribute may be mass assigned.
     */
    public function isFillable(string $key): bool
    {
        if (static::$unguarded) {
            return true;
        }

        // If the key is in the "fillable" array, we can of course assume that it's
        // a fillable attribute. Otherwise, we will check the guarded array when
        // we need to determine if the attribute is black-listed on the model.
        if (in_array($key, $this->getFillable(), true)) {
            return true;
        }

        // If the attribute is explicitly listed in the "guarded" array then we can
        // return false immediately. This means this attribute is definitely not
        // fillable and there is no point in going any further in this method.
        if ($this->isGuarded($key)) {
            return false;
        }

        return empty($this->getFillable())
            && ! str_contains($key, '.')
            && ! str_starts_with($key, '_');
    }

    /**
     * Determine if the given key is guarded.
     */
    public function isGuarded(string $key): bool
    {
        if (empty($this->getGuarded())) {
            return false;
        }

        return $this->getGuarded() === ['*']
               || ! empty(preg_grep('/^' . preg_quote($key) . '$/i', $this->getGuarded()))
               || ! $this->isGuardableColumn($key);
    }

    /**
     * Determine if the given column is a valid, guardable column.
     */
    protected function isGuardableColumn(string $key): bool
    {
        if (! isset(static::$guardableColumns[static::class])) {
            $columns = $this->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($this->getTable());

            if (empty($columns)) {
                return true;
            }
            static::$guardableColumns[static::class] = $columns;
        }

        return in_array($key, static::$guardableColumns[static::class], true);
    }

    /**
     * Determine if the model is totally guarded.
     */
    public function totallyGuarded(): bool
    {
        return count($this->getFillable()) === 0 && $this->getGuarded() === ['*'];
    }

    /**
     * Get the fillable attributes of a given array.
     */
    protected function fillableFromArray(array $attributes): array
    {
        if (count($this->getFillable()) > 0 && ! static::$unguarded) {
            return array_intersect_key($attributes, array_flip($this->getFillable()));
        }

        return $attributes;
    }
}
