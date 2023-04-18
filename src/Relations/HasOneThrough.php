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

use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\InteractsWithDictionary;
use BlitzPHP\Wolke\Relations\Concerns\SupportsDefaultModels;

class HasOneThrough extends HasManyThrough
{
    use InteractsWithDictionary;
    use SupportsDefaultModels;

    /**
     * {@inheritDoc}
     */
    public function getResults(): mixed
    {
        return $this->first() ?: $this->getDefaultFor($this->farParent);
    }

    /**
     * {@inheritDoc}
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->getDefaultFor($model));
        }

        return $models;
    }

    /**
     * Match the eagerly loaded results to their parents.
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        // Once we have the dictionary we can simply spin through the parent models to
        // link them up with their children using the keyed dictionary to make the
        // matching very convenient and easy work. Then we'll just return them.
        foreach ($models as $model) {
            if (isset($dictionary[$key = $this->getDictionaryKey($model->getAttribute($this->localKey))])) {
                $value = $dictionary[$key];
                $model->setRelation(
                    $relation,
                    reset($value)
                );
            }
        }

        return $models;
    }

    /**
     * Make a new related instance for the given model.
     */
    public function newRelatedInstanceFor(Model $parent): Model
    {
        return $this->related->newInstance();
    }
}
