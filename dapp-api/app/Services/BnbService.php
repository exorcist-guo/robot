<?php

namespace App\Services;

use Web3\Contract;
use Web3\Utils;
use Web3\Eth;
use Web3\Providers\HttpProvider;
use Web3\RequestManagers\HttpRequestManager;

class BnbService
{
    // ==================== 配置相关 ====================

    /**
     * 获取当前环境的 RPC URL
     */
    private static function getRpcUrl(): string
    {
        $env = config('bsc.env', 'test');
        return config("bsc.rpc.{$env}");
    }

    /**
     * 获取当前环境的合约地址
     */
    private static function getContractAddress(): string
    {
        $env = config('bsc.env', 'test');
        return config("bsc.contract_address.{$env}");
    }

    /**
     * 获取当前环境的 Chain ID
     */
    private static function getChainId(): int
    {
        $env = config('bsc.env', 'test');
        return config("bsc.chain_id.{$env}");
    }

    /**
     * 获取 Web3 实例
     */
    private static function getEth(int $timeout = 10): Eth
    {
        $rpcUrl = self::getRpcUrl();

        // web3.php 默认 HttpRequestManager 超时=1 秒，这里显式传入 timeout
        $requestManager = new HttpRequestManager($rpcUrl, $timeout);
        $provider = new HttpProvider($requestManager);

        return new Eth($provider);
    }

    /**
     * FomoHongbao 合约 ABI (仅包含需要调用的方法)
     */
    private static function getContractAbi(): array
    {
        return [
            [
                'name' => 'setInviter',
                'type' => 'function',
                'inputs' => [['name' => '_inviter', 'type' => 'address']],
                'outputs' => []
            ],
            [
                'name' => 'grabRedPacket',
                'type' => 'function',
                'inputs' => [],
                'outputs' => []
            ],
            [
                'name' => 'claimReward',
                'type' => 'function',
                'inputs' => [],
                'outputs' => []
            ],
            [
                'name' => 'getUserInfo',
                'type' => 'function',
                'inputs' => [['name' => '_user', 'type' => 'address']],
                'outputs' => [
                    ['name' => 'inviter', 'type' => 'address'],
                    ['name' => 'totalGrabCount', 'type' => 'uint256'],
                    ['name' => 'level', 'type' => 'uint256']
                ]
            ],
            [
                'name' => 'getGameState',
                'type' => 'function',
                'inputs' => [],
                'outputs' => [
                    ['name' => '_gameActive', 'type' => 'bool'],
                    ['name' => '_countdownEnd', 'type' => 'uint256'],
                    ['name' => '_lastGrabTime', 'type' => 'uint256'],
                    ['name' => '_lastGrabber', 'type' => 'address'],
                    ['name' => '_totalPrizePool', 'type' => 'uint256'],
                    ['name' => '_randomPool', 'type' => 'uint256'],
                    ['name' => '_teamRewardPool', 'type' => 'uint256'],
                    ['name' => '_projectPool', 'type' => 'uint256']
                ]
            ],
            [
                'name' => 'getRemainingTime',
                'type' => 'function',
                'inputs' => [],
                'outputs' => [['name' => '', 'type' => 'uint256']]
            ],
            [
                'name' => 'users',
                'type' => 'function',
                'inputs' => [['name' => '', 'type' => 'address']],
                'outputs' => [
                    ['name' => 'inviter', 'type' => 'address'],
                    ['name' => 'totalGrabCount', 'type' => 'uint256'],
                    ['name' => 'level', 'type' => 'uint256']
                ]
            ],
            [
                'name' => 'totalPrizePool',
                'type' => 'function',
                'inputs' => [],
                'outputs' => [['name' => '', 'type' => 'uint256']]
            ],
            [
                'name' => 'randomPool',
                'type' => 'function',
                'inputs' => [],
                'outputs' => [['name' => '', 'type' => 'uint256']]
            ],
            [
                'name' => 'lastGrabber',
                'type' => 'function',
                'inputs' => [],
                'outputs' => [['name' => '', 'type' => 'address']]
            ],
            [
                'name' => 'countdownEnd',
                'type' => 'function',
                'inputs' => [],
                'outputs' => [['name' => '', 'type' => 'uint256']]
            ],
            [
                'name' => 'gameActive',
                'type' => 'function',
                'inputs' => [],
                'outputs' => [['name' => '', 'type' => 'bool']]
            ],
            [
                'name' => 'startGame',
                'type' => 'function',
                'inputs' => [],
                'outputs' => []
            ],
            [
                'name' => 'endGame',
                'type' => 'function',
                'inputs' => [],
                'outputs' => []
            ],
            // 事件 (用于查询日志)
            [
                'name' => 'InviterSet',
                'type' => 'event',
                'inputs' => [
                    ['name' => 'user', 'type' => 'address', 'indexed' => true],
                    ['name' => 'inviter', 'type' => 'address', 'indexed' => true]
                ],
                'anonymous' => false
            ],
        ];
    }

