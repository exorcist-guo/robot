<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmMember;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmMemberController extends AdminController
{
    private const STATUS_MAP = [
        1 => '正常',
        0 => '禁用',
    ];

    public function index(Content $content)
    {
        return $content
            ->header('PmMember 管理')
            ->description('会员列表')
            ->breadcrumb(['text' => 'PmMember 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        return Grid::make(new PmMember(), function (Grid $grid) {
            $grid->model()->with(['inviter', 'custodyWallet'])->withCount(['copyTasks', 'custodyWallets'])->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('address', '钱包地址')->display(function ($value) {
                return $value ? '<span class="badge badge-primary">'.substr($value, 0, 10).'...'.substr($value, -6).'</span>' : '-';
            });
            $grid->column('nickname', '昵称')->limit(20);
            $grid->column('inviter.address', '邀请人')->display(function ($value) {
                return $value ? '<span class="badge badge-primary">'.substr($value, 0, 10).'...'.substr($value, -6).'</span>' : '-';
            });
            $grid->column('deep', '层级')->badge('secondary');
            $grid->column('status', '状态')->using(self::STATUS_MAP)->label([
                1 => 'success',
                0 => 'danger',
            ]);
            $grid->column('custodyWallet.signer_address', '主钱包')->display(function ($value) {
                return $value ? '<span class="badge badge-primary">'.substr($value, 0, 10).'...'.substr($value, -6).'</span>' : '-';
            });
            $grid->column('custody_wallets_count', '钱包数')->badge('info');
            $grid->column('copy_tasks_count', '跟单任务数')->badge('primary');
            $grid->column('last_login_at', '最后登录');
            $grid->column('created_at', '创建时间');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->like('address', '钱包地址');
                $filter->like('nickname', '昵称');
                $filter->equal('inviter_id', '邀请人ID');
                $filter->equal('deep', '层级');
                $filter->equal('status', '状态')->select(self::STATUS_MAP);
                $filter->between('last_login_at', '最后登录')->datetime();
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->disableBatchDelete();
        });
    }

    protected function detail($id)
    {
        return Show::make($id, new PmMember(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('address', '钱包地址');
            $show->field('nickname', '昵称');
            $show->field('avatar_url', '头像地址');
            $show->field('inviter.address', '邀请人地址');
            $show->field('path', '邀请路径');
            $show->field('deep', '层级');
            $show->field('status', '状态')->using(self::STATUS_MAP);
            $show->field('last_login_at', '最后登录');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmMember(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('address', '钱包地址');
            $form->text('nickname', '昵称')->maxlength(255);
            $form->url('avatar_url', '头像地址');
            $form->select('status', '状态')->options(self::STATUS_MAP)->required();
            $form->display('inviter_id', '邀请人ID');
            $form->display('path', '邀请路径');
            $form->display('deep', '层级');
            $form->display('last_login_at', '最后登录');
            $form->display('created_at', '创建时间');
            $form->display('updated_at', '更新时间');

            $form->disableDeleteButton();
            $form->disableCreatingCheck();
            $form->disableEditingCheck();
            $form->disableViewCheck();
        });
    }
}
