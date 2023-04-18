<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Concerns;

use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Builder;
use BlitzPHP\Wolke\Collection;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\BelongsTo;
use BlitzPHP\Wolke\Relations\BelongsToMany;
use BlitzPHP\Wolke\Relations\HasMany;
use BlitzPHP\Wolke\Relations\HasManyThrough;
use BlitzPHP\Wolke\Relations\HasOne;
use BlitzPHP\Wolke\Relations\HasOneThrough;
use BlitzPHP\Wolke\Relations\MorphMany;
use BlitzPHP\Wolke\Relations\MorphOne;
use BlitzPHP\Wolke\Relations\MorphTo;
use BlitzPHP\Wolke\Relations\MorphToMany;
use BlitzPHP\Wolke\Relations\Relation;
use Closure;

trait HasRelationships
{
    /**
     * The loaded relationships for the model.
     */
    protected array $relations = [];

    /**
     * The relationships that should be touched on save.
     */
    protected array $touches = [];

    /**
     * The many to many relationship methods.
     *
     * @var string[]
     */
    public static array $manyMethods = [
        'belongsToMany', 'morphToMany', 'morphedByMany',
    ];

    /**
     * The relation resolver callbacks.
     */
    protected static array $relationResolvers = [];

    /**
     * Define a dynamic relation resolver.
     */
    public static function resolveRelationUsing(string $name, Closure $callback): void
    {
        static::$relationResolvers = array_replace_recursive(
            static::$relationResolvers,
            [static::class => [$name => $callback]]
        );
    }

