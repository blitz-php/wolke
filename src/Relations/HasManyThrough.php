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

use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Invade\Invader;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;

/**
 * @template TRelatedModel of Model
 * @template TIntermediateModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasOneOrManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel, Collection<int, TRelatedModel>>
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\HasManyThrough</a>
 */
class HasManyThrough extends HasOneOrManyThrough
{
    /**
     * Convertit la relation en une relation "has one through".
     *
     * @return HasOneThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     */
    public function one(): HasOneThrough
    {
        return HasOneThrough::noConstraints(fn () => new HasOneThrough(
            Helpers::tap($this->getQuery(), fn (Builder $query) => Invader::make($query->getQuery())->joins = []),
            $this->farParent,
            $this->throughParent,
            $this->getFirstKeyName(),
            $this->getForeignKeyName(),
            $this->getLocalKeyName(),
            $this->getSecondLocalKeyName(),
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function initRelation(array $models, string $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * {@inheritDoc}
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        $dictionary = $this->buildDictionary($results);

        // Une fois que nous avons le dictionnaire, nous pouvons simplement parcourir les modèles parents pour
        // les lier à leurs enfants en utilisant le dictionnaire indexé par clé pour rendre la
        // correspondance très pratique et facile à travailler. Ensuite, nous les retournerons.
        foreach ($models as $model) {
            $key = $this->getDictionaryKey($model->getAttribute($this->localKey));
            
            if ($key !== null && isset($dictionary[$key])) {
                $model->setRelation(
                    $relation,
                    $this->related->newCollection($dictionary[$key])
                );
            }
        }

        return $models;
    }

    /**
     * {@inheritDoc}
     */
    public function getResults(): mixed
    {
        return null !== $this->farParent->{$this->localKey}
                ? $this->get()
                : $this->related->newCollection();
    }
}
