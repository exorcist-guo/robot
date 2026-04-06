<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmOrder;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmOrderController extends AdminController
{
    private const STATUS_MAP = [
        PmOrder::STATUS_NEW => '新建',
        PmOrder::STATUS_SUBMITTED => '已提交',
        PmOrder::STATUS_FILLED => '已成交',
        PmOrder::STATUS_PARTIAL => '部分成交',
        PmOrder::STATUS_CANCELED => '已取消',
        PmOrder::STATUS_REJECTED => '已拒绝',
        PmOrder::STATUS_ERROR => '错误',
    ];

    private const CLAIM_STATUS_MAP = [
        PmOrder::CLAIM_STATUS_NOT_NEEDED => '无需兑奖',
        PmOrder::CLAIM_STATUS_PENDING => '待兑奖',
        PmOrder::CLAIM_STATUS_CLAIMING => '兑奖中',
        PmOrder::CLAIM_STATUS_CLAIMED => '已兑奖',
        PmOrder::CLAIM_STATUS_FAILED => '兑奖失败',
        PmOrder::CLAIM_STATUS_SKIPPED => '已跳过',
    ];

    public function index(Content $content)
    {
        return $content
            ->header('PmOrder 管理')
            ->description('订单列表')
            ->breadcrumb(['text' => 'PmOrder 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        return Grid::make(new PmOrder(), function (Grid $grid) {
            $grid->model()->with(['intent.member'])->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('order_intent_id', '意图ID');
            $grid->column('intent.member.address', '会员地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('poly_order_id', 'Poly Order ID')->limit(18);
            $grid->column('exchange_nonce', 'Exchange Nonce');
            $grid->column('status', '状态')->using(self::STATUS_MAP)->label([
                PmOrder::STATUS_NEW => 'warning',
                PmOrder::STATUS_SUBMITTED => 'info',
                PmOrder::STATUS_FILLED => 'success',
                PmOrder::STATUS_PARTIAL => 'primary',
                PmOrder::STATUS_CANCELED => 'secondary',
                PmOrder::STATUS_REJECTED => 'danger',
                PmOrder::STATUS_ERROR => 'dark',
            ]);
            $grid->column('error_code', '错误码');
            $grid->column('failure_category', '失败分类');
            $grid->column('is_retryable', '可重试')->bool();
            $grid->column('retry_count', '重试次数');
            $grid->column('filled_usdc', '成交金额')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('avg_price', '均价');
            $grid->column('outcome', 'Outcome');
            $grid->column('order_type', '订单类型');
            $grid->column('is_settled', '已结算')->bool();
            $grid->column('is_win', '是否盈利')->bool();
            $grid->column('profit_usdc', '收益金额')->display(fn ($value) => self::formatSignedUsdc($value));
            $grid->column('roi_bps', '收益率(BPS)');
            $grid->column('claim_status', '兑奖状态')->using(self::CLAIM_STATUS_MAP)->label();
            $grid->column('submitted_at', '提交时间');
            $grid->column('last_sync_at', '同步时间');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
                $actions->disableEdit();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->equal('order_intent_id', '意图ID');
                $filter->like('poly_order_id', 'Poly Order ID');
                $filter->like('exchange_nonce', 'Exchange Nonce');
                $filter->equal('status', '状态')->select(self::STATUS_MAP);
                $filter->like('error_code', '错误码');
                $filter->like('failure_category', '失败分类');
                $filter->equal('is_retryable', '可重试')->select([1 => '是', 0 => '否']);
                $filter->equal('is_settled', '已结算')->select([1 => '是', 0 => '否']);
                $filter->equal('is_win', '是否盈利')->select([1 => '是', 0 => '否']);
                $filter->equal('claim_status', '兑奖状态')->select(self::CLAIM_STATUS_MAP);
                $filter->between('submitted_at', '提交时间')->datetime();
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->disableCreateButton();
            $grid->disableBatchDelete();
        });
    }

    protected function detail($id)
    {
        return Show::make($id, new PmOrder(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('order_intent_id', '意图ID');
            $show->field('intent.member.address', '会员地址');
            $show->field('poly_order_id', 'Poly Order ID');
            $show->field('exchange_nonce', 'Exchange Nonce');
            $show->field('status', '状态')->using(self::STATUS_MAP);
            $show->field('request_payload', '请求载荷')->as(fn ($value) => $value ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : '-');
            $show->field('response_payload', '响应载荷')->as(fn ($value) => $value ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : '-');
            $show->field('error_code', '错误码');
            $show->field('failure_category', '失败分类');
            $show->field('is_retryable', '可重试')->as(fn ($value) => $value ? '是' : '否');
            $show->field('retry_count', '重试次数');
            $show->field('error_message', '错误信息');
            $show->field('filled_usdc', '成交金额')->as(fn ($value) => self::formatUsdc($value));
            $show->field('avg_price', '均价');
            $show->field('original_size', '原始数量');
            $show->field('filled_size', '已成交数量');
            $show->field('order_price', '下单价格');
            $show->field('outcome', 'Outcome');
            $show->field('order_type', '订单类型');
            $show->field('remote_order_status', '远端状态');
            $show->field('is_settled', '已结算')->as(fn ($value) => $value ? '是' : '否');
            $show->field('winning_outcome', '胜出方向');
            $show->field('is_win', '是否盈利')->as(fn ($value) => $value === null ? '-' : ($value ? '是' : '否'));
            $show->field('profit_usdc', '收益金额')->as(fn ($value) => self::formatSignedUsdc($value));
            $show->field('roi_bps', '收益率(BPS)');
            $show->field('claim_status', '兑奖状态')->using(self::CLAIM_STATUS_MAP);
            $show->field('claim_tx_hash', '兑奖交易哈希');
            $show->field('submitted_at', '提交时间');
            $show->field('last_sync_at', '同步时间');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmOrder(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('order_intent_id', '意图ID');
            $form->display('poly_order_id', 'Poly Order ID');
            $form->display('exchange_nonce', 'Exchange Nonce');
            $form->display('status', '状态')->value(fn ($value) => self::STATUS_MAP[$value] ?? $value);
            $form->display('error_code', '错误码');
            $form->display('failure_category', '失败分类');
            $form->display('is_retryable', '可重试')->value(fn ($value) => $value ? '是' : '否');
            $form->display('retry_count', '重试次数');
            $form->display('error_message', '错误信息');
            $form->display('filled_usdc', '成交金额');
            $form->display('avg_price', '均价');
            $form->display('original_size', '原始数量');
            $form->display('filled_size', '已成交数量');
            $form->display('order_price', '下单价格');
            $form->display('outcome', 'Outcome');
            $form->display('order_type', '订单类型');
            $form->display('is_settled', '已结算')->value(fn ($value) => $value ? '是' : '否');
            $form->display('winning_outcome', '胜出方向');
            $form->display('is_win', '是否盈利')->value(fn ($value) => $value === null ? '-' : ($value ? '是' : '否'));
            $form->display('profit_usdc', '收益金额')->value(fn ($value) => self::formatSignedUsdc($value));
            $form->display('roi_bps', '收益率(BPS)');
            $form->display('claim_status', '兑奖状态')->value(fn ($value) => self::CLAIM_STATUS_MAP[$value] ?? $value);
            $form->display('claim_tx_hash', '兑奖交易哈希');
            $form->display('submitted_at', '提交时间');
            $form->display('last_sync_at', '同步时间');
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

    private static function formatSignedUsdc($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $amount = ((int) $value) / 1000000;
        return number_format($amount, 6).' USDC';
    }
}
