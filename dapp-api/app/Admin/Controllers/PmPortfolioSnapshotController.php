<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmPortfolioSnapshot;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmPortfolioSnapshotController extends AdminController
{
    public function index(Content $content)
    {
        return $content
            ->header('PmPortfolioSnapshot 管理')
            ->description('资产快照列表')
            ->breadcrumb(['text' => 'PmPortfolioSnapshot 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        return Grid::make(new PmPortfolioSnapshot(), function (Grid $grid) {
            $grid->model()->with('member')->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('member.address', '会员地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('available_usdc', '可用余额')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('equity_usdc', '权益')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('pnl_today_usdc', '今日盈亏')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('pnl_total_usdc', '累计盈亏')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('as_of', '快照时间');
            $grid->column('created_at', '创建时间');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
                $actions->disableEdit();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->equal('member_id', '会员ID');
                $filter->between('as_of', '快照时间')->datetime();
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->disableCreateButton();
            $grid->disableBatchDelete();
        });
    }

    protected function detail($id)
    {
        return Show::make($id, new PmPortfolioSnapshot(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('member.address', '会员地址');
            $show->field('available_usdc', '可用余额')->as(fn ($value) => self::formatUsdc($value));
            $show->field('equity_usdc', '权益')->as(fn ($value) => self::formatUsdc($value));
            $show->field('pnl_today_usdc', '今日盈亏')->as(fn ($value) => self::formatUsdc($value));
            $show->field('pnl_total_usdc', '累计盈亏')->as(fn ($value) => self::formatUsdc($value));
            $show->field('as_of', '快照时间');
            $show->field('raw', '原始数据')->as(fn ($value) => $value ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : '-');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmPortfolioSnapshot(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('member_id', '会员ID');
            $form->display('available_usdc', '可用余额');
            $form->display('equity_usdc', '权益');
            $form->display('pnl_today_usdc', '今日盈亏');
            $form->display('pnl_total_usdc', '累计盈亏');
            $form->display('as_of', '快照时间');
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
