<?php

namespace App\Admin\Controllers;

use App\Models\Pm\PmLeader;
use App\Models\Pm\PmMember;
use App\Models\Pm\PmCopyTask;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Layout\Content;
use Dcat\Admin\Http\Controllers\AdminController;

class PmCopyTaskController extends AdminController
{
    private const STATUS_MAP = [
        1 => '启用',
        0 => '暂停',
    ];

    private const MODE_MAP = [
        PmCopyTask::MODE_LEADER_COPY => 'Leader跟单',
        PmCopyTask::MODE_TAIL_SWEEP => '扫尾盘',
    ];

    public function index(Content $content)
    {
        return $content
            ->header('PmCopyTask 管理')
            ->description('跟单任务列表')
            ->breadcrumb(['text' => 'PmCopyTask 管理', 'url' => ''])
            ->body($this->grid());
    }

    protected function grid()
    {
        return Grid::make(new PmCopyTask(), function (Grid $grid) {
            $grid->model()->with(['member', 'leader'])->withCount('orderIntents')->orderBy('id', 'desc');

            $grid->column('id', 'ID')->sortable();
            $grid->column('member.address', '会员地址')->display(fn ($value) => self::maskAddress($value));
            $grid->column('mode', '模式')->using(self::MODE_MAP)->label([
                PmCopyTask::MODE_LEADER_COPY => 'primary',
                PmCopyTask::MODE_TAIL_SWEEP => 'info',
            ]);
            $grid->column('leader.display_name', '带单员')->display(function ($value) {
                return $this->mode === PmCopyTask::MODE_LEADER_COPY ? ($value ?: '-') : '-';
            });
            $grid->column('market_slug', '市场')->display(fn ($value) => $value ?: '-');
            $grid->column('status', '状态')->using(self::STATUS_MAP)->label([
                1 => 'success',
                0 => 'warning',
            ]);
            $grid->column('ratio_bps', '跟单比例')->display(fn ($value) => bcdiv((string) $value, '100', 2).'%');
            $grid->column('tail_trigger_amount', '触发阈值')->display(fn ($value) => $value ?: '-');
            $grid->column('tail_time_limit_seconds', '限制时间')->display(fn ($value) => $value ? $value.' 秒' : '-');
            $grid->column('tail_loss_count', '已亏损')->display(fn ($value) => (string) $value);
            $grid->column('tail_loss_stop_count', '停单阈值')->display(fn ($value) => (string) $value);
            $grid->column('min_usdc', '最小金额')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('max_usdc', '最大金额')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('tail_order_usdc', '扫尾盘金额')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('daily_max_usdc', '每日限额')->display(fn ($value) => self::formatUsdc($value));
            $grid->column('max_slippage_bps', '最大滑点')->display(fn ($value) => bcdiv((string) $value, '100', 2).'%');
            $grid->column('allow_partial_fill', '允许部分成交')->bool();
            $grid->column('order_intents_count', '意图数')->badge('info');
            $grid->column('deleted_at', '删除时间');
            $grid->column('created_at', '创建时间');

            $grid->actions(function (Grid\Displayers\Actions $actions) {
                $actions->disableDelete();
            });

            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id', 'ID');
                $filter->equal('member_id', '会员')->select($this->memberOptions());
                $filter->equal('leader_id', '带单员')->select($this->leaderOptions());
                $filter->equal('mode', '模式')->select(self::MODE_MAP);
                $filter->equal('status', '状态')->select(self::STATUS_MAP);
                $filter->equal('allow_partial_fill', '允许部分成交')->select([1 => '是', 0 => '否']);
                $filter->between('created_at', '创建时间')->datetime();
            });

            $grid->disableBatchDelete();
        });
    }

    protected function detail($id)
    {
        return Show::make($id, new PmCopyTask(), function (Show $show) {
            $show->field('id', 'ID');
            $show->field('member.address', '会员地址');
            $show->field('mode', '模式')->using(self::MODE_MAP);
            $show->field('leader.display_name', '带单员');
            $show->field('leader.proxy_wallet', '带单钱包');
            $show->field('market_slug', '市场Slug');
            $show->field('market_id', '市场ID');
            $show->field('market_question', '市场标题');
            $show->field('market_symbol', '标的');
            $show->field('resolution_source', '结果源');
            $show->field('price_to_beat', 'Price to beat');
            $show->field('market_end_at', '结束时间');
            $show->field('token_yes_id', '上涨Token');
            $show->field('token_no_id', '下跌Token');
            $show->field('status', '状态')->using(self::STATUS_MAP);
            $show->field('ratio_bps', '跟单比例')->as(fn ($value) => bcdiv((string) $value, '100', 2).'%');
            $show->field('min_usdc', '最小金额')->as(fn ($value) => self::formatUsdc($value));
            $show->field('max_usdc', '最大金额')->as(fn ($value) => self::formatUsdc($value));
            $show->field('tail_order_usdc', '扫尾盘金额')->as(fn ($value) => self::formatUsdc($value));
            $show->field('tail_trigger_amount', '触发阈值');
            $show->field('tail_time_limit_seconds', '限制时间')->as(fn ($value) => $value ? $value.' 秒' : '-');
            $show->field('tail_loss_count', '已亏损单数');
            $show->field('tail_loss_stop_count', '亏损停单数');
            $show->field('tail_round_started_value', '本轮开始值');
            $show->field('tail_last_triggered_round_key', '最后触发轮次');
            $show->field('tail_loss_stopped_at', '停单时间');
            $show->field('daily_max_usdc', '每日限额')->as(fn ($value) => self::formatUsdc($value));
            $show->field('max_slippage_bps', '最大滑点')->as(fn ($value) => bcdiv((string) $value, '100', 2).'%');
            $show->field('allow_partial_fill', '允许部分成交')->as(fn ($value) => $value ? '是' : '否');
            $show->field('deleted_at', '删除时间');
            $show->field('created_at', '创建时间');
            $show->field('updated_at', '更新时间');

            $show->panel()->tools(function ($tools) {
                $tools->disableDelete();
            });
        });
    }

    protected function form()
    {
        return Form::make(new PmCopyTask(), function (Form $form) {
            $form->display('id', 'ID');
            $form->select('member_id', '会员')->options($this->memberOptions())->required();
            $form->select('mode', '模式')->options(self::MODE_MAP)->default(PmCopyTask::MODE_LEADER_COPY)->required();
            $form->select('leader_id', '带单员')->options($this->leaderOptions());
            $form->text('market_slug', '市场Slug');
            $form->text('market_id', '市场ID');
            $form->text('market_question', '市场标题');
            $form->text('market_symbol', '标的');
            $form->text('resolution_source', '结果源');
            $form->text('price_to_beat', 'Price to beat');
            $form->datetime('market_end_at', '结束时间');
            $form->text('token_yes_id', '上涨Token');
            $form->text('token_no_id', '下跌Token');
            $form->select('status', '状态')->options(self::STATUS_MAP)->required();
            $form->number('ratio_bps', '跟单比例(bps)')->min(0);
            $form->number('min_usdc', '最小金额(1e6)')->min(0);
            $form->number('max_usdc', '最大金额(1e6)')->min(0);
            $form->number('tail_order_usdc', '扫尾盘下单金额(1e6)')->min(0);
            $form->text('tail_trigger_amount', '触发阈值');
            $form->number('tail_time_limit_seconds', '限制时间(秒)')->min(1)->default(30);
            $form->number('tail_loss_stop_count', '亏损停单数')->min(0)->default(0);
            $form->display('tail_loss_count', '当前亏损单数');
            $form->display('tail_round_started_value', '本轮开始值');
            $form->display('tail_last_triggered_round_key', '最后触发轮次');
            $form->display('tail_loss_stopped_at', '停单时间');
            $form->number('daily_max_usdc', '每日限额(1e6)')->min(0);
            $form->number('max_slippage_bps', '最大滑点(bps)')->min(0);
            $form->switch('allow_partial_fill', '允许部分成交')->default(0);
            $form->display('deleted_at', '删除时间');
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

    private function leaderOptions(): array
    {
        return PmLeader::query()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (PmLeader $leader) => [$leader->id => ($leader->display_name ?: 'Leader#'.$leader->id).' | '.$leader->proxy_wallet])
            ->toArray();
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
