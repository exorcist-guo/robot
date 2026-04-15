<?php

namespace App\Console\Commands;

use App\Models\Pm\PmMember;
use App\Services\Pm\PolymarketTradingService;
use Illuminate\Console\Command;

class PmCheckReadinessCommand extends Command
{
    protected $signature = 'pm:check-readiness {member_ref : pm_members.id 或 钱包地址} {side=BUY : BUY 或 SELL} {token_id? : SELL 时可选 token_id} {--price=} {--size=}';

    protected $description = '检查指定 member 的交易 readiness';

    public function handle(PolymarketTradingService $trading): int
    {
        $memberRef = $this->argument('member_ref');
        $side = strtoupper((string) $this->argument('side'));
        $tokenId = $this->argument('token_id');
        $price = $this->option('price');
        $size = $this->option('size');

        $memberQuery = PmMember::with('custodyWallet.apiCredentials');
        if (is_string($memberRef) && preg_match('/^0x[a-fA-F0-9]{40}$/', $memberRef)) {
            $member = $memberQuery->where('address', strtolower($memberRef))->first();
        } elseif (is_numeric($memberRef)) {
            $member = $memberQuery->find((int) $memberRef);
        } else {
            $member = null;
        }

        if (!$member || !$member->custodyWallet) {
            $this->error('member 或托管钱包不存在');
            return self::FAILURE;
        }

        $result = $trading->getTradingReadiness(
            $member->custodyWallet,
            $side,
            is_string($tokenId) && trim($tokenId) !== '' ? trim($tokenId) : null,
            is_string($price) && trim($price) !== '' ? trim($price) : null,
            is_string($size) && trim($size) !== '' ? trim($size) : null,
        );

        $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return ($result['is_ready'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
