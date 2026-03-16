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
use BlitzPHP\Contracts\View\RendererInterface;
use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Traits\Support\Tappable;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection;
use Closure;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @mixin Collection<TKey, TValue>
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Pagination\AbstractPaginator</a>
 */
abstract class AbstractPaginator
{
    use ForwardsCalls;
    use Tappable;

    /**
     * Tous les éléments paginés.
     *
     * @var Collection<TKey, TValue>
     */
    protected $items;

    /**
     * Le nombre d'éléments à afficher par page.
     *
     * @var int
     */
    protected $perPage;

    /**
     * La page actuellement "visualisée".
     *
     * @var int
     */
    protected $currentPage;

    /**
     * Le chemin de base à assigner à toutes les URL.
     */
    protected string $path = '/';

    /**
     * Les paramètres de requête à ajouter à toutes les URL.
     */
    protected array $query = [];

    /**
     * Le fragment d'URL à ajouter à toutes les URL.
     */
    protected ?string $fragment = null;

    /**
     * La variable de chaîne de requête utilisée pour stocker la page.
     */
    protected string $pageName = 'page';

    /**
     * Le nombre de liens à afficher de chaque côté du lien de la page actuelle.
     */
    public int $onEachSide = 3;

    /**
     * Les options du paginateur.
     */
    protected array $options = [];

    /**
     * Le rappel de résolution du chemin actuel.
     *
     * @var Closure
     */
    protected static $currentPathResolver;

    /**
     * Le rappel de résolution de la page actuelle.
     *
     * @var Closure
     */
    protected static $currentPageResolver;

    /**
     * Le rappel de résolution de la chaîne de requête.
     *
     * @var Closure
     */
    protected static $queryStringResolver;

    /**
     * Le rappel de résolution de la fabrique de vues.
     *
     * @var Closure
     */
    protected static $viewFactoryResolver;

    /**
     * La vue de pagination par défaut.
     */
    public static string $defaultView = 'BlitzPHP\Wolke\Pagination\Views\bootstrap-5';

    /**
     * La vue de pagination "simple" par défaut.
     */
    public static string $defaultSimpleView = 'BlitzPHP\Wolke\Pagination\Views\simple-bootstrap-5';

