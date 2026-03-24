<?php

return [
    // 环境: test (测试) 或 main (正式)
    'env' => env('BSC_ENV', 'test'),

    // RPC 节点配置 (BSC 节点)
    // BSC 测试网: https://data-seed-prebsc-1-s1.binance.org:8545/ (免费公共节点)
    // BSC 主网: https://bsc-dataseed.binance.org/ (免费公共节点)
    // 或使用 Alchemy/Infura 等付费服务 (需使用 bnb-mainnet.g.alchemy.com 这样的 EVM 节点)
    'rpc' => [
        'test' => env('BSC_TEST_RPC', 'https://data-seed-prebsc-1-s1.bnbchain.org:8545/'),
        'main' => env('BSC_MAIN_RPC', 'https://bsc-dataseed1.binance.org'),
    ],

    // 合约地址
    'contract_address' => [
        'test' => env('BSC_TEST_CONTRACT', '0xCE9CF913DD4C006e67D0cc09722Bd0d731754576'),
        'main' => env('BSC_MAIN_CONTRACT', '0x5377e2C2c59cD44A18e4D99Ddafb299C558F485f'),
    ],

    // USDT 地址 (BSC BEP20)
    'usdt_address' => [
        'test' => env('BSC_TEST_USDT', '0x9a256A150B172dD61B9355d5808df7bb58f43E6a'), // BSC Testnet USDT
        'main' => env('BSC_MAIN_USDT', '0xd2D8dfdf2d62dbbC22D33f2256DB0A7fAA0757c4'), // BSC Mainnet USDT
    ],

    // 私钥 (仅用于服务端签名交易，慎用！)
    'private_key' => env('BSC_PRIVATE_KEY', ''),

    // Chain ID
    'chain_id' => [
        'test' => 97,   // BSC Testnet
        'main' => 56,   // BSC Mainnet
    ],
];
