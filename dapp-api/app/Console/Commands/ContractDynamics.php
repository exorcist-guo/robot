<?php

namespace App\Console\Commands;

use App\Jobs\PmExecuteOrderIntentJob;
use App\Models\Pm\PmCopyTask;
use App\Models\Pm\PmMember;
use App\Models\Pm\PmOrderIntent;
use App\Services\Pm\GammaClient;
use App\Services\Pm\PolymarketTradingService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PlaceOrderDirectly extends Command
{
    protected $signature = 'pm:specify-order
        {user : member_id 或钱包地址}
        {currentRoundSlug : event slug 或 polymarket 链接}
        {currentPrice : 当前价格，例如 83500.12}
        {--amount=1 : 下单金额(USDC)}';

    protected $description = '指定用户、event slug、currentPrice，匹配区间 market 后直接买入 token_yes_id';

    public function handle(GammaClient $gammaClient, PolymarketTradingService $trading): int
    {
        $userInput = trim((string) $this->argument('user'));
        $slugInput = trim((string) $this->argument('currentRoundSlug'));
        $currentPrice = trim((string) $this->argument('currentPrice'));
        $amountInput = trim((string) $this->option('amount'));

        if (!preg_match('/^\d+(\.\d+)?$/', $currentPrice) || bccomp($currentPrice, '0', 8) <= 0) {
            $this->error('currentPrice 必须是大于 0 的数字，例如 83500.12');
            return self::FAILURE;
        }

        if (!preg_match('/^\d+(\.\d+)?$/', $amountInput) || bccomp($amountInput, '0', 8) <= 0) {
            $this->error('amount 必须是大于 0 的数字，例如 --amount=1');
            return self::FAILURE;
        }

        $member = $this->resolveMember($userInput);
        if (!$member) {
            $this->error("未找到用户: {$userInput}");
            return self::FAILURE;
        }

        $task = $this->resolveTask($member->id);
        if (!$task) {
            $this->error("未找到用户 {$member->id} 的 tail_sweep 任务");
            return self::FAILURE;
        }

        $slug = $gammaClient->extractSlug($slugInput);
        if ($slug === '') {
            $this->error('currentRoundSlug 不能为空');
            return self::FAILURE;
        }

        $market = $this->resolveEventMarket($gammaClient, $slug, $currentPrice);
        if ($market === []) {
            $this->error("未匹配到当前价格对应 market: {$slug} @ {$currentPrice}");
            return self::FAILURE;
        }

        $task->market_slug = (string) ($market['slug'] ?? '');
        $task->market_id = (string) ($market['market_id'] ?? '');
        $task->market_question = (string) ($market['question'] ?? '');
        $task->resolution_source = (string) ($market['resolution_source'] ?? '');
        $task->price_to_beat = (string) ($market['price_to_beat'] ?? '0');
        $task->token_yes_id = (string) ($market['token_yes_id'] ?? '');
        $task->token_no_id = (string) ($market['token_no_id'] ?? '');
        if (!empty($market['end_at'])) {
            $task->market_end_at = Carbon::parse((string) $market['end_at'], 'UTC');
        }
        $task->save();

        $tokenYesId = trim((string) $task->token_yes_id);
        if ($tokenYesId === '') {
            $this->error('选中的 market 没有 token_yes_id');
            return self::FAILURE;
        }

        $quote = $trading->getOrderBookMarketPrice($tokenYesId, PolymarketTradingService::SIDE_BUY, $amountInput);
        $leaderPrice = (string) ($quote['price'] ?? '0');
        if (!preg_match('/^\d+(\.\d+)?$/', $leaderPrice) || bccomp($leaderPrice, '0', 8) <= 0) {
            $this->error("token_yes_id 当前不可交易或无法获取价格: {$tokenYesId}");
            return self::FAILURE;
        }

        $amountAtomic = (int) BigDecimal::of($amountInput)
            ->multipliedBy('1000000')
            ->toScale(0, RoundingMode::DOWN)
            ->__toString();

        if ($amountAtomic <= 0) {
            $this->error('下单金额换算后为 0，请增大 --amount');
            return self::FAILURE;
        }

        $intent = PmOrderIntent::create([
            'copy_task_id' => $task->id,
            'leader_trade_id' => null,
            'member_id' => $member->id,
            'token_id' => $tokenYesId,
            'side' => PolymarketTradingService::SIDE_BUY,
            'leader_price' => $leaderPrice,
            'target_usdc' => $amountAtomic,
            'clamped_usdc' => $amountAtomic,
            'status' => PmOrderIntent::STATUS_PENDING,
            'skip_reason' => null,
            'risk_snapshot' => [
                'mode' => PmCopyTask::MODE_TAIL_SWEEP,
                'market_slug' => $task->market_slug,
                'market_id' => $task->market_id,
                'market_question' => $task->market_question,
                'resolution_source' => $task->resolution_source,
                'trigger_side' => 'yes',
                'price_to_beat' => (string) ($task->price_to_beat ?: '0'),
                'token_yes_id' => $task->token_yes_id,
                'token_no_id' => $task->token_no_id,
                'allow_partial_fill' => (bool) $task->allow_partial_fill,
                'max_slippage_bps' => (int) $task->max_slippage_bps,
                'daily_max_usdc' => $task->daily_max_usdc,
                'manual_test' => true,
                'current_price' => $currentPrice,
            ],
            'decision_payload' => [
                'manual_test' => [
                    'user_input' => $userInput,
                    'resolved_member_id' => $member->id,
                    'task_id' => $task->id,
                    'event_slug' => $slug,
                    'current_price' => $currentPrice,
                    'amount_usdc' => $amountInput,
                    'matched_market' => $market,
                    'quote' => $quote,
                ],
            ],
            'execution_mode' => (bool) config('pm.copy_dry_run', false) ? 'dry_run' : 'live',
            'execution_stage' => 'queued',
        ]);

        PmExecuteOrderIntentJob::dispatchSync($intent->id);

        $intent->refresh();
        $order = $intent->order()->latest('id')->first();

        $this->line(json_encode([
            'member_id' => $member->id,
            'member_address' => (string) $member->address,
            'task_id' => $task->id,
            'event_slug' => $slug,
            'current_price' => $currentPrice,
            'matched_market_slug' => (string) $task->market_slug,
            'matched_market_id' => (string) $task->market_id,
            'matched_market_question' => (string) $task->market_question,
            'token_yes_id' => $tokenYesId,
            'token_no_id' => (string) $task->token_no_id,
            'leader_price' => $leaderPrice,
            'amount_usdc' => $amountInput,
            'intent_id' => $intent->id,
            'intent_status' => (int) $intent->status,
            'intent_skip_reason' => (string) ($intent->skip_reason ?? ''),
            'intent_last_error_code' => (string) ($intent->last_error_code ?? ''),
            'intent_last_error_message' => (string) ($intent->last_error_message ?? ''),
            'order_id' => $order?->id,
            'order_status' => $order?->status,
            'order_error_code' => (string) ($order?->error_code ?? ''),
            'order_error_message' => (string) ($order?->error_message ?? ''),
            'poly_order_id' => (string) ($order?->poly_order_id ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return $intent->status === PmOrderIntent::STATUS_SUBMITTED
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function resolveMember(string $userInput): ?PmMember
    {
        if ($userInput !== '' && ctype_digit($userInput)) {
            return PmMember::query()->find((int) $userInput);
        }

        return PmMember::query()
            ->where('address', strtolower($userInput))
            ->first();
    }

    private function resolveTask(int $memberId): ?PmCopyTask
    {
        return PmCopyTask::query()
            ->where('member_id', $memberId)
            ->where('mode', PmCopyTask::MODE_TAIL_SWEEP)
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveEventMarket(GammaClient $gammaClient, string $eventSlug, string $currentPrice): array
    {
        $event = $gammaClient->getEventBySlug($eventSlug) ?? [];
        $markets = $event['markets'] ?? [];
        if (!is_array($markets) || $markets === []) {
            return [];
        }

        foreach ($markets as $market) {
            if (!is_array($market)) {
                continue;
            }

            if ($this->marketMatchesCurrentPrice($market, $currentPrice)) {
                return $this->normalizeResolvedMarket($market, $event, $eventSlug);
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $market
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function normalizeResolvedMarket(array $market, array $event, string $fallbackSlug): array
    {
        $outcomes = $this->decodeJsonArray($market['outcomes'] ?? []);
        $tokenIds = $this->decodeJsonArray($market['clobTokenIds'] ?? []);
        $tokenMap = [];
        foreach ($outcomes as $index => $name) {
            $tokenMap[strtolower((string) $name)] = (string) ($tokenIds[$index] ?? '');
        }

        return [
            'slug' => (string) ($market['slug'] ?? $fallbackSlug),
            'market_id' => (string) ($market['id'] ?? ''),
            'question' => (string) ($market['question'] ?? $event['title'] ?? ''),
            'resolution_source' => (string) ($market['resolutionSource'] ?? $event['resolutionSource'] ?? ''),
            'price_to_beat' => (string) ($event['eventMetadata']['priceToBeat'] ?? $market['eventMetadata']['priceToBeat'] ?? '0'),
            'end_at' => (string) ($market['endDate'] ?? $event['endDate'] ?? ''),
            'token_yes_id' => $tokenMap['up'] ?? $tokenMap['yes'] ?? '',
            'token_no_id' => $tokenMap['down'] ?? $tokenMap['no'] ?? '',
            'outcomes' => $outcomes,
            'token_ids' => $tokenIds,
        ];
    }

    /**
     * @param array<string,mixed> $market
     */
    private function marketMatchesCurrentPrice(array $market, string $currentPrice): bool
    {
        $question = (string) ($market['question'] ?? '');
        if ($question === '') {
            return false;
        }

        if (preg_match('/between\s*\$?([\d,]+)\s*and\s*\$?([\d,]+)/i', $question, $matches)) {
            $min = str_replace(',', '', (string) ($matches[1] ?? ''));
            $max = str_replace(',', '', (string) ($matches[2] ?? ''));
            return bccomp($currentPrice, $min, 8) >= 0 && bccomp($currentPrice, $max, 8) < 0;
        }

        if (preg_match('/above\s*\$?([\d,]+)/i', $question, $matches)) {
            $min = str_replace(',', '', (string) ($matches[1] ?? ''));
            return bccomp($currentPrice, $min, 8) > 0;
        }

        if (preg_match('/below\s*\$?([\d,]+)/i', $question, $matches)) {
            $max = str_replace(',', '', (string) ($matches[1] ?? ''));
            return bccomp($currentPrice, $max, 8) < 0;
        }

        return false;
    }

    /**
     * @return array<int,mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }
}