    /**
     * Détermine si la valeur donnée est un numéro de page valide.
     */
    protected function isValidPageNumber(mixed $page): bool
    {
        return $page >= 1 && filter_var($page, FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Obtient l'URL de la page précédente.
     */
    public function previousPageUrl(): ?string
    {
        if ($this->currentPage() > 1) {
            return $this->url($this->currentPage() - 1);
        }

        return null;
    }

    /**
     * Crée une plage d'URL de pagination.
     */
    public function getUrlRange(int $start, int $end): array
    {
        return Collection::range($start, $end)
            ->mapWithKeys(fn ($page) => [$page => $this->url($page)])
            ->all();
    }

    /**
     * Obtient l'URL pour un numéro de page donné.
     */
    public function url(int $page): string
    {
        if ($page <= 0) {
            $page = 1;
        }

        // Si nous avons des paires clé/valeur supplémentaires de chaîne de requête qui doivent être ajoutées
        // à l'URL, nous les mettrons sous forme de chaîne de requête puis les attacherons
        // à l'URL. Cela permet d'ajouter des informations supplémentaires comme le stockage des tris.
        $parameters = [$this->pageName => $page];

        if (count($this->query) > 0) {
            $parameters = array_merge($this->query, $parameters);
        }

        return $this->path()
                        . (str_contains($this->path(), '?') ? '&' : '?')
                        . Arr::query($parameters)
                        . $this->buildFragment();
    }

    /**
     * Obtient/définit le fragment d'URL à ajouter aux URL.
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
     * Ajoute un ensemble de valeurs de chaîne de requête au paginateur.
     */
    public function appends(array|string|null $key, ?string $value = null): static
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
     * Ajoute un tableau de valeurs de chaîne de requête.
     */
    protected function appendArray(array $keys): static
    {
        foreach ($keys as $key => $value) {
            $this->addQuery($key, $value);
        }

        return $this;
    }

    /**
     * Ajoute toutes les valeurs de chaîne de requête actuelles au paginateur.
     */
    public function withQueryString(): static
    {
        if (isset(static::$queryStringResolver)) {
            return $this->appends(call_user_func(static::$queryStringResolver));
        }

        return $this;
    }

    /**
     * Ajoute une valeur de chaîne de requête au paginateur.
     */
    protected function addQuery(string $key, string $value): static
    {
        if ($key !== $this->pageName) {
            $this->query[$key] = $value;
        }

        return $this;
    }

    /**
     * Construit la partie complète du fragment d'une URL.
     */
    protected function buildFragment(): string
    {
        return $this->fragment ? '#' . $this->fragment : '';
    }

    /**
     * Charge un ensemble de relations sur la collection de relations mixtes.
     */
    public function loadMorph(string $relation, array $relations): static
    {
        $this->getCollection()->loadMorph($relation, $relations);

        return $this;
    }

    /**
     * Charge un ensemble de compteurs de relations sur la collection de relations mixtes.
     */
    public function loadMorphCount(string $relation, array $relations): static
    {
        $this->getCollection()->loadMorphCount($relation, $relations);

        return $this;
    }

    /**
     * Obtient la tranche d'éléments paginés.
     *
     * @return array<TKey, TValue>
     */
    public function items(): array
    {
        return $this->items->all();
    }

    /**
     * Obtient le numéro du premier élément dans la tranche.
     */
    public function firstItem(): ?int
    {
        return count($this->items) > 0 ? ($this->currentPage - 1) * $this->perPage + 1 : null;
    }

    /**
     * Obtient le numéro du dernier élément dans la tranche.
     */
    public function lastItem(): ?int
    {
        return count($this->items) > 0 ? $this->firstItem() + $this->count() - 1 : null;
    }

    /**
     * Transforme chaque élément de la tranche d'éléments en utilisant un rappel.
     *
     * @template TMapValue
     *
     * @param  callable(TValue, TKey): TMapValue  $callback
     *
     * @phpstan-this-out static<TKey, TMapValue>
     */
    public function through(callable $callback): static
    {
        $this->items->transform($callback);

        return $this;
    }

    /**
     * Obtient le nombre d'éléments affichés par page.
     */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /**
     * Détermine s'il y a assez d'éléments pour diviser en plusieurs pages.
     */
    public function hasPages(): bool
    {
        return $this->currentPage() !== 1 || $this->hasMorePages();
    }

    /**
     * Détermine si le paginateur est sur la première page.
     */
    public function onFirstPage(): bool
    {
        return $this->currentPage() <= 1;
    }

    /**
     * Détermine si le paginateur est sur la dernière page.
     */
    public function onLastPage(): bool
    {
        return ! $this->hasMorePages();
    }

    /**
     * Obtient la page actuelle.
     */
    public function currentPage(): ?int
    {
        return $this->currentPage;
    }

    /**
     * Obtient la variable de chaîne de requête utilisée pour stocker la page.
     */
    public function getPageName(): string
    {
        return $this->pageName;
    }

    /**
     * Définit la variable de chaîne de requête utilisée pour stocker la page.
     */
    public function setPageName(string $name): static
    {
        $this->pageName = $name;

        return $this;
    }

    /**
     * Définit le chemin de base à assigner à toutes les URL.
     */
    public function withPath(string $path): static
    {
        return $this->setPath($path);
    }

    /**
     * Définit le chemin de base à assigner à toutes les URL.
     */
    public function setPath(string $path): static
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Définit le nombre de liens à afficher de chaque côté du lien de la page actuelle.
     */
    public function onEachSide(int $count): static
    {
        $this->onEachSide = $count;

        return $this;
    }

    /**
     * Obtient le chemin de base pour les URL générées par le paginateur.
     */
    public function path(): ?string
    {
        return preg_replace('/(&)?' . $this->pageName . '=(\d+)/', '', $this->path);
    }

    /**
     * Résout le chemin de la requête actuelle ou retourne la valeur par défaut.
     */
    public static function resolveCurrentPath(string $default = '/'): string
    {
        if (isset(static::$currentPathResolver)) {
            return call_user_func(static::$currentPathResolver);
        }

        return $default;
    }

    /**
     * Définit le rappel de résolution du chemin de requête actuel.
     */
    public static function currentPathResolver(Closure $resolver): void
    {
        static::$currentPathResolver = $resolver;
    }

    /**
     * Résout la page actuelle ou retourne la valeur par défaut.
     */
    public static function resolveCurrentPage(string $pageName = 'page', int $default = 1): int
    {
        if (isset(static::$currentPageResolver)) {
            return (int) call_user_func(static::$currentPageResolver, $pageName);
        }

        return $default;
    }

    /**
     * Définit le rappel de résolution de la page actuelle.
     */
    public static function currentPageResolver(Closure $resolver): void
    {
        static::$currentPageResolver = $resolver;
    }

    /**
     * Résout la chaîne de requête ou retourne la valeur par défaut.
     * 
     * @return string
     */
    public static function resolveQueryString(array|string|null $default = null)
    {
        if (isset(static::$queryStringResolver)) {
            return (static::$queryStringResolver)();
        }

        return $default;
    }

    /**
     * Définit le rappel de résolution de la chaîne de requête.
     */
    public static function queryStringResolver(Closure $resolver): void
    {
        static::$queryStringResolver = $resolver;
    }

    /**
     * Obtient une instance de la fabrique de vues à partir du résolveur.
     */
    public static function viewFactory(): RendererInterface
    {
        return call_user_func(static::$viewFactoryResolver);
    }

    /**
     * Définit le rappel de résolution de la fabrique de vues.
     */
    public static function viewFactoryResolver(Closure $resolver): void
    {
        static::$viewFactoryResolver = $resolver;
    }

    /**
     * Définit la vue de pagination par défaut.
     */
    public static function defaultView(string $view): void
    {
        static::$defaultView = $view;
    }

    /**
     * Définit la vue de pagination "simple" par défaut.
     */
    public static function defaultSimpleView(string $view): void
    {
        static::$defaultSimpleView = $view;
    }

    /**
     * Indique que le style Tailwind doit être utilisé pour les liens générés.
     */
    public static function useTailwind(): void
    {
        static::defaultView('BlitzPHP\Wolke\Pagination\Views\tailwind');
        static::defaultSimpleView('BlitzPHP\Wolke\Pagination\Views\simple-tailwind');
    }

    /**
     * Indique que le style Bootstrap 4 doit être utilisé pour les liens générés.
     */
    public static function useBootstrap(int $version = 5): void
    {
        match($version) {
            3       => static::useBootstrapThree(),
            4       => static::useBootstrapFour(),
            default => static::useBootstrapFive(),
        };
    }

    /**
     * Indique que le style Bootstrap 3 doit être utilisé pour les liens générés.
     */
    public static function useBootstrapThree(): void
    {
        static::defaultView('BlitzPHP\Wolke\Pagination\Views\bootstrap-3');
        static::defaultSimpleView('BlitzPHP\Wolke\Pagination\Views\simple-bootstrap-3');
    }

    /**
     * Indique que le style Bootstrap 4 doit être utilisé pour les liens générés.
     */
    public static function useBootstrapFour(): void
    {
        static::defaultView('BlitzPHP\Wolke\Pagination\Views\bootstrap-4');
        static::defaultSimpleView('BlitzPHP\Wolke\Pagination\Views\simple-bootstrap-4');
    }

    /**
     * Indique que le style Bootstrap 5 doit être utilisé pour les liens générés.
     */
    public static function useBootstrapFive()
    {
        static::defaultView('BlitzPHP\Wolke\Pagination\Views\bootstrap-5');
        static::defaultSimpleView('BlitzPHP\Wolke\Pagination\Views\simple-bootstrap-5');
    }

    /**
     * Obtient un itérateur pour les éléments.
     *
     * @return ArrayIterator<TKey, TValue>
     */
    public function getIterator(): ArrayIterator
    {
        return $this->items->getIterator();
    }

    /**
     * Détermine si la liste des éléments est vide.
     */
    public function isEmpty(): bool
    {
        return $this->items->isEmpty();
    }

    /**
     * Détermine si la liste des éléments n'est pas vide.
     */
    public function isNotEmpty(): bool
    {
        return $this->items->isNotEmpty();
    }

    /**
     * Obtient le nombre d'éléments pour la page actuelle.
     */
    public function count(): int
    {
        return $this->items->count();
    }

    /**
     * Obtient la collection sous-jacente du paginateur.
     *
     * @return Collection<TKey, TValue>
     */
    public function getCollection(): Collection
    {
        return $this->items;
    }

    /**
     * Définit la collection sous-jacente du paginateur.
     *
     * @param Collection<TKey, TValue>  $collection
     */
    public function setCollection(Collection $collection): static
    {
        $this->items = $collection;

        return $this;
    }

    /**
     * Obtient les options du paginateur.
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * Détermine si l'élément donné existe.
     */
    public function offsetExists(mixed $key): bool
    {
        return $this->items->has($key);
    }

    /**
     * Obtient l'élément à l'offset donné.
     */
    public function offsetGet(mixed $key): mixed
    {
        return $this->items->get($key);
    }

    /**
     * Définit l'élément à l'offset donné.
     */
    public function offsetSet(mixed $key, mixed $value): void
    {
        $this->items->put($key, $value);
    }

    /**
     * Supprime l'élément à la clé donnée.
     */
    public function offsetUnset(mixed $key): void
    {
        $this->items->forget($key);
    }

    /**
     * Affiche le contenu du paginateur en HTML.
     */
    public function toHtml(): string
    {
        return (string) $this->render();
    }

    /**
     * Effectue des appels dynamiques vers la collection.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->getCollection(), $method, $parameters);
    }

    /**
     * Affiche le contenu du paginateur lors de la conversion en chaîne.
     */
    public function __toString(): string
    {
        return (string) $this->render();
    }
}
