<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Exceptions;

use RuntimeException;

/**
 * CastException est levée pour une initialisation et une gestion de cast invalides.
 */
class CastException extends RuntimeException
{
    public function getExitCode(): int
    {
        return 3;
    }

    /**
     * Levée lorsque la classe de cast n'étend pas BaseCast.
     *
     * @return static
     */
    public static function invalidInterface(string $class)
    {
        return new static(lang('Cast.baseCastMissing', [$class]));
    }

    /**
     * Levée lorsque le format JSON est invalide.
     *
     * @return static
     */
    public static function invalidJsonFormat(int $error)
    {
        switch ($error) {
            case JSON_ERROR_DEPTH:
                return new static(lang('Cast.jsonErrorDepth'));

            case JSON_ERROR_STATE_MISMATCH:
                return new static(lang('Cast.jsonErrorStateMismatch'));

            case JSON_ERROR_CTRL_CHAR:
                return new static(lang('Cast.jsonErrorCtrlChar'));

            case JSON_ERROR_SYNTAX:
                return new static(lang('Cast.jsonErrorSyntax'));

            case JSON_ERROR_UTF8:
                return new static(lang('Cast.jsonErrorUtf8'));

            default:
                return new static(lang('Cast.jsonErrorUnknown'));
        }
    }

    /**
     * Levée lorsque la méthode de cast n'est pas `get` ou `set`.
     *
     * @return static
     */
    public static function invalidMethod(string $method)
    {
        return new static(lang('Cast.invalidCastMethod', [$method]));
    }

    /**
     * Levée lorsque l'horodatage de cast n'est pas un horodatage correct.
     *
     * @return static
     */
    public static function invalidTimestamp()
    {
        return new static(lang('Cast.invalidTimestamp'));
    }
}
