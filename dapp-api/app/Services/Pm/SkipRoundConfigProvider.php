<?php

namespace App\Services\Pm;

class SkipRoundConfigProvider
{
    /**
     * @return array<string,mixed>
     */
    public function get(): array
    {
        return [
            'strategy_key' => 'member5_btc_skip_round',
            'strategy_name' => '会员5-BTC隔一轮预测',
            'member_id' => 5,
            'market_slug' => 'btc-updown-5m',
            'resolution_source' => 'https://data.chain.link/streams/btc-usd',
            'symbol' => 'btc/usd',
            'base_size' => '5',
            'loss_bet_multiplier' => '1',
            'max_lose_reset_limit' => 5,
            'min_predict_diff' => '1',
        ];
    }
}
