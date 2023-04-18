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
use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection as IterableCollection;
use BlitzPHP\Utilities\String\Text;
use Closure;
use LogicException;

class Collection extends IterableCollection
{
    /**
     * Find a model in the collection by key.
     *
     * @return Model|static|null
     */
    public function find(mixed $key, mixed $default = null)
    {
        if ($key instanceof Model) {
            $key = $key->getKey();
        }

        if ($key instanceof Arrayable) {
            $key = $key->toArray();
        }

        if (is_array($key)) {
            if ($this->isEmpty()) {
                return new static();
            }

            return $this->whereIn($this->first()->getKeyName(), $key);
        }

        return Arr::first($this->items, static fn ($model) => $model->getKey() === $key, $default);
    }

    /**
     * Load a set of relationships onto the collection.
     */
    public function load(array|string $relations): self
    {
        if ($this->isNotEmpty()) {
            if (is_string($relations)) {
                $relations = func_get_args();
            }

            $query = $this->first()->newQueryWithoutRelationships()->with($relations);

            $this->items = $query->eagerLoadRelations($this->items);
        }

        return $this;
    }

    /**
     * Load a set of aggregations over relationship's column onto the collection.
     */
    public function loadAggregate(array|string $relations, string $column, ?string $function = null): self
    {
        if ($this->isEmpty()) {
            return $this;
        }

        $models = $this->first()->newModelQuery()
            ->whereKey($this->modelKeys())
            ->select($this->first()->getKeyName())
            ->withAggregate($relations, $column, $function)
            ->get()
            ->keyBy($this->first()->getKeyName());

        $attributes = Arr::except(
            array_keys($models->first()->getAttributes()),
            $models->first()->getKeyName()
        );

        $this->each(static function ($model) use ($models, $attributes) {
            $extraAttributes = Arr::only($models->get($model->getKey())->getAttributes(), $attributes);

            $model->forceFill($extraAttributes)->syncOriginalAttributes($attributes);
        });

        return $this;
    }

    /**
     * Load a set of relationship counts onto the collection.
     */
    public function loadCount(array|string $relations): self
    {
        return $this->loadAggregate($relations, '*', 'count');
    }

    /**
     * Load a set of relationship's max column values onto the collection.
     */
    public function loadMax(array|string $relations, string $column): self
    {
        return $this->loadAggregate($relations, $column, 'max');
    }

    /**
     * Load a set of relationship's min column values onto the collection.
     */
    public function loadMin(array|string $relations, string $column): self
    {
        return $this->loadAggregate($relations, $column, 'min');
    }

    /**
     * Load a set of relationship's column summations onto the collection.
     */
    public function loadSum(array|string $relations, string $column): self
    {
        return $this->loadAggregate($relations, $column, 'sum');
    }

    /**
     * Load a set of relationship's average column values onto the collection.
     */
    public function loadAvg(array|string $relations, string $column): self
    {
        return $this->loadAggregate($relations, $column, 'avg');
    }

    /**
     * Load a set of related existences onto the collection.
     */
    public function loadExists(array|string $relations): self
    {
        return $this->loadAggregate($relations, '*', 'exists');
    }

    /**
     * Load a set of relationships onto the collection if they are not already eager loaded.
     *
     * @param  array|...string  $relations
     */
    public function loadMissing($relations): self
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        foreach ($relations as $key => $value) {
            if (is_numeric($key)) {
                $key = $value;
            }

            $segments = explode('.', explode(':', $key)[0]);

            if (Text::contains($key, ':')) {
                $segments[count($segments) - 1] .= ':' . explode(':', $key)[1];
            }

            $path = [];

            foreach ($segments as $segment) {
                $path[] = [$segment => $segment];
            }

            if (is_callable($value)) {
                $path[count($segments) - 1][end($segments)] = $value;
            }

            $this->loadMissingRelation($this, $path);
        }

