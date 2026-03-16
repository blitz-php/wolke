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
use BlitzPHP\Contracts\View\RendererInterface;
use BlitzPHP\Traits\Support\ForwardsCalls;
use BlitzPHP\Traits\Support\Tappable;
use BlitzPHP\Utilities\Iterable\Arr;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Utilities\String\Text;
use BlitzPHP\Wolke\Model;
use BlitzPHP\Wolke\Relations\Pivot;
use Closure;
use Exception;
use stdClass;
use Stringable;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @mixin Collection<TKey, TValue>
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Pagination\AbstractCursorPaginator</a>
 */
abstract class AbstractCursorPaginator implements Stringable
{
    use ForwardsCalls;
    use Tappable;

    /**
     * Indique s'il y a plus d'éléments dans la source de données.
     *
     * @return bool
     */
    protected $hasMore;

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
     * La variable de chaîne de curseur utilisée pour stocker la page.
     */
    protected string $cursorName = 'cursor';

    /**
     * Le curseur actuel.
     */
    protected ?Cursor $cursor = null;

    /**
     * Les paramètres du paginateur pour le curseur.
     */
    protected array $parameters = [];

    /**
     * Les options du paginateur.
     */
    protected array $options = [];

    /**
     * Le rappel de résolution du curseur actuel.
     *
     * @var Closure
     */
    protected static $currentCursorResolver;

    /**
     * Obtient l'URL pour un curseur donné.
     */
    public function url(?Cursor $cursor): string
    {
        // Si nous avons des paires clé/valeur supplémentaires de chaîne de requête qui doivent être ajoutées
        // à l'URL, nous les mettrons sous forme de chaîne de requête puis les attacherons
        // à l'URL. Cela permet d'ajouter des informations supplémentaires comme le stockage des tris.
        $parameters = null === $cursor ? [] : [$this->cursorName => $cursor->encode()];

        if (count($this->query) > 0) {
            $parameters = array_merge($this->query, $parameters);
        }

        return $this->path()
            . (str_contains($this->path(), '?') ? '&' : '?')
            . Arr::query($parameters)
            . $this->buildFragment();
    }

    /**
     * Obtient l'URL de la page précédente.
     */
    public function previousPageUrl(): ?string
    {
        if (null === ($previousCursor = $this->previousCursor())) {
            return null;
        }

        return $this->url($previousCursor);
    }

    /**
     * L'URL de la page suivante, ou null.
     */
    public function nextPageUrl(): ?string
    {
        if (null === ($nextCursor = $this->nextCursor())) {
            return null;
        }

        return $this->url($nextCursor);
    }

    /**
     * Obtient le "curseur" qui pointe vers l'ensemble d'éléments précédent.
     */
    public function previousCursor(): ?Cursor
    {
        if (null === $this->cursor
            || ($this->cursor->pointsToPreviousItems() && ! $this->hasMore)) {
            return null;
        }

        if ($this->items->isEmpty()) {
            return null;
        }

        return $this->getCursorForItem($this->items->first(), false);
    }

    /**
     * Obtient le "curseur" qui pointe vers l'ensemble d'éléments suivant.
     */
    public function nextCursor(): ?Cursor
    {
        if ((null === $this->cursor && ! $this->hasMore)
            || (null !== $this->cursor && $this->cursor->pointsToNextItems() && ! $this->hasMore)) {
            return null;
        }

        if ($this->items->isEmpty()) {
            return null;
        }

        return $this->getCursorForItem($this->items->last(), true);
    }

    /**
     * Obtient une instance de curseur pour l'élément donné.
     */
    public function getCursorForItem(ArrayAccess|stdClass $item, bool $isNext = true): Cursor
    {
        return new Cursor($this->getParametersForItem($item), $isNext);
    }

