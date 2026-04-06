<?php

namespace App\Admin\Controllers;

use App\Admin\Repositories\ReplyComment;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Admin;

class ReplyCommentController extends AdminController
{
    /**
     * page index
     */
    public function index(Content $content)
    {
        return $content
            ->header('列表')
            ->description('全部')
            ->breadcrumb(['text'=>'列表','url'=>''])
            ->body($this->grid());
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new ReplyComment(), function (Grid $grid) {
            $grid->column('id')->sortable();
            $grid->column('comment_id');
            $grid->column('account_id');
            $grid->column('reply_content');
            $grid->column('is_successful');
            $grid->column('error_msg');
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();
            // $grid->setActionClass(Grid\Displayers\Actions::class); // 行操作按钮显示方式 图标方式
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                // $actions->disableDelete(); //  禁用删除
                // $actions->disableEdit();   //  禁用修改
                // $actions->disableQuickEdit(); //禁用快速修改(弹窗形式)
                // $actions->disableView(); //  禁用查看
            });
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id');
        
            });
        });
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     *
     * @return Show
     */
    protected function detail($id)
    {
        return Show::make($id, new ReplyComment(), function (Show $show) {
            $show->field('id');
            $show->field('comment_id');
            $show->field('account_id');
            $show->field('reply_content');
            $show->field('is_successful');
            $show->field('error_msg');
            $show->field('created_at');
            $show->field('updated_at');
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(new ReplyComment(), function (Form $form) {
            $form->display('id');
            $form->text('comment_id');
            $form->text('account_id');
            $form->text('reply_content');
            $form->text('is_successful');
            $form->text('error_msg');
        
            $form->display('created_at');
            $form->display('updated_at');
        });
    }
}
