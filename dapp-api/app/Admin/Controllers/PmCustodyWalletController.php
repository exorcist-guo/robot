<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmMember;
use App\Models\Pm\PmCustodyWallet;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmCustodyWalletController extends AdminController
{
    private const ROLE_MAP = [
        PmCustodyWallet::ROLE_MASTER => '主钱包',
        PmCustodyWallet::ROLE_SUB => '子钱包',
    ];

    private const STATUS_MAP = [
        PmCustodyWallet::STATUS_ENABLED => '启用',
        PmCustodyWallet::STATUS_DISABLED => '锁定',
    ];

    private const SIGNATURE_TYPE_MAP = [
        0 => 'EOA',
        1 => 'ProxyEmail',
        2 => 'ProxyWallet/Safe',
    ];

    public function index(Content $content)
    {
        return $content
            ->header('PmCustodyWallet 管理')
            ->description('托管钱包列表')
            ->breadcrumb(['text' => 'PmCustodyWallet 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        return Grid::make(new PmCustodyWallet(), function (Grid $grid) {
            $grid->model()->with(['member', 'parentWallet', 'apiCredentials'])->withCount('subWallets')->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('member.address', '会员地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('address', '登录地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('wallet_role', '钱包角色')->using(self::ROLE_MAP)->label([
                PmCustodyWallet::ROLE_MASTER => 'success',
                PmCustodyWallet::ROLE_SUB => 'info',
            ]);
            $grid->column('parentWallet.signer_address', '父钱包')->display(fn ($value) => self::maskAddress($value));
            $grid->column('purpose', '用途')->limit(20);
            $grid->column('signer_address', '签名地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('funder_address', '资金地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('signature_type', '签名类型')->using(self::SIGNATURE_TYPE_MAP)->badge('secondary');
            $grid->column('exchange_nonce', 'Exchange Nonce');
            $grid->column('status', '状态')->using(self::STATUS_MAP)->label([
                PmCustodyWallet::STATUS_ENABLED => 'success',
                PmCustodyWallet::STATUS_DISABLED => 'danger',
            ]);
            $grid->column('apiCredentials.id', '凭证状态')->display(function ($value) {
                return $value ? '<span class="badge badge-success">已配置</span>' : '<span class="badge badge-secondary">未配置</span>';
            });
            $grid->column('sub_wallets_count', '子钱包数')->badge('info');
            $grid->column('created_at', '创建时间');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->equal('member_id', '会员')->select($this->memberOptions());
                $filter->equal('wallet_role', '钱包角色')->select(self::ROLE_MAP);
                $filter->like('signer_address', '签名地址');
                $filter->like('funder_address', '资金地址');
                $filter->equal('signature_type', '签名类型')->select(self::SIGNATURE_TYPE_MAP);
                $filter->equal('status', '状态')->select(self::STATUS_MAP);
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->disableBatchDelete();
        });
    }

    protected function detail($id)
    {
        return Show::make($id, new PmCustodyWallet(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('member.address', '会员地址');
            $show->field('address', '登录地址');
            $show->field('wallet_role', '钱包角色')->using(self::ROLE_MAP);
            $show->field('parentWallet.signer_address', '父钱包地址');
            $show->field('purpose', '用途');
            $show->field('signer_address', '签名地址');
            $show->field('funder_address', '资金地址');
            $show->field('en_private_key', '私钥密文')->as(fn () => '已隐藏');
            $show->field('encryption_version', '加密版本');
            $show->field('signature_type', '签名类型')->using(self::SIGNATURE_TYPE_MAP);
            $show->field('exchange_nonce', 'Exchange Nonce');
            $show->field('status', '状态')->using(self::STATUS_MAP);
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmCustodyWallet(), function (Form $form) {
            $form->display('id', 'ID');
            $form->select('member_id', '会员')->options($this->memberOptions())->required();
            $form->text('address', '登录地址');
            $form->select('wallet_role', '钱包角色')->options(self::ROLE_MAP)->required();
            $form->select('parent_wallet_id', '父钱包')->options($this->walletOptions());
            $form->text('purpose', '用途');
            $form->text('signer_address', '签名地址')->required();
            $form->text('funder_address', '资金地址');
            $form->display('en_private_key', '私钥密文')->value('已隐藏');
            $form->display('encryption_version', '加密版本');
            $form->select('signature_type', '签名类型')->options(self::SIGNATURE_TYPE_MAP)->required();
            $form->text('exchange_nonce', 'Exchange Nonce');
            $form->select('status', '状态')->options(self::STATUS_MAP)->required();
            $form->display('created_at', '创建时间');
            $form->display('updated_at', '更新时间');

            $form->disableDeleteButton();
            $form->disableCreatingCheck();
            $form->disableEditingCheck();
            $form->disableViewCheck();
        });
    }

    private function memberOptions(): array
    {
        return PmMember::query()->orderBy('id')->pluck('address', 'id')->toArray();
    }

    private function walletOptions(): array
    {
        return PmCustodyWallet::query()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (PmCustodyWallet $wallet) => [$wallet->id => '#'.$wallet->id.' '.$wallet->signer_address])
            ->toArray();
    }

    private static function maskAddress(?string $value): string
    {
        if (! $value) {
            return '-';
        }

        return '<span class="badge badge-primary">'.substr($value, 0, 10).'...'.substr($value, -6).'</span>';
    }
}
