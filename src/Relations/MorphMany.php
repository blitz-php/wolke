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
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphOneOrMany<TRelatedModel, TDeclaringModel, Collection<int, TRelatedModel>>
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\MorphMany</a>
 */
class MorphMany extends MorphOneOrMany
{
    /**
     * Convertit la relation en une relation "morph one".
     * 
     * @return MorphOne<TRelatedModel, TDeclaringModel>
     */
    public function one(): MorphOne
    {
        return MorphOne::noConstraints(fn () => Helpers::tap(
            new MorphOne(
                $this->getQuery(),
                $this->getParent(),
                $this->morphType,
                $this->foreignKey,
                $this->localKey
            ),
            function ($morphOne) {
                if ($inverse = $this->getInverseRelationship()) {
                    $morphOne->inverse($inverse);
                }
            }
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function getResults(): mixed
    {
        return null !== $this->getParentKey()
                ? $this->query->get()
                : $this->related->newCollection();
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
     *{@inheritDoc}
     */
    public function match(array $models, Collection $results, string $relation): array
    {
        return $this->matchMany($models, $results, $relation);
    }

    /**
     * Crée une nouvelle instance du modèle lié. Permet l'assignation en masse.
     */
    public function forceCreate(array $attributes = []): Model
    {
        $attributes[$this->getMorphType()] = $this->morphClass;

        return parent::forceCreate($attributes);
    }
}
