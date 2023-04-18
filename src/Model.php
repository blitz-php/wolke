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

use ArrayAccess;
use BlitzPHP\Contracts\Database\ConnectionInterface;
use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Contracts\Support\Jsonable;
use BlitzPHP\Database\Builder\BaseBuilder;
use BlitzPHP\Database\Connection\BaseConnection;
use BlitzPHP\Database\Database;
use BlitzPHP\Database\Exceptions\DatabaseException;
use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Concerns\GuardsAttributes;
use BlitzPHP\Wolke\Concerns\HasAttributes;
use BlitzPHP\Wolke\Concerns\HasEvents;
use BlitzPHP\Wolke\Concerns\HasGlobalScopes;
use BlitzPHP\Wolke\Concerns\HasRelationships;
use BlitzPHP\Wolke\Concerns\HasTimestamps;
use BlitzPHP\Wolke\Concerns\HidesAttributes;
use BlitzPHP\Wolke\Contracts\Scope;
use BlitzPHP\Wolke\Events\Dispatcher;
use BlitzPHP\Wolke\Exceptions\JsonEncodingException;
use BlitzPHP\Wolke\Exceptions\MassAssignmentException;
use BlitzPHP\Wolke\Relations\Concerns\AsPivot;
use BlitzPHP\Wolke\Relations\Pivot;
use JsonSerializable;
use LogicException;

class Model implements Arrayable, ArrayAccess, Jsonable, JsonSerializable
{
    use ForwardsCalls;
    use GuardsAttributes;
    use HasAttributes;
    use HasEvents;
    use HasGlobalScopes;
    use HasRelationships;
    use HasTimestamps;
    use HidesAttributes;

    /**
     * The name of the "created at" column.
     *
     * @var string|null
     */
    public const CREATED_AT = 'created_at';

    /**
     * The name of the "updated at" column.
     *
     * @var string|null
     */
    public const UPDATED_AT = 'updated_at';

    /**
     * The connection name for the model.
     */
    protected string $connection = 'default';

    /**
     * The table associated with the model.
     */
    protected string $table = '';

    /**
     * The primary key for the model.
     */
    protected string $primaryKey = 'id';

    /**
     * The "type" of the primary key ID.
     */
    protected string $keyType = 'int';

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public bool $incrementing = true;

    /**
     * The relations to eager load on every query.
     */
    protected array $with = [];

    /**
     * The relationship counts that should be eager loaded on every query.
     */
    protected array $withCount = [];

    /**
     * The number of models to return for pagination.
     */
    protected int $perPage = 15;

    /**
     * Indicates if the model exists.
     */
    public bool $exists = false;

    /**
     * Indicates if the model was inserted during the current request lifecycle.
     */
    public bool $wasRecentlyCreated = false;

    /**
     * The connection resolver instance.
     *
     * @var \BlitzPHP\Database\Connection\BaseConnection
     */
    protected static $resolver;

    /**
     * The array of booted models.
     */
    protected static array $booted = [];

    /**
     * The array of trait initializers that will be called on each new instance.
     */
    protected static array $traitInitializers = [];

    /**
     * The array of global scopes on the model.
     */
    protected static array $globalScopes = [];

    /**
     * The list of models classes that should not be affected with touch.
     */
    protected static array $ignoreOnTouch = [];

    /**
     * Create a new Eloquent model instance.
     */
    public function __construct(array $attributes = [])
    {
        static::setEventDispatcher(new Dispatcher());

        $this->bootIfNotBooted();

        $this->initializeTraits();

        $this->syncOriginal();

        $this->fill($attributes);
    }

    /**
     * Check if the model needs to be booted and if so, do it.
     */
    protected function bootIfNotBooted(): void
    {
        if (! isset(static::$booted[static::class])) {
            static::$booted[static::class] = true;

            $this->fireModelEvent('booting', false);

            static::booting();
            static::boot();
            static::booted();

            $this->fireModelEvent('booted', false);
        }
    }

