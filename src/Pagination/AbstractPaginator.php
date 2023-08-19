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

use ArrayIterator;
use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Utilities\Helpers;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\String\Text;
use Closure;
use ReturnTypeWillChange;

abstract class AbstractPaginator
{
    use ForwardsCalls;

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
     * The current page being "viewed".
     *
     * @var int
     */
    protected $currentPage;

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
     * The query string variable used to store the page.
     */
    protected string $pageName = 'page';

    /**
     * The number of links to display on each side of current page link.
     */
    public int $onEachSide = 3;

    /**
     * The paginator options.
     */
    protected array $options = [];

    /**
     * The current path resolver callback.
     *
     * @var Closure
     */
    protected static $currentPathResolver;

    /**
     * The current page resolver callback.
     *
     * @var Closure
     */
    protected static $currentPageResolver;

    /**
     * The query string resolver callback.
     *
     * @var Closure
     */
    protected static $queryStringResolver;

    /**
     * The view factory resolver callback.
     *
     * @var Closure
     */
    protected static $viewFactoryResolver;

    /**
     * The default pagination view.
     */
    public static string $defaultView = 'BlitzPHP\Wolke\Pagination\Views\bootstrap-4';

    /**
     * The default "simple" pagination view.
     */
    public static string $defaultSimpleView = 'BlitzPHP\Wolke\Pagination\Views\simple-bootstrap-4';

