# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Polymarket Copy Trading Bot** system that allows users to automatically replicate trades from leader accounts on Polymarket. The system consists of:

- **Backend**: `dapp-api/` - Laravel 10.10 + Sanctum + dcat-plus/laravel-admin
- **Frontend**: `dapp-h5/` - Vue3 + Vant (currently empty directory)
- **Architecture**: Custodial wallet system where the backend holds encrypted private keys and executes trades automatically

## Key Technical Components

### 1. Order Lifecycle

Orders flow through multiple states and services:

1. **Trade Detection**: `PmPollLeaderTradesCommand` polls leader trades from Polymarket Data API
2. **Intent Creation**: `PmCreateOrderIntentsJob` generates order intents based on copy tasks
3. **Order Execution**: `PmExecuteOrderIntentJob` signs and submits orders to Polymarket CLOB
4. **Status Sync**: `PmSyncOrdersCommand` syncs order status from remote
5. **Settlement**: `PmOrderSettlementSyncService` calculates PnL after market resolution
6. **Claiming**: `PmAutoClaimOrderWinningsJob` claims winnings on-chain for profitable orders

### 2. Tail Sweep System

A specialized trading mode that monitors price movements and triggers orders when conditions are met:

- **Price Daemon**: `PmTailSweepPriceDaemonCommand` subscribes to Chainlink price feeds via WebSocket
- **Market Daemon**: `PmMarketInfoDaemonCommand` subscribes to Polymarket market data via WebSocket
- **Scanner**: `PmScanTailSweepCommand` continuously scans tasks and generates intents when price/time thresholds are met
- **Price Cache**: `TailSweepPriceCache` stores real-time prices in Redis with TTL
- **Dynamic Thresholds**: Tasks use price-to-time mappings (e.g., `[200=>180, 100=>120, 30=>60]` means $200 price change requires 180s window)

### 3. Cryptographic Operations

All done in pure PHP (no Node.js dependency):

- **EIP-712 Signing**: `PolymarketOrderSigner` + `SleepFinance\Eip712` for order signatures
- **Private Key Management**: `CustodyCipher` encrypts/decrypts keys using `PM_CUSTODY_KEY`
- **Wallet Authorization**: `EthSignature` for personal_sign login flow
- **On-chain Claims**: `PolymarketClaimService` builds and signs Polygon transactions

### 4. Settlement Logic Critical Details

**IMPORTANT**: The settlement system has specific edge cases that must be preserved:

- **Market End Time**: Always parse `market.endDate` with explicit UTC timezone (`Carbon::parse($date, 'UTC')`) to avoid local timezone misinterpretation
- **Winner Detection**: Use `!empty($token['winner'])` not `=== true` because Polymarket API returns both boolean `true` and string `"1"`
- **Canceled Orders**: Orders with status `canceled_market_resolved` but `size_matched > 0` are treated as filled (partial fills before market resolution)
- **Settlement Timing**: Only mark `is_settled=true` when `market.closed=true`, not just when market end time has passed
- **Filled Amount Fallback**: If remote doesn't provide `takingAmount`/`filledAmount`, calculate as `filled_size * price`

## Common Commands

### Development & Testing

```bash
cd dapp-api

# Clear all caches (required after code changes in production)
php artisan optimize:clear

# Run specific test command (not PHPUnit)
php artisan test --poly_order_id=0x...

# Validate Polymarket setup (read-only check)
php artisan pm:validate-setup
```

### Order Management

```bash
# Sync order status from Polymarket CLOB
php artisan pm:sync-orders

# Sync settlement and calculate PnL
php artisan pm:sync-order-settlement --only-unsettled --queue-claim

# Settle and claim specific order with detailed logs
php artisan pm:settle-and-claim --order-id=123

# Verify claim transactions on-chain
php artisan pm:verify-claim-tx --all-claimed

# Replace stuck claim transaction (if mempool congestion)
php artisan pm:replace-stuck-claim-tx --order-id=123
```

### Leader Trade Polling

```bash
# Poll leader trades and create intents (run continuously)
php artisan pm:poll-leader-trades

# Compare trade sources (debugging)
php artisan pm:compare-leader-trade-sources

# Sync trades from chain (alternative source)
php artisan pm:sync-leader-trades-from-chain
```

### Tail Sweep Operations

```bash
# Start price feed daemon (must run before scanner)
php artisan pm:tail-sweep-price-daemon

# Start market info daemon (optional, for cached market data)
php artisan pm:market-info-daemon

# Start tail sweep scanner (generates intents when conditions met)
php artisan pm:scan-tail-sweep

# Debug single scan iteration
php artisan pm:scan-tail-sweep --once
```

### Queue Workers

```bash
# Start queue worker (required for async jobs)
php artisan queue:work --queue=default --tries=3

# Monitor failed jobs
php artisan queue:failed
php artisan queue:retry all
```

