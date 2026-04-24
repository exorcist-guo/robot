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
            'strategy_key' => 'member7_btc_skip_round',
            'strategy_name' => '会员7-BTC隔一轮预测',
            'member_id' => 7,
            'market_slug' => 'btc-updown-5m',
            'resolution_source' => 'https://data.chain.link/streams/btc-usd',
            'symbol' => 'btc/usd',
            'base_bet' => '5',
            'max_lose_reset_limit' => 5,
            'min_predict_diff' => '1',
        ];
    }
}
