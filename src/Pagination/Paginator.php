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
use BlitzPHP\Contracts\Support\Arrayable;
use BlitzPHP\Contracts\Support\Jsonable;
use BlitzPHP\Utilities\Iterable\Collection;
use BlitzPHP\Wolke\Contracts\Paginator as PaginatorContract;
use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * @template TKey of array-key
 *
 * @template-covariant TValue
 *
 * @extends AbstractPaginator<TKey, TValue>
 *
 * @implements Arrayable<TKey, TValue>
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 * @implements PaginatorContract<TKey, TValue>
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Pagination\Paginator</a>
 */
class Paginator extends AbstractPaginator implements Arrayable, ArrayAccess, Countable, IteratorAggregate, Jsonable, JsonSerializable, PaginatorContract
{
    /**
     * Détermine s'il y a plus d'éléments dans la source de données.
     *
     * @return bool
     */
    protected $hasMore;

    /**
     * Crée une nouvelle instance de paginateur.
     * 
     * @param  Collection<TKey, TValue>|Arrayable<TKey, TValue>|iterable<TKey, TValue>  $items
     * @param  array{path: string, query: array, fragment: ?string, pageName: string}  $options
     */
    public function __construct(mixed $items, int $perPage, ?int $currentPage = null, array $options = [])
    {
        $this->options = $options;

        foreach ($options as $key => $value) {
            $this->{$key} = $value;
        }

        $this->perPage     = $perPage;
        $this->currentPage = $this->setCurrentPage($currentPage);
        $this->path        = $this->path !== '/' ? rtrim($this->path, '/') : $this->path;

        $this->setItems($items);
    }

    /**
     * Obtient la page actuelle pour la requête.
     */
    protected function setCurrentPage(?int $currentPage): int
    {
        $currentPage = $currentPage ?: static::resolveCurrentPage();

        return $this->isValidPageNumber($currentPage) ? (int) $currentPage : 1;
    }

    /**
     * Définit les éléments pour le paginateur.
     * 
     * @param Collection<TKey, TValue>|Arrayable<TKey, TValue>|iterable<TKey, TValue>|null $items
     */
    protected function setItems(mixed $items): void
    {
        $this->items = $items instanceof Collection ? $items : new Collection($items);

        $this->hasMore = $this->items->count() > $this->perPage;

        $this->items = $this->items->slice(0, $this->perPage);
    }

    /**
     * Obtient l'URL de la page suivante.
     */
    public function nextPageUrl(): ?string
    {
        if ($this->hasMorePages()) {
            return $this->url($this->currentPage() + 1);
        }

        return null;
    }

    /**
     * Affiche le paginateur en utilisant la vue donnée.
     */
    public function links(?string $view = null, array $data = []): string
    {
        return $this->render($view, $data);
    }

    /**
     * Affiche le paginateur en utilisant la vue donnée.
     */
    public function render(?string $view = null, array $data = []): string
    {
        return static::viewFactory()
            ->addData(array_merge($data, ['paginator' => $this]))
            ->render($view ?: static::$defaultSimpleView);
    }

    /**
     * Indique manuellement que le paginateur a plus de pages.
     */
    public function hasMorePagesWhen(bool $hasMore = true): static
    {
        $this->hasMore = $hasMore;

        return $this;
    }

    /**
     * Détermine s'il y a plus d'éléments dans la source de données.
     */
    public function hasMorePages(): bool
    {
        return $this->hasMore;
    }

    /**
     * Obtient l'instance sous forme de tableau.
     */
    public function toArray(): array
    {
        return [
            'current_page'     => $this->currentPage(),
            'current_page_url' => $this->url($this->currentPage()),
            'data'             => $this->items->toArray(),
            'first_page_url'   => $this->url(1),
            'from'             => $this->firstItem(),
            'next_page_url'    => $this->nextPageUrl(),
            'path'             => $this->path(),
            'per_page'         => $this->perPage(),
            'prev_page_url'    => $this->previousPageUrl(),
            'to'               => $this->lastItem(),
        ];
    }

    /**
     * Convertit l'objet en quelque chose de sérialisable en JSON.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convertit l'objet en sa représentation JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Convertit l'objet en JSON formaté de façon jolie.
     */
    public function toPrettyJson(int $options = 0): string
    {
        return $this->toJson(JSON_PRETTY_PRINT | $options);
    }
}
