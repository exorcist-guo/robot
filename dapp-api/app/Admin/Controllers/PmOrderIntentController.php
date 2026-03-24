<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmOrderIntent;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmOrderIntentController extends AdminController
{
    private const STATUS_MAP = [
        PmOrderIntent::STATUS_PENDING => '待处理',
        PmOrderIntent::STATUS_SUBMITTED => '已提交',
        PmOrderIntent::STATUS_SKIPPED => '已跳过',
        PmOrderIntent::STATUS_FAILED => '失败',
    ];

    private const SIDE_MAP = [
        'BUY' => '买入',
        'SELL' => '卖出',
    ];

    public function index(Content $content)
    {
        return $content
            ->header('PmOrderIntent 管理')
            ->description('下单意图列表')
            ->breadcrumb(['text' => 'PmOrderIntent 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        return Grid::make(new PmOrderIntent(), function (Grid $grid) {
            $grid->model()->with(['copyTask', 'leaderTrade', 'member', 'order'])->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('member.address', '会员地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('copy_task_id', '跟单任务ID');
            $grid->column('leaderTrade.trade_id', 'Leader Trade')->limit(16);
            $grid->column('token_id', 'Token ID')->limit(16);
            $grid->column('side', '方向')->using(self::SIDE_MAP)->label([
                'BUY' => 'success',
                'SELL' => 'danger',
            ]);
            $grid->column('target_usdc', '目标金额')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('clamped_usdc', '限制后金额')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('status', '状态')->using(self::STATUS_MAP)->label([
                PmOrderIntent::STATUS_PENDING => 'warning',
                PmOrderIntent::STATUS_SUBMITTED => 'info',
                PmOrderIntent::STATUS_SKIPPED => 'secondary',
                PmOrderIntent::STATUS_FAILED => 'danger',
            ]);
            $grid->column('attempt_count', '尝试次数');
            $grid->column('order.status', '订单状态')->display(function ($value) {
                return $value === null ? '-' : $value;
            });
            $grid->column('created_at', '创建时间');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
                $actions->disableEdit();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->equal('copy_task_id', '跟单任务ID');
                $filter->equal('leader_trade_id', 'Leader Trade ID');
                $filter->equal('member_id', '会员ID');
                $filter->like('token_id', 'Token ID');
                $filter->equal('side', '方向')->select(self::SIDE_MAP);
                $filter->equal('status', '状态')->select(self::STATUS_MAP);
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->disableCreateButton();
            $grid->disableBatchDelete();
        });
    }

    protected function detail($id)
    {
        return Show::make($id, new PmOrderIntent(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('copy_task_id', '跟单任务ID');
            $show->field('leaderTrade.trade_id', 'Leader Trade ID');
            $show->field('member.address', '会员地址');
            $show->field('token_id', 'Token ID');
            $show->field('side', '方向')->using(self::SIDE_MAP);
            $show->field('leader_price', 'Leader 价格');
            $show->field('target_usdc', '目标金额')->as(fn ($value) => self::formatUsdc($value));
            $show->field('clamped_usdc', '限制后金额')->as(fn ($value) => self::formatUsdc($value));
            $show->field('status', '状态')->using(self::STATUS_MAP);
            $show->field('skip_reason', '跳过原因');
            $show->field('attempt_count', '尝试次数');
            $show->field('last_error_code', '错误码');
            $show->field('last_error_message', '错误信息');
            $show->field('risk_snapshot', '风险快照')->as(fn ($value) => $value ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : '-');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmOrderIntent(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('copy_task_id', '跟单任务ID');
            $form->display('leader_trade_id', 'Leader Trade ID');
            $form->display('member_id', '会员ID');
            $form->display('token_id', 'Token ID');
            $form->display('side', '方向');
            $form->display('leader_price', 'Leader 价格');
            $form->display('target_usdc', '目标金额');
            $form->display('clamped_usdc', '限制后金额');
            $form->display('status', '状态')->value(fn ($value) => self::STATUS_MAP[$value] ?? $value);
            $form->display('skip_reason', '跳过原因');
            $form->display('attempt_count', '尝试次数');
            $form->display('last_error_code', '错误码');
            $form->display('last_error_message', '错误信息');
            $form->display('created_at', '创建时间');
            $form->display('updated_at', '更新时间');

            $form->disableDeleteButton();
            $form->disableCreatingCheck();
            $form->disableEditingCheck();
            $form->disableViewCheck();
            $form->disableSubmitButton();
        });
    }

    private static function maskAddress(?string $value): string
    {
        if (! $value) {
            return '-';
        }

        return '<span class="badge badge-primary">'.substr($value, 0, 10).'...'.substr($value, -6).'</span>';
    }

    private static function formatUsdc($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format(((int) $value) / 1000000, 6).' USDC';
    }
}
