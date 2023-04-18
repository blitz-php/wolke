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

class UrlWindow
{
    /**
     * Create a new URL window instance.
     *
     * @param PaginatorContract $paginator The paginator implementation.
     *
     * @return void
     */
    public function __construct(protected PaginatorContract $paginator)
    {
    }

    /**
     * Create a new URL window instance.
     */
    public static function make(PaginatorContract $paginator): array
    {
        return (new static($paginator))->get();
    }

    /**
     * Get the window of URLs to be shown.
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
     * Get the slider of URLs there are not enough pages to slide.
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
     * Create a URL slider links.
     */
    protected function getUrlSlider(int $onEachSide): array
    {
        $window = $onEachSide + 4;

        if (! $this->hasPages()) {
            return ['first' => null, 'slider' => null, 'last' => null];
        }

        // If the current page is very close to the beginning of the page range, we will
        // just render the beginning of the page range, followed by the last 2 of the
        // links in this list, since we will not have room to create a full slider.
        if ($this->currentPage() <= $window) {
            return $this->getSliderTooCloseToBeginning($window, $onEachSide);
        }

        // If the current page is close to the ending of the page range we will just get
        // this first couple pages, followed by a larger window of these ending pages
        // since we're too close to the end of the list to create a full on slider.
        if ($this->currentPage() > ($this->lastPage() - $window)) {
            return $this->getSliderTooCloseToEnding($window, $onEachSide);
        }

        // If we have enough room on both sides of the current page to build a slider we
        // will surround it with both the beginning and ending caps, with this window
        // of pages in the middle providing a Google style sliding paginator setup.
        return $this->getFullSlider($onEachSide);
    }

    /**
     * Get the slider of URLs when too close to beginning of window.
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
     * Get the slider of URLs when too close to ending of window.
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
     * Get the slider of URLs when a full slider can be made.
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
     * Get the page range for the current page window.
     */
    public function getAdjacentUrlRange(int $onEachSide): array
    {
        return $this->paginator->getUrlRange(
            $this->currentPage() - $onEachSide,
            $this->currentPage() + $onEachSide
        );
    }

    /**
     * Get the starting URLs of a pagination slider.
     */
    public function getStart(): array
    {
        return $this->paginator->getUrlRange(1, 2);
    }

    /**
     * Get the ending URLs of a pagination slider.
     */
    public function getFinish(): array
    {
        return $this->paginator->getUrlRange(
            $this->lastPage() - 1,
            $this->lastPage()
        );
    }

    /**
     * Determine if the underlying paginator being presented has pages to show.
     */
    public function hasPages(): bool
    {
        return $this->paginator->lastPage() > 1;
    }

    /**
     * Get the current page from the paginator.
     */
    protected function currentPage(): int
    {
        return $this->paginator->currentPage();
    }

    /**
     * Get the last page from the paginator.
     */
    protected function lastPage(): int
    {
        return $this->paginator->lastPage();
    }
}
