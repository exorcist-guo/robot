<?php

return [
    'enabled' => (bool) env('DAPP_PY_ENABLED', false),
    'base_url' => env('DAPP_PY_BASE_URL', 'http://127.0.0.1:8001'),
    'timeout' => (int) env('DAPP_PY_TIMEOUT', 15),
    'connect_timeout' => (int) env('DAPP_PY_CONNECT_TIMEOUT', 5),
    'internal_token' => env('DAPP_PY_INTERNAL_TOKEN', ''),
    'leader_trade_mode' => env('DAPP_PY_LEADER_TRADE_MODE', 'data_api'),
];
