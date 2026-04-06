<?php

namespace App\Admin\Controllers;

use App\Models\PerformanceRecord;
use App\Models\Member;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Layout\Content;

class PerformanceRecordController extends AdminController
{
    /**
     * 业绩类型映射
     */
    const TYPE_MAP = [
        'grab' => '抢红包',
        'team_grab' => '团队抢红包',
    ];

    /**
     * page index
     */
    public function index(Content $content)
    {
        return $content
            ->header('业绩记录')
            ->description('业绩明细')
            ->breadcrumb(['text' => '业绩记录', 'url' => ''])
            ->body($this->grid());
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new PerformanceRecord(), function (Grid $grid) {
            $grid->column('id', 'ID')->sortable();
            $grid->column('member_id', '会员ID')->display(function ($value) {
                return $value ?: '-';
            });
            $grid->column('member.address', '钱包地址')->display(function ($value) {
                return $value ? '<span class="badge badge-primary">' . substr($value, 0, 10) . '...' . substr($value, -6) . '</span>' : '-';
            });
            $grid->column('parent_id', '上级ID')->display(function ($value) {
                return $value ?: '-';
            });
            $grid->column('parent.address', '上级地址')->display(function ($value) {
                return $value ? '<span class="badge badge-info">' . substr($value, 0, 8) . '...' . substr($value, -4) . '</span>' : '-';
            });
            $grid->column('amount', '金额')->display(function ($value) {
                return '<span class="badge badge-success">' . number_format($value, 2) . ' USDT</span>';
            });
            $grid->column('type', '类型')->using(self::TYPE_MAP)->label([
                'grab' => 'primary',         // 抢红包
                'team_grab' => 'warning',     // 团队抢红包
            ]);
            $grid->column('contract_dynamic_id', '合约动态ID')->display(function ($value) {
                return $value ?: '-';
            });
            $grid->column('time_stamp', '时间戳')->display(function ($value) {
                return $value ? date('Y-m-d H:i:s', strtotime($value)) : '-';
            });
            $grid->column('created_at', '创建时间');

            $grid->model()->with(['member', 'parent'])->orderBy('id', 'desc');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
                $actions->disableEdit();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->equal('member_id', '会员ID');
                $filter->whereHas('member', function ($query) {
                    $query->where('address', 'like', "%{$this->input}%");
                }, '钱包地址');
                $filter->equal('parent_id', '上级ID');
                $filter->whereHas('parent', function ($query) {
                    $query->where('address', 'like', "%{$this->input}%");
                }, '上级地址');
                $filter->equal('type', '类型')->select(self::TYPE_MAP);
                $filter->between('amount', '金额');
                $filter->equal('contract_dynamic_id', '合约动态ID');
                $filter->between('time_stamp', '时间戳')->datetime();
                $filter->between('created_at', '创建时间')->datetime();
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
        return Show::make($id, new PerformanceRecord(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('member_id', '会员ID');
            $show->field('member.address', '钱包地址');
            $show->field('parent_id', '上级ID');
            $show->field('parent.address', '上级地址');
            $show->field('amount', '金额')->as(function ($value) {
                return number_format($value, 8) . ' USDT';
            });
            $show->field('type', '类型')->using(self::TYPE_MAP);
            $show->field('contract_dynamic_id', '合约动态ID');
            $show->field('time_stamp', '时间戳')->as(function ($value) {
                return $value ? date('Y-m-d H:i:s', strtotime($value)) : '-';
            });
            $show->field('created_at', '创建时间');
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
        return Form::make(new PerformanceRecord(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('member_id', '会员ID');

            $form->display('member.address', '钱包地址')->value(function () {
                $member = Member::find($this->member_id);
                return $member ? $member->address : '-';
            });

            $form->display('parent_id', '上级ID');

            $form->display('parent.address', '上级地址')->value(function () {
                if ($this->parent_id) {
                    $parent = Member::find($this->parent_id);
                    return $parent ? $parent->address : '-';
                }
                return '-';
            });

            $form->display('amount', '金额')->value(function ($value) {
                return number_format($value, 8) . ' USDT';
            });
            $form->display('type', '类型')->value(function ($value) {
                return self::TYPE_MAP[$value] ?? $value;
            });
            $form->display('contract_dynamic_id', '合约动态ID');
            $form->display('time_stamp', '时间戳')->value(function ($value) {
                return $value ? date('Y-m-d H:i:s', strtotime($value)) : '-';
            });
            $form->display('created_at', '创建时间');
            $form->display('updated_at', '更新时间');

            $form->disableDeleteButton();
            $form->disableCreatingCheck();
            $form->disableEditingCheck();
            $form->disableViewCheck();

            // 禁用所有字段编辑
            $form->disableSubmitButton();
        });
    }
}