### Scheduled Tasks

The following run automatically via Laravel scheduler (cron):

```bash
# Every minute: verify claimed orders on-chain
php artisan pm:verify-claim-tx --all-claimed

# Every minute: sync unsettled orders and queue claims
php artisan pm:sync-order-settlement --only-unsettled --queue-claim
```

## Architecture Patterns

### Service Layer

- **PolymarketTradingService**: Main facade for CLOB operations (place order, get orderbook, etc.)
- **PolymarketDataClient**: Fetches public data (trades, positions, PnL)
- **GammaClient**: Fetches market metadata
- **PolygonRpcService**: Direct RPC calls to Polygon for on-chain data
- **PmOrderSettlementSyncService**: Core settlement logic (status mapping, PnL calculation, claim eligibility)

### Job Queue

All async operations use Laravel jobs:

- `PmCreateOrderIntentsJob`: Batch create intents from leader trades
- `PmExecuteOrderIntentJob`: Execute single order intent (with retry logic)
- `PmSyncOrderStatusJob`: Sync single order status
- `PmSyncOrderSettlementJob`: Sync single order settlement
- `PmAutoClaimOrderWinningsJob`: Claim winnings for single order

### Models

Key models in `app/Models/Pm/`:

- `PmMember`: User account (linked to wallet address)
- `PmCustodyWallet`: Encrypted private key storage
- `PmCopyTask`: Copy trading configuration (leader, ratio, min/max amounts)
- `PmLeaderTrade`: Leader's trade records from Polymarket
- `PmOrderIntent`: Pending order to be executed
- `PmOrder`: Executed order with status/settlement/claim tracking

### Configuration

All Polymarket settings in `config/pm.php`:

- API endpoints (CLOB, Gamma, Data)
- WebSocket settings (Chainlink, Market)
- Cache stores (Redis required for daemons)
- Contract addresses (CTF Exchange, Collateral Token)
- Tail sweep thresholds and timeouts

## Critical Redis Requirements

**Daemon commands require Redis** with `LockProvider` support:

- `pm:tail-sweep-price-daemon`
- `pm:market-info-daemon`
- `pm:scan-tail-sweep`

Set `CACHE_DRIVER=redis` or configure specific cache stores:
- `PM_TAIL_SWEEP_PRICE_CACHE_STORE`
- `PM_MARKET_INFO_CACHE_STORE`

**Redis Connection Issues**: Long-running daemons may encounter `errno=10054` (connection closed by server). The scanner includes `reconnectRedis()` logic to handle this.

## Security Notes

- Private keys are encrypted with `PM_CUSTODY_KEY` (separate from `APP_KEY`)
- Never log decrypted private keys or raw signatures
- Login uses nonce + personal_sign to prove address ownership
- All order signatures use EIP-712 typed data

## Testing & Debugging

### Debug Order Intent

```bash
# See what payload would be generated for an intent
php artisan pm:debug-order-intent {intent_id}
```

### Dry Run Settlement

```bash
# Test settlement logic without saving
php artisan pm:settle-and-claim --order-id=123 --dry-run
```

### Check Daemon Health

Daemons write heartbeat keys to Redis:
- `pm:tail_sweep_price:daemon:heartbeat`
- `pm:market_info:daemon:heartbeat`

If missing or stale, daemon needs restart.

## Common Issues

### Orders Not Settling

1. Check market end time parsing (must use UTC)
2. Verify `market.closed=true` in settlement_payload
3. Check winner field type (boolean vs string "1")
4. Ensure order created >10 minutes ago (settlement delay)

### Tail Sweep Not Triggering

1. Verify price daemon is running and updating cache
2. Check price-time threshold configuration matches current price movement
3. Ensure task status is active (status=1)
3. Look for "missing_best_price" errors (orderbook unavailable)

### Claim Failures

1. "replacement transaction underpriced": Reset `claim_status=1` and retry
2. "insufficient funds": Check wallet USDC balance for gas
3. Transaction stuck: Use `pm:replace-stuck-claim-tx` with higher gas price

## Database Migrations

Run migrations to set up tables:

```bash
php artisan migrate
```

Key tables: `pm_members`, `pm_custody_wallets`, `pm_copy_tasks`, `pm_leader_trades`, `pm_order_intents`, `pm_orders`

## Environment Variables

Critical env vars (see `config/pm.php` for full list):

```bash
PM_CUSTODY_KEY=          # Encryption key for private keys
PM_CLOB_BASE_URL=        # Polymarket CLOB API
PM_GAMMA_BASE_URL=       # Polymarket Gamma API
PM_POLYGON_RPC_URL=      # Polygon RPC endpoint
PM_CHAIN_ID=137          # Polygon mainnet
PM_COLLATERAL_TOKEN=     # USDC contract address
CACHE_DRIVER=redis       # Required for daemons
QUEUE_CONNECTION=redis   # Recommended for production
```
