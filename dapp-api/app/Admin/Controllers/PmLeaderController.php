<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmLeader;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmLeaderController extends AdminController
{
    private const STATUS_MAP = [
        1 => '启用',
        0 => '禁用',
    ];

    public function index(Content $content)
    {
        return $content
            ->header('PmLeader 管理')
            ->description('带单员列表')
            ->breadcrumb(['text' => 'PmLeader 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        $maskAddress = fn (?string $value) => $value
            ? '<span class="badge badge-primary">'.substr($value, 0, 10).'...'.substr($value, -6).'</span>'
            : '-';

        return Grid::make(new PmLeader(), function (Grid $grid) use ($maskAddress) {
            $grid->model()->withCount(['copyTasks', 'trades'])->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('display_name', '显示名')->limit(20);
            $grid->column('input_address', '录入地址')->display(fn ($value) => $maskAddress($value));
            $grid->column('proxy_wallet', '代理钱包')->display(fn ($value) => $maskAddress($value));
            $grid->column('status', '状态')->using(self::STATUS_MAP)->label([
                1 => 'success',
                0 => 'danger',
            ]);
            $grid->column('copy_tasks_count', '跟单任务数')->badge('primary');
            $grid->column('trades_count', '成交数')->badge('info');
            $grid->column('last_seen_trade_at', '最近成交时间');
            $grid->column('created_at', '创建时间');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->like('display_name', '显示名');
                $filter->like('input_address', '录入地址');
                $filter->like('proxy_wallet', '代理钱包');
                $filter->equal('status', '状态')->select(self::STATUS_MAP);
                $filter->between('last_seen_trade_at', '最近成交时间')->datetime();
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->disableBatchDelete();
        });
    }

    protected function detail($id)
    {
        return Show::make($id, new PmLeader(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('display_name', '显示名');
            $show->field('avatar_url', '头像地址');
            $show->field('input_address', '录入地址');
            $show->field('proxy_wallet', '代理钱包');
            $show->field('status', '状态')->using(self::STATUS_MAP);
            $show->field('last_seen_trade_at', '最近成交时间');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmLeader(), function (Form $form) {
            $form->display('id', 'ID');
            $form->text('display_name', '显示名')->maxlength(255);
            $form->url('avatar_url', '头像地址');
            $form->text('input_address', '录入地址')->required();
            $form->text('proxy_wallet', '代理钱包')->required();
            $form->select('status', '状态')->options(self::STATUS_MAP)->required();
            $form->display('last_seen_trade_at', '最近成交时间');
            $form->display('created_at', '创建时间');
            $form->display('updated_at', '更新时间');

            $form->disableDeleteButton();
            $form->disableCreatingCheck();
            $form->disableEditingCheck();
            $form->disableViewCheck();
        });
    }
}
