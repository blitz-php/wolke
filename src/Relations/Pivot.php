<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Relations;

use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\AsPivot;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\Pivot</a>
 */
class Pivot extends Model
{
    use AsPivot;

    /**
     * Indique si les IDs sont auto-incrémentés.
     */
    public bool $incrementing = false;

    /**
     * Les attributs qui ne sont pas assignables en masse.
     * 
     * @var list<string>
     */
    protected array $guarded = [];
}
