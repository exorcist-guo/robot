<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pm\PmMember;
use App\Models\Pm\PmPortfolioSnapshot;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class AssetController extends Controller
{
    use ApiResponseTrait;

    private function currentMember(Request $request): PmMember
    {
        /** @var PmMember $user */
        $user = $request->user();
        return $user;
    }

    public function positions(Request $request)
    {
        $member = $this->currentMember($request);
        $snapshot = PmPortfolioSnapshot::where('member_id', $member->id)
            ->orderByDesc('as_of')
            ->first();

        return $this->success('ok', [
            'available_usdc' => $snapshot?->available_usdc !== null ? (string) $snapshot->available_usdc : '0',
            'equity_usdc' => $snapshot?->equity_usdc !== null ? (string) $snapshot->equity_usdc : '0',
            'pnl_today_usdc' => $snapshot?->pnl_today_usdc !== null ? (string) $snapshot->pnl_today_usdc : '0',
            'pnl_total_usdc' => $snapshot?->pnl_total_usdc !== null ? (string) $snapshot->pnl_total_usdc : '0',
            'positions' => $snapshot?->raw['positions'] ?? [],
            'as_of' => $snapshot?->as_of?->toDateTimeString(),
        ]);
    }
}
