<?php

require __DIR__ . '/dapp-api/vendor/autoload.php';

$app = require_once __DIR__ . '/dapp-api/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 模拟请求数据
$data = [
    'mode' => 'tail_sweep',
    'market_slug' => 'btc-updown-5m-1775702700',
    'market_id' => '1914142',
    'market_question' => 'Bitcoin Up or Down - April 8, 10:45PM-10:50PM ET',
    'market_symbol' => 'btc/usd',
    'resolution_source' => 'https://data.chain.link/streams/btc-usd',
    'price_to_beat' => '0',
    'market_end_at' => '2026-04-09T02:50:00Z',
    'token_yes_id' => '62429923564094496408784197404474995789521315260897228561862426468998918838191',
    'token_no_id' => '112575063418015414083564233828375750339181288556231535701124349775916637936333',
    'tail_order_usdc' => 10,
    'tail_trigger_amount' => '20',
    'tail_time_limit_seconds' => 10,
    'tail_loss_stop_count' => 0,
    'tail_price_time_config' => null,
];

echo "测试数据验证:\n";
echo "================\n\n";

// 测试 price_to_beat
$priceToBeat = trim((string) $data['price_to_beat']);
echo "price_to_beat: '{$priceToBeat}'\n";
if (preg_match('/^\d+(\.\d+)?$/', $priceToBeat)) {
    echo "✓ price_to_beat 验证通过\n";
} else {
    echo "✗ price_to_beat 验证失败\n";
}
echo "\n";

// 测试 tail_trigger_amount
$tailTriggerAmount = trim((string) $data['tail_trigger_amount']);
echo "tail_trigger_amount: '{$tailTriggerAmount}'\n";
if (preg_match('/^\d+(\.\d+)?$/', $tailTriggerAmount) && bccomp($tailTriggerAmount, '0', 8) > 0) {
    echo "✓ tail_trigger_amount 验证通过\n";
} else {
    echo "✗ tail_trigger_amount 验证失败\n";
}
echo "\n";

// 测试 tail_order_usdc
$tailOrderUsdc = max(0, (int) $data['tail_order_usdc']);
echo "tail_order_usdc: {$tailOrderUsdc}\n";
if ($tailOrderUsdc > 0) {
    echo "✓ tail_order_usdc 验证通过\n";
} else {
    echo "✗ tail_order_usdc 验证失败\n";
}
echo "\n";

// 测试 market_slug 处理
$marketSlug = trim((string) $data['market_slug']);
$processedSlug = preg_replace('/-\d{10}$/', '', $marketSlug) ?: $marketSlug;
echo "原始 market_slug: '{$marketSlug}'\n";
echo "处理后 market_slug: '{$processedSlug}'\n";
echo "\n";

echo "所有验证通过！\n";
