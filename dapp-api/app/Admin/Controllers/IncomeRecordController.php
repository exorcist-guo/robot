<?php

namespace App\Admin\Controllers;

use App\Models\IncomeRecord;
use App\Models\Member;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use Dcat\Admin\Layout\Content;

class IncomeRecordController extends AdminController
{
    /**
     * page index
     */
    public function index(Content $content)
    {
        return $content
            ->header('收益记录')
            ->description('收益明细')
            ->breadcrumb(['text' => '收益记录', 'url' => ''])
            ->body($this->grid());
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new IncomeRecord(), function (Grid $grid) {
            $grid->column('id', 'ID')->sortable();
            $grid->column('member_id', '会员ID')->display(function ($value) {
                return $value ?: '-';
            });
            $grid->column('member.address', '钱包地址')->display(function ($value) {
                return $value ? '<span class="badge badge-primary">' . substr($value, 0, 10) . '...' . substr($value, -6) . '</span>' : '-';
            });
            $grid->column('amount', '金额')->display(function ($value) {
                return '<span class="badge badge-success">' . number_format($value, 2) . ' USDT</span>';
            });
            $grid->column('type', '收益类型')->using(IncomeRecord::TYPE_MAP)->label([
                'random_reward' => 'info',       // 随机奖励
                'direct_reward' => 'success',     // 直推奖励
                'team_reward' => 'warning',       // 团队奖励
                'super_prize' => 'danger',        // 超级大奖
                'second_prize' => 'primary',      // 二等奖
            ]);
            $grid->column('from_address', '来源地址')->display(function ($value) {
                return $value ? substr($value, 0, 10) . '...' . substr($value, -6) : '-';
            });
            $grid->column('tx_hash', '交易哈希')->display(function ($value) {
                return $value ? '<a href="https://etherscan.io/tx/' . $value . '" target="_blank" class="badge badge-dark">' . substr($value, 0, 10) . '...</a>' : '-';
            });
            $grid->column('block_number', '区块号');
            $grid->column('time_stamp', '时间戳')->display(function ($value) {
                return $value ? date('Y-m-d H:i:s', strtotime($value)) : '-';
            });
            $grid->column('performance_record_id', '业绩ID')->display(function ($value) {
                return $value ?: '-';
            });
            $grid->column('contract_dynamic_id', '合约动态ID')->display(function ($value) {
                return $value ?: '-';
            });
            $grid->column('remark', '备注')->display(function ($value) {
                return $value ?: '-';
            })->limit(30);
            $grid->column('created_at', '创建时间');

            $grid->model()->with('member')->orderBy('id', 'desc');

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
                $filter->equal('type', '收益类型')->select(IncomeRecord::TYPE_MAP);
                $filter->between('amount', '金额');
                $filter->like('tx_hash', '交易哈希');
                $filter->like('from_address', '来源地址');
                $filter->equal('block_number', '区块号');
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
        return Show::make($id, new IncomeRecord(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('member_id', '会员ID');
            $show->field('member.address', '钱包地址');
            $show->field('amount', '金额')->as(function ($value) {
                return number_format($value, 8) . ' USDT';
            });
            $show->field('type', '收益类型')->using(IncomeRecord::TYPE_MAP);
            $show->field('tx_hash', '交易哈希');
            $show->field('from_address', '来源地址');
            $show->field('block_number', '区块号');
            $show->field('time_stamp', '时间戳')->as(function ($value) {
                return $value ? date('Y-m-d H:i:s', strtotime($value)) : '-';
            });
            $show->field('contract_dynamic_id', '合约动态ID');
            $show->field('performance_record_id', '业绩记录ID');
            $show->field('from_grab_id', '来源抢红包ID');
            $show->field('remark', '备注');
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
        return Form::make(new IncomeRecord(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('member_id', '会员ID');

            $form->display('member.address', '钱包地址')->value(function () {
                $member = Member::find($this->member_id);
                return $member ? $member->address : '-';
            });

            $form->display('amount', '金额')->value(function ($value) {
                return number_format($value, 8) . ' USDT';
            });
            $form->display('type', '收益类型')->value(function ($value) {
                return IncomeRecord::TYPE_MAP[$value] ?? $value;
            });
            $form->display('tx_hash', '交易哈希');
            $form->display('from_address', '来源地址');
            $form->display('block_number', '区块号');
            $form->display('time_stamp', '时间戳')->value(function ($value) {
                return $value ? date('Y-m-d H:i:s', strtotime($value)) : '-';
            });
            $form->display('contract_dynamic_id', '合约动态ID');
            $form->display('performance_record_id', '业绩记录ID');
            $form->display('from_grab_id', '来源抢红包ID');
            $form->display('remark', '备注');
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
