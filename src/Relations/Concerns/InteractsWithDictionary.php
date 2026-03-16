<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Relations\Concerns;

use BlitzPHP\Utilities\Helpers;
use InvalidArgumentException;
use UnitEnum;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\Concerns\InteractsWithDictionary</a>
 */
trait InteractsWithDictionary
{
    /**
     * Obtient une clé de dictionnaire - en la convertissant en chaîne si nécessaire.
     *
     * @throws InvalidArgumentException
     */
    protected function getDictionaryKey(mixed $attribute): mixed
    {
        if (is_object($attribute)) {
            if (method_exists($attribute, '__toString')) {
                return $attribute->__toString();
            }

            if ($attribute instanceof UnitEnum) {
                return Helpers::enumValue($attribute);
            }

            throw new InvalidArgumentException('La valeur de l\'attribut du modèle est un objet mais n\'a pas de méthode __toString.');
        }

        return $attribute;
    }
}
