<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmCustodyTransferRequest;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmCustodyTransferRequestController extends AdminController
{
    private const STATUS_MAP = [
        PmCustodyTransferRequest::STATUS_DRAFT => '草稿',
        PmCustodyTransferRequest::STATUS_SIGNED => '已签名',
        PmCustodyTransferRequest::STATUS_SUBMITTED => '已提交',
        PmCustodyTransferRequest::STATUS_CONFIRMED => '已确认',
        PmCustodyTransferRequest::STATUS_FAILED => '失败',
        PmCustodyTransferRequest::STATUS_EXPIRED => '已过期',
    ];

    public function index(Content $content)
    {
        return $content
            ->header('PmCustodyTransferRequest 管理')
            ->description('托管转账请求列表')
            ->breadcrumb(['text' => 'PmCustodyTransferRequest 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        return Grid::make(new PmCustodyTransferRequest(), function (Grid $grid) {
            $grid->model()->with(['member', 'subWallet', 'masterWallet'])->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('member.address', '会员地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('subWallet.signer_address', '子钱包')->display(fn ($value) => self::maskAddress($value));
            $grid->column('masterWallet.signer_address', '主钱包')->display(fn ($value) => self::maskAddress($value));
            $grid->column('chain_id', '链ID');
            $grid->column('token_address', 'Token 地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('from_address', '转出地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('to_address', '转入地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('amount', '数量');
            $grid->column('action', '动作');
            $grid->column('tx_hash', '交易哈希')->display(fn ($value) => self::maskHash($value));
            $grid->column('status', '状态')->using(self::STATUS_MAP)->label([
                PmCustodyTransferRequest::STATUS_DRAFT => 'secondary',
                PmCustodyTransferRequest::STATUS_SIGNED => 'info',
                PmCustodyTransferRequest::STATUS_SUBMITTED => 'primary',
                PmCustodyTransferRequest::STATUS_CONFIRMED => 'success',
                PmCustodyTransferRequest::STATUS_FAILED => 'danger',
                PmCustodyTransferRequest::STATUS_EXPIRED => 'warning',
            ]);
            $grid->column('submitted_at', '提交时间');
            $grid->column('confirmed_at', '确认时间');
            $grid->column('created_at', '创建时间');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
                $actions->disableEdit();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->equal('member_id', '会员ID');
                $filter->equal('sub_wallet_id', '子钱包ID');
                $filter->equal('master_wallet_id', '主钱包ID');
                $filter->equal('chain_id', '链ID');
                $filter->like('tx_hash', '交易哈希');
                $filter->like('action', '动作');
                $filter->equal('status', '状态')->select(self::STATUS_MAP);
                $filter->between('submitted_at', '提交时间')->datetime();
                $filter->between('confirmed_at', '确认时间')->datetime();
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->disableCreateButton();
            $grid->disableBatchDelete();
        });
    }

    protected function detail($id)
    {
        return Show::make($id, new PmCustodyTransferRequest(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('member.address', '会员地址');
            $show->field('subWallet.signer_address', '子钱包地址');
            $show->field('masterWallet.signer_address', '主钱包地址');
            $show->field('chain_id', '链ID');
            $show->field('token_address', 'Token 地址');
            $show->field('from_address', '转出地址');
            $show->field('to_address', '转入地址');
            $show->field('amount', '数量');
            $show->field('nonce', 'Nonce');
            $show->field('deadline_at', '截止时间')->as(fn ($value) => $value ? date('Y-m-d H:i:s', (int) $value) : '-');
            $show->field('action', '动作');
            $show->field('signature_payload_hash', '签名载荷哈希')->as(fn ($value) => self::maskHash($value));
            $show->field('signature', '签名')->as(fn () => '已隐藏');
            $show->field('tx_hash', '交易哈希');
            $show->field('status', '状态')->using(self::STATUS_MAP);
            $show->field('failure_reason', '失败原因');
            $show->field('raw_request_json', '请求报文')->as(fn () => '受限查看');
            $show->field('raw_response_json', '响应报文')->as(fn () => '受限查看');
            $show->field('submitted_at', '提交时间');
            $show->field('confirmed_at', '确认时间');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmCustodyTransferRequest(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('member_id', '会员ID');
            $form->display('sub_wallet_id', '子钱包ID');
            $form->display('master_wallet_id', '主钱包ID');
            $form->display('chain_id', '链ID');
            $form->display('token_address', 'Token 地址');
            $form->display('from_address', '转出地址');
            $form->display('to_address', '转入地址');
            $form->display('amount', '数量');
            $form->display('nonce', 'Nonce');
            $form->display('deadline_at', '截止时间')->value(fn ($value) => $value ? date('Y-m-d H:i:s', (int) $value) : '-');
            $form->display('action', '动作');
            $form->display('signature_payload_hash', '签名载荷哈希');
            $form->display('signature', '签名')->value('已隐藏');
            $form->display('tx_hash', '交易哈希');
            $form->display('status', '状态')->value(fn ($value) => self::STATUS_MAP[$value] ?? $value);
            $form->display('failure_reason', '失败原因');
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

    private static function maskHash(?string $value): string
    {
        if (! $value) {
            return '-';
        }

        return substr($value, 0, 12).'...'.substr($value, -6);
    }
}
