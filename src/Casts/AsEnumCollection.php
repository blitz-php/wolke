<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Casts;

use BackedEnum;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Wolke\Contracts\Castable;
use BlitzPHP\Wolke\Contracts\CastsAttributes;
use BlitzPHP\Wolke\Contracts\SerializesCastableAttributes;
use BlitzPHP\Wolke\Model;

class AsEnumCollection implements Castable
{
    /**
     * Get the caster class to use when casting from / to this cast target.
     *
     * @template TEnum of \UnitEnum|\BackedEnum
     *
     * @param array{class-string<TEnum>} $arguments
     *
     * @return CastsAttributes<Collection<array-key, TEnum>, iterable<TEnum>>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class ($arguments) implements CastsAttributes, SerializesCastableAttributes {
            public function __construct(protected array $arguments)
            {
            }

            public function get(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                if (! isset($attributes[$key]) || null === $attributes[$key]) {
                    return null;
                }

                $data = Json::decode($attributes[$key]);

                if (! is_array($data)) {
                    return null;
                }

                $enumClass = $this->arguments[0];

                return (new Collection($data))->map(static function ($value) use ($enumClass) {
                    return is_subclass_of($enumClass, BackedEnum::class)
                        ? $enumClass::from($value)
                        : constant($enumClass . '::' . $value);
                });
            }

            public function set($model, $key, $value, $attributes)
            {
                $value = $value !== null
                    ? Json::encode((new Collection($value))->map(fn ($enum) => $this->getStorableEnumValue($enum))->jsonSerialize())
                    : null;

                return [$key => $value];
            }

            public function serialize($model, string $key, $value, array $attributes)
            {
                return (new Collection($value))->map(fn ($enum) => $this->getStorableEnumValue($enum))->toArray();
            }

            protected function getStorableEnumValue($enum)
            {
                if (is_string($enum) || is_int($enum)) {
                    return $enum;
                }

                return $enum instanceof BackedEnum ? $enum->value : $enum->name;
            }
        };
    }
}
