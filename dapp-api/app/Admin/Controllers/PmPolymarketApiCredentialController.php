<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmPolymarketApiCredential;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmPolymarketApiCredentialController extends AdminController
{
    public function index(Content $content)
    {
        return $content
            ->header('PmPolymarketApiCredential 管理')
            ->description('Polymarket API 凭证列表')
            ->breadcrumb(['text' => 'PmPolymarketApiCredential 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        return Grid::make(new PmPolymarketApiCredential(), function (Grid $grid) {
            $grid->model()->with('custodyWallet')->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('custody_wallet_id', '钱包ID');
            $grid->column('custodyWallet.signer_address', '签名地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('encryption_version', '加密版本');
            $grid->column('api_key_ciphertext', 'API Key')->display(fn ($value) => self::maskedSecret($value));
            $grid->column('api_secret_ciphertext', 'API Secret')->display(fn ($value) => self::maskedSecret($value));
            $grid->column('passphrase_ciphertext', 'Passphrase')->display(fn ($value) => self::maskedSecret($value));
            $grid->column('derived_at', '派生时间');
            $grid->column('last_validated_at', '最近校验');
            $grid->column('created_at', '创建时间');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
                $actions->disableEdit();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->equal('custody_wallet_id', '钱包ID');
                $filter->equal('encryption_version', '加密版本');
                $filter->between('derived_at', '派生时间')->datetime();
                $filter->between('last_validated_at', '最近校验')->datetime();
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->disableCreateButton();
            $grid->disableBatchDelete();
        });
    }

    protected function detail($id)
    {
        return Show::make($id, new PmPolymarketApiCredential(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('custody_wallet_id', '钱包ID');
            $show->field('custodyWallet.signer_address', '签名地址');
            $show->field('api_key_ciphertext', 'API Key')->as(fn ($value) => self::maskedSecret($value));
            $show->field('api_secret_ciphertext', 'API Secret')->as(fn ($value) => self::maskedSecret($value));
            $show->field('passphrase_ciphertext', 'Passphrase')->as(fn ($value) => self::maskedSecret($value));
            $show->field('encryption_version', '加密版本');
            $show->field('derived_at', '派生时间');
            $show->field('last_validated_at', '最近校验');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmPolymarketApiCredential(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('custody_wallet_id', '钱包ID');
            $form->display('api_key_ciphertext', 'API Key')->value('已脱敏');
            $form->display('api_secret_ciphertext', 'API Secret')->value('已脱敏');
            $form->display('passphrase_ciphertext', 'Passphrase')->value('已脱敏');
            $form->display('encryption_version', '加密版本');
            $form->display('derived_at', '派生时间');
            $form->display('last_validated_at', '最近校验');
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

    private static function maskedSecret(?string $value): string
    {
        if (! $value) {
            return '未配置';
        }

        $length = strlen($value);

        return '已配置('.$length.' chars)';
    }
}
