<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Pagination;

use ArrayAccess;
use ArrayIterator;
use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\String\Text;
use Closure;
use Exception;
use stdClass;

abstract class AbstractCursorPaginator
{
    use ForwardsCalls;

    /**
     * Indicates whether there are more items in the data source.
     *
     * @return bool
     */
    protected $hasMore;

    /**
     * All of the items being paginated.
     *
     * @var Collection
     */
    protected $items;

    /**
     * The number of items to be shown per page.
     *
     * @var int
     */
    protected $perPage;

    /**
     * The base path to assign to all URLs.
     */
    protected string $path = '/';

    /**
     * The query parameters to add to all URLs.
     */
    protected array $query = [];

    /**
     * The URL fragment to add to all URLs.
     */
    protected ?string $fragment = null;

    /**
     * The cursor string variable used to store the page.
     */
    protected string $cursorName = 'cursor';

    /**
     * The current cursor.
     */
    protected ?Cursor $cursor = null;

    /**
     * The paginator parameters for the cursor.
     */
    protected array $parameters = [];

    /**
     * The paginator options.
     */
    protected array $options = [];

    /**
     * The current cursor resolver callback.
     *
     * @var Closure
     */
    protected static $currentCursorResolver;

    /**
     * Get the URL for a given cursor.
     */
    public function url(?Cursor $cursor): string
    {
        // If we have any extra query string key / value pairs that need to be added
        // onto the URL, we will put them in query string form and then attach it
        // to the URL. This allows for extra information like sortings storage.
        $parameters = null === $cursor ? [] : [$this->cursorName => $cursor->encode()];

        if (count($this->query) > 0) {
            $parameters = array_merge($this->query, $parameters);
        }

        return $this->path()
            . (Text::contains($this->path(), '?') ? '&' : '?')
            . Arr::query($parameters)
            . $this->buildFragment();
    }

    /**
     * Get the URL for the previous page.
     */
    public function previousPageUrl(): ?string
    {
        if (null === ($previousCursor = $this->previousCursor())) {
            return null;
        }

        return $this->url($previousCursor);
    }

    /**
     * The URL for the next page, or null.
     */
    public function nextPageUrl(): ?string
    {
        if (null === ($nextCursor = $this->nextCursor())) {
            return null;
        }

        return $this->url($nextCursor);
    }

    /**
     * Get the "cursor" that points to the previous set of items.
     */
    public function previousCursor(): ?Cursor
    {
        if (null === $this->cursor
            || ($this->cursor->pointsToPreviousItems() && ! $this->hasMore)) {
            return null;
        }

        return $this->getCursorForItem($this->items->first(), false);
    }

    /**
     * Get the "cursor" that points to the next set of items.
     */
    public function nextCursor(): ?Cursor
    {
        if ((null === $this->cursor && ! $this->hasMore)
            || (null !== $this->cursor && $this->cursor->pointsToNextItems() && ! $this->hasMore)) {
            return null;
        }

        return $this->getCursorForItem($this->items->last(), true);
    }

    /**
     * Get a cursor instance for the given item.
     */
    public function getCursorForItem(ArrayAccess|stdClass $item, bool $isNext = true): Cursor
    {
        return new Cursor($this->getParametersForItem($item), $isNext);
    }

    /**
     * Get the cursor parameters for a given object.
     *
     * @throws Exception
     */
    public function getParametersForItem(ArrayAccess|stdClass $item): array
    {
        return Helpers::collect($this->parameters)
            ->flip()
            ->map(static function ($_, $parameterName) use ($item) {
                if ($item instanceof ArrayAccess || is_array($item)) {
                    return $item[$parameterName] ?? $item[Text::afterLast($parameterName, '.')];
                }
                if (is_object($item)) {
                    return $item->{$parameterName} ?? $item->{Text::afterLast($parameterName, '.')};
                }

                throw new Exception('Only arrays and objects are supported when cursor paginating items.');
            })->toArray();
    }

    /**
     * Get / set the URL fragment to be appended to URLs.
     *
     * @return self|string|null
     */
    public function fragment(?string $fragment = null)
    {
        if (null === $fragment) {
            return $this->fragment;
        }

        $this->fragment = $fragment;

        return $this;
    }

    /**
     * Add a set of query string values to the paginator.
     */
    public function appends(null|array|string $key, ?string $value = null): self
    {
        if (null === $key) {
            return $this;
        }

        if (is_array($key)) {
            return $this->appendArray($key);
        }

        return $this->addQuery($key, $value);
    }

    /**
     * Add an array of query string values.
     */
    protected function appendArray(array $keys): self
    {
        foreach ($keys as $key => $value) {
            $this->addQuery($key, $value);
        }

        return $this;
    }

