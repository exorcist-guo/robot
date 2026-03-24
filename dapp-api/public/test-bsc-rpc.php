<?php

/**
 * 测试 BSC RPC 连接
 * 访问: http://你的域名/test-bsc-rpc.php
 */

use Web3\Web3;
use Web3\Net;
use Web3\Eth;

// 测试的 RPC 地址
$rpcUrl = 'https://data-seed-prebsc-1-s1.binance.org:8545/';

echo "<h2>BSC RPC 连接测试</h2>";
echo "<p>RPC URL: " . htmlspecialchars($rpcUrl) . "</p>";

// 测试 1: 简单的 curl 请求（禁用 SSL 验证）
echo "<h3>测试 1: cURL 基础连接 (SSL 验证关闭)</h3>";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $rpcUrl);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'jsonrpc' => '2.0',
    'method' => 'net_version',
    'params' => [],
    'id' => 1
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$result = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($result === false) {
    echo "<p style='color:red'>✗ 失败: {$error}</p>";
    echo "<p style='color:orange'>💡 如果浏览器可以访问但 PHP 不行，可能是 web 服务器使用了代理</p>";
} else {
    echo "<p style='color:green'>✓ HTTP {$httpCode}</p>";
    $json = json_decode($result, true);
    if ($json && isset($json['result'])) {
        echo "<p>✓ Chain ID (net_version): " . htmlspecialchars($json['result']) . " (97 = BSC Testnet, 56 = BSC Mainnet)</p>";
    }
    echo "<pre>" . htmlspecialchars($result) . "</pre>";
}

// 测试 2: 使用 web3.php 获取区块号
echo "<h3>测试 2: Web3.php 获取最新区块号</h3>";
try {
    require __DIR__ . '/../vendor/autoload.php';

    $eth = new Eth($rpcUrl);
    $eth->blockNumber(function ($err, $data) {
        if ($err !== null) {
            echo "<p style='color:red'>✗ 失败: " . htmlspecialchars($err->getMessage()) . "</p>";
        } else {
            // $data 是 BigInteger 对象
            echo "<p style='color:green'>✓ 最新区块号: " . htmlspecialchars((string)$data) . "</p>";
        }
    });

} catch (\Exception $e) {
    echo "<p style='color:red'>✗ 异常: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 测试 3: 调用合约
echo "<h3>测试 3: 调用合约 getGameState</h3>";
try {
    $contractAddress = '0x271A34b110E555259bf38D1D30Ab95af8Bfb50a9';
    $abi = json_decode('[{"name":"getGameState","type":"function","inputs":[],"outputs":[{"name":"_gameActive","type":"bool"},{"name":"_countdownEnd","type":"uint256"},{"name":"_lastGrabTime","type":"uint256"},{"name":"_lastGrabber","type":"address"},{"name":"_totalPrizePool","type":"uint256"},{"name":"_randomPool","type":"uint256"},{"name":"_teamRewardPool","type":"uint256"},{"name":"_projectPool","type":"uint256"}]}]', true);

    $contract = new \Web3\Contract($rpcUrl, $abi);
    $contract->at($contractAddress);

    $contract->call('getGameState', [], function ($err, $data) use (&$result) {
        if ($err !== null) {
            echo "<p style='color:red'>✗ 合约调用失败: " . htmlspecialchars($err->getMessage()) . "</p>";
        } else {
            echo "<p style='color:green'>✓ 合约调用成功!</p>";
            echo "<ul>";
            echo "<li>游戏激活: " . ($data[0] ? '是' : '否') . "</li>";
            echo "<li>倒计时结束: " . htmlspecialchars($data[1]->toString()) . "</li>";
            echo "<li>总奖池: " . htmlspecialchars($data[4]->toString()) . " wei</li>";
            echo "</ul>";
        }
    });

} catch (\Exception $e) {
    echo "<p style='color:red'>✗ 异常: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 测试 4: 检查 PHP curl 配置
echo "<h3>测试 4: PHP curl 配置</h3>";
echo "<ul>";
echo "<li>curl 扩展: " . (extension_loaded('curl') ? '<span style="color:green">✓</span>' : '<span style="color:red">✗</span>') . "</li>";
echo "<li>openssl 扩展: " . (extension_loaded('openssl') ? '<span style="color:green">✓</span>' : '<span style="color:red">✗</span>') . "</li>";

if (extension_loaded('curl')) {
    $curlVersion = curl_version();
    echo "<li>curl 版本: " . htmlspecialchars($curlVersion['version']) . "</li>";
    echo "<li>SSL 支持: " . (isset($curlVersion['ssl_version']) ? htmlspecialchars($curlVersion['ssl_version']) : '无') . "</li>";
}
echo "</ul>";

echo "<hr>";
echo "<p><strong>下一步:</strong> 如果测试1和2都成功，说明 RPC 连接正常，可以运行 <code>php artisan bnb:healthcheck</code></p>";