    /**
     * Perform any actions required before the model boots.
     */
    protected static function booting(): void
    {
    }

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        static::bootTraits();
    }

    /**
     * Boot all of the bootable traits on the model.
     */
    protected static function bootTraits(): void
    {
        $class = static::class;

        $booted = [];

        static::$traitInitializers[$class] = [];

        foreach (Helpers::classUsesRecursive($class) as $trait) {
            $method = 'boot' . Helpers::classBasename($trait);

            if (method_exists($class, $method) && ! in_array($method, $booted, true)) {
                forward_static_call([$class, $method]);

                $booted[] = $method;
            }

            if (method_exists($class, $method = 'initialize' . Helpers::classBasename($trait))) {
                static::$traitInitializers[$class][] = $method;

                static::$traitInitializers[$class] = array_unique(
                    static::$traitInitializers[$class]
                );
            }
        }
    }

    /**
     * Initialize any initializable traits on the model.
     */
    protected function initializeTraits(): void
    {
        foreach (static::$traitInitializers[static::class] as $method) {
            $this->{$method}();
        }
    }

    /**
     * Perform any actions required after the model boots.
     */
    protected static function booted(): void
    {
    }

    /**
     * Clear the list of booted models so they will be re-booted.
     */
    public static function clearBootedModels(): void
    {
        static::$booted = [];

        static::$globalScopes = [];
    }

    /**
     * Disables relationship model touching for the current class during given callback scope.
     */
    public static function withoutTouching(callable $callback): void
    {
        static::withoutTouchingOn([static::class], $callback);
    }

    /**
     * Disables relationship model touching for the given model classes during given callback scope.
     */
    public static function withoutTouchingOn(array $models, callable $callback): void
    {
        static::$ignoreOnTouch = array_values(array_merge(static::$ignoreOnTouch, $models));

        try {
            $callback();
        } finally {
            static::$ignoreOnTouch = array_values(array_diff(static::$ignoreOnTouch, $models));
        }
    }

    /**
     * Determine if the given model is ignoring touches.
     */
    public static function isIgnoringTouch(?string $class = null): bool
    {
        $class = $class ?: static::class;

        if (! get_class_vars($class)['timestamps'] || ! $class::UPDATED_AT) {
            return true;
        }

        foreach (static::$ignoreOnTouch as $ignoredClass) {
            if ($class === $ignoredClass || is_subclass_of($class, $ignoredClass)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @throws MassAssignmentException
     */
    public function fill(array $attributes): self
    {
        $totallyGuarded = $this->totallyGuarded();

        foreach ($this->fillableFromArray($attributes) as $key => $value) {
            // The developers may choose to place some attributes in the "fillable" array
            // which means only those attributes may be set through mass assignment to
            // the model, and all others will just get ignored for security reasons.
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            } elseif ($totallyGuarded) {
                throw new MassAssignmentException(sprintf(
                    'Add [%s] to fillable property to allow mass assignment on [%s].',
                    $key,
                    static::class
                ));
            }
        }

        return $this;
    }

    /**
     * Fill the model with an array of attributes. Force mass assignment.
     */
    public function forceFill(array $attributes): self
    {
        return static::unguarded(fn () => $this->fill($attributes));
    }

    /**
     * Qualify the given column name by the model's table.
     */
    public function qualifyColumn(string $column): string
    {
        if (Text::contains($column, '.')) {
            return $column;
        }

        return $this->getTable() . '.' . $column;
    }

    /**
     * Create a new instance of the given model.
     *
     * @return static
     */
    public function newInstance(array $attributes = [], bool $exists = false)
    {
        // This method just provides a convenient way for us to generate fresh model
        // instances of this current model. It is particularly useful during the
        // hydration of new objects via the Eloquent query builder instances.
        $model = new static((array) $attributes);

        $model->exists = $exists;

        $model->setConnection(
            $this->getConnectionName()
        );

        $model->setTable($this->getTable());

        $model->mergeCasts($this->casts);

        return $model;
    }

    /**
     * Create a new model instance that is existing.
     *
     * @return static
     */
    public function newFromBuilder(array $attributes = [], ?string $connection = null)
    {
        $model = $this->newInstance([], true);

        $model->setRawAttributes((array) $attributes, true);

        $model->setConnection($connection ?: $this->getConnectionName());

        $model->fireModelEvent('retrieved', false);

        return $model;
    }

    /**
     * Begin querying the model on a given connection.
     */
    public static function on(?string $connection = null): Builder
    {
        // First we will just create a fresh instance of this model, and then we can set the
        // connection on the model so that it is used for the queries we execute, as well
        // as being set on every relation we retrieve without a custom connection name.
        $instance = new static();

        $instance->setConnection($connection);

        return $instance->newQuery();
    }

    /**
     * Begin querying the model on the write connection.
     */
    public static function onWriteConnection(): BaseBuilder
    {
        return static::query()->useWritePdo();
    }

    /**
     * Get all of the models from the database.
     *
     * @param  array|...string  $columns
     *
     * @return Collection|static[]
     */
    public static function all(array|string $columns = ['*'])
    {
        return static::query()->get(
            is_array($columns) ? $columns : func_get_args()
        );
    }

    /**
     * Begin querying a model with eager loading.
     *
     * @param  array|...string  $relations
     */
    public static function with(array|string $relations): Builder
    {
        return static::query()->with(
            is_string($relations) ? func_get_args() : $relations
        );
    }

    /**
     * Eager load relations on the model.
     *
     * @param  array|...string  $relations
     */
    public function load($relations): self
    {
        $query = $this->newQueryWithoutRelationships()->with(
            is_string($relations) ? func_get_args() : $relations
        );

        $query->eagerLoadRelations([$this]);

        return $this;
    }

    /**
     * Eager load relationships on the polymorphic relation of a model.
     */
    public function loadMorph(string $relation, array $relations): self
    {
        if (! $this->{$relation}) {
            return $this;
        }

        $className = get_class($this->{$relation});

        $this->{$relation}->load($relations[$className] ?? []);

        return $this;
    }

    /**
     * Eager load relations on the model if they are not already eager loaded.
     *
     * @param  array|...string  $relations
     */
    public function loadMissing($relations): self
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        $this->newCollection([$this])->loadMissing($relations);

        return $this;
    }

    /**
     * Eager load relation's column aggregations on the model.
     */
    public function loadAggregate(array|string $relations, string $column, ?string $function = null): self
    {
        $this->newCollection([$this])->loadAggregate($relations, $column, $function);

        return $this;
    }

    /**
     * Eager load relation counts on the model.
     *
     * @param  array|...string  $relations
     */
    public function loadCount($relations): self
    {
        $relations = is_string($relations) ? func_get_args() : $relations;

        return $this->loadAggregate($relations, '*', 'count');
    }

    /**
     * Eager load relation max column values on the model.
     *
     * @param  array|...string  $relations
     */
    public function loadMax($relations, string $column): self
    {
        return $this->loadAggregate($relations, $column, 'max');
    }

    /**
     * Eager load relation min column values on the model.
     */
    public function loadMin(array|string $relations, string $column): self
    {
        return $this->loadAggregate($relations, $column, 'min');
    }

    /**
     * Eager load relation's column summations on the model.
     */
    public function loadSum(array|string $relations, string $column): self
    {
        return $this->loadAggregate($relations, $column, 'sum');
    }

    /**
     * Eager load relation average column values on the model.
     */
    public function loadAvg(array|string $relations, string $column): self
    {
        return $this->loadAggregate($relations, $column, 'avg');
    }

    /**
     * Eager load related model existence values on the model.
     */
    public function loadExists(array|string $relations): self
    {
        return $this->loadAggregate($relations, '*', 'exists');
    }

    /**
     * Eager load relationship column aggregation on the polymorphic relation of a model.
     */
    public function loadMorphAggregate(string $relation, array $relations, string $column, ?string $function = null): self
    {
        if (! $this->{$relation}) {
            return $this;
        }

        $className = get_class($this->{$relation});

        $this->{$relation}->loadAggregate($relations[$className] ?? [], $column, $function);

        return $this;
    }

    /**
     * Eager load relationship counts on the polymorphic relation of a model.
     */
    public function loadMorphCount(string $relation, array $relations): self
    {
        return $this->loadMorphAggregate($relation, $relations, '*', 'count');
    }

    /**
     * Eager load relationship max column values on the polymorphic relation of a model.
     */
    public function loadMorphMax(string $relation, array $relations, string $column): self
    {
        return $this->loadMorphAggregate($relation, $relations, $column, 'max');
    }

    /**
     * Eager load relationship min column values on the polymorphic relation of a model.
     */
    public function loadMorphMin(string $relation, array $relations, string $column): self
    {
        return $this->loadMorphAggregate($relation, $relations, $column, 'min');
    }

    /**
     * Eager load relationship column summations on the polymorphic relation of a model.
     */
    public function loadMorphSum(string $relation, array $relations, string $column): self
    {
        return $this->loadMorphAggregate($relation, $relations, $column, 'sum');
    }

    /**
     * Eager load relationship average column values on the polymorphic relation of a model.
     */
    public function loadMorphAvg(string $relation, array $relations, string $column): self
    {
        return $this->loadMorphAggregate($relation, $relations, $column, 'avg');
    }

    /**
     * Increment a column's value by a given amount.
     */
    protected function increment(string $column, float|int $amount = 1, array $extra = []): int
    {
        return $this->incrementOrDecrement($column, $amount, $extra, 'increment');
    }

    /**
     * Decrement a column's value by a given amount.
     */
    protected function decrement(string $column, float|int $amount = 1, array $extra = []): int
    {
        return $this->incrementOrDecrement($column, $amount, $extra, 'decrement');
    }

    /**
     * Run the increment or decrement method on the model.
     */
    protected function incrementOrDecrement(string $column, float|int $amount, array $extra, string $method): int
    {
        $query = $this->newQueryWithoutRelationships();

        if (! $this->exists) {
            return $query->{$method}($column, $amount, $extra);
        }

        $this->{$column} = $this->isClassDeviable($column)
            ? $this->deviateClassCastableAttribute($method, $column, $amount)
            : $this->{$column} + ($method === 'increment' ? $amount : $amount * -1);

        $this->forceFill($extra);

        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        return Helpers::tap($this->setKeysForSaveQuery($query)->{$method}($column, $amount, $extra), function () use ($column) {
            $this->syncChanges();

            $this->fireModelEvent('updated', false);

            $this->syncOriginalAttribute($column);
        });
    }

    /**
     * Update the model in the database.
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->fill($attributes)->save($options);
    }

    /**
     * Update the model in the database without raising any events.
     */
    public function updateQuietly(array $attributes = [], array $options = []): bool
    {
        if (! $this->exists) {
            return false;
        }

        return $this->fill($attributes)->saveQuietly($options);
    }

    /**
     * Save the model and all of its relationships.
     */
    public function push(): bool
    {
        if (! $this->save()) {
            return false;
        }

        // To sync all of the relationships to the database, we will simply spin through
        // the relationships and save each model via this "push" method, which allows
        // us to recurse into all of these nested relations for the model instance.
        foreach ($this->relations as $models) {
            $models = $models instanceof Collection
                        ? $models->all() : [$models];

            foreach (array_filter($models) as $model) {
                if (! $model->push()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Save the model to the database without raising any events.
     */
    public function saveQuietly(array $options = []): bool
    {
        return static::withoutEvents(fn () => $this->save($options));
    }

    /**
     * Save the model to the database.
     */
    public function save(array $options = []): bool
    {
        $this->mergeAttributesFromClassCasts();

        $query = $this->newModelQuery();

        // If the "saving" event returns false we'll bail out of the save and return
        // false, indicating that the save failed. This provides a chance for any
        // listeners to cancel save operations if validations fail or whatever.
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        // If the model already exists in the database we can just update our record
        // that is already in this database using the current IDs in this "where"
        // clause to only update this model. Otherwise, we'll just insert them.
        if ($this->exists) {
            $saved = $this->isDirty() ?
                        $this->performUpdate($query) : true;
        }

        // If the model is brand new, we'll insert it into our database and set the
        // ID attribute on the model to the value of the newly inserted row's ID
        // which is typically an auto-increment value managed by the database.
        else {
            $saved = $this->performInsert($query);
        }

        // If the model is successfully saved, we need to do a few more things once
        // that is done. We will call the "saved" method here to run any actions
        // we need to happen after a model gets successfully saved right here.
        if ($saved) {
            $this->finishSave($options);
        }

        return $saved;
    }

    /**
     * Save the model to the database using transaction.
     *
     * @throws DatabaseException
     */
    public function saveOrFail(array $options = []): bool
    {
        $this->getConnection()->transBegin();

        try {
            $result = $this->save($options);

            $this->getConnection()->transCommit();
        } catch (DatabaseException $e) {
            $this->getConnection()->transRollback();

            throw $e;
        }

        return $result;
    }

    /**
     * Perform any actions that are necessary after the model is saved.
     */
    protected function finishSave(array $options): void
    {
        $this->fireModelEvent('saved', false);

        if ($this->isDirty() && ($options['touch'] ?? true)) {
            $this->touchOwners();
        }

        $this->syncOriginal();
    }

    /**
     * Perform a model update operation.
     */
    protected function performUpdate(Builder $query): bool
    {
        // If the updating event returns false, we will cancel the update operation so
        // developers can hook Validation systems into their models and cancel this
        // operation if the model does not pass validation. Otherwise, we update.
        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        // First we need to create a fresh query instance and touch the creation and
        // update timestamp on the model which are maintained by us for developer
        // convenience. Then we will just continue saving the model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // Once we have run the update operation, we will fire the "updated" event for
        // this model instance. This will allow developers to hook into these after
        // models are updated, giving them a chance to do any special processing.
        $dirty = $this->getDirty();

        if (count($dirty) > 0) {
            $this->setKeysForSaveQuery($query)->update($dirty);

            $this->syncChanges();

            $this->fireModelEvent('updated', false);
        }

        return true;
    }

    /**
     * Set the keys for a select query.
     */
    protected function setKeysForSelectQuery(Builder $query): Builder
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSelectQuery());

        return $query;
    }

    /**
     * Get the primary key value for a select query.
     */
    protected function getKeyForSelectQuery(): mixed
    {
        return $this->original[$this->getKeyName()] ?? $this->getKey();
    }

    /**
     * Set the keys for a save update query.
     */
    protected function setKeysForSaveQuery(Builder $query): Builder
    {
        $query->where($this->getKeyName(), '=', $this->getKeyForSaveQuery());

        return $query;
    }

    /**
     * Get the primary key value for a save query.
     */
    protected function getKeyForSaveQuery(): mixed
    {
        return $this->original[$this->getKeyName()] ?? $this->getKey();
    }

    /**
     * Perform a model insert operation.
     */
    protected function performInsert(Builder $query): bool
    {
        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        // First we'll need to create a fresh query instance and touch the creation and
        // update timestamps on this model, which are maintained by us for developer
        // convenience. After, we will just continue saving these model instances.
        if ($this->usesTimestamps()) {
            $this->updateTimestamps();
        }

        // If the model has an incrementing key, we can use the "insertGetId" method on
        // the query builder, which will give us back the final inserted ID for this
        // table from the database. Not all tables have to be incrementing though.
        $attributes = $this->getAttributesForInsert();

        $query->insert($attributes);

        if ($this->getIncrementing()) {
            $this->insertAndSetId($query, $attributes);
        }

        // If the table isn't incrementing we'll simply insert these attributes as they
        // are. These attribute arrays must contain an "id" column previously placed
        // there by the developer as the manually determined key for these models.
        else {
            if (empty($attributes)) {
                return true;
            }
        }

        // We will go ahead and set the exists property to true, so that it is set when
        // the created event is fired, just in case the developer tries to update it
        // during the event. This will allow them to do so and run an update here.
        $this->exists = true;

        $this->wasRecentlyCreated = true;

        $this->fireModelEvent('created', false);

        return true;
    }

    /**
     * Insert the given attributes and set the ID on the model.
     */
    protected function insertAndSetId(Builder $query, array $attributes): void
    {
        $this->setAttribute($this->getKeyName(), $query->getQuery()->db()->insertID());
    }

    /**
     * Destroy the models for the given IDs.
     *
     * @param array|Collection|int|string $ids
     */
    public static function destroy($ids): int
    {
        if ($ids instanceof Collection) {
            $ids = $ids->modelKeys();
        }

        if ($ids instanceof IterableCollection) {
            $ids = $ids->all();
        }

        $ids = is_array($ids) ? $ids : func_get_args();

        if (count($ids) === 0) {
            return 0;
        }

        // We will actually pull the models from the database table and call delete on
        // each of them individually so that their events get fired properly with a
        // correct set of attributes in case the developers wants to check these.
        $key = ($instance = new static())->getKeyName();

        $count = 0;

        foreach ($instance->whereIn($key, $ids)->get() as $model) {
            if ($model->delete()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Delete the model from the database.
     *
     * @throws LogicException
     */
    public function delete(): ?bool
    {
        $this->mergeAttributesFromClassCasts();

        if (null === $this->getKeyName()) {
            throw new LogicException('No primary key defined on model.');
        }

        // If the model doesn't exist, there is nothing to delete so we'll just return
        // immediately and not do anything else. Otherwise, we will continue with a
        // deletion process on the model, firing the proper events, and so forth.
        if (! $this->exists) {
            return null;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        // Here, we'll touch the owning models, verifying these timestamps get updated
        // for the models. This will allow any caching to get broken on the parents
        // by the timestamp. Then we will go ahead and delete the model instance.
        $this->touchOwners();

        $this->performDeleteOnModel();

        // Once the model has been deleted, we will fire off the deleted event so that
        // the developers may hook into post-delete operations. We will then return
        // a boolean true as the delete is presumably successful on the database.
        $this->fireModelEvent('deleted', false);

        return true;
    }

    /**
     * Force a hard delete on a soft deleted model.
     *
     * This method protects developers from running forceDelete when the trait is missing.
     */
    public function forceDelete(): ?bool
    {
        return $this->delete();
    }

    /**
     * Perform the actual delete query on this model instance.
     */
    protected function performDeleteOnModel(): void
    {
        $this->setKeysForSaveQuery($this->newModelQuery())->delete();

        $this->exists = false;
    }

    /**
     * Begin querying the model.
     */
    public static function query(): Builder
    {
        return (new static())->newQuery();
    }

    /**
     * Get a new query builder for the model's table.
     */
    public function newQuery(): Builder
    {
        return $this->registerGlobalScopes($this->newQueryWithoutScopes());
    }

    /**
     * Get a new query builder that doesn't have any global scopes or eager loading.
     */
    public function newModelQuery(): Builder
    {
        return $this->newEloquentBuilder($this->newBaseQueryBuilder())->setModel($this);
    }

    /**
     * Get a new query builder with no relationships loaded.
     */
    public function newQueryWithoutRelationships(): Builder
    {
        return $this->registerGlobalScopes($this->newModelQuery());
    }

    /**
     * Register the global scopes for this builder instance.
     */
    public function registerGlobalScopes(Builder $builder): Builder
    {
        foreach ($this->getGlobalScopes() as $identifier => $scope) {
            $builder->withGlobalScope($identifier, $scope);
        }

        return $builder;
    }

    /**
     * Get a new query builder that doesn't have any global scopes.
     */
    public function newQueryWithoutScopes(): Builder
    {
        return $this->newModelQuery()
            ->with($this->with)
            ->withCount($this->withCount);
    }

    /**
     * Get a new query instance without a given scope.
     */
    public function newQueryWithoutScope(Scope|string $scope): Builder
    {
        return $this->newQuery()->withoutGlobalScope($scope);
    }

    /**
     * Get a new query to restore one or more models by their queueable IDs.
     */
    public function newQueryForRestoration(array|int $ids): Builder
    {
        return is_array($ids)
                ? $this->newQueryWithoutScopes()->whereIn($this->getQualifiedKeyName(), $ids)
                : $this->newQueryWithoutScopes()->whereKey($ids);
    }

    /**
     * Create a new Eloquent query builder for the model.
     */
    public function newEloquentBuilder(BaseBuilder $query): Builder
    {
        return new Builder($query);
    }

    /**
     * Get a new query builder instance for the connection.
     */
    protected function newBaseQueryBuilder(): BaseBuilder
    {
        return $this->getConnection()->table($this->getTable());
    }

    /**
     * Create a new Eloquent Collection instance.
     */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /**
     * Create a new pivot model instance.
     */
    public function newPivot(self $parent, array $attributes, string $table, bool $exists, ?string $using = null): Pivot
    {
        return $using ? $using::fromRawAttributes($parent, $attributes, $table, $exists)
                      : Pivot::fromAttributes($parent, $attributes, $table, $exists);
    }

    /**
     * Determine if the model has a given scope.
     */
    public function hasNamedScope(string $scope): bool
    {
        return method_exists($this, 'scope' . ucfirst($scope));
    }

    /**
     * Apply the given named scope if possible.
     */
    public function callNamedScope(string $scope, array $parameters = []): mixed
    {
        return $this->{'scope' . ucfirst($scope)}(...$parameters);
    }

    /**
     * Convert the model instance to an array.
     */
    public function toArray(): array
    {
        return array_merge($this->attributesToArray(), $this->relationsToArray());
    }

    /**
     * Convert the model instance to JSON.
     *
     * @throws JsonEncodingException
     */
    public function toJson(int $options = 0): string
    {
        $json = json_encode($this->jsonSerialize(), $options);

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw JsonEncodingException::forModel($this, json_last_error_msg());
        }

        return $json;
    }

    /**
     * Convert the object into something JSON serializable.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Reload a fresh model instance from the database.
     *
     * @param  array|...string  $with
     *
     * @return static|null
     */
    public function fresh(array|string $with = [])
    {
        if (! $this->exists) {
            return;
        }

        return $this->setKeysForSelectQuery($this->newQueryWithoutScopes())
            ->with(is_string($with) ? func_get_args() : $with)
            ->first();
    }

    /**
     * Reload the current model instance with fresh attributes from the database.
     */
    public function refresh(): self
    {
        if (! $this->exists) {
            return $this;
        }

        $this->setRawAttributes(
            $this->setKeysForSelectQuery($this->newQueryWithoutScopes())->firstOrFail()->attributes
        );

        $this->load(Helpers::collect($this->relations)->reject(static function ($relation) {
            return $relation instanceof Pivot
                || (is_object($relation) && in_array(AsPivot::class, Helpers::classUsesRecursive($relation), true));
        })->keys()->all());

        $this->syncOriginal();

        return $this;
    }

    /**
     * Clone the model into a new, non-existing instance.
     *
     * @return static
     */
    public function replicate(?array $except = null)
    {
        $defaults = [
            $this->getKeyName(),
            $this->getCreatedAtColumn(),
            $this->getUpdatedAtColumn(),
        ];

        $attributes = Arr::except(
            $this->getAttributes(),
            $except ? array_unique(array_merge($except, $defaults)) : $defaults
        );

        return Helpers::tap(new static(), function ($instance) use ($attributes) {
            $instance->setRawAttributes($attributes);

            $instance->setRelations($this->relations);

            $instance->fireModelEvent('replicating', false);
        });
    }

    /**
     * Determine if two models have the same ID and belong to the same table.
     */
    public function is(?self $model): bool
    {
        return null !== $model
               && $this->getKey() === $model->getKey()
               && $this->getTable() === $model->getTable()
               && $this->getConnectionName() === $model->getConnectionName();
    }

    /**
     * Determine if two models are not the same.
     */
    public function isNot(?self $model): bool
    {
        return ! $this->is($model);
    }

    /**
     * Get the database connection for the model.
     */
    public function getConnection(): BaseConnection
    {
        return static::resolveConnection($this->getConnectionName());
    }

    /**
     * Get the current connection name for the model.
     */
    public function getConnectionName(): ?string
    {
        return $this->connection;
    }

    /**
     * Set the connection associated with the model.
     */
    public function setConnection(?string $name): self
    {
        $this->connection = $name;

        return $this;
    }

    /**
     * Resolve a connection instance.
     */
    public static function resolveConnection(?string $connection = null): BaseConnection
    {
        return static::$resolver = Database::connection([], static::class);
    }

    /**
     * Get the connection resolver instance.
     */
    public static function getConnectionResolver(): BaseConnection
    {
        return static::$resolver;
    }

    /**
     * Set the connection resolver instance.
     */
    public static function setConnectionResolver(ConnectionInterface $resolver): void
    {
        static::$resolver = $resolver;
    }

    /**
     * Unset the connection resolver for models.
     */
    public static function unsetConnectionResolver(): void
    {
        static::$resolver = null;
    }

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return $this->table ?? Text::snake(Text::pluralStudly(Helpers::classBasename($this)));
    }

    /**
     * Set the table associated with the model.
     */
    public function setTable(string $table): self
    {
        $this->table = $table;

        return $this;
    }

    /**
     * Get the primary key for the model.
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Set the primary key for the model.
     */
    public function setKeyName(string $key): self
    {
        $this->primaryKey = $key;

        return $this;
    }

    /**
     * Get the table qualified key name.
     */
    public function getQualifiedKeyName(): string
    {
        return $this->qualifyColumn($this->getKeyName());
    }

    /**
     * Get the auto-incrementing key type.
     */
    public function getKeyType(): string
    {
        return $this->keyType;
    }

    /**
     * Set the data type for the primary key.
     */
    public function setKeyType(string $type): self
    {
        $this->keyType = $type;

        return $this;
    }

    /**
     * Get the value indicating whether the IDs are incrementing.
     */
    public function getIncrementing(): bool
    {
        return $this->incrementing;
    }

    /**
     * Set whether IDs are incrementing.
     */
    public function setIncrementing(bool $value): self
    {
        $this->incrementing = $value;

        return $this;
    }

    /**
     * Get the value of the model's primary key.
     */
    public function getKey(): mixed
    {
        return $this->getAttribute($this->getKeyName());
    }

    // /**
    //  * Get the queueable identity for the entity.
    //  *
    //  * @return mixed
    //  */
    // public function getQueueableId()
    // {
    //     return $this->getKey();
    // }

    // /**
    //  * Get the queueable relationships for the entity.
    //  *
    //  * @return array
    //  */
    // public function getQueueableRelations()
    // {
    //     $relations = [];

    //     foreach ($this->getRelations() as $key => $relation) {
    //         if (! method_exists($this, $key)) {
    //             continue;
    //         }

    //         $relations[] = $key;

    //         if ($relation instanceof QueueableCollection) {
    //             foreach ($relation->getQueueableRelations() as $collectionValue) {
    //                 $relations[] = $key . '.' . $collectionValue;
    //             }
    //         }

    //         if ($relation instanceof QueueableEntity) {
    //             foreach ($relation->getQueueableRelations() as $entityKey => $entityValue) {
    //                 $relations[] = $key . '.' . $entityValue;
    //             }
    //         }
    //     }

    //     return array_unique($relations);
    // }

    // /**
    //  * Get the queueable connection for the entity.
    //  *
    //  * @return string|null
    //  */
    // public function getQueueableConnection()
    // {
    //     return $this->getConnectionName();
    // }

    // /**
    //  * Get the value of the model's route key.
    //  *
    //  * @return mixed
    //  */
    // public function getRouteKey()
    // {
    //     return $this->getAttribute($this->getRouteKeyName());
    // }

    // /**
    //  * Get the route key for the model.
    //  *
    //  * @return string
    //  */
    // public function getRouteKeyName()
    // {
    //     return $this->getKeyName();
    // }

    // /**
    //  * Retrieve the model for a bound value.
    //  *
    //  * @param  mixed  $value
    //  * @param  string|null  $field
    //  * @return \BlitzPHP\Wolke\Model|null
    //  */
    // public function resolveRouteBinding($value, $field = null)
    // {
    //     return $this->where($field ?? $this->getRouteKeyName(), $value)->first();
    // }

    // /**
    //  * Retrieve the child model for a bound value.
    //  *
    //  * @param  string  $childType
    //  * @param  mixed  $value
    //  * @param  string|null  $field
    //  * @return \BlitzPHP\Wolke\Model|null
    //  */
    // public function resolveChildRouteBinding($childType, $value, $field)
    // {
    //     $relationship = $this->{Text::plural(Text::camel($childType))}();

    //     $field = $field ?: $relationship->getRelated()->getRouteKeyName();

    //     if (
    //         $relationship instanceof HasManyThrough ||
    //         $relationship instanceof BelongsToMany
    //     ) {
    //         return $relationship->where($relationship->getRelated()->getTable() . '.' . $field, $value)->first();
    //     } else {
    //         return $relationship->where($field, $value)->first();
    //     }
    // }

    /**
     * Get the default foreign key name for the model.
     */
    public function getForeignKey(): string
    {
        return Text::snake(Helpers::classBasename($this)) . '_' . $this->getKeyName();
    }

    /**
     * Get the number of models to return per page.
     */
    public function getPerPage(): int
    {
        return $this->perPage;
    }

    /**
     * Set the number of models to return per page.
     */
    public function setPerPage(int $perPage): self
    {
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * Dynamically retrieve attributes on the model.
     */
    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes on the model.
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Determine if the given attribute exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return null !== $this->getAttribute($offset);
    }

    /**
     * Get the value for a given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->getAttribute($offset);
    }

    /**
     * Set the value for a given offset.
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->setAttribute($offset, $value);
    }

    /**
     * Unset the value for a given offset.
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset], $this->relations[$offset]);
    }

    /**
     * Determine if an attribute or relation exists on the model.
     */
    public function __isset(string $key): bool
    {
        return $this->offsetExists($key);
    }

    /**
     * Unset an attribute on the model.
     */
    public function __unset(string $key): void
    {
        $this->offsetUnset($key);
    }

    /**
     * Handle dynamic method calls into the model.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        if (in_array($method, ['increment', 'decrement'], true)) {
            return $this->{$method}(...$parameters);
        }

        if ($resolver = (static::$relationResolvers[static::class][$method] ?? null)) {
            return $resolver($this);
        }

        return $this->forwardCallTo($this->newQuery(), $method, $parameters);
    }

    /**
     * Handle dynamic static method calls into the model.
     */
    public static function __callStatic(string $method, array $parameters = []): mixed
    {
        return (new static())->{$method}(...$parameters);
    }

    /**
     * Convert the model to its string representation.
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

    /**
     * Prepare the object for serialization.
     */
    public function __sleep(): array
    {
        $this->mergeAttributesFromClassCasts();

        $this->classCastCache = [];

        return array_keys(get_object_vars($this));
    }

    /**
     * When a model is being unserialized, check if it needs to be booted.
     */
    public function __wakeup(): void
    {
        $this->bootIfNotBooted();
    }
}