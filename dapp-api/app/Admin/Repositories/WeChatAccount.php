<?php

namespace App\Admin\Repositories;

use App\Models\WeChatAccount as Model;
use Dcat\Admin\Repositories\EloquentRepository;

class WeChatAccount extends EloquentRepository
{
    /**
     * Model.
     *
     * @var string
     */
    protected $eloquentClass = Model::class;
}
