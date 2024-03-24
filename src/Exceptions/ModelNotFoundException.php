<?php

/**
 * This file is part of Blitz PHP framework - Eloquent ORM Adapter.
 *
 * (c) 2023 Dimitri Sitchet Tomkeu <devcode.dst@gmail.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace BlitzPHP\Wolke\Exceptions;

use BlitzPHP\Utilities\Iterable\Arr;
use Exception;

class ModelNotFoundException extends Exception
{
    /**
     * Name of the affected Eloquent model.
     *
     * @var string
     */
    protected $model;

    /**
     * The affected model IDs.
     *
     * @var array|int
     */
    protected $ids;

    /**
     * Set the affected Eloquent model and instance ids.
     */
    public function setModel(string $model, array|int $ids = []): self
    {
        $this->model = $model;
        $this->ids   = Arr::wrap($ids);

        $this->message = "No query results for model [{$model}]";

        if (count($this->ids) > 0) {
            $this->message .= ' ' . implode(', ', $this->ids);
        } else {
            $this->message .= '.';
        }

        return $this;
    }

    /**
     * Get the affected Wolke model.
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the affected Wolke model IDs.
     *
     * @return array|int
     */
    public function getIds()
    {
        return $this->ids;
    }
}
