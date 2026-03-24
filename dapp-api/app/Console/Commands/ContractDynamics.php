<?php

namespace App\Console\Commands;

use App\Jobs\ContractDynamicsJob;
use App\Models\ContractDynamic;
use App\Services\BnbChainService;
use Illuminate\Console\Command;

class ContractDynamics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'contract-dynamics';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '合约动态';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // $this->checkDynamics();
        while ( true){
            $this->checkDynamics();
            sleep(5);
        }
    }

    public function checkDynamics()
    {
        $env = config('bsc.env');
        $contractAddress = config('bsc.contract_address.'.$env, '');

        $chain_id = intval($item['chainId'] ?? config('bsc.chain_id.'.$env, 97));

        $startBlock = ContractDynamic::where('chain_id', $chain_id)->orderByDesc('id')->value('block_number');

        $startBlock = $startBlock?:78854250;

        $list = BnbChainService::getList($contractAddress, $startBlock);
        if (!$list || empty($list['result']) || !is_array($list['result'])) {
            $this->error('获取交易列表失败');
            return;
        }

        $inserted = 0;
        foreach ($list['result'] as $item) {
            if (!is_array($item) || empty($item['hash'])) {
                continue;
            }

            $model = ContractDynamic::updateOrCreate(
                ['tx_hash' => $item['hash']],
                [
                    'chain_id' => $chain_id,
                    'contract_address' => strtolower($contractAddress),
                    'block_number' => intval($item['blockNumber'] ?? 0),
                    'time_stamp' => intval($item['timeStamp'] ?? 0),
                    'block_hash' => $item['blockHash'] ?? null,
                    'nonce' => isset($item['nonce']) ? intval($item['nonce']) : null,
                    'transaction_index' => isset($item['transactionIndex']) ? intval($item['transactionIndex']) : null,
                    'from_address' => isset($item['from']) ? strtolower($item['from']) : null,
                    'to_address' => isset($item['to']) ? strtolower($item['to']) : null,
                    'value' => (string)($item['value'] ?? '0'),
                    'gas' => isset($item['gas']) ? (string)$item['gas'] : null,
                    'gas_price' => isset($item['gasPrice']) ? (string)$item['gasPrice'] : null,
                    'cumulative_gas_used' => isset($item['cumulativeGasUsed']) ? (string)$item['cumulativeGasUsed'] : null,
                    'gas_used' => isset($item['gasUsed']) ? (string)$item['gasUsed'] : null,
                    'confirmations' => isset($item['confirmations']) ? (string)$item['confirmations'] : null,
                    'is_error' => intval($item['isError'] ?? 0),
                    'txreceipt_status' => intval($item['txreceipt_status'] ?? 0),
                    'input' => $item['input'] ?? null,
                    'method_id' => $item['methodId'] ?? null,
                    'function_name' => $item['functionName'] ?? null,
                ]
            );

            // 新创建记录时，添加到队列
            if ($model->wasRecentlyCreated) {
                ContractDynamicsJob::dispatch($model->id);
            }

            $inserted++;
        }

        $this->info("写入/更新交易记录: {$inserted} 条");
    }



}