    /**
     * Define a one-to-one relationship.
     */
    public function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasOne($instance->newQuery(), $this, $instance->getTable() . '.' . $foreignKey, $localKey);
    }

    /**
     * Instantiate a new HasOne relationship.
     */
    protected function newHasOne(Builder $query, Model $parent, string $foreignKey, string $localKey): HasOne
    {
        return new HasOne($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Define a has-one-through relationship.
     */
    public function hasOneThrough(string $related, string $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null): HasOneThrough
    {
        $through = new $through();

        $firstKey = $firstKey ?: $this->getForeignKey();

        $secondKey = $secondKey ?: $through->getForeignKey();

        return $this->newHasOneThrough(
            $this->newRelatedInstance($related)->newQuery(),
            $this,
            $through,
            $firstKey,
            $secondKey,
            $localKey ?: $this->getKeyName(),
            $secondLocalKey ?: $through->getKeyName()
        );
    }

    /**
     * Instantiate a new HasOneThrough relationship.
     */
    protected function newHasOneThrough(Builder $query, Model $farParent, Model $throughParent, string $firstKey, string $secondKey, string $localKey, string $secondLocalKey): HasOneThrough
    {
        return new HasOneThrough($query, $farParent, $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey);
    }

    /**
     * Define a polymorphic one-to-one relationship.
     */
    public function morphOne(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphOne
    {
        $instance = $this->newRelatedInstance($related);

        [$type, $id] = $this->getMorphs($name, $type, $id);

        $table = $instance->getTable();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newMorphOne($instance->newQuery(), $this, $table . '.' . $type, $table . '.' . $id, $localKey);
    }

    /**
     * Instantiate a new MorphOne relationship.
     */
    protected function newMorphOne(Builder $query, Model $parent, string $type, string $id, string $localKey): MorphOne
    {
        return new MorphOne($query, $parent, $type, $id, $localKey);
    }

    /**
     * Define an inverse one-to-one or many relationship.
     */
    public function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null, ?string $relation = null): BelongsTo
    {
        // If no relation name was given, we will use this debug backtrace to extract
        // the calling method's name and use that as the relationship name as most
        // of the time this will be what we desire to use for the relationships.
        if (null === $relation) {
            $relation = $this->guessBelongsToRelation();
        }

        $instance = $this->newRelatedInstance($related);

        // If no foreign key was supplied, we can use a backtrace to guess the proper
        // foreign key name by using the name of the relationship function, which
        // when combined with an "_id" should conventionally match the columns.
        if (null === $foreignKey) {
            $foreignKey = Text::snake($relation) . '_' . $instance->getKeyName();
        }

        // Once we have the foreign key names, we'll just create a new Eloquent query
        // for the related models and returns the relationship instance which will
        // actually be responsible for retrieving and hydrating every relations.
        $ownerKey = $ownerKey ?: $instance->getKeyName();

        return $this->newBelongsTo(
            $instance->newQuery(),
            $this,
            $foreignKey,
            $ownerKey,
            $relation
        );
    }

    /**
     * Instantiate a new BelongsTo relationship.
     */
    protected function newBelongsTo(Builder $query, Model $child, string $foreignKey, string $ownerKey, string $relation): BelongsTo
    {
        return new BelongsTo($query, $child, $foreignKey, $ownerKey, $relation);
    }

    /**
     * Define a polymorphic, inverse one-to-one or many relationship.
     */
    public function morphTo(?string $name = null, ?string $type = null, ?string $id = null, ?string $ownerKey = null): MorphTo
    {
        // If no name is provided, we will use the backtrace to get the function name
        // since that is most likely the name of the polymorphic interface. We can
        // use that to get both the class and foreign key that will be utilized.
        $name = $name ?: $this->guessBelongsToRelation();

        [$type, $id] = $this->getMorphs(
            Text::snake($name),
            $type,
            $id
        );

        // If the type value is null it is probably safe to assume we're eager loading
        // the relationship. In this case we'll just pass in a dummy query where we
        // need to remove any eager loads that may already be defined on a model.
        return null === ($class = $this->getAttributeFromArray($type)) || $class === ''
                    ? $this->morphEagerTo($name, $type, $id, $ownerKey)
                    : $this->morphInstanceTo($class, $name, $type, $id, $ownerKey);
    }

    /**
     * Define a polymorphic, inverse one-to-one or many relationship.
     */
    protected function morphEagerTo(string $name, string $type, string $id, string $ownerKey): MorphTo
    {
        return $this->newMorphTo(
            $this->newQuery()->setEagerLoads([]),
            $this,
            $id,
            $ownerKey,
            $type,
            $name
        );
    }

    /**
     * Define a polymorphic, inverse one-to-one or many relationship.
     */
    protected function morphInstanceTo(string $target, string $name, string $type, string $id, string $ownerKey): MorphTo
    {
        $instance = $this->newRelatedInstance(
            static::getActualClassNameForMorph($target)
        );

        return $this->newMorphTo(
            $instance->newQuery(),
            $this,
            $id,
            $ownerKey ?? $instance->getKeyName(),
            $type,
            $name
        );
    }

    /**
     * Instantiate a new MorphTo relationship.
     */
    protected function newMorphTo(Builder $query, Model $parent, string $foreignKey, string $ownerKey, string $type, string $relation): MorphTo
    {
        return new MorphTo($query, $parent, $foreignKey, $ownerKey, $type, $relation);
    }

    /**
     * Retrieve the actual class name for a given morph class.
     */
    public static function getActualClassNameForMorph(string $class): string
    {
        return Arr::get(Relation::morphMap() ?: [], $class, $class);
    }

    /**
     * Guess the "belongs to" relationship name.
     */
    protected function guessBelongsToRelation(): string
    {
        [$one, $two, $caller] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        return $caller['function'];
    }

    /**
     * Define a one-to-many relationship.
     */
    public function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $instance = $this->newRelatedInstance($related);

        $foreignKey = $foreignKey ?: $this->getForeignKey();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newHasMany(
            $instance->newQuery(),
            $this,
            $instance->getTable() . '.' . $foreignKey,
            $localKey
        );
    }

    /**
     * Instantiate a new HasMany relationship.
     */
    protected function newHasMany(Builder $query, Model $parent, string $foreignKey, string $localKey): HasMany
    {
        return new HasMany($query, $parent, $foreignKey, $localKey);
    }

    /**
     * Define a has-many-through relationship.
     */
    public function hasManyThrough(string $related, string $through, ?string $firstKey = null, ?string $secondKey = null, ?string $localKey = null, ?string $secondLocalKey = null): HasManyThrough
    {
        $through = new $through();

        $firstKey = $firstKey ?: $this->getForeignKey();

        $secondKey = $secondKey ?: $through->getForeignKey();

        return $this->newHasManyThrough(
            $this->newRelatedInstance($related)->newQuery(),
            $this,
            $through,
            $firstKey,
            $secondKey,
            $localKey ?: $this->getKeyName(),
            $secondLocalKey ?: $through->getKeyName()
        );
    }

    /**
     * Instantiate a new HasManyThrough relationship.
     */
    protected function newHasManyThrough(Builder $query, Model $farParent, Model $throughParent, string $firstKey, string $secondKey, string $localKey, string $secondLocalKey): HasManyThrough
    {
        return new HasManyThrough($query, $farParent, $throughParent, $firstKey, $secondKey, $localKey, $secondLocalKey);
    }

    /**
     * Define a polymorphic one-to-many relationship.
     */
    public function morphMany(string $related, string $name, ?string $type = null, ?string $id = null, ?string $localKey = null): MorphMany
    {
        $instance = $this->newRelatedInstance($related);

        // Here we will gather up the morph type and ID for the relationship so that we
        // can properly query the intermediate table of a relation. Finally, we will
        // get the table and create the relationship instances for the developers.
        [$type, $id] = $this->getMorphs($name, $type, $id);

        $table = $instance->getTable();

        $localKey = $localKey ?: $this->getKeyName();

        return $this->newMorphMany($instance->newQuery(), $this, $table . '.' . $type, $table . '.' . $id, $localKey);
    }

    /**
     * Instantiate a new MorphMany relationship.
     */
    protected function newMorphMany(Builder $query, Model $parent, string $type, string $id, string $localKey): MorphMany
    {
        return new MorphMany($query, $parent, $type, $id, $localKey);
    }

    /**
     * Define a many-to-many relationship.
     */
    public function belongsToMany(
        string $related,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        ?string $relation = null
    ): BelongsToMany {
        // If no relationship name was passed, we will pull backtraces to get the
        // name of the calling function. We will use that function name as the
        // title of this relation since that is a great convention to apply.
        if (null === $relation) {
            $relation = $this->guessBelongsToManyRelation();
        }

        // First, we'll need to determine the foreign key and "other key" for the
        // relationship. Once we have determined the keys we'll make the query
        // instances as well as the relationship instances we need for this.
        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();

        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        // If no table name was provided, we can guess it by concatenating the two
        // models using underscores in alphabetical order. The two model names
        // are transformed to snake case from their default CamelCase also.
        if (null === $table) {
            $table = $this->joiningTable($related, $instance);
        }

        return $this->newBelongsToMany(
            $instance->newQuery(),
            $this,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
            $relation
        );
    }

    /**
     * Instantiate a new BelongsToMany relationship.
     */
    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $relationName = null
    ): BelongsToMany {
        return new BelongsToMany($query, $parent, $table, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey, $relationName);
    }

    /**
     * Define a polymorphic many-to-many relationship.
     */
    public function morphToMany(
        string $related,
        string $name,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null,
        bool $inverse = false
    ): MorphToMany {
        $caller = $this->guessBelongsToManyRelation();

        // First, we will need to determine the foreign key and "other key" for the
        // relationship. Once we have determined the keys we will make the query
        // instances, as well as the relationship instances we need for these.
        $instance = $this->newRelatedInstance($related);

        $foreignPivotKey = $foreignPivotKey ?: $name . '_id';

        $relatedPivotKey = $relatedPivotKey ?: $instance->getForeignKey();

        // Now we're ready to create a new query builder for this related model and
        // the relationship instances for this relation. This relations will set
        // appropriate query constraints then entirely manages the hydrations.
        if (! $table) {
            $words = preg_split('/(_)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE);

            $lastWord = array_pop($words);

            $table = implode('', $words) . Text::plural($lastWord);
        }

        return $this->newMorphToMany(
            $instance->newQuery(),
            $this,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey ?: $this->getKeyName(),
            $relatedKey ?: $instance->getKeyName(),
            $caller,
            $inverse
        );
    }

    /**
     * Instantiate a new MorphToMany relationship.
     */
    protected function newMorphToMany(
        Builder $query,
        Model $parent,
        string $name,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey,
        string $relatedKey,
        ?string $relationName = null,
        bool $inverse = false
    ): MorphToMany {
        return new MorphToMany(
            $query,
            $parent,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
            $inverse
        );
    }

    /**
     * Define a polymorphic, inverse many-to-many relationship.
     */
    public function morphedByMany(
        string $related,
        string $name,
        ?string $table = null,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): MorphToMany {
        $foreignPivotKey = $foreignPivotKey ?: $this->getForeignKey();

        // For the inverse of the polymorphic many-to-many relations, we will change
        // the way we determine the foreign and other keys, as it is the opposite
        // of the morph-to-many method since we're figuring out these inverses.
        $relatedPivotKey = $relatedPivotKey ?: $name . '_id';

        return $this->morphToMany(
            $related,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            true
        );
    }

    /**
     * Get the relationship name of the belongsToMany relationship.
     */
    protected function guessBelongsToManyRelation(): ?string
    {
        $caller = Arr::first(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), static function ($trace) {
            return ! in_array(
                $trace['function'],
                array_merge(static::$manyMethods, ['guessBelongsToManyRelation']),
                true
            );
        });

        return null !== $caller ? $caller['function'] : null;
    }

    /**
     * Get the joining table name for a many-to-many relation.
     */
    public function joiningTable(string $related, ?Model $instance = null): string
    {
        // The joining table name, by convention, is simply the snake cased models
        // sorted alphabetically and concatenated with an underscore, so we can
        // just sort the models and join them together to get the table name.
        $segments = [
            $instance ? $instance->joiningTableSegment()
                      : Text::snake(Helpers::classBasename($related)),
            $this->joiningTableSegment(),
        ];

        // Now that we have the model names in an array we can just sort them and
        // use the implode function to join them together with an underscores,
        // which is typically used by convention within the database system.
        sort($segments);

        return strtolower(implode('_', $segments));
    }

    /**
     * Get this model's half of the intermediate table name for belongsToMany relationships.
     */
    public function joiningTableSegment(): string
    {
        return Text::snake(Helpers::classBasename($this));
    }

    /**
     * Determine if the model touches a given relation.
     */
    public function touches(string $relation): bool
    {
        return in_array($relation, $this->getTouchedRelations(), true);
    }

    /**
     * Touch the owning relations of the model.
     */
    public function touchOwners(): void
    {
        foreach ($this->getTouchedRelations() as $relation) {
            $this->{$relation}()->touch();

            if ($this->{$relation} instanceof self) {
                $this->{$relation}->fireModelEvent('saved', false);

                $this->{$relation}->touchOwners();
            } elseif ($this->{$relation} instanceof Collection) {
                $this->{$relation}->each->touchOwners();
            }
        }
    }

    /**
     * Get the polymorphic relationship columns.
     */
    protected function getMorphs(string $name, string $type, string $id): array
    {
        return [$type ?: $name . '_type', $id ?: $name . '_id'];
    }

    /**
     * Get the class name for polymorphic relations.
     */
    public function getMorphClass(): string
    {
        $morphMap = Relation::morphMap();

        if (! empty($morphMap) && in_array(static::class, $morphMap, true)) {
            return array_search(static::class, $morphMap, true);
        }

        return static::class;
    }

    /**
     * Create a new model instance for a related model.
     */
    protected function newRelatedInstance(string $class): object
    {
        return Helpers::tap(new $class(), function ($instance) {
            if (! $instance->getConnectionName()) {
                $instance->setConnection($this->connection);
            }
        });
    }

    /**
     * Get all the loaded relations for the instance.
     */
    public function getRelations(): array
    {
        return $this->relations;
    }

    /**
     * Get a specified relationship.
     */
    public function getRelation(string $relation): mixed
    {
        return $this->relations[$relation];
    }

    /**
     * Determine if the given relation is loaded.
     */
    public function relationLoaded(string $key): bool
    {
        return array_key_exists($key, $this->relations);
    }

    /**
     * Set the given relationship on the model.
     */
    public function setRelation(string $relation, mixed $value): self
    {
        $this->relations[$relation] = $value;

        return $this;
    }

    /**
     * Unset a loaded relationship.
     */
    public function unsetRelation(string $relation): self
    {
        unset($this->relations[$relation]);

        return $this;
    }

    /**
     * Set the entire relations array on the model.
     */
    public function setRelations(array $relations): self
    {
        $this->relations = $relations;

        return $this;
    }

    /**
     * Duplicate the instance and unset all the loaded relations.
     */
    public function withoutRelations(): self
    {
        $model = clone $this;

        return $model->unsetRelations();
    }

    /**
     * Unset all the loaded relations for the instance.
     */
    public function unsetRelations(): self
    {
        $this->relations = [];

        return $this;
    }

    /**
     * Get the relationships that are touched on save.
     */
    public function getTouchedRelations(): array
    {
        return $this->touches;
    }

    /**
     * Set the relationships that are touched on save.
     */
    public function setTouchedRelations(array $touches): self
    {
        $this->touches = $touches;

        return $this;
    }
}
