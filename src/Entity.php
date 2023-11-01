<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke;

abstract class Entity extends Model
{
    /**
     * Juste pour la conformité du framework
     * 
     * BlitzPHP a une terminologie differente des modeles par rapport à Laravel (qui a été utilisé pour ce package).
     * Chez BlitzPHP, les modeles sont utilisés pour l'ecriture des repositories (manipulation du query builder)
     * Ce qu'on appel model chez Laravel est appelé entité chez BlitzPHP d'où la presence de cette classe 
     */    
}