    /**
     * Determine if the given value is a valid page number.
     */
    protected function isValidPageNumber(int $page): bool
    {
        return $page >= 1 && filter_var($page, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Get the URL for the previous page.
     */
    public function previousPageUrl(): ?string
    {
        if ($this->currentPage() > 1) {
            return $this->url($this->currentPage() - 1);
        }

        return null;
    }

    /**
     * Create a range of pagination URLs.
     */
    public function getUrlRange(int $start, int $end): array
    {
        return Helpers::collect(range($start, $end))->mapWithKeys(fn ($page) => [$page => $this->url($page)])->all();
    }

    /**
     * Get the URL for a given page number.
     */
    public function url(int $page): string
    {
        if ($page <= 0) {
            $page = 1;
        }

        // If we have any extra query string key / value pairs that need to be added
        // onto the URL, we will put them in query string form and then attach it
        // to the URL. This allows for extra information like sortings storage.
        $parameters = [$this->pageName => $page];

        if (count($this->query) > 0) {
            $parameters = array_merge($this->query, $parameters);
        }

        return $this->path()
                        . (Text::contains($this->path(), '?') ? '&' : '?')
                        . Arr::query($parameters)
                        . $this->buildFragment();
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
        if (isset(static::$queryStringResolver)) {
            return $this->appends(call_user_func(static::$queryStringResolver));
        }

        return $this;
    }

    /**
     * Add a query string value to the paginator.
     */
    protected function addQuery(string $key, string $value): self
    {
        if ($key !== $this->pageName) {
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
     * Get the number of the first item in the slice.
     */
    public function firstItem(): ?int
    {
        return count($this->items) > 0 ? ($this->currentPage - 1) * $this->perPage + 1 : null;
    }

    /**
     * Get the number of the last item in the slice.
     */
    public function lastItem(): ?int
    {
        return count($this->items) > 0 ? $this->firstItem() + $this->count() - 1 : null;
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
     * Determine if there are enough items to split into multiple pages.
     */
    public function hasPages(): bool
    {
        return $this->currentPage() !== 1 || $this->hasMorePages();
    }

    /**
     * Determine if the paginator is on the first page.
     */
    public function onFirstPage(): bool
    {
        return $this->currentPage() <= 1;
    }

    /**
     * Determine if the paginator is on the last page.
     */
    public function onLastPage(): bool
    {
        return ! $this->hasMorePages();
    }

    /**
     * Get the current page.
     */
    public function currentPage(): ?int
    {
        return $this->currentPage;
    }

    /**
     * Get the query string variable used to store the page.
     */
    public function getPageName(): string
    {
        return $this->pageName;
    }

    /**
     * Set the query string variable used to store the page.
     */
    public function setPageName(string $name): self
    {
        $this->pageName = $name;

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
     * Set the number of links to display on each side of current page link.
     */
    public function onEachSide(int $count): self
    {
        $this->onEachSide = $count;

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
     * Resolve the current request path or return the default value.
     */
    public static function resolveCurrentPath(string $default = '/'): string
    {
        if (isset(static::$currentPathResolver)) {
            return call_user_func(static::$currentPathResolver);
        }

        return $default;
    }

    /**
     * Set the current request path resolver callback.
     */
    public static function currentPathResolver(Closure $resolver): void
    {
        static::$currentPathResolver = $resolver;
    }

    /**
     * Resolve the current page or return the default value.
     *
     * @param string $pageName
     * @param int    $default
     *
     * @return int
     */
    public static function resolveCurrentPage($pageName = 'page', $default = 1)
    {
        if (isset(static::$currentPageResolver)) {
            return (int) call_user_func(static::$currentPageResolver, $pageName);
        }

        return $default;
    }

    /**
     * Set the current page resolver callback.
     *
     * @return void
     */
    public static function currentPageResolver(Closure $resolver)
    {
        static::$currentPageResolver = $resolver;
    }

    /**
     * Resolve the query string or return the default value.
     *
     * @param array|string|null $default
     *
     * @return string
     */
    public static function resolveQueryString($default = null)
    {
        if (isset(static::$queryStringResolver)) {
            return (static::$queryStringResolver)();
        }

        return $default;
    }

    /**
     * Set with query string resolver callback.
     */
    public static function queryStringResolver(Closure $resolver): void
    {
        static::$queryStringResolver = $resolver;
    }

    /**
     * Get an instance of the view factory from the resolver.
     *
     * @return RendererInterface
     */
    public static function viewFactory()
    {
        return call_user_func(static::$viewFactoryResolver);
    }

    /**
     * Set the view factory resolver callback.
     */
    public static function viewFactoryResolver(Closure $resolver): void
    {
        static::$viewFactoryResolver = $resolver;
    }

    /**
     * Set the default pagination view.
     */
    public static function defaultView(string $view): void
    {
        static::$defaultView = $view;
    }

    /**
     * Set the default "simple" pagination view.
     */
    public static function defaultSimpleView(string $view): void
    {
        static::$defaultSimpleView = $view;
    }

    /**
     * Indicate that Bootstrap 4 styling should be used for generated links.
     */
    public static function useBootstrap(): void
    {
        static::defaultView('BlitzPHP\Wolke\Pagination\Views\bootstrap-4');
        static::defaultSimpleView('BlitzPHP\Wolke\Pagination\Views\simple-bootstrap-4');
    }

    /**
     * Indicate that Bootstrap 3 styling should be used for generated links.
     */
    public static function useBootstrapThree(): void
    {
        static::defaultView('BlitzPHP\Wolke\Pagination\Views\default');
        static::defaultSimpleView('BlitzPHP\Wolke\Pagination\Views\simple-default');
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
     *
     * @return int
     */
    #[ReturnTypeWillChange]
    public function count()
    {
        return $this->items->count();
    }

    /**
     * Get the paginator's underlying collection.
     *
     * @return \Tightenco\Collect\Support\Collection
     */
    public function getCollection()
    {
        return $this->items;
    }

    /**
     * Set the paginator's underlying collection.
     *
     * @param \Tightenco\Collect\Support\Collection $collection
     *
     * @return $this
     */
    public function setCollection(Collection $collection)
    {
        $this->items = $collection;

        return $this;
    }

    /**
     * Get the paginator options.
     *
     * @return array
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Determine if the given item exists.
     *
     * @param mixed $key
     *
     * @return bool
     */
    #[ReturnTypeWillChange]
    public function offsetExists($key)
    {
        return $this->items->has($key);
    }

    /**
     * Get the item at the given offset.
     *
     * @param mixed $key
     *
     * @return mixed
     */
    #[ReturnTypeWillChange]
    public function offsetGet($key)
    {
        return $this->items->get($key);
    }

    /**
     * Set the item at the given offset.
     *
     * @param mixed $key
     * @param mixed $value
     *
     * @return void
     */
    #[ReturnTypeWillChange]
    public function offsetSet($key, $value)
    {
        $this->items->put($key, $value);
    }

    /**
     * Unset the item at the given key.
     *
     * @param mixed $key
     *
     * @return void
     */
    #[ReturnTypeWillChange]
    public function offsetUnset($key)
    {
        $this->items->forget($key);
    }

    /**
     * Render the contents of the paginator to HTML.
     *
     * @return string
     */
    public function toHtml()
    {
        return (string) $this->render();
    }

    /**
     * Make dynamic calls into the collection.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return mixed
     */
    public function __call($method, $parameters)
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