    /**
     * 获取合约实例
     */
    private static function getContract(int $timeout = 10): Contract
    {
        $eth = self::getEth($timeout);
        $contract = new Contract($eth->getProvider(), self::getContractAbi());
        $contract->at(self::getContractAddress());
        return $contract;
    }

    /**
     * 获取事件签名哈希 (keccak256)
     *
     * @param string $signature 事件签名，例如 "InviterSet(address,address)"
     * @return string
     */
    private static function getEventSignature(string $signature): string
    {
        return Utils::sha3($signature);
    }

    // ==================== 查询方法 (不需要私钥) ====================

    /**
     * 获取用户信息
     *
     * @param string $userAddress 用户地址 (0x...)
     * @return array
     */
    public static function getUserInfo(string $userAddress): array
    {
        try {
            $contract = self::getContract();
            $result = [];

            // web3p/web3.php 的 Contract::call 参数需要按顺序逐个传入
            // 不能把函数参数包在数组里，否则 address 类型会收到 array，触发 "Array to string conversion"
            $contract->call('getUserInfo', $userAddress, function ($err, $data) use (&$result) {
                if ($err !== null) {
                    $result = ['error' => $err->getMessage()];
                    return;
                }
                $result = [
                    'inviter' => $data['inviter'] ?? $data[0] ?? null,
                    'totalGrabCount' => ($data['totalGrabCount'] ?? $data[1] ?? null) ? Utils::toHex($data['totalGrabCount'] ?? $data[1]) : '0',
                    'level' => ($data['level'] ?? $data[2] ?? null) ? Utils::toHex($data['level'] ?? $data[2]) : '0',
                ];
            });

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 获取游戏状态
     *
     * @return array
     */
    public static function getGameState(): array
    {
        try {
            $contract = self::getContract();
            $result = [];

            $contract->call('getGameState', [], function ($err, $data) use (&$result) {
                if ($err !== null) {
                    $result = ['error' => $err->getMessage()];
                    return;
                }
                $result = [
                    'gameActive' => $data['_gameActive'] ?? $data[0] ?? false,
                    'countdownEnd' => ($data['_countdownEnd'] ?? $data[1] ?? null) ? Utils::toHex($data['_countdownEnd'] ?? $data[1]) : '0',
                    'lastGrabTime' => ($data['_lastGrabTime'] ?? $data[2] ?? null) ? Utils::toHex($data['_lastGrabTime'] ?? $data[2]) : '0',
                    'lastGrabber' => $data['_lastGrabber'] ?? $data[3] ?? null,
                    'totalPrizePool' => ($data['_totalPrizePool'] ?? $data[4] ?? null) ? self::weiToEth($data['_totalPrizePool'] ?? $data[4]) : '0',
                    'randomPool' => ($data['_randomPool'] ?? $data[5] ?? null) ? self::weiToEth($data['_randomPool'] ?? $data[5]) : '0',
                    'teamRewardPool' => ($data['_teamRewardPool'] ?? $data[6] ?? null) ? self::weiToEth($data['_teamRewardPool'] ?? $data[6]) : '0',
                    'projectPool' => ($data['_projectPool'] ?? $data[7] ?? null) ? self::weiToEth($data['_projectPool'] ?? $data[7]) : '0',
                ];
            });

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 获取剩余时间（秒）
     *
     * @return array
     */
    public static function getRemainingTime(): array
    {
        try {
            $contract = self::getContract();
            $result = [];

            $contract->call('getRemainingTime', [], function ($err, $data) use (&$result) {
                if ($err !== null) {
                    $result = ['error' => $err->getMessage()];
                    return;
                }
                $value = $data[''] ?? $data[0] ?? null;
                $result = [
                    'remainingSeconds' => $value ? Utils::toHex($value) : '0',
                ];
            });

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 获取总奖池
     *
     * @return array
     */
    public static function getTotalPrizePool(): array
    {
        try {
            $contract = self::getContract();
            $result = [];

            $contract->call('totalPrizePool', [], function ($err, $data) use (&$result) {
                if ($err !== null) {
                    $result = ['error' => $err->getMessage()];
                    return;
                }
                $value = $data[''] ?? $data[0] ?? null;
                $result = [
                    'totalPrizePool' => $value ? self::weiToEth($value) : '0',
                ];
            });

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 获取随机奖池
     *
     * @return array
     */
    public static function getRandomPool(): array
    {
        try {
            $contract = self::getContract();
            $result = [];

            $contract->call('randomPool', [], function ($err, $data) use (&$result) {
                if ($err !== null) {
                    $result = ['error' => $err->getMessage()];
                    return;
                }
                $value = $data[''] ?? $data[0] ?? null;
                $result = [
                    'randomPool' => $value ? self::weiToEth($value) : '0',
                ];
            });

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 获取最后抢红包的人
     *
     * @return array
     */
    public static function getLastGrabber(): array
    {
        try {
            $contract = self::getContract();
            $result = [];

            $contract->call('lastGrabber', [], function ($err, $data) use (&$result) {
                if ($err !== null) {
                    $result = ['error' => $err->getMessage()];
                    return;
                }
                $result = [
                    'lastGrabber' => $data[''] ?? $data[0] ?? null,
                ];
            });

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 获取游戏是否激活
     *
     * @return array
     */
    public static function getGameActive(): array
    {
        try {
            $contract = self::getContract();
            $result = [];

            $contract->call('gameActive', [], function ($err, $data) use (&$result) {
                if ($err !== null) {
                    $result = ['error' => $err->getMessage()];
                    return;
                }
                $value = $data[''] ?? $data[0] ?? false;
                $result = [
                    'gameActive' => (bool) $value,
                ];
            });

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 获取倒计时结束时间
     *
     * @return array
     */
    public static function getCountdownEnd(): array
    {
        try {
            $contract = self::getContract();
            $result = [];

            $contract->call('countdownEnd', [], function ($err, $data) use (&$result) {
                if ($err !== null) {
                    $result = ['error' => $err->getMessage()];
                    return;
                }
                $value = $data[''] ?? $data[0] ?? null;
                $result = [
                    'countdownEnd' => $value ? Utils::toHex($value) : '0',
                ];
            });

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // ==================== 交易方法 (构建编码数据，供前端钱包使用) ====================

    /**
     * 构建 setInviter 交易数据
     *
     * @param string $inviterAddress 邀请人地址
     * @return string|null
     */
    public static function buildSetInviterTx(string $inviterAddress): ?string
    {
        try {
            $contract = new Contract(null, self::getContractAbi());
            $data = $contract->getData('setInviter', $inviterAddress);
            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 获取设置上级的交易记录 (InviterSet 事件日志)
     *
     * @param string|null $userAddress 用户地址 (过滤条件，可选)
     * @param string|null $inviterAddress 邀请人地址 (过滤条件，可选)
     * @param int|null $fromBlock 起始区块号 (可选，默认最新 10000 个区块)
     * @param int|null $toBlock 结束区块号 (可选，默认 "latest")
     * @return array
     */
    public static function getSetInviterTxLogs(?string $userAddress = null, ?string $inviterAddress = null, ?int $fromBlock = null, ?int $toBlock = null): array
    {
        try {
            $eth = self::getEth(30); // 日志查询可能较慢，增加超时
            $contractAddress = self::getContractAddress();

            // 构造 topics: [事件签名, user地址, inviter地址]
            $topics = [self::getEventSignature('InviterSet(address,address)')];

            if ($userAddress !== null) {
                if (!self::isValidAddress($userAddress)) {
                    return ['error' => 'Invalid user address'];
                }
                // 地址补齐到 32 字节 (64 个十六进制字符)
                $topics[] = '0x000000000000000000000000' . substr($userAddress, 2);
            }

            if ($inviterAddress !== null) {
                if (!self::isValidAddress($inviterAddress)) {
                    return ['error' => 'Invalid inviter address'];
                }
                $topics[] = '0x000000000000000000000000' . substr($inviterAddress, 2);
            }

            // 默认查询最近 10000 个区块
            if ($fromBlock === null) {
                $latest = self::getLatestBlockNumber($eth);
                $fromBlock = '0x' . dechex(max(0, $latest - 10000));
            } else {
                $fromBlock = '0x' . dechex($fromBlock);
            }

            if ($toBlock === null) {
                $toBlock = 'latest';
            } else {
                $toBlock = '0x' . dechex($toBlock);
            }

            $result = [];
            $eth->getLogs([
                'fromBlock' => $fromBlock,
                'toBlock' => $toBlock,
                'address' => $contractAddress,
                'topics' => $topics
            ], function ($err, $logs) use (&$result) {
                if ($err !== null) {
                    $result = ['error' => $err->getMessage()];
                    return;
                }

                $formattedLogs = [];
                foreach ($logs as $log) {
                    $formattedLogs[] = [
                        'txHash' => $log->transactionHash ?? null,
                        'blockNumber' => isset($log->blockNumber) ? hexdec($log->blockNumber) : null,
                        'user' => self::topicToAddress($log->topics[1] ?? null),
                        'inviter' => self::topicToAddress($log->topics[2] ?? null),
                    ];
                }

                $result = [
                    'total' => count($formattedLogs),
                    'logs' => $formattedLogs
                ];
            });

            return $result;
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * 获取最新区块号 (返回十进制整数)
     */
    private static function getLatestBlockNumber(Eth $eth): int
    {
        $result = 0;
        $eth->blockNumber(function ($err, $block) use (&$result) {
            if ($err === null && $block) {
                // blockNumber 返回 BigInteger，value 是十六进制字符串，例如 "0x05323cc8"
                $value = $block->value ?? $block;

                if (is_string($value) && strpos($value, '0x') === 0) {
                    $result = hexdec($value);
                    return;
                }

                if (is_object($value) && method_exists($value, 'toString')) {
                    $valStr = $value->toString();
                    $result = (strpos($valStr, '0x') === 0) ? hexdec($valStr) : intval($valStr);
                    return;
                }

                $result = intval($value);
            }
        });
        return $result;
    }

    /**
     * 将 topic 转换为地址 (取后 20 字节)
     */
    private static function topicToAddress($topic): ?string
    {
        if ($topic === null || $topic === '') {
            return null;
        }
        $value = is_object($topic) && isset($topic->value) ? $topic->value : $topic;
        if (is_string($value) && strlen($value) >= 66) {
            return '0x' . substr($value, 26);
        }
        return null;
    }


    /**
     * 构建 grabRedPacket 交易数据
     *
     * @return string|null
     */
    public static function buildGrabRedPacketTx(): ?string
    {
        try {
            $contract = new Contract(null, self::getContractAbi());
            $data = $contract->getData('grabRedPacket');
            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 构建 claimReward 交易数据
     *
     * @return string|null
     */
    public static function buildClaimRewardTx(): ?string
    {
        try {
            $contract = new Contract(null, self::getContractAbi());
            $data = $contract->getData('claimReward');
            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 构建 startGame 交易数据 (仅Owner)
     *
     * @return string|null
     */
    public static function buildStartGameTx(): ?string
    {
        try {
            $contract = new Contract(null, self::getContractAbi());
            $data = $contract->getData('startGame');
            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 构建 endGame 交易数据
     *
     * @return string|null
     */
    public static function buildEndGameTx(): ?string
    {
        try {
            $contract = new Contract(null, self::getContractAbi());
            $data = $contract->getData('endGame');
            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    // ==================== 工具方法 ====================

    /**
     * Wei 转换为 ETH/USDT (18位精度)
     *
     * @param mixed $wei
     * @return string
     */
    public static function weiToEth($wei): string
    {
        $weiValue = is_object($wei) ? $wei->value : $wei;
        if (is_string($weiValue) && strpos($weiValue, '0x') === 0) {
            $weiValue = hexdec($weiValue);
        }
        $eth = (string) gmp_div($weiValue, '1000000000000000000');
        return $eth;
    }

    /**
     * ETH/USDT 转换为 Wei (18位精度)
     *
     * @param string $eth
     * @return string
     */
    public static function ethToWei(string $eth): string
    {
        $wei = bcmul($eth, '1000000000000000000', 0);
        return '0x' . dechex($wei);
    }

    /**
     * 验证地址格式
     *
     * @param string $address
     * @return bool
     */
    public static function isValidAddress(string $address): bool
    {
        return preg_match('/^0x[a-fA-F0-9]{40}$/', $address) === 1;
    }

    /**
     * 获取当前环境配置
     *
     * @return array
     */
    public static function getEnvConfig(): array
    {
        $env = config('bsc.env', 'test');
        return [
            'env' => $env,
            'rpc_url' => config("bsc.rpc.{$env}"),
            'contract_address' => config("bsc.contract_address.{$env}"),
            'usdt_address' => config("bsc.usdt_address.{$env}"),
            'chain_id' => config("bsc.chain_id.{$env}"),
        ];
    }

    /**
     * 切换环境
     *
     * @param string $env 'test' 或 'main'
     * @return array
     */
    public static function switchEnv(string $env): array
    {
        if (!in_array($env, ['test', 'main'])) {
            return ['error' => 'Invalid env, must be test or main'];
        }

        // 临时修改配置
        config(['bsc.env' => $env]);

        return [
            'env' => $env,
            'rpc_url' => config("bsc.rpc.{$env}"),
            'contract_address' => config("bsc.contract_address.{$env}"),
            'chain_id' => config("bsc.chain_id.{$env}"),
        ];
    }
}
