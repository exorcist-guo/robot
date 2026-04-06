<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmAuthNonce;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmAuthNonceController extends AdminController
{
    public function index(Content $content)
    {
        return $content
            ->header('PmAuthNonce 管理')
            ->description('认证 nonce 列表')
            ->breadcrumb(['text' => 'PmAuthNonce 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        $maskAddress = fn (?string $value) => $value
            ? '<span class="badge badge-primary">'.substr($value, 0, 10).'...'.substr($value, -6).'</span>'
            : '-';

        return Grid::make(new PmAuthNonce(), function (Grid $grid) use ($maskAddress) {
            $grid->model()->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('address', '钱包地址')->display(fn ($value) => $maskAddress($value));
            $grid->column('nonce', 'Nonce')->limit(20);
            $grid->column('expires_at', '过期时间');
            $grid->column('used_at', '使用时间');
            $grid->column('ip', 'IP');
            $grid->column('ua_hash', 'UA Hash')->display(fn ($value) => $value ? substr($value, 0, 12).'...' : '-');
            $grid->column('created_at', '创建时间');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
                $actions->disableEdit();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->like('address', '钱包地址');
                $filter->like('nonce', 'Nonce');
                $filter->like('ip', 'IP');
                $filter->between('expires_at', '过期时间')->datetime();
                $filter->between('used_at', '使用时间')->datetime();
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->disableCreateButton();
            $grid->disableBatchDelete();
        });
    }

    protected function detail($id)
    {
        return Show::make($id, new PmAuthNonce(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('address', '钱包地址');
            $show->field('nonce', 'Nonce');
            $show->field('expires_at', '过期时间');
            $show->field('used_at', '使用时间');
            $show->field('ip', 'IP');
            $show->field('ua_hash', 'UA Hash');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmAuthNonce(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('address', '钱包地址');
            $form->display('nonce', 'Nonce');
            $form->display('expires_at', '过期时间');
            $form->display('used_at', '使用时间');
            $form->display('ip', 'IP');
            $form->display('ua_hash', 'UA Hash');
            $form->display('created_at', '创建时间');
            $form->display('updated_at', '更新时间');

            $form->disableDeleteButton();
            $form->disableCreatingCheck();
            $form->disableEditingCheck();
            $form->disableViewCheck();
            $form->disableSubmitButton();
        });
    }
}
