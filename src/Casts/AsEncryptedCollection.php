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

use BlitzPHP\Database\Config\Services;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Contracts\Castable;
use BlitzPHP\Wolke\Contracts\CastsAttributes;
use BlitzPHP\Wolke\Model;
use InvalidArgumentException;

class AsEncryptedCollection implements Castable
{
    /**
     * Obtient la classe de cast à utiliser pour le casting depuis/vers cette cible de cast.
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class ($arguments) implements CastsAttributes {
            public function __construct(protected array $arguments)
            {
                $this->arguments = array_pad(array_values($this->arguments), 2, '');
            }

            public function get(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                $collectionClass = $this->arguments[0] ?? Collection::class;

                if (! is_a($collectionClass, Collection::class, true)) {
                    throw new InvalidArgumentException('La classe fournie doit étendre [' . Collection::class . '].');
                }

                if (! isset($attributes[$key])) {
                    return null;
                }

                $instance = new $collectionClass(Json::decode(Services::encrypter()->decrypt($attributes[$key])));

                if (! isset($this->arguments[1]) || ! $this->arguments[1]) {
                    return $instance;
                }

                if (is_string($this->arguments[1])) {
                    $this->arguments[1] = Text::parseCallback($this->arguments[1]);
                }

                return is_callable($this->arguments[1])
                    ? $instance->map($this->arguments[1])
                    : $instance->mapInto($this->arguments[1][0]);
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                if (null !== $value) {
                    return [$key => Services::encrypter()->encrypt(Json::encode($value))];
                }

                return null;
            }
        };
    }

    /**
     * Spécifie le type d'objet vers lequel chaque élément de la collection doit être mappé.
     *
     * @param array{class-string, string}|class-string $map Le mappage à appliquer
     */
    public static function of(array|string $map): string
    {
        return static::using('', $map);
    }

    /**
     * Spécifie la collection pour le cast.
     *
     * @param class-string                                  $class La classe de collection à utiliser
     * @param array{class-string, string}|class-string|null $map   Le mappage à appliquer
     */
    public static function using(string $class, array|string|null $map = null): string
    {
        if (is_array($map) && is_callable($map)) {
            $map = $map[0] . '@' . $map[1];
        }

        return static::class . ':' . implode(',', [$class, $map]);
    }
}
