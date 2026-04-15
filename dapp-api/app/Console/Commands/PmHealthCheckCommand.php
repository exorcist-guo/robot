<?php

namespace App\Console\Commands;

use App\Models\Pm\PmLeader;
use App\Models\Pm\PmMember;
use App\Services\Pm\GammaClient;
use App\Services\Pm\PolymarketTradingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class PmHealthCheckCommand extends Command
{
    protected $signature = 'pm:health-check {member_ref? : pm_members.id 或 钱包地址}';

    protected $description = '检查 Polymarket 自动跟单系统核心依赖与 readiness';

    public function handle(PolymarketTradingService $trading): int
    {
        $checks = [];
        $gamma = new GammaClient();

        try {
            DB::connection()->getPdo();
            $checks['db'] = ['ok' => true];
        } catch (\Throwable $e) {
            $checks['db'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        $checks['config'] = [
            'ok' => (bool) config('pm.clob_base_url') && (bool) config('pm.polygon_rpc_url') && (bool) config('pm.custody_key'),
            'clob_base_url' => config('pm.clob_base_url'),
            'polygon_rpc_url' => config('pm.polygon_rpc_url') ? 'configured' : 'missing',
            'copy_execution_enabled' => (bool) config('pm.copy_execution_enabled'),
            'copy_dry_run' => (bool) config('pm.copy_dry_run'),
        ];

        try {
            $leaders = PmLeader::query()->where('status', 1)->count();
            $checks['leaders'] = ['ok' => true, 'active_count' => $leaders];
        } catch (\Throwable $e) {
            $checks['leaders'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        try {
            $checks['cache'] = ['ok' => Cache::getStore() !== null];
        } catch (\Throwable $e) {
            $checks['cache'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        $memberRef = $this->argument('member_ref');
        if ($memberRef) {
            $memberQuery = PmMember::with('custodyWallet.apiCredentials');
            if (is_string($memberRef) && preg_match('/^0x[a-fA-F0-9]{40}$/', $memberRef)) {
                $member = $memberQuery->where('address', strtolower($memberRef))->first();
            } elseif (is_numeric($memberRef)) {
                $member = $memberQuery->find((int) $memberRef);
            } else {
                $member = null;
            }

            if ($member && $member->custodyWallet) {
                try {
                    $checks['member_readiness'] = $trading->getTradingReadiness($member->custodyWallet, PolymarketTradingService::SIDE_BUY);
                } catch (\Throwable $e) {
                    $checks['member_readiness'] = ['ok' => false, 'error' => $e->getMessage()];
                }

                try {
                    $checks['member_allowance'] = $trading->getAllowanceStatus($member->custodyWallet);
                } catch (\Throwable $e) {
                    $checks['member_allowance'] = ['ok' => false, 'error' => $e->getMessage()];
                }
            } else {
                $checks['member'] = ['ok' => false, 'error' => 'member_or_wallet_missing'];
            }
        }

        try {
            $leader = PmLeader::query()->where('status', 1)->first();
            if ($leader) {
                $checks['gamma'] = ['ok' => is_array($gamma->getPublicProfile($leader->proxy_wallet))];
            } else {
                $checks['gamma'] = ['ok' => true, 'skipped' => 'no_active_leader'];
            }
        } catch (\Throwable $e) {
            $checks['gamma'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        $this->line(json_encode($checks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        foreach ($checks as $value) {
            if (is_array($value) && array_key_exists('ok', $value) && $value['ok'] === false) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