        return $this;
    }

    /**
     * Load a relationship path if it is not already eager loaded.
     */
    protected function loadMissingRelation(self $models, array $path): void
    {
        $relation = array_shift($path);

        $name = explode(':', key($relation))[0];

        if (is_string(reset($relation))) {
            $relation = reset($relation);
        }

        $models->filter(static fn ($model) => null !== $model && ! $model->relationLoaded($name))->load($relation);

        if (empty($path)) {
            return;
        }

        $models = $models->pluck($name);

        if ($models->first() instanceof IterableCollection) {
            $models = $models->collapse();
        }

        $this->loadMissingRelation(new static($models), $path);
    }

    /**
     * Load a set of relationships onto the mixed relationship collection.
     */
    public function loadMorph(string $relation, array $relations): self
    {
        $this->pluck($relation)
            ->filter()
            ->groupBy(static fn ($model) => get_class($model))
            ->each(static function ($models, $className) use ($relations) {
                static::make($models)->load($relations[$className] ?? []);
            });

        return $this;
    }

    /**
     * Load a set of relationship counts onto the mixed relationship collection.
     */
    public function loadMorphCount(string $relation, array $relations): self
    {
        $this->pluck($relation)
            ->filter()
            ->groupBy(static fn ($model) => get_class($model))
            ->each(static function ($models, $className) use ($relations) {
                static::make($models)->loadCount($relations[$className] ?? []);
            });

        return $this;
    }

    /**
     * Determine if a key exists in the collection.
     *
     * @param mixed $key
     */
    public function contains($key, mixed $operator = null, mixed $value = null): bool
    {
        if (func_num_args() > 1 || $this->useAsCallable($key)) {
            return parent::contains(...func_get_args());
        }

        if ($key instanceof Model) {
            return parent::contains(static fn ($model) => $model->is($key));
        }

        return parent::contains(static fn ($model) => $model->getKey() === $key);
    }

    /**
     * Get the array of primary keys.
     */
    public function modelKeys(): array
    {
        return array_map(static fn ($model) => $model->getKey(), $this->items);
    }

    /**
     * Merge the collection with the given items.
     *
     * @param array|ArrayAccess $items
     *
     * @return static
     */
    public function merge($items)
    {
        $dictionary = $this->getDictionary();

        foreach ($items as $item) {
            $dictionary[$item->getKey()] = $item;
        }

        return new static(array_values($dictionary));
    }

    /**
     * Run a map over each of the items.
     *
     * @return IterableCollection|static
     */
    public function map(callable $callback)
    {
        $result = parent::map($callback);

        return $result->contains(static fn ($item) => ! $item instanceof Model) ? $result->toBase() : $result;
    }

    /**
     * Run an associative map over each of the items.
     *
     * The callback should return an associative array with a single key / value pair.
     *
     * @return IterableCollection|static
     */
    public function mapWithKeys(callable $callback)
    {
        $result = parent::mapWithKeys($callback);

        return $result->contains(static fn ($item) => ! $item instanceof Model) ? $result->toBase() : $result;
    }

    /**
     * Reload a fresh model instance from the database for all the entities.
     *
     * @param  array|...string  $with
     *
     * @return static
     */
    public function fresh($with = [])
    {
        if ($this->isEmpty()) {
            return new static();
        }

        $model = $this->first();

        $freshModels = $model->newQueryWithoutScopes()
            ->with(is_string($with) ? func_get_args() : $with)
            ->whereIn($model->getKeyName(), $this->modelKeys())
            ->get()
            ->getDictionary();

        return $this->filter(static fn ($model) => $model->exists && isset($freshModels[$model->getKey()]))
            ->map(static fn ($model) => $freshModels[$model->getKey()]);
    }

    /**
     * {@inheritDoc}
     */
    public function diff($items)
    {
        $diff = new static();

        $dictionary = $this->getDictionary($items);

        foreach ($this->items as $item) {
            if (! isset($dictionary[$item->getKey()])) {
                $diff->add($item);
            }
        }

        return $diff;
    }

    /**
     * {@inheritDoc}
     */
    public function intersect($items)
    {
        $intersect = new static();

        if (empty($items)) {
            return $intersect;
        }

        $dictionary = $this->getDictionary($items);

        foreach ($this->items as $item) {
            if (isset($dictionary[$item->getKey()])) {
                $intersect->add($item);
            }
        }

        return $intersect;
    }

    /**
     * {@inheritDoc}
     */
    public function unique($key = null, bool $strict = false)
    {
        if (null !== $key) {
            return parent::unique($key, $strict);
        }

        return new static(array_values($this->getDictionary()));
    }

    /**
     * {@inheritDoc}
     */
    public function only($keys)
    {
        if (null === $keys) {
            return new static($this->items);
        }

        $dictionary = Arr::only($this->getDictionary(), $keys);

        return new static(array_values($dictionary));
    }

    /**
     * {@inheritDoc}
     */
    public function except($keys)
    {
        $dictionary = Arr::except($this->getDictionary(), $keys);

        return new static(array_values($dictionary));
    }

    /**
     * Make the given, typically visible, attributes hidden across the entire collection.
     */
    public function makeHidden(array|string $attributes): self
    {
        return $this->each->makeHidden($attributes);
    }

    /**
     * Make the given, typically hidden, attributes visible across the entire collection.
     */
    public function makeVisible(array|string $attributes): self
    {
        return $this->each->makeVisible($attributes);
    }

    /**
     * Append an attribute across the entire collection.
     */
    public function append(array|string $attributes): self
    {
        return $this->each->append($attributes);
    }

    /**
     * Get a dictionary keyed by primary keys.
     */
    public function getDictionary(ArrayAccess|iterable|null $items = null): array
    {
        $items = null === $items ? $this->items : $items;

        $dictionary = [];

        foreach ($items as $value) {
            $dictionary[$value->getKey()] = $value;
        }

        return $dictionary;
    }

    /**
     * The following methods are intercepted to always return base collections.
     *
     * @param mixed $value
     */

    /**
     * {@inheritDoc}
     *
     * @return IterableCollection
     */
    public function pluck($value, ?string $key = null)
    {
        return $this->toBase()->pluck($value, $key);
    }

    /**
     * {@inheritDoc}
     *
     * @return IterableCollection
     */
    public function keys()
    {
        return $this->toBase()->keys();
    }

    /**
     * {@inheritDoc}
     *
     * @return IterableCollection
     */
    public function zip($items)
    {
        return $this->toBase()->zip(...func_get_args());
    }

    /**
     * {@inheritDoc}
     *
     * @return IterableCollection
     */
    public function collapse()
    {
        return $this->toBase()->collapse();
    }

    /**
     * {@inheritDoc}
     *
     * @return IterableCollection
     */
    public function flatten(int $depth = INF)
    {
        return $this->toBase()->flatten($depth);
    }

    /**
     * {@inheritDoc}
     *
     * @return IterableCollection
     */
    public function flip()
    {
        return $this->toBase()->flip();
    }

    /**
     * {@inheritDoc}
     *
     * @return IterableCollection
     */
    public function pad(int $size, mixed $value)
    {
        return $this->toBase()->pad($size, $value);
    }

    /**
     * {@inheritDoc}
     */
    protected function duplicateComparator(bool $strict): Closure
    {
        return static fn ($a, $b) => $a->is($b);
    }

    /**
     * Get the type of the entities being queued.
     *
     * @throws LogicException
     */
    public function getQueueableClass(): ?string
    {
        if ($this->isEmpty()) {
            return null;
        }

        $class = get_class($this->first());

        $this->each(static function ($model) use ($class) {
            if (get_class($model) !== $class) {
                throw new LogicException('La mise en file d\'attente de collections avec plusieurs types de modèles n\'est pas prise en charge.');
            }
        });

        return $class;
    }

    /**
     * Get the identifiers for all of the entities.
     */
    public function getQueueableIds(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        return $this->modelKeys();
    }

    /**
     * Get the relationships of the entities being queued.
     */
    public function getQueueableRelations(): array
    {
        if ($this->isEmpty()) {
            return [];
        }

        $relations = $this->map->getQueueableRelations()->all();

        if (count($relations) === 0 || $relations === [[]]) {
            return [];
        }
        if (count($relations) === 1) {
            return reset($relations);
        }

        return array_intersect(...$relations);
    }

    /**
     * Get the connection of the entities being queued.
     *
     * @throws LogicException
     */
    public function getQueueableConnection(): ?string
    {
        if ($this->isEmpty()) {
            return null;
        }

        $connection = $this->first()->getConnectionName();

        $this->each(static function ($model) use ($connection) {
            if ($model->getConnectionName() !== $connection) {
                throw new LogicException('La mise en file d\'attente des collections avec plusieurs connexions de modèle n\'est pas prise en charge.');
            }
        });

        return $connection;
    }

    /**
     * Get the Eloquent query builder from the collection.
     *
     * @throws LogicException
     */
    public function toQuery(): Builder
    {
        $model = $this->first();

        if (! $model) {
            throw new LogicException('Unable to create query for empty collection.');
        }

        $class = get_class($model);

        if ($this->filter(fn ($model) => ! $model instanceof $class)->isNotEmpty()) {
            throw new LogicException('Unable to create query for collection with mixed types.');
        }

        return $model->newModelQuery()->whereKey($this->modelKeys());
    }
}
