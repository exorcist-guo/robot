<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmLeaderboardDailyStat;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmLeaderboardDailyStatController extends AdminController
{
    public function index(Content $content)
    {
        return $content
            ->header('PmLeaderboardDailyStat 管理')
            ->description('排行榜统计列表')
            ->breadcrumb(['text' => 'PmLeaderboardDailyStat 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        return Grid::make(new PmLeaderboardDailyStat(), function (Grid $grid) {
            $grid->model()->with(['leaderboardUser'])->orderBy('stat_date', 'desc')->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('leaderboard_user_id', '用户ID');
            $grid->column('leaderboardUser.address', '用户地址');
            $grid->column('stat_date', '统计日期')->sortable();

            $grid->column('day_total_orders', '日订单数');
            $grid->column('day_win_orders', '日赢单数');
            $grid->column('day_loss_orders', '日亏单数');
            $grid->column('day_win_rate_bps', '日胜率')->display(fn ($value) => self::formatPercentFromBps($value));
            $grid->column('day_invested_amount_usdc', '日投入')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('day_profit_amount_usdc', '日利润')->display(fn ($value) => self::formatSignedUsdc($value));

            $grid->column('week_total_orders', '周订单数');
            $grid->column('week_win_rate_bps', '周胜率')->display(fn ($value) => self::formatPercentFromBps($value));
            $grid->column('week_invested_amount_usdc', '周投入')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('week_profit_amount_usdc', '周利润')->display(fn ($value) => self::formatSignedUsdc($value));

            $grid->column('month_total_orders', '月订单数');
            $grid->column('month_win_rate_bps', '月胜率')->display(fn ($value) => self::formatPercentFromBps($value));
            $grid->column('month_invested_amount_usdc', '月投入')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('month_profit_amount_usdc', '月利润')->display(fn ($value) => self::formatSignedUsdc($value));

            $grid->column('all_total_orders', '总订单数');
            $grid->column('all_win_rate_bps', '总胜率')->display(fn ($value) => self::formatPercentFromBps($value));
            $grid->column('all_invested_amount_usdc', '总投入')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('all_profit_amount_usdc', '总利润')->display(fn ($value) => self::formatSignedUsdc($value));
            $grid->column('created_at', '创建时间');

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->equal('leaderboard_user_id', '用户ID');
                $filter->like('leaderboardUser.address', '用户地址');
                $filter->equal('stat_date', '统计日期')->date();
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
                $actions->disableEdit();
            });

            $grid->disableCreateButton();
            $grid->disableBatchDelete();
        });
    }

    protected function detail($id)
    {
        return Show::make($id, new PmLeaderboardDailyStat(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('leaderboard_user_id', '用户ID');
            $show->field('leaderboardUser.address', '用户地址');
            $show->field('stat_date', '统计日期');
            $show->field('day_total_orders', '日订单数');
            $show->field('day_win_orders', '日赢单数');
            $show->field('day_loss_orders', '日亏单数');
            $show->field('day_win_rate_bps', '日胜率')->as(fn ($value) => self::formatPercentFromBps($value));
            $show->field('day_invested_amount_usdc', '日投入')->as(fn ($value) => self::formatUsdc($value));
            $show->field('day_profit_amount_usdc', '日利润')->as(fn ($value) => self::formatSignedUsdc($value));
            $show->field('week_total_orders', '周订单数');
            $show->field('week_win_orders', '周赢单数');
            $show->field('week_loss_orders', '周亏单数');
            $show->field('week_win_rate_bps', '周胜率')->as(fn ($value) => self::formatPercentFromBps($value));
            $show->field('week_invested_amount_usdc', '周投入')->as(fn ($value) => self::formatUsdc($value));
            $show->field('week_profit_amount_usdc', '周利润')->as(fn ($value) => self::formatSignedUsdc($value));
            $show->field('month_total_orders', '月订单数');
            $show->field('month_win_orders', '月赢单数');
            $show->field('month_loss_orders', '月亏单数');
            $show->field('month_win_rate_bps', '月胜率')->as(fn ($value) => self::formatPercentFromBps($value));
            $show->field('month_invested_amount_usdc', '月投入')->as(fn ($value) => self::formatUsdc($value));
            $show->field('month_profit_amount_usdc', '月利润')->as(fn ($value) => self::formatSignedUsdc($value));
            $show->field('all_total_orders', '总订单数');
            $show->field('all_win_orders', '总赢单数');
            $show->field('all_loss_orders', '总亏单数');
            $show->field('all_win_rate_bps', '总胜率')->as(fn ($value) => self::formatPercentFromBps($value));
            $show->field('all_invested_amount_usdc', '总投入')->as(fn ($value) => self::formatUsdc($value));
            $show->field('all_profit_amount_usdc', '总利润')->as(fn ($value) => self::formatSignedUsdc($value));
            $show->field('raw', '原始统计')->as(fn ($value) => $value ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : '-');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmLeaderboardDailyStat(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('leaderboard_user_id', '用户ID');
            $form->display('stat_date', '统计日期');
            $form->display('day_total_orders', '日订单数');
            $form->display('day_win_orders', '日赢单数');
            $form->display('day_loss_orders', '日亏单数');
            $form->display('day_win_rate_bps', '日胜率(BPS)');
            $form->display('day_invested_amount_usdc', '日投入')->value(fn ($value) => self::formatUsdc($value));
            $form->display('day_profit_amount_usdc', '日利润')->value(fn ($value) => self::formatSignedUsdc($value));
            $form->display('week_total_orders', '周订单数');
            $form->display('week_win_rate_bps', '周胜率(BPS)');
            $form->display('week_invested_amount_usdc', '周投入')->value(fn ($value) => self::formatUsdc($value));
            $form->display('week_profit_amount_usdc', '周利润')->value(fn ($value) => self::formatSignedUsdc($value));
            $form->display('month_total_orders', '月订单数');
            $form->display('month_win_rate_bps', '月胜率(BPS)');
            $form->display('month_invested_amount_usdc', '月投入')->value(fn ($value) => self::formatUsdc($value));
            $form->display('month_profit_amount_usdc', '月利润')->value(fn ($value) => self::formatSignedUsdc($value));
            $form->display('all_total_orders', '总订单数');
            $form->display('all_win_rate_bps', '总胜率(BPS)');
            $form->display('all_invested_amount_usdc', '总投入')->value(fn ($value) => self::formatUsdc($value));
            $form->display('all_profit_amount_usdc', '总利润')->value(fn ($value) => self::formatSignedUsdc($value));
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

        return number_format(((int) $value) / 1000000, 0, '.', '').' USDC';
    }

    private static function formatSignedUsdc($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format(((int) $value) / 1000000, 0, '.', '').' USDC';
    }

    private static function formatPercentFromBps($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format(((int) $value) / 100, 2, '.', '').'%';
    }
}
