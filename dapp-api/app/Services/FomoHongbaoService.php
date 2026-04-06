<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Web3\Utils;
use EthTool\Credential;
use Exception;

/**
 * FomoHongbao 合约服务
 * 用于处理链上等级更新等操作
 */
class FomoHongbaoService
{
    // setUserLevel 函数签名: 0x6a627842
    const FUNCTION_SET_USER_LEVEL = '0x6a627842';

    /**
     * 更新链上用户等级（带重试机制）
     *
     * @param string $userAddress 用户地址
     * @param int $level 新等级 (0-15)
     * @param int $maxRetries 最大重试次数，默认3次
     * @return array 返回交易结果
     * @throws Exception
     */
    public static function setUserLevel(string $userAddress, int $level, int $maxRetries = 3): array
    {
        // 验证参数
        if ($level < 0 || $level > 15) {
            throw new Exception('Invalid level: must be between 0 and 15');
        }

        // 确保地址格式正确
        $userAddress = strtolower(trim($userAddress));
        if (!preg_match('/^0x[a-f0-9]{40}$/', $userAddress)) {
            throw new Exception('Invalid user address format');
        }

        $lastError = null;

        // 重试循环
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Log::info("FomoHongbao: 尝试更新用户等级 (第{$attempt}次)", [
                    'user' => $userAddress,
                    'level' => $level,
                    'attempt' => $attempt
                ]);

                $result = self::executeSetUserLevel($userAddress, $level);

                Log::info("FomoHongbao: 等级更新成功", [
                    'user' => $userAddress,
                    'level' => $level,
                    'txHash' => $result['txHash'] ?? null
                ]);

                return [
                    'success' => true,
                    'attempt' => $attempt,
                    'txHash' => $result['txHash'] ?? null,
                    'message' => '等级更新成功'
                ];

            } catch (Exception $e) {
                $lastError = $e;

                Log::warning("FomoHongbao: 等级更新失败 (第{$attempt}次)", [
                    'user' => $userAddress,
                    'level' => $level,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);

                // 如果不是最后一次尝试，等待后重试
                if ($attempt < $maxRetries) {
                    $waitTime = $attempt * 2; // 递增等待时间：2秒、4秒、6秒
                    sleep($waitTime);
                }
            }
        }

        // 所有重试都失败
        Log::error("FomoHongbao: 等级更新失败，已达最大重试次数", [
            'user' => $userAddress,
            'level' => $level,
            'maxRetries' => $maxRetries,
            'lastError' => $lastError ? $lastError->getMessage() : 'Unknown error'
        ]);

