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

use BadMethodCallException;
use BlitzPHP\Utilities\String\Stringable;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Relations\HasMany;
use BlitzPHP\Wolke\Relations\HasOne;
use BlitzPHP\Wolke\Relations\HasOneOrMany;
use BlitzPHP\Wolke\Relations\MorphOneOrMany;

/**
 * @template TIntermediateModel of Model
 * @template TDeclaringModel of Model
 * @template TLocalRelationship of HasOneOrMany<TIntermediateModel, TDeclaringModel>
 */
class PendingHasThroughRelationship
{
    /**
     * Create a pending has-many-through or has-one-through relationship.
     *
     * @param TDeclaringModel    $rootModel         The root model that the relationship exists on.
     * @param TLocalRelationship $localRelationship The local relationship.
     */
    public function __construct(protected Model $rootModel, protected HasOneOrMany $localRelationship)
    {
    }

    /**
     * Define the distant relationship that this model has.
     *
     * @template TRelatedModel of Model
     *
     * @param  string|(callable(TIntermediateModel): (HasOne<TRelatedModel, TIntermediateModel>|HasMany<TRelatedModel, TIntermediateModel>|MorphOneOrMany<TRelatedModel, TIntermediateModel>))  $callback
     * 
     * @return (
     *     $callback is string
     *     ? HasManyThrough<Model, TIntermediateModel, TDeclaringModel>|HasOneThrough<Model, TIntermediateModel, TDeclaringModel>
     *     : (
     *         TLocalRelationship is HasMany<TIntermediateModel, TDeclaringModel>
     *         ? HasManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     *         : (
     *              $callback is callable(TIntermediateModel): HasMany<TRelatedModel, TIntermediateModel>
     *              ? HasManyThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     *              : HasOneThrough<TRelatedModel, TIntermediateModel, TDeclaringModel>
     *         )
     *     )
     * )
     */
    public function has($callback)
    {
        if (is_string($callback)) {
            $callback = fn () => $this->localRelationship->getRelated()->{$callback}();
        }

        $distantRelation = $callback($this->localRelationship->getRelated());

        if ($distantRelation instanceof HasMany || $this->localRelationship instanceof HasMany) {
            $returnedRelation = $this->rootModel->hasManyThrough(
                $distantRelation->getRelated()::class,
                $this->localRelationship->getRelated()::class,
                $this->localRelationship->getForeignKeyName(),
                $distantRelation->getForeignKeyName(),
                $this->localRelationship->getLocalKeyName(),
                $distantRelation->getLocalKeyName(),
            );
        } else {
            $returnedRelation = $this->rootModel->hasOneThrough(
                $distantRelation->getRelated()::class,
                $this->localRelationship->getRelated()::class,
                $this->localRelationship->getForeignKeyName(),
                $distantRelation->getForeignKeyName(),
                $this->localRelationship->getLocalKeyName(),
                $distantRelation->getLocalKeyName(),
            );
        }

        if ($this->localRelationship instanceof MorphOneOrMany) {
            $returnedRelation->where($this->localRelationship->getQualifiedMorphType(), $this->localRelationship->getMorphClass());
        }

        return $returnedRelation;
    }

    /**
     * Handle dynamic method calls into the model.
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (Text::startsWith($method, 'has')) {
            return $this->has((new Stringable($method))->after('has')->lcfirst()->toString());
        }

        throw new BadMethodCallException(sprintf(
            'Call to undefined method %s::%s()',
            static::class,
            $method
        ));
    }
}
