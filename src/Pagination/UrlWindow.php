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

use BlitzPHP\Wolke\Contracts\LengthAwarePaginator as PaginatorContract;

/**
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Pagination\UrlWindow</a>
 */
class UrlWindow
{
    /**
     * Crée une nouvelle instance de fenêtre d'URL.
     *
     * @param PaginatorContract $paginator L'implémentation du paginateur.
     */
    public function __construct(protected PaginatorContract $paginator)
    {
    }

    /**
     * Crée une nouvelle instance de fenêtre d'URL.
     */
    public static function make(PaginatorContract $paginator): array
    {
        return (new static($paginator))->get();
    }

    /**
     * Obtient la fenêtre des URL à afficher.
     */
    public function get(): array
    {
        $onEachSide = $this->paginator->onEachSide;

        if ($this->paginator->lastPage() < ($onEachSide * 2) + 8) {
            return $this->getSmallSlider();
        }

        return $this->getUrlSlider($onEachSide);
    }

    /**
     * Obtient le curseur d'URL lorsqu'il n'y a pas assez de pages pour faire défiler.
     */
    protected function getSmallSlider(): array
    {
        return [
            'first'  => $this->paginator->getUrlRange(1, $this->lastPage()),
            'slider' => null,
            'last'   => null,
        ];
    }

    /**
     * Crée un curseur de liens URL.
     */
    protected function getUrlSlider(int $onEachSide): array
    {
        $window = $onEachSide + 4;

        if (! $this->hasPages()) {
            return ['first' => null, 'slider' => null, 'last' => null];
        }

        // Si la page actuelle est très proche du début de la plage de pages, nous
        // afficherons simplement le début de la plage de pages, suivi des 2 derniers
        // liens de cette liste, car nous n'aurons pas de place pour créer un curseur complet.
        if ($this->currentPage() <= $window) {
            return $this->getSliderTooCloseToBeginning($window, $onEachSide);
        }

        // Si la page actuelle est proche de la fin de la plage de pages, nous obtiendrons
        // ces premières pages, suivies d'une plus grande fenêtre de ces pages de fin
        // car nous sommes trop près de la fin de la liste pour créer un curseur complet.
        if ($this->currentPage() > ($this->lastPage() - $window)) {
            return $this->getSliderTooCloseToEnding($window, $onEachSide);
        }

        // Si nous avons assez d'espace des deux côtés de la page actuelle pour construire un curseur,
        // nous l'entourerons à la fois des chapeaux de début et de fin, avec cette fenêtre
        // de pages au milieu fournissant une configuration de paginateur coulissant de style Google.
        return $this->getFullSlider($onEachSide);
    }

    /**
     * Obtient le curseur d'URL quand trop près du début de la fenêtre.
     */
    protected function getSliderTooCloseToBeginning(int $window, int $onEachSide): array
    {
        return [
            'first'  => $this->paginator->getUrlRange(1, $window + $onEachSide),
            'slider' => null,
            'last'   => $this->getFinish(),
        ];
    }

    /**
     * Obtient le curseur d'URL quand trop près de la fin de la fenêtre.
     */
    protected function getSliderTooCloseToEnding(int $window, int $onEachSide): array
    {
        $last = $this->paginator->getUrlRange(
            $this->lastPage() - ($window + ($onEachSide - 1)),
            $this->lastPage()
        );

        return [
            'first'  => $this->getStart(),
            'slider' => null,
            'last'   => $last,
        ];
    }

    /**
     * Obtient le curseur d'URL quand un curseur complet peut être fait.
     */
    protected function getFullSlider(int $onEachSide): array
    {
        return [
            'first'  => $this->getStart(),
            'slider' => $this->getAdjacentUrlRange($onEachSide),
            'last'   => $this->getFinish(),
        ];
    }

    /**
     * Obtient la plage de pages pour la fenêtre de page actuelle.
     */
    public function getAdjacentUrlRange(int $onEachSide): array
    {
        return $this->paginator->getUrlRange(
            $this->currentPage() - $onEachSide,
            $this->currentPage() + $onEachSide
        );
    }

    /**
     * Obtient les URL de début d'un curseur de pagination.
     */
    public function getStart(): array
    {
        return $this->paginator->getUrlRange(1, 2);
    }

    /**
     * Obtient les URL de fin d'un curseur de pagination.
     */
    public function getFinish(): array
    {
        return $this->paginator->getUrlRange(
            $this->lastPage() - 1,
            $this->lastPage()
        );
    }

    /**
     * Détermine si le paginateur sous-jacent présenté a des pages à afficher.
     */
    public function hasPages(): bool
    {
        return $this->paginator->lastPage() > 1;
    }

    /**
     * Obtient la page actuelle du paginateur.
     */
    protected function currentPage(): int
    {
        return $this->paginator->currentPage();
    }

    /**
     * Obtient la dernière page du paginateur.
     */
    protected function lastPage(): int
    {
        return $this->paginator->lastPage();
    }
}