    /**
     * Add all current query string values to the paginator.
     */
    public function withQueryString(): self
    {
        if (null !== ($query = Paginator::resolveQueryString())) {
            return $this->appends($query);
        }

        return $this;
    }

    /**
     * Add a query string value to the paginator.
     */
    protected function addQuery(string $key, string $value): self
    {
        if ($key !== $this->cursorName) {
            $this->query[$key] = $value;
        }

        return $this;
    }

    /**
     * Build the full fragment portion of a URL.
     */
    protected function buildFragment(): string
    {
        return $this->fragment ? '#' . $this->fragment : '';
    }

    /**
     * Load a set of relationships onto the mixed relationship collection.
     */
    public function loadMorph(string $relation, array $relations): self
    {
        $this->getCollection()->loadMorph($relation, $relations);

        return $this;
    }

    /**
     * Load a set of relationship counts onto the mixed relationship collection.
     */
    public function loadMorphCount(string $relation, array $relations): self
    {
        $this->getCollection()->loadMorphCount($relation, $relations);

        return $this;
    }

    /**
     * Get the slice of items being paginated.
     */
    public function items(): array
    {
        return $this->items->all();
    }

    /**
     * Transform each item in the slice of items using a callback.
     */
    public function through(callable $callback): self
    {
        $this->items->transform($callback);

        return $this;
    }

    /**
     * Get the number of items shown per page.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Get the current cursor being paginated.
     */
    public function cursor(): ?Cursor
    {
        return $this->cursor;
    }

    /**
     * Get the query string variable used to store the cursor.
     */
    public function getCursorName(): string
    {
        return $this->cursorName;
    }

    /**
     * Set the query string variable used to store the cursor.
     */
    public function setCursorName(string $name): self
    {
        $this->cursorName = $name;

        return $this;
    }

    /**
     * Set the base path to assign to all URLs.
     */
    public function withPath(string $path): self
    {
        return $this->setPath($path);
    }

    /**
     * Set the base path to assign to all URLs.
     */
    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Get the base path for paginator generated URLs.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Resolve the current cursor or return the default value.
     *
     * @param mixed|null $default
     *
     * @return Cursor|null
     */
    public static function resolveCurrentCursor(string $cursorName = 'cursor', $default = null)
    {
        if (isset(static::$currentCursorResolver)) {
            return call_user_func(static::$currentCursorResolver, $cursorName);
        }

        return $default;
    }

    /**
     * Set the current cursor resolver callback.
     */
    public static function currentCursorResolver(Closure $resolver): void
    {
        static::$currentCursorResolver = $resolver;
    }

    /**
     * Get an instance of the view factory from the resolver.
     *
     * @return \BlitzPHP\View\RendererInterface
     */
    public static function viewFactory()
    {
        return Paginator::viewFactory();
    }

    /**
     * Get an iterator for the items.
     */
    public function getIterator(): ArrayIterator
    {
        return $this->items->getIterator();
    }

    /**
     * Determine if the list of items is empty.
     */
    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /**
     * Determine if the list of items is not empty.
     */
    public function isNotEmpty(): bool
    {
        return $this->items->isNotEmpty();
    }

    /**
     * Get the number of items for the current page.
     */
    public function count(): int
    {
        return $this->items->count();
    }

    /**
     * Get the paginator's underlying collection.
     */
    public function getCollection(): Collection
    {
        return $this->items;
    }

    /**
     * Set the paginator's underlying collection.
     */
    public function setCollection(Collection $collection): self
    {
        $this->items = $collection;

        return $this;
    }

    /**
     * Get the paginator options.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Determine if the given item exists.
     */
    public function offsetExists(mixed $key): bool
    {
        return $this->items->has($key);
    }

    /**
     * Get the item at the given offset.
     */
    public function offsetGet(mixed $key): mixed
    {
        return $this->items->get($key);
    }

    /**
     * Set the item at the given offset.
     */
    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->items->put($key, $value);
    }

    /**
     * Unset the item at the given key.
     */
    public function offsetUnset(mixed $key): void
    {
        $this->items->forget($key);
    }

    /**
     * Render the contents of the paginator to HTML.
     */
    public function toHtml(): string
    {
        return (string) $this->render();
    }

    /**
     * Make dynamic calls into the collection.
     */
    public function __call(string $method, array $parameters = []): mixed
    {
        return $this->forwardCallTo($this->getCollection(), $method, $parameters);
    }

    /**
     * Render the contents of the paginator when casting to a string.
     */
    public function __toString(): string
    {
        return (string) $this->render();
    }
}
