<?php

namespace App\Services\Pm;

use App\Models\Pm\PmCustodyWallet;

class PolymarketClaimOrchestrator
{
    public function __construct(
        private readonly PolymarketGaslessClaimService $gaslessClaimService,
        private readonly PolymarketClaimService $claimService,
    ) {
    }

    /**
     * @param array<int,array<string,mixed>> $positions
     * @return array<string,mixed>
     */
    public function claimPositions(
        PmCustodyWallet $wallet,
        array $positions,
        bool $dryRun = false,
        bool $gaslessOnly = false,
        bool $onchainOnly = false,
        bool $fallback = true,
        int $timeoutSeconds = 30,
    ): array {
        if ($onchainOnly) {
            $result = $this->claimService->claimPositionsOnChain($wallet, $positions, $dryRun);
            return $result + [
                'channel' => 'onchain',
                'fallback_used' => false,
            ];
        }

        $gaslessResult = $this->gaslessClaimService->claimPositions($wallet, $positions, $timeoutSeconds);
        if (($gaslessResult['success'] ?? false) === true) {
            return $gaslessResult + [
                'channel' => 'gasless',
                'fallback_used' => false,
            ];
        }

        if ($dryRun || $gaslessOnly || !$fallback || (($gaslessResult['should_fallback'] ?? false) !== true)) {
            return $gaslessResult + [
                'channel' => 'gasless',
                'fallback_used' => false,
            ];
        }

        $onchainResult = $this->claimService->claimPositionsOnChain($wallet, $positions, false);
        return $onchainResult + [
            'channel' => 'onchain',
            'fallback_used' => true,
            'gasless_result' => $gaslessResult,
        ];
    }
}
