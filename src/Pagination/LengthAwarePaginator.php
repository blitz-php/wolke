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
use BlitzPHP\Wolke\Contracts\LengthAwarePaginator as LengthAwarePaginatorContract;
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
 * @implements LengthAwarePaginatorContract<TKey, TValue>
 * 
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Pagination\LengthAwarePaginator</a>
 */
class LengthAwarePaginator extends AbstractPaginator implements Arrayable, ArrayAccess, Countable, IteratorAggregate, Jsonable, JsonSerializable, LengthAwarePaginatorContract
{
    /**
     * La dernière page disponible.
     */
    protected int $lastPage;

    /**
     * Crée une nouvelle instance de paginateur.
     *
     * @param  Collection<TKey, TValue>|Arrayable<TKey, TValue>|iterable<TKey, TValue>|null  $items
     * @param int $total Le nombre total d'éléments avant le découpage.
     * @param  array{path: string, query: array, fragment: ?string, pageName: string}  $options
     */
    public function __construct(mixed $items, protected int $total, int $perPage, ?int $currentPage = null, array $options = [])
    {
        $this->options = $options;

        foreach ($options as $key => $value) {
            $this->{$key} = $value;
        }

        $this->perPage     = $perPage;
        $this->lastPage    = max((int) ceil($total / $perPage), 1);
        $this->path        = $this->path !== '/' ? rtrim($this->path, '/') : $this->path;
        $this->currentPage = $this->setCurrentPage($currentPage, $this->pageName);
        $this->items       = $items instanceof Collection ? $items : new Collection($items);
    }

    /**
     * Obtient la page actuelle pour la requête.
     */
    protected function setCurrentPage(?int $currentPage, string $pageName): int
    {
        $currentPage = $currentPage ?: static::resolveCurrentPage($pageName);

        return $this->isValidPageNumber($currentPage) ? (int) $currentPage : 1;
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
        return static::viewFactory()->addData(array_merge($data, [
            'paginator' => $this,
            'elements'  => $this->elements(),
        ]))->render($view ?: static::$defaultView);
    }

    /**
     * Obtient les liens du paginateur sous forme de collection (pour les réponses JSON).
     */
    public function linkCollection(): Collection
    {
        return (new Collection($this->elements()))->flatMap(function ($item) {
            if (! is_array($item)) {
                return [['url' => null, 'label' => '...', 'active' => false]];
            }

            return (new Collection($item))->map(fn ($url, $page) => [
                'url'    => $url,
                'label'  => (string) $page,
                'page'   => $page,
                'active' => $this->currentPage() === $page,
            ]);
        })->prepend([
            'url'    => $this->previousPageUrl(),
            'label'  => function_exists('lang') ? lang('Pagination.previous') : 'Précédent',
            'active' => false,
        ])->push([
            'url'    => $this->nextPageUrl(),
            'label'  => function_exists('lang') ? lang('Pagination.next') : 'Suivant',
            'page'   => $this->hasMorePages() ? $this->currentPage() + 1 : null,
            'active' => false,
        ]);
    }

    /**
     * Obtient le tableau des éléments à passer à la vue.
     */
    protected function elements(): array
    {
        $window = UrlWindow::make($this);

        return array_filter([
            $window['first'],
            is_array($window['slider']) ? '...' : null,
            $window['slider'],
            is_array($window['last']) ? '...' : null,
            $window['last'],
        ]);
    }

    /**
     * Obtient le nombre total d'éléments paginés.
     */
    public function total(): int
    {
        return $this->total;
    }

    /**
     * Détermine s'il y a plus d'éléments dans la source de données.
     */
    public function hasMorePages(): bool
    {
        return $this->currentPage() < $this->lastPage();
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
     * Obtient la dernière page.
     */
    public function lastPage(): int
    {
        return $this->lastPage;
    }

    /**
     * Obtient l'instance sous forme de tableau.
     */
    public function toArray(): array
    {
        return [
            'current_page'   => $this->currentPage(),
            'data'           => $this->items->toArray(),
            'first_page_url' => $this->url(1),
            'from'           => $this->firstItem(),
            'last_page'      => $this->lastPage(),
            'last_page_url'  => $this->url($this->lastPage()),
            'links'          => $this->linkCollection()->toArray(),
            'next_page_url'  => $this->nextPageUrl(),
            'path'           => $this->path(),
            'per_page'       => $this->perPage(),
            'prev_page_url'  => $this->previousPageUrl(),
            'to'             => $this->lastItem(),
            'total'          => $this->total(),
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
