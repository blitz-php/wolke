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
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Relations\HasMany;
use BlitzPHP\Wolke\Relations\HasOne;

class PendingHasThroughRelationship
{
    /**
     * Create a pending has-many-through or has-one-through relationship.
     *
     * @param Model          $rootModel         The root model that the relationship exists on.
     * @param HasMany|HasOne $localRelationship The local relationship.
     */
    public function __construct(protected Model $rootModel, protected HasMany|HasOne $localRelationship)
    {
    }

    /**
     * Define the distant relationship that this model has.
     *
     * @param (callable(Model): (HasMany|HasOne))|string $callback
     *
     * @return Relations\HasManyThrough|Relations\HasOneThrough
     */
    public function has($callback)
    {
        if (is_string($callback)) {
            $callback = fn () => $this->localRelationship->getRelated()->{$callback}();
        }

        $distantRelation = $callback($this->localRelationship->getRelated());

        if ($distantRelation instanceof HasMany) {
            return $this->rootModel->hasManyThrough(
                $distantRelation->getRelated()::class,
                $this->localRelationship->getRelated()::class,
                $this->localRelationship->getForeignKeyName(),
                $distantRelation->getForeignKeyName(),
                $this->localRelationship->getLocalKeyName(),
                $distantRelation->getLocalKeyName(),
            );
        }

        return $this->rootModel->hasOneThrough(
            $distantRelation->getRelated()::class,
            $this->localRelationship->getRelated()::class,
            $this->localRelationship->getForeignKeyName(),
            $distantRelation->getForeignKeyName(),
            $this->localRelationship->getLocalKeyName(),
            $distantRelation->getLocalKeyName(),
        );
    }

    /**
     * Handle dynamic method calls into the model.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        if (Text::startsWith($method, 'has')) {
            return $this->has(Text::of($method)->after('has')->lcfirst()->toString());
        }

        throw new BadMethodCallException(sprintf(
            'Call to undefined method %s::%s()',
            static::class,
            $method
        ));
    }
}
