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

use CodeIgniter\Config\Services;

class ViewBridge
{
    public function make($view, $data = [])
    {
        return Services::renderer()->setData($data)->render($view);
    }
}
