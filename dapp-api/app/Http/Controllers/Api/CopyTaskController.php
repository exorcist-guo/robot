<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmLeader;
use App\Models\Pm\PmMember;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class CopyTaskController extends Controller
{
    use ApiResponseTrait;

    private function currentMember(Request $request): PmMember
    {
        /** @var PmMember $user */
        $user = $request->user();
        return $user;
    }

    public function index(Request $request)
    {
        $member = $this->currentMember($request);

        $list = PmCopyTask::query()
            ->select([
                'id',
                'leader_id',
                'status',
                'mode',
                'ratio_bps',
                'min_usdc',
                'max_usdc',
                'max_slippage_bps',
                'allow_partial_fill',
                'daily_max_usdc',
                'maker_max_quantity_per_token',
                'tail_order_usdc',
                'tail_trigger_amount',
                'tail_time_limit_seconds',
                'tail_loss_stop_count',
                'tail_loss_count',
                'tail_price_time_config',
                'market_slug',
                'market_id',
                'market_question',
                'market_symbol',
                'resolution_source',
                'price_to_beat',
                'market_end_at',
                'token_yes_id',
                'token_no_id',
                'created_at',
            ])
            ->with(['leader:id,proxy_wallet,display_name,avatar_url'])
            ->where('member_id', $member->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (PmCopyTask $t) => [
                'id' => $t->id,
                'status' => $t->status,
                'mode' => $t->mode,
                'ratio_bps' => $t->ratio_bps,
                'min_usdc' => $t->min_usdc / 1000000,
                'max_usdc' => $t->max_usdc / 1000000,
                'max_slippage_bps' => $t->max_slippage_bps,
                'allow_partial_fill' => (bool) $t->allow_partial_fill,
                'daily_max_usdc' => $t->daily_max_usdc === null ? null : ($t->daily_max_usdc / 1000000),
                'maker_max_quantity_per_token' => $t->maker_max_quantity_per_token,
                'tail_order_usdc' => $t->tail_order_usdc / 1000000,
                'tail_trigger_amount' => $t->tail_trigger_amount,
                'tail_time_limit_seconds' => $t->tail_time_limit_seconds,
                'tail_loss_stop_count' => $t->tail_loss_stop_count,
                'tail_loss_count' => $t->tail_loss_count,
                'tail_price_time_config' => $t->tail_price_time_config,
                'market' => [
                    'slug' => $t->market_slug,
                    'market_id' => $t->market_id,
                    'question' => $t->market_question,
                    'symbol' => $t->market_symbol,
                    'resolution_source' => $t->resolution_source,
                    'price_to_beat' => $t->price_to_beat,
                    'end_at' => $t->market_end_at?->toDateTimeString(),
                    'token_yes_id' => $t->token_yes_id,
                    'token_no_id' => $t->token_no_id,
                ],
                'leader' => $t->leader_id ? [
                    'id' => $t->leader->id,
                    'proxy_wallet' => $t->leader->proxy_wallet,
                    'display_name' => $t->leader->display_name,
                    'avatar_url' => $t->leader->avatar_url,
                ] : null,
                'created_at' => $t->created_at?->toDateTimeString(),
            ]);

        return $this->success('ok', ['list' => $list]);
    }

    public function store(Request $request)
    {
        $member = $this->currentMember($request);
        $mode = (string) $request->input('mode', PmCopyTask::MODE_LEADER_COPY);

        if ($mode === PmCopyTask::MODE_TAIL_SWEEP || $mode === PmCopyTask::MODE_TAIL_SWEEP_MANY) {
            return $this->storeTailSweep($request, $member);
        }

        return $this->storeLeaderCopy($request, $member);
    }

    public function update(Request $request, int $id)
    {
        $member = $this->currentMember($request);
        $task = $this->findOwnedTask($member, $id);
        if (!$task) {
            return $this->error('任务不存在');
        }

        if ($task->mode === PmCopyTask::MODE_TAIL_SWEEP || $task->mode === PmCopyTask::MODE_TAIL_SWEEP_MANY) {
            return $this->updateTailSweep($request, $task);
        }

        $fields = [];
        foreach (['ratio_bps', 'min_usdc', 'max_usdc', 'maker_max_quantity_per_token'] as $k) {
            if ($request->has($k)) {
                $fields[$k] = $request->input($k);
            }
        }

        if (isset($fields['ratio_bps'])) {
            $ratioBps = (int) $fields['ratio_bps'];
            if ($ratioBps <= 0 || $ratioBps > 1000000) {
                return $this->error('ratio_bps 不合法');
            }
            $fields['ratio_bps'] = $ratioBps;
        }
        if (isset($fields['min_usdc'])) {
            $fields['min_usdc'] = max(0, (int) round(((float) $fields['min_usdc']) * 1000000));
        }
        if (isset($fields['max_usdc'])) {
            $fields['max_usdc'] = max(0, (int) round(((float) $fields['max_usdc']) * 1000000));
        }
        if (isset($fields['maker_max_quantity_per_token'])) {
            $value = trim((string) $fields['maker_max_quantity_per_token']);
            if ($value === '') {
                $fields['maker_max_quantity_per_token'] = null;
            } elseif (!preg_match('/^\d+(\.\d+)?$/', $value)) {
                return $this->error('maker_max_quantity_per_token 不合法');
            } else {
                $fields['maker_max_quantity_per_token'] = $value;
            }
        }

        $task->fill(array_merge($fields, $this->normalizeCommonRiskFieldsForPatch($request, true)));
        $task->save();

        return $this->success('ok');
    }

    public function pause(Request $request, int $id)
    {
        $member = $this->currentMember($request);
        $task = $this->findOwnedTask($member, $id);
        if (!$task) {
            return $this->error('任务不存在');
        }

        $task->status = 0;
        $task->save();

        return $this->success('ok');
    }

    public function resume(Request $request, int $id)
    {
        $member = $this->currentMember($request);
        $task = $this->findOwnedTask($member, $id);
        if (!$task) {
            return $this->error('任务不存在');
        }

        $task->status = 1;
        $task->save();

        return $this->success('ok');
    }

    public function destroy(Request $request, int $id)
    {
        $member = $this->currentMember($request);
        $task = $this->findOwnedTask($member, $id);
        if (!$task) {
            return $this->error('任务不存在');
        }

        $task->delete();

        return $this->success('删除成功');
    }

    private function storeLeaderCopy(Request $request, PmMember $member)
    {
        $leaderId = (int) $request->input('leader_id', 0);
        $ratioBps = (int) $request->input('ratio_bps', 10000);
        $minUsdc = max(0, (int) round(((float) $request->input('min_usdc', 0)) * 1000000));
        $maxUsdc = max(0, (int) round(((float) $request->input('max_usdc', 0)) * 1000000));
        $makerMaxQuantityPerToken = trim((string) $request->input('maker_max_quantity_per_token', ''));

        if ($leaderId <= 0) {
            return $this->error('leader_id 必填');
        }
        $leader = PmLeader::find($leaderId);
        if (!$leader) {
            return $this->error('leader 不存在');
        }
        if ($ratioBps <= 0 || $ratioBps > 1000000) {
            return $this->error('ratio_bps 不合法');
        }
        if ($makerMaxQuantityPerToken !== '' && !preg_match('/^\d+(\.\d+)?$/', $makerMaxQuantityPerToken)) {
            return $this->error('maker_max_quantity_per_token 不合法');
        }

        $payload = array_merge($this->normalizeCommonRiskFieldsForCreate($request, false), [
            'mode' => PmCopyTask::MODE_LEADER_COPY,
            'status' => 1,
            'ratio_bps' => $ratioBps,
            'min_usdc' => max(0, $minUsdc),
            'max_usdc' => max(0, $maxUsdc),
            'maker_max_quantity_per_token' => $makerMaxQuantityPerToken !== '' ? $makerMaxQuantityPerToken : null,
        ]);

        $task = PmCopyTask::withTrashed()
            ->where('member_id', $member->id)
            ->where('mode', PmCopyTask::MODE_LEADER_COPY)
            ->where('leader_id', $leader->id)
            ->first();

        if ($task) {
            if ($task->trashed()) {
                $task->restore();
            }
            $task->fill($payload);
            $task->save();
        } else {
            $task = PmCopyTask::create([
                'member_id' => $member->id,
                'leader_id' => $leader->id,
                ...$payload,
            ]);
        }

        return $this->success('ok', ['id' => $task->id]);
    }

    private function storeTailSweep(Request $request, PmMember $member)
    {
        $mode = (string) $request->input('mode', PmCopyTask::MODE_TAIL_SWEEP);
        $marketSlug = trim((string) $request->input('market_slug', ''));
        // 保留完整的 slug（包含时间戳），每次创建新任务
        // $marketSlug = preg_replace('/-\d{10}$/', '', $marketSlug) ?: $marketSlug;
        $marketId = trim((string) $request->input('market_id', ''));
        $tokenYesId = trim((string) $request->input('token_yes_id', ''));
        $tokenNoId = trim((string) $request->input('token_no_id', ''));
        $priceToBeat = trim((string) $request->input('price_to_beat', ''));
        $tailOrderUsdc = max(0, (int) ($request->input('tail_order_usdc', 0) * 1000000));
        $tailTriggerAmount = trim((string) $request->input('tail_trigger_amount', '0'));
        $tailTimeLimitSeconds = max(1, (int) $request->input('tail_time_limit_seconds', 30));
        $tailLossStopCount = max(0, (int) $request->input('tail_loss_stop_count', 0));
        $tailPriceTimeConfig = $request->input('tail_price_time_config');

        if ($marketSlug === '' || $marketId === '') {
            return $this->error('market 信息必填');
        }
        if ($tokenYesId === '' || $tokenNoId === '') {
            return $this->error('token 信息不完整');
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $priceToBeat)) {
            return $this->error('price_to_beat 不合法');
        }
        if (!preg_match('/^\d+(\.\d+)?$/', $tailTriggerAmount) || bccomp($tailTriggerAmount, '0', 8) <= 0) {
            return $this->error('tail_trigger_amount 不合法');
        }
        if ($tailOrderUsdc <= 0) {
            return $this->error('tail_order_usdc 必须大于 0');
        }

        $payload = array_merge($this->normalizeCommonRiskFieldsForCreate($request, true), [
            'mode' => $mode,
            'leader_id' => null,
            'status' => 1,
            'market_slug' => $marketSlug,
            'market_id' => $marketId,
            'market_question' => (string) $request->input('market_question', ''),
            'market_symbol' => (string) $request->input('market_symbol', 'btc/usd'),
            'resolution_source' => (string) $request->input('resolution_source', ''),
            'token_yes_id' => $tokenYesId,
            'token_no_id' => $tokenNoId,
            'price_to_beat' => $priceToBeat,
            'market_end_at' => $request->input('market_end_at'),
            'tail_order_usdc' => $tailOrderUsdc,
            'tail_trigger_amount' => $tailTriggerAmount,
            'tail_time_limit_seconds' => $tailTimeLimitSeconds,
            'tail_loss_stop_count' => $tailLossStopCount,
            'tail_price_time_config' => is_array($tailPriceTimeConfig) && $tailPriceTimeConfig !== [] ? $tailPriceTimeConfig : null,
        ]);

        // 直接创建新任务，不复用已有任务
        $task = PmCopyTask::create([
            'member_id' => $member->id,
            ...$payload,
        ]);

        return $this->success('ok', ['id' => $task->id]);
    }

    private function updateTailSweep(Request $request, PmCopyTask $task)
    {
        $fields = [];
        foreach (['tail_order_usdc', 'tail_trigger_amount', 'tail_time_limit_seconds', 'tail_loss_stop_count', 'tail_price_time_config'] as $k) {
            if ($request->has($k)) {
                $fields[$k] = $request->input($k);
            }
        }

        if (isset($fields['tail_order_usdc'])) {
            $fields['tail_order_usdc'] = max(0, (int) ($fields['tail_order_usdc'] * 1000000));
            if ($fields['tail_order_usdc'] <= 0) {
                return $this->error('tail_order_usdc 必须大于 0');
            }
        }
        if (isset($fields['tail_trigger_amount'])) {
            $value = trim((string) $fields['tail_trigger_amount']);
            if (!preg_match('/^\d+(\.\d+)?$/', $value) || bccomp($value, '0', 8) <= 0) {
                return $this->error('tail_trigger_amount 不合法');
            }
            $fields['tail_trigger_amount'] = $value;
        }
        if (isset($fields['tail_time_limit_seconds'])) {
            $fields['tail_time_limit_seconds'] = max(1, (int) $fields['tail_time_limit_seconds']);
        }
        if (isset($fields['tail_loss_stop_count'])) {
            $fields['tail_loss_stop_count'] = max(0, (int) $fields['tail_loss_stop_count']);
        }
        if (isset($fields['tail_price_time_config'])) {
            $config = $fields['tail_price_time_config'];
            if ($config === null || $config === '' || $config === []) {
                $fields['tail_price_time_config'] = null;
            } elseif (is_array($config)) {
                $fields['tail_price_time_config'] = $config;
            } else {
                return $this->error('tail_price_time_config 格式不正确');
            }
        }

        $task->fill(array_merge($fields, $this->normalizeCommonRiskFieldsForPatch($request, true)));
        $task->save();

        return $this->success('ok');
    }

    private function normalizeCommonRiskFieldsForCreate(Request $request, bool $allowNullDailyMaxUsdc = false): array
    {
        return $this->normalizeCommonRiskFields($request, $allowNullDailyMaxUsdc, false);
    }

    private function normalizeCommonRiskFieldsForPatch(Request $request, bool $allowNullDailyMaxUsdc = false): array
    {
        return $this->normalizeCommonRiskFields($request, $allowNullDailyMaxUsdc, true);
    }

    private function normalizeCommonRiskFields(
        Request $request,
        bool $allowNullDailyMaxUsdc = false,
        bool $preserveWhenMissing = false
    ): array {
        $fields = [];

        if (!$preserveWhenMissing || $request->has('max_slippage_bps')) {
            $fields['max_slippage_bps'] = max(0, (int) $request->input('max_slippage_bps', 50));
        }

        if (!$preserveWhenMissing || $request->has('allow_partial_fill')) {
            $fields['allow_partial_fill'] = (bool) $request->input('allow_partial_fill', true);
        }

        if ($preserveWhenMissing && !$request->has('daily_max_usdc') && !$request->has('maker_max_quantity_per_token')) {
            return $fields;
        }

        $dailyMaxUsdc = $request->input('daily_max_usdc');
        if ($allowNullDailyMaxUsdc && ($dailyMaxUsdc === null || $dailyMaxUsdc === '')) {
            $dailyMaxUsdc = null;
        } else {
            $dailyMaxUsdc = max(0, (int) round(((float) $dailyMaxUsdc) * 1000000));
        }

        $fields['daily_max_usdc'] = $dailyMaxUsdc;

        if (!$preserveWhenMissing || $request->has('maker_max_quantity_per_token')) {
            $makerMaxQuantityPerToken = trim((string) $request->input('maker_max_quantity_per_token', ''));
            if ($makerMaxQuantityPerToken === '') {
                $fields['maker_max_quantity_per_token'] = null;
            } elseif (preg_match('/^\d+(\.\d+)?$/', $makerMaxQuantityPerToken)) {
                $fields['maker_max_quantity_per_token'] = $makerMaxQuantityPerToken;
            }
        }

        return $fields;
    }

    private function findOwnedTask(PmMember $member, int $id): ?PmCopyTask
    {
        return PmCopyTask::where('member_id', $member->id)->where('id', $id)->first();
    }
}
