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
use BlitzPHP\Utilities\Iterable\Collection;
use UnexpectedValueException;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Pagination\Cursor</a>
 */
class Cursor implements Arrayable
{
    /**
     * Crée une nouvelle instance de curseur.
     *
     * @param array $parameters        Les paramètres associés au curseur.
     * @param bool  $pointsToNextItems Détermine si le curseur pointe vers l'ensemble d'éléments suivant ou précédent.
     */
    public function __construct(protected array $parameters, protected bool $pointsToNextItems = true)
    {
    }

    /**
     * Obtient le paramètre donné à partir du curseur.
     *
     * @throws UnexpectedValueException
     */
    public function parameter(string $parameterName): ?string
    {
        if (! isset($this->parameters[$parameterName])) {
            throw new UnexpectedValueException("Impossible de trouver le paramètre [{$parameterName}] dans l'élément de pagination.");
        }

        return $this->parameters[$parameterName];
    }

    /**
     * Obtient les paramètres donnés à partir du curseur.
     */
    public function parameters(array $parameterNames): array
    {
        return (new Collection($parameterNames))
            ->map(fn ($parameterName) => $this->parameter($parameterName))
            ->toArray();
    }

    /**
     * Détermine si le curseur pointe vers l'ensemble d'éléments suivant.
     */
    public function pointsToNextItems(): bool
    {
        return $this->pointsToNextItems;
    }

    /**
     * Détermine si le curseur pointe vers l'ensemble d'éléments précédent.
     */
    public function pointsToPreviousItems(): bool
    {
        return ! $this->pointsToNextItems;
    }

    /**
     * Obtient la représentation en tableau du curseur.
     */
    public function toArray(): array
    {
        return array_merge($this->parameters, [
            '_pointsToNextItems' => $this->pointsToNextItems,
        ]);
    }

    /**
     * Obtient la représentation en chaîne encodée du curseur pour construire une URL.
     */
    public function encode(): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode(json_encode($this->toArray())));
    }

    /**
     * Obtient une instance de curseur à partir de la représentation en chaîne encodée.
     */
    public static function fromEncoded(?string $encodedString): ?static
    {
        if (null === $encodedString) {
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
