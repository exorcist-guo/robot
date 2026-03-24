<?php

namespace App\Console\Commands;

use App\Models\Pm\PmCopyTask;
use App\Services\Pm\PolymarketClientFactory;
use App\Services\Pm\PolymarketDataClient;
use App\Services\Pm\TailSweepPriceCache;
use Illuminate\Console\Command;

class TestCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '测试 Polymarket CLOB 集成';

    /**
     * Execute the console command.
     */
    public function handle(PolymarketClientFactory $factory)
    {
        //获取时间戳，当现在是 9:54 ,我要获取 9:50:00 的数据时间戳，就是当时分钟的时间是五的倍数的时间戳



        $tasks = PmCopyTask::where('status',1)->where('mode','tail_sweep')->select('market_slug')->groupBy('market_slug')->get();
        $gammaClient = $factory->makeReadClient();
        foreach ($tasks as $task){
            $market_slug = $task->market_slug;
            $now = time();
            $minutes = (int)date('i', time());
            $targetMinutes = floor($minutes / 5) * 5; // 向下取整到最近的 5 的倍数
            $timestamp = strtotime(date('Y-m-d H:', $now) . sprintf('%02d', $targetMinutes) . ':00');
            $market_slug = $market_slug . '-'. $timestamp;
            $list = $gammaClient->gamma()->markets()->getBySlug('btc-updown-5m-1774341600');
        }


        exit;

        //获取市场列表



        // $list = array_values(array_filter($list, static fn (array $market): bool => ($market['category'] ?? null) === 'Crypto'));
        var_dump($list);

        exit;

        $priceCache = new TailSweepPriceCache();
        // 默认标的是 btc/usd；同一 symbol 在本轮扫描内只读取一次共享缓存。
        $symbol = $priceCache->normalizeSymbol( 'btc/usd');
        $snapshots = [];
        if (!array_key_exists($symbol, $snapshots)) {
            $snapshot = $priceCache->getSnapshot($symbol);
            if (!$priceCache->isFresh($snapshot)) {
                $snapshots[$symbol] = null;
                $this->warn("标的 {$symbol} 缓存行情缺失或已过期，跳过本轮扫描");
            } else {
                $snapshots[$symbol] = $snapshot;
            }
        }

        $snapshot = $snapshots[$symbol];


        // currentPrice 是实时价格；priceToBeat 是市场设定的结算基准价。
        $currentPrice = (string) ($snapshot['value'] ?? '0');


        var_dump($snapshot);
        exit;


        $dataClient = new PolymarketDataClient();
        $trades = $dataClient->getTradesByUser('0x63ce342161250d705dc0b16df89036c8e5f9ba9a', 1, 0);
        var_dump($trades);



        exit;
        // 注意：这是一个测试命令，用于验证 Polymarket CLOB 集成
        // 生产环境应该从数据库或配置中读取凭据，而不是硬编码

        $privateKey = '4a8a958b74044f46e4c22173ee8f7080ba4f3f6d47cd73b3326e337c86d38ec9';
        $funder = '0x15181c57De2780D33072cd4FFB54623f4C02194C';
        $signatureType = 0; // EOA

        try {
            $this->info('开始测试 Polymarket CLOB API...');

            // 计算 signer 地址
            $addr = new \kornrunner\Ethereum\Address($privateKey);
            $signerAddress = '0x' . $addr->get();
            $this->info('Signer: ' . $signerAddress);
            $this->info('Funder: ' . $funder);

            // 使用 ClobAuthenticator 派生或创建 API 凭证
            $signer = new \PolymarketPhp\Polymarket\Auth\Signer\Eip712Signer(
                $privateKey,
                (int) config('pm.chain_id', 137)
            );

            $auth = new \PolymarketPhp\Polymarket\Auth\ClobAuthenticator(
                $signer,
                (string) config('pm.clob_base_url'),
                (int) config('pm.chain_id', 137)
            );

            $this->info('正在派生 API 凭证...');
            $creds = $auth->deriveOrCreateCredentials(0);

            $this->info('API Key: ' . $creds->apiKey);
            $this->info('凭证派生成功！');

            // 创建认证客户端
            $client = $factory->makeAuthedClobClient($privateKey, $creds);

            $this->info('正在获取余额信息...');
            $balance = $client->clob()->account()->getBalanceAllowance([
                'asset_type' => 'COLLATERAL',
                'signature_type' => $signatureType,
                'funder' => strtolower($funder),
            ]);

            $this->info('✓ 余额查询成功！');
            dd($balance);

        } catch (\PolymarketPhp\Polymarket\Exceptions\AuthenticationException $e) {
            $this->error('认证失败：' . $e->getMessage());
            $this->error('HTTP Code: ' . $e->getCode());
            $this->warn('可能原因:');
            $this->warn('1. 私钥格式不正确');
            $this->warn('2. API 凭证已过期，请尝试重新派生');
            $this->warn('3. Funder 地址与私钥不匹配');
            return self::FAILURE;
        } catch (\Exception $e) {
            $this->error('请求失败：' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
