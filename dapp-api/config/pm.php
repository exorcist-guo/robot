<?php

return [
    // 用于托管私钥/Polymarket 凭证加密（建议单独于 APP_KEY）
    'custody_key' => env('PM_CUSTODY_KEY', '019cf9d9-26f9-7050-b0f1-c574b1a7df74'),
    'google_public_key_path' => env('PM_GOOGLE_PUBLIC_KEY_PATH', config_path('public_key.pem')),
    'google_private_key_path' => env('PM_GOOGLE_PRIVATE_KEY_PATH', config_path('private_key.pem')),

    // Polymarket 相关基础配置
    'gamma_base_url' => env('PM_GAMMA_BASE_URL', 'https://gamma-api.polymarket.com'),
    'clob_base_url' => env('PM_CLOB_BASE_URL', 'https://clob.polymarket.com'),
    'http_timeout' => (int) env('PM_HTTP_TIMEOUT', 90),
    'http_connect_timeout' => (int) env('PM_HTTP_CONNECT_TIMEOUT', 15),
    'leader_trade_source' => env('PM_LEADER_TRADE_SOURCE', 'data_api'),
    'leader_trade_confirmations' => (int) env('PM_LEADER_TRADE_CONFIRMATIONS', 6),
    'leader_trade_reorg_buffer' => (int) env('PM_LEADER_TRADE_REORG_BUFFER', 20),

    // tail sweep 行情常驻订阅
    'chainlink_rtds_host' => env('PM_CHAINLINK_RTDS_HOST', 'ws-live-data.polymarket.com'),
    'chainlink_rtds_port' => (int) env('PM_CHAINLINK_RTDS_PORT', 443),
    'chainlink_rtds_origin' => env('PM_CHAINLINK_RTDS_ORIGIN', 'https://polymarket.com'),
    'chainlink_rtds_connect_timeout_seconds' => (int) env('PM_CHAINLINK_RTDS_CONNECT_TIMEOUT_SECONDS', 8),
    'chainlink_rtds_read_timeout_seconds' => (int) env('PM_CHAINLINK_RTDS_READ_TIMEOUT_SECONDS', 8),
    'tail_sweep_price_cache_store' => env('PM_TAIL_SWEEP_PRICE_CACHE_STORE', ''),
    'tail_sweep_price_symbols' => array_values(array_filter(array_map(
        static fn (string $value) => strtolower(trim($value)),
        explode(',', (string) env('PM_TAIL_SWEEP_PRICE_SYMBOLS', 'btc/usd'))
    ))),
    'tail_sweep_price_snapshot_ttl_seconds' => (int) env('PM_TAIL_SWEEP_PRICE_SNAPSHOT_TTL_SECONDS', 120),
    'tail_sweep_price_stale_after_seconds' => (int) env('PM_TAIL_SWEEP_PRICE_STALE_AFTER_SECONDS', 12),
    'tail_sweep_price_metadata_ttl_seconds' => (int) env('PM_TAIL_SWEEP_PRICE_METADATA_TTL_SECONDS', 300),
    'tail_sweep_price_daemon_heartbeat_ttl_seconds' => (int) env('PM_TAIL_SWEEP_PRICE_DAEMON_HEARTBEAT_TTL_SECONDS', 30),
    'tail_sweep_price_daemon_lock_seconds' => (int) env('PM_TAIL_SWEEP_PRICE_DAEMON_LOCK_SECONDS', 600),
    'tail_sweep_price_daemon_reconnect_sleep_seconds' => (int) env('PM_TAIL_SWEEP_PRICE_DAEMON_RECONNECT_SLEEP_SECONDS', 3),
    'tail_sweep_price_daemon_idle_sleep_seconds' => (int) env('PM_TAIL_SWEEP_PRICE_DAEMON_IDLE_SLEEP_SECONDS', 5),
    'tail_sweep_price_symbol_refresh_seconds' => (int) env('PM_TAIL_SWEEP_PRICE_SYMBOL_REFRESH_SECONDS', 10),
    'tail_sweep_price_require_redis' => filter_var(env('PM_TAIL_SWEEP_PRICE_REQUIRE_REDIS', true), FILTER_VALIDATE_BOOL),

    // market websocket 常驻订阅
    'market_ws_host' => env('PM_MARKET_WS_HOST', 'ws-subscriptions-clob.polymarket.com'),
    'market_ws_port' => (int) env('PM_MARKET_WS_PORT', 443),
    'market_ws_path' => env('PM_MARKET_WS_PATH', '/ws/market'),
    'market_ws_origin' => env('PM_MARKET_WS_ORIGIN', 'https://polymarket.com'),
    'market_ws_connect_timeout_seconds' => (int) env('PM_MARKET_WS_CONNECT_TIMEOUT_SECONDS', 8),
    'market_ws_read_timeout_seconds' => (int) env('PM_MARKET_WS_READ_TIMEOUT_SECONDS', 8),
    'market_info_cache_store' => env('PM_MARKET_INFO_CACHE_STORE', ''),
    'market_info_subscriptions' => json_decode((string) env('PM_MARKET_INFO_SUBSCRIPTIONS', '[]'), true) ?: [],
    'market_info_snapshot_ttl_seconds' => (int) env('PM_MARKET_INFO_SNAPSHOT_TTL_SECONDS', 120),
    'market_info_stale_after_seconds' => (int) env('PM_MARKET_INFO_STALE_AFTER_SECONDS', 20),
    'market_info_metadata_ttl_seconds' => (int) env('PM_MARKET_INFO_METADATA_TTL_SECONDS', 300),
    'market_info_daemon_heartbeat_ttl_seconds' => (int) env('PM_MARKET_INFO_DAEMON_HEARTBEAT_TTL_SECONDS', 30),
    'market_info_daemon_lock_seconds' => (int) env('PM_MARKET_INFO_DAEMON_LOCK_SECONDS', 600),
    'market_info_daemon_reconnect_sleep_seconds' => (int) env('PM_MARKET_INFO_DAEMON_RECONNECT_SLEEP_SECONDS', 3),
    'market_info_daemon_idle_sleep_seconds' => (int) env('PM_MARKET_INFO_DAEMON_IDLE_SLEEP_SECONDS', 5),
    'market_info_refresh_seconds' => (int) env('PM_MARKET_INFO_REFRESH_SECONDS', 10),
    'market_info_require_redis' => filter_var(env('PM_MARKET_INFO_REQUIRE_REDIS', true), FILTER_VALIDATE_BOOL),

    // tail sweep 扫描 daemon
    'tail_sweep_scan_cache_store' => env('PM_TAIL_SWEEP_SCAN_CACHE_STORE', env('PM_TAIL_SWEEP_PRICE_CACHE_STORE', '')),
    'tail_sweep_scan_require_redis' => filter_var(env('PM_TAIL_SWEEP_SCAN_REQUIRE_REDIS', true), FILTER_VALIDATE_BOOL),
    'tail_sweep_scan_lock_seconds' => (int) env('PM_TAIL_SWEEP_SCAN_LOCK_SECONDS', 600),
    'tail_sweep_scan_loop_sleep_seconds' => (int) env('PM_TAIL_SWEEP_SCAN_LOOP_SLEEP_SECONDS', 5),
    'tail_sweep_market_cache_ttl_seconds' => (int) env('PM_TAIL_SWEEP_MARKET_CACHE_TTL_SECONDS', 1800),

    // Polymarket CTF Exchange (Polygon) 相关
    'chain_id' => (int) env('PM_CHAIN_ID', 137),
    'exchange_contract' => env('PM_EXCHANGE_CONTRACT', '0x4bFb41d5B3570DeFd03C39a9A4D8dE6Bd8B8982E'),
    'ctf_contract' => strtolower((string) env('PM_CTF_CONTRACT', '0x4D97DCd97eC945f40cF65F87097ACe5EA0476045')),
    'polygon_rpc_url' => env('PM_POLYGON_RPC_URL', ''),
    'collateral_token' => strtolower((string) env('PM_COLLATERAL_TOKEN', '0x2791Bca1f2de4661ED88A30C99A7a9449Aa84174')),
    'maker_fee_rate_bps' => (string) env('PM_MAKER_FEE_RATE_BPS', '0'),
    'taker_fee_rate_bps' => (string) env('PM_TAKER_FEE_RATE_BPS', '1000'),
    'default_fee_rate_bps' => (string) env('PM_DEFAULT_FEE_RATE_BPS', '1000'),
    'approval_spenders' => array_values(array_filter(array_map(
        static fn (string $value) => strtolower(trim($value)),
        explode(',', (string) env('PM_APPROVAL_SPENDERS', '0x4bFb41d5B3570DeFd03C39a9A4D8dE6Bd8B8982E,0xC5d563A36AE78145C45a50134d48A1215220f80a,0xd91E80cF2E7be2e162c6513ceD06f1dD0dA35296'))
    ))),

    // 登录 nonce
    'login_nonce_ttl_seconds' => (int) env('PM_LOGIN_NONCE_TTL', 300),

    // sponsor transfer
    'sponsor_private_key' => env('PM_SPONSOR_PRIVATE_KEY', ''),
    'sponsored_transfer_executor' => strtolower((string) env('PM_SPONSORED_TRANSFER_EXECUTOR', '')),
    'sponsored_transfer_deadline_ttl_seconds' => (int) env('PM_SPONSORED_TRANSFER_DEADLINE_TTL', 600),
    'sponsored_transfer_allowed_tokens' => array_values(array_filter(array_map(
        static fn (string $value) => strtolower(trim($value)),
        explode(',', (string) env('PM_SPONSORED_TRANSFER_ALLOWED_TOKENS', (string) env('PM_COLLATERAL_TOKEN', '')))
    ))),
    'sponsored_transfer_max_amount' => (string) env('PM_SPONSORED_TRANSFER_MAX_AMOUNT', '0'),
];
