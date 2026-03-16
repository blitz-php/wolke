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

use BlitzPHP\Database\Exceptions\RecordsNotFoundException;
use BlitzPHP\Utilities\Iterable\Arr;

/**
 * @template TModel of \BlitzPHP\Wolke\Model
 */
class ModelNotFoundException extends RecordsNotFoundException
{
    /**
     * Name of the affected Eloquent model.
     *
     * @var class-string<TModel>
     */
    protected $model;

    /**
     * The affected model IDs.
     *
     * @var list<int|string>
     */
    protected $ids;

    /**
     * Set the affected Eloquent model and instance ids.
     *
     * @param  class-string<TModel>  $model
     * @param  list<int|string>|int|string  $ids
     */
    public function setModel(string $model, array|int|string $ids = []): self
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
     *
     * @return class-string<TModel>
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Get the affected Wolke model IDs.
     *
     * @return list<int|string>
     */
    public function getIds()
    {
        return $this->ids;
    }
}
