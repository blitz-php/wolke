<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Contracts;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Contracts\Database\Eloquent\Castable</a>
 */
interface Castable
{
    /**
     * Obtient le nom de la classe de cast à utiliser lors du casting depuis/vers cette cible de cast.
     *
     * @param list<string> $arguments
     *
     * @return CastsAttributes|CastsInboundAttributes|class-string<CastsAttributes|CastsInboundAttributes>
     */
    public static function castUsing(array $arguments);
}
