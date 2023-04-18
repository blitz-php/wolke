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
 * CastException is thrown for invalid cast initialization and management.
 */
class CastException extends RuntimeException
{
    public function getExitCode(): int
    {
        return 3;
    }

    /**
     * Thrown when the cast class does not extends BaseCast.
     *
     * @return static
     */
    public static function invalidInterface(string $class)
    {
        return new static(lang('Cast.baseCastMissing', [$class]));
    }

    /**
     * Thrown when the Json format is invalid.
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
     * Thrown when the cast method is not `get` or `set`.
     *
     * @return static
     */
    public static function invalidMethod(string $method)
    {
        return new static(lang('Cast.invalidCastMethod', [$method]));
    }

    /**
     * Thrown when the casting timestamp is not correct timestamp.
     *
     * @return static
     */
    public static function invalidTimestamp()
    {
        return new static(lang('Cast.invalidTimestamp'));
    }
}
