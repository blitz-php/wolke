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
 * @credit <a href="http://laravel.com/">Laravel - Illuminate\Database\Eloquent\ModelNotFoundException</a>
 *
 * @template TModel of \BlitzPHP\Wolke\Model
 */
class ModelNotFoundException extends RecordsNotFoundException
{
    /**
     * Nom du modèle Eloquent concerné.
     *
     * @var class-string<TModel>
     */
    protected $model;

    /**
     * Les IDs du modèle concerné.
     *
     * @var list<int|string>
     */
    protected $ids;

    /**
     * Définit le modèle Eloquent concerné et les IDs d'instance.
     *
     * @param class-string<TModel>        $model
     * @param int|list<int|string>|string $ids
     */
    public function setModel(string $model, array|int|string $ids = []): self
    {
        $this->model = $model;
        $this->ids   = Arr::wrap($ids);

        $this->message = "Aucun résultat de requête pour le modèle [{$model}]";

        if (count($this->ids) > 0) {
            $this->message .= ' ' . implode(', ', $this->ids);
        } else {
            $this->message .= '.';
        }

        return $this;
    }

    /**
     * Obtient le modèle Wolke concerné.
     *
     * @return class-string<TModel>
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * Obtient les IDs du modèle Wolke concerné.
     *
     * @return list<int|string>
     */
    public function getIds()
    {
        return $this->ids;
    }
}