    /**
     * Obtient les paramètres du curseur pour un objet donné.
     *
     * @throws Exception
     */
    public function getParametersForItem(ArrayAccess|stdClass $item): array
    {
        return (new Collection($this->parameters))
            ->filter()
            ->flip()
            ->map(function ($_, $parameterName) use ($item) {
                if ($item instanceof Model
                    && null !== ($parameter = $this->getPivotParameterForItem($item, $parameterName))) {
                    return $parameter;
                }
                if ($item instanceof ArrayAccess || is_array($item)) {
                    return $this->ensureParameterIsPrimitive(
                        $item[$parameterName] ?? $item[Text::afterLast($parameterName, '.')]
                    );
                }
                if (is_object($item)) {
                    return $this->ensureParameterIsPrimitive(
                        $item->{$parameterName} ?? $item->{Text::afterLast($parameterName, '.')}
                    );
                }

                throw new Exception('Seuls les tableaux et les objets sont supportés lors de la pagination par curseur des éléments.');
            })->toArray();
    }

    /**
     * Obtient la valeur du paramètre de curseur à partir d'un modèle pivot si applicable.
     */
    protected function getPivotParameterForItem(Model $item, string $parameterName): ?string
    {
        $table = Text::beforeLast($parameterName, '.');

        foreach ($item->getRelations() as $relation) {
            if ($relation instanceof Pivot && $relation->getTable() === $table) {
                return $this->ensureParameterIsPrimitive(
                    $relation->getAttribute(Text::afterLast($parameterName, '.'))
                );
            }
        }

        return null;
    }

    /**
     * S'assure que le paramètre est d'un type primitif.
     *
     * Cela peut résoudre les problèmes qui surviennent lorsque le développeur utilise un objet valeur pour un attribut.
     */
    protected function ensureParameterIsPrimitive(mixed $parameter): mixed
    {
        return is_object($parameter) && method_exists($parameter, '__toString')
                        ? (string) $parameter
                        : $parameter;
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
        if (null !== ($query = Paginator::resolveQueryString())) {
            return $this->appends($query);
        }

        return $this;
    }

    /**
     * Ajoute une valeur de chaîne de requête au paginateur.
     */
    protected function addQuery(string $key, string $value): static
    {
        if ($key !== $this->cursorName) {
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
     * Transforme chaque élément de la tranche d'éléments en utilisant un rappel.
     *
     * @template TThroughValue
     *
     * @param  callable(TValue, TKey): TThroughValue  $callback
     * 
     * @phpstan-this-out static<TKey, TThroughValue>
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
     * Obtient le curseur actuel en cours de pagination.
     */
    public function cursor(): ?Cursor
    {
        return $this->cursor;
    }

    /**
     * Obtient la variable de chaîne de requête utilisée pour stocker le curseur.
     */
    public function getCursorName(): string
    {
        return $this->cursorName;
    }

    /**
     * Définit la variable de chaîne de requête utilisée pour stocker le curseur.
     */
    public function setCursorName(string $name): static
    {
        $this->cursorName = $name;

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
     * Obtient le chemin de base pour les URL générées par le paginateur.
     */
    public function path(): ?string
    {
        return $this->path;
    }

    /**
     * Résout le curseur actuel ou retourne la valeur par défaut.
     */
    public static function resolveCurrentCursor(string $cursorName = 'cursor', ?Cursor $default = null): ?Cursor
    {
        if (isset(static::$currentCursorResolver)) {
            return call_user_func(static::$currentCursorResolver, $cursorName);
        }

        return $default;
    }

    /**
     * Définit le rappel de résolution du curseur actuel.
     */
    public static function currentCursorResolver(Closure $resolver): void
    {
        static::$currentCursorResolver = $resolver;
    }

    /**
     * Obtient une instance de la fabrique de vues à partir du résolveur.
     */
    public static function viewFactory(): RendererInterface
    {
        return Paginator::viewFactory();
    }

    /**
     * Obtient un itérateur pour les éléments.
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
     * @template TSetKey of array-key
     * @template TSetValue
     *
     * @param Collection<TSetKey, TSetValue>  $collection
     * 
     * @phpstan-this-out static<TSetKey, TSetValue>
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
    public function __call(string $method, array $parameters = []): mixed
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
