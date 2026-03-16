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

use BlitzPHP\Database\Builder\JoinClause;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Contracts\SupportsPartialRelations;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Concerns\CanBeOneOfMany;
use BlitzPHP\Wolke\Relations\Concerns\ComparesRelatedModels;
use BlitzPHP\Wolke\Relations\Concerns\SupportsDefaultModels;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\Relations\HasOneThrough</a>
 * 
 * @template TRelatedModel of Model
 * @template TIntermediateModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasOneOrManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel, ?TRelatedModel>
 */
class HasOneThrough extends HasOneOrManyThrough implements SupportsPartialRelations
{
    use CanBeOneOfMany;
    use ComparesRelatedModels;
    use SupportsDefaultModels;

    /**
     * {@inheritDoc}
     */
    public function getResults(): mixed
    {
        if (null === $this->getParentKey()) {
            return $this->getDefaultFor($this->farParent);
        }

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
     * {@inheritDoc}
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        if ($this->isOneOfMany()) {
            $this->mergeOneOfManyJoinsTo($query);
        }

        return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
    }

    /** 
     * {@inheritDoc} 
     */
    public function addOneOfManySubQueryConstraints(Builder $query, $column = null, $aggregate = null): void
    {
        $query->select([$this->getQualifiedFirstKeyName()]);

        // Nous devons joindre des sous-requêtes qui ne sont pas la sous-requête la plus interne, qui est jointe dans la méthode CanBeOneOfMany::ofMany...
        if ($this->getOneOfManySubQuery() !== null) {
            $this->performJoin($query);
        }
    }

    /** 
     * {@inheritDoc} 
     */
    public function getOneOfManySubQuerySelectColumns(): array
    {
        return [$this->getQualifiedFirstKeyName()];
    }

    /** 
     * {@inheritDoc} 
     */
    public function addOneOfManyJoinSubQueryConstraints(JoinClause $join): void
    {
        $join->on($this->qualifySubSelectColumn($this->firstKey), '=', $this->getQualifiedFirstKeyName());
    }

    /**
     * Crée une nouvelle instance liée pour le modèle donné.
     *
     * @param  TDeclaringModel  $parent
     * 
     * @return TRelatedModel
     */
    public function newRelatedInstanceFor(Model $parent): Model
    {
        return $this->related->newInstance();
    }

    /** 
     * {@inheritDoc} 
     */
    protected function getRelatedKeyFrom(Model $model): mixed
    {
        return $model->getAttribute($this->getForeignKeyName());
    }

    /** 
     * {@inheritDoc}
     */
    public function getParentKey(): mixed
    {
        return $this->farParent->getAttribute($this->localKey);
    }
}
