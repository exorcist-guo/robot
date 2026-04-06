<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Grid\SetMemberLevel;
use App\Models\Member;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Layout\Content;

class MemberController extends AdminController
{
    /**
     * page index
     */
    public function index(Content $content)
    {
        return $content
            ->header('会员管理')
            ->description('会员列表')
            ->breadcrumb(['text' => '会员管理', 'url' => ''])
            ->body($this->grid());
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new Member(), function (Grid $grid) {
            $grid->column('id', 'ID')->sortable();
            $grid->column('address', '钱包地址')->display(function ($value) {
                return '<span class="badge badge-primary">' . substr($value, 0, 10) . '...' . substr($value, -6) . '</span>';
            });
            $grid->column('pid', '上级ID')->display(function ($value) {
                if ($value == 0) return '<span class="badge badge-secondary">无</span>';
                return $value;
            });
            $grid->column('parent.address', '上级地址')->display(function ($value) {
                return $value ? '<span class="badge badge-info">' . substr($value, 0, 8) . '...' . substr($value, -4) . '</span>' : '-';
            });
            $grid->column('level', '等级')->using([
                0 => 'LV0',
                1 => 'LV1', 2 => 'LV2', 3 => 'LV3', 4 => 'LV4', 5 => 'LV5',
                6 => 'LV6', 7 => 'LV7', 8 => 'LV8', 9 => 'LV9', 10 => 'LV10',
                11 => 'LV11', 12 => 'LV12', 13 => 'LV13', 14 => 'LV14', 15 => 'LV15',
            ])->label([
                0 => 'secondary', 1 => 'success', 2 => 'info', 3 => 'primary', 4 => 'warning', 5 => 'danger',
                6 => 'success', 7 => 'info', 8 => 'primary', 9 => 'warning', 10 => 'danger',
                11 => 'dark', 12 => 'secondary', 13 => 'success', 14 => 'info', 15 => 'primary',
            ]);
            $grid->column('deep', '层级')->badge('gray');
            $grid->column('performance', '业绩')->display(function ($value) {
                return bcadd($value,'0', 2) ;
            })->label('success');
            $grid->column('total_earnings', '总收益')->display(function ($value) {
                return bcadd($value,'0', 2) ;
            })->label('warning');
            $grid->column('total_consumption', '总消费')->display(function ($value) {
                return bcadd($value,'0', 2);
            })->label('info');
            $grid->column('total_grab_count', '抢红包次数')->badge('primary');
            $grid->column('created_at', '注册时间')->display(function ($value) {
                return date('Y-m-d H:i', strtotime($value));
            });

            $grid->model()->orderBy('id', 'desc');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
                $actions->disableEdit();
                $actions->append(new SetMemberLevel());
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->like('address', '钱包地址');
                $filter->equal('pid', '上级ID');
                $filter->equal('level', '等级')->select([
                    0 => 'LV0', 1 => 'LV1', 2 => 'LV2', 3 => 'LV3', 4 => 'LV4', 5 => 'LV5',
                    6 => 'LV6', 7 => 'LV7', 8 => 'LV8', 9 => 'LV9', 10 => 'LV10',
                    11 => 'LV11', 12 => 'LV12', 13 => 'LV13', 14 => 'LV14', 15 => 'LV15',
                ]);
                $filter->equal('deep', '层级')->select([
                    0 => '第0层', 1 => '第1层', 2 => '第2层', 3 => '第3层',
                    4 => '第4层', 5 => '第5层', 6 => '第6层', 7 => '第7层',
                ]);
                $filter->between('created_at', '注册时间')->datetime();
            });

            $grid->disableCreateButton();
            $grid->disableBatchDelete();
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
        return Show::make($id, new Member(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('address', '钱包地址');
            $show->field('pid', '上级ID');
            $show->field('parent.address', '上级地址');
            $show->field('deep', '层级深度');
            $show->field('path', '层级路径');
            $show->field('level', '等级')->as(function ($value) {
                $levelMap = [
                    0 => 'LV0', 1 => 'LV1', 2 => 'LV2', 3 => 'LV3', 4 => 'LV4', 5 => 'LV5',
                    6 => 'LV6', 7 => 'LV7', 8 => 'LV8', 9 => 'LV9', 10 => 'LV10',
                    11 => 'LV11', 12 => 'LV12', 13 => 'LV13', 14 => 'LV14', 15 => 'LV15',
                ];
                return $levelMap[$value] ?? 'LV0';
            });
            $show->field('performance', '业绩')->as(function ($value) {
                return number_format($value, 8) . ' USDT';
            });
            $show->field('total_earnings', '总收益')->as(function ($value) {
                return number_format($value, 8) . ' USDT';
            });
            $show->field('total_consumption', '总消费')->as(function ($value) {
                return number_format($value, 8) . ' USDT';
            });
            $show->field('total_grab_count', '抢红包次数');
            $show->field('created_at', '注册时间');
            $show->field('updated_at', '更新时间');

            $show->panel()
                ->tools(function ($tools) {
                    $tools->disableDelete();
                });
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(new Member(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('address', '钱包地址');
            $form->display('pid', '上级ID');

            $form->select('level', '等级')->options([
                0 => 'LV0', 1 => 'LV1', 2 => 'LV2', 3 => 'LV3', 4 => 'LV4', 5 => 'LV5',
                6 => 'LV6', 7 => 'LV7', 8 => 'LV8', 9 => 'LV9', 10 => 'LV10',
                11 => 'LV11', 12 => 'LV12', 13 => 'LV13', 14 => 'LV14', 15 => 'LV15',
            ])->required();
            $form->decimal('performance', '业绩')->attribute('min', 0);
            $form->decimal('total_earnings', '总收益')->attribute('min', 0);
            $form->decimal('total_consumption', '总消费')->attribute('min', 0);
            $form->number('total_grab_count', '抢红包次数')->default(0);

            $form->display('created_at', '注册时间');
            $form->display('updated_at', '更新时间');

            $form->disableDeleteButton();
            $form->disableCreatingCheck();
            $form->disableEditingCheck();
            $form->disableViewCheck();
        });
    }
}