        throw new Exception("等级更新失败，已重试{$maxRetries}次: " . ($lastError ? $lastError->getMessage() : 'Unknown error'));
    }

    /**
     * 执行 setUserLevel 合约调用
     *
     * @param string $userAddress 用户地址
     * @param int $level 等级
     * @return array
     * @throws Exception
     */
    public static function executeSetUserLevel(string $userAddress, int $level): array
    {
        $config = config('bsc');

        // 获取当前环境配置
        $env = $config['env'] ?? 'test';
        $rpcUrl = $config['rpc'][$env] ?? '';
        $chainId = $config['chain_id'][$env] ?? 56;
        $privateKey = '0x693aa7461ed033c1e2d742ca37438d8922e94467e7994791f8117169116cffb2';

        if (empty($privateKey)) {
            throw new Exception('BSC_PRIVATE_KEY not configured');
        }

        if (empty($rpcUrl)) {
            throw new Exception('RPC URL not configured');
        }

        // 创建凭证
        $credential = Credential::fromKey($privateKey);
        $walletAddress = $credential->getAddress();
        $contractAddress = $config['contract_address'][$env] ?? '';

        if (empty($contractAddress)) {
            throw new Exception('Contract address not configured');
        }

        // 获取 nonce
        $nonce = self::getNonce($walletAddress, $rpcUrl);

        // 获取 gasPrice
        $gasPrice = self::getGasPrice($rpcUrl);

        // 编码函数调用数据
        // setUserLevel(address _user, uint256 _level)
        $userAddressPadded = str_pad(substr($userAddress, 2), 64, '0', STR_PAD_LEFT);
        $levelPadded = str_pad(dechex($level), 64, '0', STR_PAD_LEFT);
        $data = self::FUNCTION_SET_USER_LEVEL . $userAddressPadded . $levelPadded;

        // 构建交易
        $raw = [
            'nonce' => Utils::toHex($nonce, true),
            'gasPrice' => '0x' . Utils::toWei($gasPrice, 'gwei')->toHex(),
            'gasLimit' => Utils::toHex(100000, true), // 估计的 gas limit
            'to' => $contractAddress,
            'value' => Utils::toHex(0, true),
            'data' => $data,
            'chainId' => $chainId
        ];

        // 签名交易
        $signed = $credential->signTransaction($raw);

        // 发送交易
        $result = self::sendRawTransaction($signed, $rpcUrl);

        if (isset($result['error']) || !isset($result['result'])) {
            throw new Exception('Transaction failed: ' . json_encode($result));
        }

        return [
            'txHash' => $result['result']
        ];
    }

    /**
     * 获取账户 nonce
     *
     * @param string $address
     * @param string $rpcUrl
     * @return int
     */
    private static function getNonce(string $address, string $rpcUrl): int
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'eth_getTransactionCount',
            'params' => [$address, 'latest'],
            'id' => 1
        ];

        $response = self::rpcCall($rpcUrl, $data);

        if (isset($response['error'])) {
            throw new Exception('Failed to get nonce: ' . json_encode($response['error']));
        }

        return hexdec($response['result']);
    }

    /**
     * 获取当前 gas 价格
     *
     * @param string $rpcUrl
     * @return float (gwei)
     */
    private static function getGasPrice(string $rpcUrl): float
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'eth_gasPrice',
            'params' => [],
            'id' => 1
        ];

        $response = self::rpcCall($rpcUrl, $data);

        if (isset($response['error'])) {
            throw new Exception('Failed to get gasPrice: ' . json_encode($response['error']));
        }

        return hexdec($response['result']) / 1000000000;
    }

    /**
     * 发送原始交易
     *
     * @param string $signedTx 签名后的交易
     * @param string $rpcUrl
     * @return array
     */
    private static function sendRawTransaction(string $signedTx, string $rpcUrl): array
    {
        $data = [
            'jsonrpc' => '2.0',
            'method' => 'eth_sendRawTransaction',
            'params' => [$signedTx],
            'id' => 1
        ];

        return self::rpcCall($rpcUrl, $data);
    }

    /**
     * RPC 调用
     *
     * @param string $rpcUrl
     * @param array $data
     * @return array
     * @throws Exception
     */
    private static function rpcCall(string $rpcUrl, array $data): array
    {
        $ch = curl_init($rpcUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception('RPC call failed: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new Exception('RPC call failed with HTTP code: ' . $httpCode);
        }

        $result = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . $response);
        }

        return $result;
    }

    /**
     * 批量更新多个用户的等级
     *
     * @param array $users [['address' => '0x...', 'level' => 5], ...]
     * @param int $maxRetries 每个用户的最大重试次数
     * @return array ['success' => [], 'failed' => []]
     */
    public static function batchSetUserLevel(array $users, int $maxRetries = 3): array
    {
        $results = [
            'success' => [],
            'failed' => []
        ];

        foreach ($users as $user) {
            if (!isset($user['address']) || !isset($user['level'])) {
                $results['failed'][] = [
                    'user' => $user,
                    'error' => 'Missing address or level'
                ];
                continue;
            }

            try {
                $result = self::setUserLevel($user['address'], $user['level'], $maxRetries);
                $results['success'][] = [
                    'address' => $user['address'],
                    'level' => $user['level'],
                    'txHash' => $result['txHash']
                ];
            } catch (Exception $e) {
                $results['failed'][] = [
                    'address' => $user['address'],
                    'level' => $user['level'],
                    'error' => $e->getMessage()
                ];
            }

            // 避免请求过快，每个请求之间延迟
            usleep(500000); // 0.5秒
        }

        return $results;
    }
}
