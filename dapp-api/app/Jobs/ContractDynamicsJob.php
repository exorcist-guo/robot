<?php

namespace App\Jobs;

use App\Console\Commands\ContractDynamics;

use App\Models\ContractDynamic;
use App\Models\IncomeRecord;
use App\Models\Member;
use App\Models\PerformanceRecord;
use App\Services\BnbChainService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use \Exception;
use Illuminate\Support\Facades\Log;

class ContractDynamicsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // 事件签名（topics[0]）
    const EVENT_RANDOM_REWARD = '0xc53f3cf272c49d7c6f801737ca0453f745a1b8afb3524f89dd70be54df3bf7a9'; // RandomRewardDistributed
    const EVENT_DIRECT_REWARD = '0xd962b342ac32fe9a61eacf6e3d4a79c854d1898d4bf626d389d8e05220a59eef'; // DirectRewardPaid
    const EVENT_TEAM_REWARD = '0xc44138bf3f59a193677ae51d52c0e36b18bc2e018f7b73d232deab73ca21b844'; // TeamRewardPaid(address,uint256,uint256)
    const EVENT_GAME_ENDED = '0xd4ba6fec82d9b0e8ffe50b9fed9e4be3b25c984ce1e0e016405a5528726e8a2c'; // GameEnded(address,uint256)
    const EVENT_LUCKY_REWARD = '0x0a157c96d70bd3634a33dcb7995b160ee599c1aa5924fe58256370e1b723a963'; // LuckyRewardDistributed(address,uint256)
    const EVENT_GRAB_RED_PACKET = '0x82ebef5485bae4a30d0265c68fc6e82e0144b39084fa0cac47a6ed7a372b4e2d'; // GrabRedPacket
    const EVENT_TRANSFER = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef'; // Transfer

    protected $push_id;

    /**
     * Create a new job instance.
     */
    public function __construct($id)
    {
        $this->push_id = $id;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $id = $this->push_id;
        $contract_dynamic = ContractDynamic::where('id', $id)->first();


        //状态正确,才执行
        if($contract_dynamic && $contract_dynamic->status == 0){
            try{
                $env = config('bsc.env');
                $contractAddress = config('bsc.contract_address.'.$env, '');
                $contractAddress = strtolower($contractAddress);

                DB::beginTransaction();
                $contract_dynamic->status = 1;   //处理完成

                if($contract_dynamic->txreceipt_status == 1 && $contract_dynamic->to_address == $contractAddress){
                    switch ($contract_dynamic->method_id) {
                        case '0xc4712e21':  //grabRedPacket()
                            /*
                            1.增加自己的业绩,和上级的业绩
                            2.创建一个业绩记录表
                            3.创建一个收益记录表(要有收益记录类型)
                            4.给用户表增加收益的统计字段,和抢购消费统计字段
                            5.写入业绩记录,和收益记录
                            参考文件:
                               合约@src/FomoHongbao.sol
                               记录写入地方@dapp-api\app\Console\Commands\ContractDynamics.php
                            */

                            // 抢红包金额固定为 10 USDT
                            $grabAmount = 10;

                            // 获取或创建用户
                            $user = Member::where('address', $contract_dynamic->from_address)->first();
                            if (!$user) {
                                $user = Member::createMember($contract_dynamic->from_address);
                            }

                            // 更新用户消费和抢红包次数
                            $user->total_consumption += $grabAmount;
                            $user->total_grab_count += 1;
                            $user->performance += $grabAmount;
                            $user->save();

                            // 创建自己的业绩记录
                            $performanceRecord = PerformanceRecord::create([
                                'member_id' => $user->id,
                                'parent_id' => $user->pid,
                                'amount' => $grabAmount,
                                'contract_dynamic_id' => $contract_dynamic->id,
                                'time_stamp' => date('Y-m-d H:i:s', $contract_dynamic->time_stamp),
                                'type' => 'grab',
                            ]);
                            Member::checkLevel($user);

                            // 给上级增加业绩（遍历所有上级）

                            $user_ids = array_reverse(explode('/',$user->path));
                            $parentIds = array_filter($user_ids);

                            foreach ($parentIds as $parentId) {
                                $parent = Member::find($parentId);
                                if ($parent) {
                                    $parent->performance += $grabAmount;
                                    $parent->save();

                                    // 创建上级的业绩记录
                                    PerformanceRecord::create([
                                        'member_id' => $parent->id,
                                        'parent_id' => $parent->pid,
                                        'amount' => $grabAmount,
                                        'contract_dynamic_id' => $contract_dynamic->id,
                                        'time_stamp' => date('Y-m-d H:i:s', $contract_dynamic->time_stamp),
                                        'type' => 'team_grab',
                                    ]);
                                    Member::checkLevel($parent);
                                }
                            }

                            //获取交易详情，失败则重试
                            $transactionReceipt = null;
                            $retryCount = 0;
                            $maxRetries = 3;

                            while ($retryCount < $maxRetries) {
                                $transactionReceipt = BnbChainService::getNewTransactionReceipt($contract_dynamic->tx_hash);
                                if ($transactionReceipt && isset($transactionReceipt['result'])) {
                                    break;
                                }
                                $retryCount++;
                                if ($retryCount < $maxRetries) {
                                    sleep(3);
                                }
                            }

                            // 解析交易收据中的事件日志，创建收益记录
                            if ($transactionReceipt && isset($transactionReceipt['result']['logs'])) {
                                $this->parseTransactionLogs($transactionReceipt['result']['logs'], $user, $contract_dynamic);
                            }else{
                                $contract_dynamic->status = 6;
                            }

                            break;
                        case '0x6cbc2ded':  //endGame()
                            // 获取交易详情，失败则重试
                            $transactionReceipt = null;
                            $retryCount = 0;
                            $maxRetries = 3;

                            while ($retryCount < $maxRetries) {
                                $transactionReceipt = BnbChainService::getNewTransactionReceipt($contract_dynamic->tx_hash);
                                if ($transactionReceipt && isset($transactionReceipt['result'])) {
                                    break;
                                }
                                $retryCount++;
                                if ($retryCount < $maxRetries) {
                                    sleep(3);
                                }
                            }

                            // 解析结束游戏的交易日志
                            if ($transactionReceipt && isset($transactionReceipt['result']['logs'])) {
                                $this->parseEndGameLogs($transactionReceipt['result']['logs'], $contract_dynamic);
                            } else {
                                $contract_dynamic->status = 6;
                            }

                            break;

                        case '0xe2f72829':  //setInviter(address _inviter)
                            $user = Member::where('address', $contract_dynamic->from_address)->first();
                            if(!$user){
                                $user = Member::createMember($contract_dynamic->from_address);
                            }
                            if(empty($user->pid)){
                                // 从 input 中解析出邀请人地址
                                // 格式: 0xe2f72829 + 64字符的地址参数（32字节，左补零）
                                $input = $contract_dynamic->input;
                                $p_address = '0x' . substr($input, 10 + 24);  // 跳过方法选择器(10字符)和前面的24个零，得到40字符地址
                                $p_address = strtolower($p_address);
                                $p_member = Member::where('address', $p_address)->first();
                                if(!$p_member){
                                    $p_member = Member::createMember($p_address);
                                }

                                if($user->id == $p_member->id){
                                    throw new Exception('邀请人不能是自己');
                                }

                                $paths = explode('/', $p_member->path);
                                if(in_array($user->id, $paths)) {
                                    throw new Exception('邀请人不能是自己的下级');
                                }

                                $pid = $p_member->id;
                                if($p_member->path){
                                    $path = $p_member->path . $pid.'/';
                                }else{
                                    $path = '/'.$pid.'/';
                                }
                                $user->pid = $pid;
                                $user->path = $path;
                                $user->deep = $p_member->deep + 1;
                                $user->save();
                            }
                            break;
                        default:
                            $contract_dynamic->status = 2;

                    }







                }elseif($contract_dynamic->to_address != $contractAddress){
                    $contract_dynamic->status = 3;
                }else{
                    $contract_dynamic->status = 4;
                }
                $contract_dynamic->save();
                DB::commit();
            }catch (Exception $e){
                DB::rollBack();
                $contract_dynamic->status = 5;
                $contract_dynamic->save();
                Log::channel('contract_dynamic')->info('处理合约记录失败:'.$contract_dynamic->id,[$e->getMessage(),$e->getFile(),$e->getLine()]);

            }





        }

    }

    /**
     * 解析交易日志并创建收益记录
     */
    private function parseTransactionLogs($logs, $user, $contract_dynamic)
    {
        foreach ($logs as $log) {
            if (!isset($log['topics'][0])) {
                continue;
            }

            $topic0 = $log['topics'][0];

            // RandomRewardDistributed 事件 (随机奖励)
            // event RandomRewardDistributed(address indexed user, uint256 amount);
            if ($topic0 === self::EVENT_RANDOM_REWARD) {
                $this->handleRandomReward($log, $user, $contract_dynamic);
            }
            // DirectRewardPaid 事件 (直推奖励)
            // event DirectRewardPaid(address indexed inviter, address indexed invitee, uint256 amount);
            elseif ($topic0 === self::EVENT_DIRECT_REWARD) {
                $this->handleDirectReward($log, $user, $contract_dynamic);
            }
            // TeamRewardPaid 事件 (团队奖励)
            // event TeamRewardPaid(address indexed user, uint256 amount, uint256 level);
            elseif ($topic0 === self::EVENT_TEAM_REWARD) {
                $this->handleTeamReward($log, $user, $contract_dynamic);
            }
            // LuckyRewardDistributed 事件 (幸运奖)
            // event LuckyRewardDistributed(address indexed user, uint256 amount);
            elseif ($topic0 === self::EVENT_LUCKY_REWARD) {
                $this->handleLuckyReward($log, $user, $contract_dynamic);
            }
        }
    }

    /**
     * 处理随机奖励事件
     */
    private function handleRandomReward($log, $user, $contract_dynamic)
    {
        // topics[1] = user address (indexed)
        // data = amount
        if (!isset($log['topics'][1]) || !isset($log['data'])) {
            return;
        }

        $rewardAddress = '0x' . substr($log['topics'][1], -40);
        $rewardAddress = strtolower($rewardAddress);

        // 只处理当前用户的奖励
        if ($rewardAddress !== strtolower($user->address)) {
            return;
        }

        $amountHex = $log['data'];
        $amount = hexdec($amountHex) / 1e18; // 转换为 USDT

        // 创建收益记录
        IncomeRecord::create([
            'member_id' => $user->id,
            'amount' => $amount,
            'type' => IncomeRecord::TYPE_RANDOM_REWARD,
            'tx_hash' => $contract_dynamic->tx_hash,
            'contract_dynamic_id' => $contract_dynamic->id,
            'from_address' => $rewardAddress,
            'time_stamp' => date('Y-m-d H:i:s', $contract_dynamic->time_stamp),
            'remark' => '抢红包随机奖励',
        ]);

        // 更新用户总收益
        $user->total_earnings += $amount;
        $user->save();
    }

    /**
     * 处理直推奖励事件
     */
    private function handleDirectReward($log, $user, $contract_dynamic)
    {
        // topics[1] = inviter address (indexed)
        // topics[2] = invitee address (indexed)
        // data = amount
        if (!isset($log['topics'][1]) || !isset($log['data'])) {
            return;
        }

        $inviterAddress = '0x' . substr($log['topics'][1], -40);
        $inviterAddress = strtolower($inviterAddress);

        $amountHex = $log['data'];
        $amount = hexdec($amountHex) / 1e18;

        // 查找邀请人
        $inviter = Member::where('address', $inviterAddress)->first();
        if (!$inviter) {
            $inviter = Member::createMember($inviterAddress);
        }

        // 创建收益记录
        IncomeRecord::create([
            'member_id' => $inviter->id,
            'amount' => $amount,
            'type' => IncomeRecord::TYPE_DIRECT_REWARD,
            'tx_hash' => $contract_dynamic->tx_hash,
            'contract_dynamic_id' => $contract_dynamic->id,
            'from_address' => $user->address,
            'time_stamp' => date('Y-m-d H:i:s', $contract_dynamic->time_stamp),
            'remark' => '直推奖励',
        ]);

        // 更新邀请人总收益
        $inviter->total_earnings += $amount;
        $inviter->save();
    }

    /**
     * 处理团队奖励事件
     */
    private function handleTeamReward($log, $user, $contract_dynamic)
    {
        // topics[1] = user address (indexed)
        // data = amount (uint256) + level (uint256)
        if (!isset($log['topics'][1]) || !isset($log['data'])) {
            return;
        }

        $rewardAddress = '0x' . substr($log['topics'][1], -40);
        $rewardAddress = strtolower($rewardAddress);

        // data 包含两个 uint256，每个32字节(64个hex字符)
        $data = $log['data'];
        // 去掉 0x 前缀
        $data = substr($data, 2);

        // amount 是前32字节
        $amountHex = '0x' . substr($data, 0, 64);
        $amount = hexdec($amountHex) / 1e18;

        // level 是后32字节
        $levelHex = '0x' . substr($data, 64, 64);
        $level = hexdec($levelHex);

        // 查找获得奖励的用户
        $rewardMember = Member::where('address', $rewardAddress)->first();
        if (!$rewardMember) {
            $rewardMember = Member::createMember($rewardAddress);
        }

        // 创建收益记录
        IncomeRecord::create([
            'member_id' => $rewardMember->id,
            'amount' => $amount,
            'type' => IncomeRecord::TYPE_TEAM_REWARD,
            'tx_hash' => $contract_dynamic->tx_hash,
            'contract_dynamic_id' => $contract_dynamic->id,
            'from_address' => $user->address,
            'time_stamp' => date('Y-m-d H:i:s', $contract_dynamic->time_stamp),
            'remark' => '团队奖励 (等级: ' . $level . ')',
        ]);

        // 更新用户总收益
        $rewardMember->total_earnings += $amount;
        $rewardMember->save();
    }

    /**
     * 处理幸运奖事件
     * 幸运奖：千分之1几率获得totalPrizePool的10%
     * event LuckyRewardDistributed(address indexed user, uint256 amount);
     */
    private function handleLuckyReward($log, $user, $contract_dynamic)
    {
        // topics[1] = user address (indexed)
        // data = amount
        if (!isset($log['topics'][1]) || !isset($log['data'])) {
            return;
        }

        $winnerAddress = '0x' . substr($log['topics'][1], -40);
        $winnerAddress = strtolower($winnerAddress);

        $amountHex = $log['data'];
        $amount = hexdec($amountHex) / 1e18; // 转换为 USDT

        // 查找或创建获奖用户
        $winner = Member::where('address', $winnerAddress)->first();
        if (!$winner) {
            $winner = Member::createMember($winnerAddress);
        }

        // 创建收益记录
        IncomeRecord::create([
            'member_id' => $winner->id,
            'amount' => $amount,
            'type' => IncomeRecord::TYPE_LUCKY_REWARD,
            'tx_hash' => $contract_dynamic->tx_hash,
            'contract_dynamic_id' => $contract_dynamic->id,
            'from_address' => $user->address,
            'time_stamp' => date('Y-m-d H:i:s', $contract_dynamic->time_stamp),
            'remark' => '幸运奖 (千分之1几率)',
        ]);

        // 更新用户总收益
        $winner->total_earnings += $amount;
        $winner->save();
    }

    /**
     * 解析结束游戏交易日志
     * 处理超级大奖和二等奖池分配
     */
    private function parseEndGameLogs($logs, $contract_dynamic)
    {
        $env = config('bsc.env');
        $usdtAddress = strtolower(config('bsc.usdt_address.'.$env, '0x9a256a150b172dd61b9355d5808df7bb58f43e6a'));
        $contractAddress = strtolower(config('bsc.contract_address.'.$env, ''));
        $projectWallet = strtolower(config('bsc.project_wallet', ''));

        // 从 GameEnded 事件中获取获奖者信息
        $winnerAddress = null;

        foreach ($logs as $log) {
            if (!isset($log['topics'][0])) {
                continue;
            }

            $topic0 = $log['topics'][0];

            // GameEnded 事件 - 获取获奖者
            // event GameEnded(address indexed winner, uint256 superPrize);
            if ($topic0 === self::EVENT_GAME_ENDED && isset($log['topics'][1])) {
                $winnerAddress = '0x' . substr($log['topics'][1], -40);
                $winnerAddress = strtolower($winnerAddress);
            }
        }

        // 处理所有 Transfer 事件
        foreach ($logs as $log) {
            if (!isset($log['topics'][0])) {
                continue;
            }

            $topic0 = $log['topics'][0];
            $logAddress = isset($log['address']) ? strtolower($log['address']) : '';

            // Transfer 事件（USDT转账）
            if ($topic0 === self::EVENT_TRANSFER && $logAddress === $usdtAddress) {
                if (!isset($log['topics'][2]) || !isset($log['data'])) {
                    continue;
                }

                $toAddress = '0x' . substr($log['topics'][2], -40);
                $toAddress = strtolower($toAddress);

                // 跳过转给项目方的记录
                if ($toAddress === $projectWallet) {
                    continue;
                }

                // 只记录从合约转出的
                $fromAddress = '0x' . substr($log['topics'][1], -40);
                $fromAddress = strtolower($fromAddress);

                if ($fromAddress !== $contractAddress) {
                    continue;
                }

                $amount = hexdec($log['data']) / 1e18;

                // 查找或创建收款用户
                $receiver = Member::where('address', $toAddress)->first();
                if (!$receiver) {
                    $receiver = Member::createMember($toAddress);
                }

                // 判断奖励类型
                // 如果收款地址是 GameEnded 的获奖者，则是超级大奖
                // 否则是二等奖（因为游戏结束时只有这两类奖励发给用户）
                if ($toAddress === $winnerAddress) {
                    $rewardType = IncomeRecord::TYPE_SUPER_PRIZE;
                    $remark = '超级大奖';
                } else {
                    $rewardType = IncomeRecord::TYPE_SECOND_PRIZE;
                    $remark = '二等奖';
                }

                // 创建收益记录
                IncomeRecord::create([
                    'member_id' => $receiver->id,
                    'amount' => $amount,
                    'type' => $rewardType,
                    'tx_hash' => $contract_dynamic->tx_hash,
                    'contract_dynamic_id' => $contract_dynamic->id,
                    'time_stamp' => date('Y-m-d H:i:s', $contract_dynamic->time_stamp),
                    'remark' => $remark,
                ]);

                // 更新用户总收益
                $receiver->total_earnings += $amount;
                $receiver->save();
            }
        }
    }

}
