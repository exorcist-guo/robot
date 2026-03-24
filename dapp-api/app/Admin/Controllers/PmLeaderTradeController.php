<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmLeaderTrade;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmLeaderTradeController extends AdminController
{
    private const SIDE_MAP = [
        'BUY' => '买入',
        'SELL' => '卖出',
    ];

    public function index(Content $content)
    {
        return $content
            ->header('PmLeaderTrade 管理')
            ->description('带单成交列表')
            ->breadcrumb(['text' => 'PmLeaderTrade 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        return Grid::make(new PmLeaderTrade(), function (Grid $grid) {
            $grid->model()->with('leader')->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('leader.display_name', '带单员')->display(function ($value) {
                return $value ?: '-';
            });
            $grid->column('trade_id', 'Trade ID')->limit(20);
            $grid->column('market_id', 'Market ID')->limit(16);
            $grid->column('token_id', 'Token ID')->limit(16);
            $grid->column('side', '方向')->using(self::SIDE_MAP)->label([
                'BUY' => 'success',
                'SELL' => 'danger',
            ]);
            $grid->column('price', '价格');
            $grid->column('size_usdc', '成交金额')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('traded_at', '成交时间')->display(fn ($value) => $value ? date('Y-m-d H:i:s', (int) $value) : '-');
            $grid->column('created_at', '创建时间');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
                $actions->disableEdit();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->equal('leader_id', '带单员ID');
                $filter->like('trade_id', 'Trade ID');
                $filter->like('market_id', 'Market ID');
                $filter->like('token_id', 'Token ID');
                $filter->equal('side', '方向')->select(self::SIDE_MAP);
                $filter->between('traded_at', '成交时间')->datetime();
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->disableCreateButton();
            $grid->disableBatchDelete();
        });
    }

    protected function detail($id)
    {
        return Show::make($id, new PmLeaderTrade(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('leader.display_name', '带单员');
            $show->field('leader.proxy_wallet', '带单钱包');
            $show->field('trade_id', 'Trade ID');
            $show->field('market_id', 'Market ID');
            $show->field('token_id', 'Token ID');
            $show->field('side', '方向')->using(self::SIDE_MAP);
            $show->field('price', '价格');
            $show->field('size_usdc', '成交金额')->as(fn ($value) => self::formatUsdc($value));
            $show->field('raw', '原始数据')->as(fn ($value) => $value ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : '-');
            $show->field('traded_at', '成交时间')->as(fn ($value) => $value ? date('Y-m-d H:i:s', (int) $value) : '-');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmLeaderTrade(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('leader_id', '带单员ID');
            $form->display('trade_id', 'Trade ID');
            $form->display('market_id', 'Market ID');
            $form->display('token_id', 'Token ID');
            $form->display('side', '方向');
            $form->display('price', '价格');
            $form->display('size_usdc', '成交金额');
            $form->display('traded_at', '成交时间')->value(fn ($value) => $value ? date('Y-m-d H:i:s', (int) $value) : '-');
            $form->display('created_at', '创建时间');
            $form->display('updated_at', '更新时间');

            $form->disableDeleteButton();
            $form->disableCreatingCheck();
            $form->disableEditingCheck();
            $form->disableViewCheck();
            $form->disableSubmitButton();
        });
    }

    private static function formatUsdc($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format(((int) $value) / 1000000, 6).' USDC';
    }
}
