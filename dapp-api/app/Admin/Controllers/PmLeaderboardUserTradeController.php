<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmLeaderboardUserTrade;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmLeaderboardUserTradeController extends AdminController
{
    public function index(Content $content)
    {
        return $content
            ->header('PmLeaderboardUserTrade 管理')
            ->description('排行榜用户列表')
            ->breadcrumb(['text' => 'PmLeaderboardUserTrade 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        return Grid::make(new PmLeaderboardUserTrade(), function (Grid $grid) {
            $grid->model()->with(['leaderboardUser'])->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('leaderboard_user_id', '用户ID');
            $grid->column('leaderboardUser.address', '用户地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('external_position_id', '外部记录ID')->limit(20);
            $grid->column('title', '标题')->limit(30);
            $grid->column('slug', 'Slug')->limit(24);
            $grid->column('outcome', 'Outcome');
            $grid->column('avg_price', '均价')->display(fn ($value) => self::formatPrice($value));
            $grid->column('price', '现价/收盘价')->display(fn ($value) => self::formatPrice($value));
            $grid->column('size', '数量')->display(fn ($value) => self::formatSize($value));
            $grid->column('invested_amount_usdc', '投入')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('pnl_amount_usdc', '盈亏')->display(fn ($value) => self::formatSignedUsdc($value));
            $grid->column('profit_amount_usdc', '盈利')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('loss_amount_usdc', '亏损')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('is_win', '赢单')->bool();
            $grid->column('pnl_status', '盈亏状态')->label();
            $grid->column('pnl_ratio_bps', '收益率(BPS)');
            $grid->column('traded_at', '成交时间');
            $grid->column('settled_at', '结算时间');

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->equal('leaderboard_user_id', '用户ID');
                $filter->like('leaderboardUser.address', '用户地址');
                $filter->like('external_position_id', '外部记录ID');
                $filter->like('slug', 'Slug');
                $filter->like('title', '标题');
                $filter->equal('is_win', '赢单')->select([1 => '是', 0 => '否']);
                $filter->equal('pnl_status', '盈亏状态')->select([
                    'profit' => '盈利',
                    'loss' => '亏损',
                    'flat' => '持平',
                ]);
                $filter->between('traded_at', '成交时间')->datetime();
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
        return Show::make($id, new PmLeaderboardUserTrade(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('leaderboard_user_id', '用户ID');
            $show->field('leaderboardUser.address', '用户地址');
            $show->field('external_position_id', '外部记录ID');
            $show->field('market_id', '市场ID');
            $show->field('token_id', 'Token ID');
            $show->field('title', '标题');
            $show->field('slug', 'Slug');
            $show->field('outcome', 'Outcome');
            $show->field('opposite_outcome', '对手 Outcome');
            $show->field('avg_price', '均价')->as(fn ($value) => self::formatPrice($value));
            $show->field('price', '现价/收盘价')->as(fn ($value) => self::formatPrice($value));
            $show->field('size', '数量')->as(fn ($value) => self::formatSize($value));
            $show->field('invested_amount_usdc', '投入')->as(fn ($value) => self::formatUsdc($value));
            $show->field('pnl_amount_usdc', '盈亏')->as(fn ($value) => self::formatSignedUsdc($value));
            $show->field('profit_amount_usdc', '盈利')->as(fn ($value) => self::formatUsdc($value));
            $show->field('loss_amount_usdc', '亏损')->as(fn ($value) => self::formatUsdc($value));
            $show->field('is_win', '赢单')->as(fn ($value) => $value === null ? '-' : ($value ? '是' : '否'));
            $show->field('pnl_status', '盈亏状态');
            $show->field('pnl_ratio_bps', '收益率(BPS)');
            $show->field('order_status', '订单状态');
            $show->field('is_settled', '已结算')->as(fn ($value) => $value ? '是' : '否');
            $show->field('traded_at', '成交时间');
            $show->field('settled_at', '结算时间');
            $show->field('last_synced_at', '同步时间');
            $show->field('raw', '原始数据')->as(fn ($value) => $value ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : '-');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmLeaderboardUserTrade(), function (Form $form) {
            $form->display('id', 'ID');
            $form->display('leaderboard_user_id', '用户ID');
            $form->display('external_position_id', '外部记录ID');
            $form->display('title', '标题');
            $form->display('slug', 'Slug');
            $form->display('outcome', 'Outcome');
            $form->display('avg_price', '均价')->value(fn ($value) => self::formatPrice($value));
            $form->display('price', '现价/收盘价')->value(fn ($value) => self::formatPrice($value));
            $form->display('size', '数量')->value(fn ($value) => self::formatSize($value));
            $form->display('invested_amount_usdc', '投入')->value(fn ($value) => self::formatUsdc($value));
            $form->display('pnl_amount_usdc', '盈亏')->value(fn ($value) => self::formatSignedUsdc($value));
            $form->display('profit_amount_usdc', '盈利')->value(fn ($value) => self::formatUsdc($value));
            $form->display('loss_amount_usdc', '亏损')->value(fn ($value) => self::formatUsdc($value));
            $form->display('is_win', '赢单')->value(fn ($value) => $value === null ? '-' : ($value ? '是' : '否'));
            $form->display('pnl_status', '盈亏状态');
            $form->display('pnl_ratio_bps', '收益率(BPS)');
            $form->display('traded_at', '成交时间');
            $form->display('settled_at', '结算时间');

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

    private static function formatPrice($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 4, '.', '');
    }

    private static function formatSize($value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2, '.', '');
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
}
