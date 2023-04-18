<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Pagination;

use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Utilities\Helpers;
use UnexpectedValueException;

class Cursor implements Arrayable
{
    /**
     * Create a new cursor instance.
     *
     * @param array $parameters        The parameters associated with the cursor.
     * @param bool  $pointsToNextItems Determine whether the cursor points to the next or previous set of items.
     */
    public function __construct(protected array $parameters, protected bool $pointsToNextItems = true)
    {
    }

    /**
     * Get the given parameter from the cursor.
     *
     * @throws UnexpectedValueException
     */
    public function parameter(string $parameterName): ?string
    {
        if (! isset($this->parameters[$parameterName])) {
            throw new UnexpectedValueException("Unable to find parameter [{$parameterName}] in pagination item.");
        }

        return $this->parameters[$parameterName];
    }

    /**
     * Get the given parameters from the cursor.
     */
    public function parameters(array $parameterNames): array
    {
        return Helpers::collect($parameterNames)->map(fn ($parameterName) => $this->parameter($parameterName))->toArray();
    }

    /**
     * Determine whether the cursor points to the next set of items.
     */
    public function pointsToNextItems(): bool
    {
        return $this->pointsToNextItems;
    }

    /**
     * Determine whether the cursor points to the previous set of items.
     */
    public function pointsToPreviousItems(): bool
    {
        return ! $this->pointsToNextItems;
    }

    /**
     * Get the array representation of the cursor.
     */
    public function toArray(): array
    {
        return array_merge($this->parameters, [
            '_pointsToNextItems' => $this->pointsToNextItems,
        ]);
    }

    /**
     * Get the encoded string representation of the cursor to construct a URL.
     */
    public function encode(): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($this->toArray())));
    }

    /**
     * Get a cursor instance from the encoded string representation.
     *
     * @return static|null
     */
    public static function fromEncoded(?string $encodedString)
    {
        if (null === $encodedString || ! is_string($encodedString)) {
            return null;
        }

        $parameters = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $encodedString), true), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $pointsToNextItems = $parameters['_pointsToNextItems'];

        unset($parameters['_pointsToNextItems']);

        return new static($parameters, $pointsToNextItems);
    }
}
