<?php

namespace App\Admin\Repositories;

use App\Models\ReplyComment as Model;
use Dcat\Admin\Repositories\EloquentRepository;

class ReplyComment extends EloquentRepository
{
    /**
     * Model.
     *
     * @var string
     */
    protected $eloquentClass = Model::class;
}
