<?php


namespace App\Services;


use GuzzleHttp\Client;
use Illuminate\Support\Facades\Crypt;
use Web3\Web3;
use Web3\Utils;
use EthTool\Callback;
use EthTool\Credential;
use EthTool\KeyStore;
use Elliptic\EC;
use kornrunner\Keccak;
use Exception;

class BnbChainService {
    const API_KEY = '7MW1HG3QAZCTVKVKVWJIIZ4KH6BB4R6J21';
//    const URL = 'https://api.bscscan.com/api';
    const URL = 'https://api.etherscan.io/v2/api';

    const CHAIN_ID = '56';


    public static  function formatMessage(array $siweMessage): string
    {
        $message = "{$siweMessage['domain']} wants you to sign in with your Ethereum account:";
        $message .= "\n{$siweMessage['address']}\n\n";
        $message .= "{$siweMessage['statement']}\n\n";
        $message .= "URI: {$siweMessage['uri']}\n";
        $message .= "Version: {$siweMessage['version']}\n";
        $message .= "Chain ID: {$siweMessage['chainId']}\n";
        $message .= "Nonce: {$siweMessage['nonce']}\n";
        $message .= "Issued At: {$siweMessage['issuedAt']}";

        return $message;
    }

    public static function recoverAddress(string $message, string $signature): string
    {
        // 添加以太坊签名前缀
        $messageHash = Keccak::hash("\x19Ethereum Signed Message:\n" . strlen($message) . $message, 256);

        // 解析签名
        $signature = Utils::stripZero($signature);
        if (strlen($signature) !== 130) {
            throw new \Exception('Invalid signature length');
        }

        $r = substr($signature, 0, 64);
        $s = substr($signature, 64, 64);
        $v = substr($signature, 128, 2);

        // 调整v值
        $v = hexdec($v);
        if ($v >= 27) {
            $v -= 27;
        }

        // 使用椭圆曲线恢复公钥
        $ec = new EC('secp256k1');
        $publicKey = $ec->recoverPubKey($messageHash, [
            'r' => $r,
            's' => $s
        ], $v);

        // 从公钥生成地址
        $publicKeyHex = $publicKey->encode('hex');
        $address = '0x' . substr(Keccak::hash(substr(hex2bin($publicKeyHex), 1), 256), 24);

        return $address;
    }

    //创建地址
    public static function createAddress(){
        $ec = new Ec('secp256k1');
        $keyPair = $ec->genKeyPair();
        $privateKey = $keyPair->getPrivate()->toString(16, 2);
        $publicKey = $keyPair->getPublic()->encode('hex');
        $address = '0x' . substr(Keccak::hash(substr(hex2bin($publicKey), 1), 256), 24);
        $address = strtolower($address);
        $enPrivateKey = GoogleDecryptServices::encrypt($privateKey);
        $data = [
            'address' => $address,
            'public_key' => $publicKey,
            'private_key' => $privateKey,
            'enPrivateKey' => $enPrivateKey,
        ];
        return $data;
    }

    public static function createNewAddress(){
        $ec = new Ec('secp256k1');
        $keyPair = $ec->genKeyPair();
        $privateKey = $keyPair->getPrivate()->toString(16, 2);
        $publicKey = $keyPair->getPublic()->encode('hex');
        $address = '0x' . substr(Keccak::hash(substr(hex2bin($publicKey), 1), 256), 24);
        $address = strtolower($address);
        $data = [
            'address' => $address,
            'public_key' => $publicKey,
            'private_key' => $privateKey,
        ];
        return $data;
    }

    public static function getUrl($url){
        $client = new Client();
        $response = $client->get($url);
        $response = $response->getBody();
        $response = json_decode($response, true);
        return $response;
    }

    public static function getBalance($address){
        $url = self::URL.'?chainid='.self::CHAIN_ID.'&module=account&action=balance&apikey='.self::API_KEY.'&address='.$address;
        $result = self::getUrl($url);

        $balance = 0;
        if(!empty($result['result'])){
            $balance = number_format($result['result'] / 1000000000000000000, 8, '.', '');
        }
        return $balance;
    }

    public static function getTokenBalance($address,$token){
        $url = self::URL.'?chainid='.self::CHAIN_ID.'&module=account&action=tokenbalance&apikey='.self::API_KEY.'&address='.$address.'&contractaddress='.$token;
        $result = self::getUrl($url);
        $balance = 0;
        if(!empty($result['result'])){
            $balance = number_format($result['result'] / 1000000000000000000, 8, '.', '');
        }
        return $balance;
    }
    public static function getList($contractaddress,$startblock,$offset = 1000){
        $url = self::URL.'?chainid='.self::CHAIN_ID."&module=account&action=txlist&address={$contractaddress}&page=1&offset={$offset}&startblock={$startblock}&sort=asc&apikey=".self::API_KEY;

        $s_time = time();

        $result = self::getUrl($url);

        $e_time = time();
        $s = $e_time - $s_time;
        var_dump("延时:{$s}");
        if(empty($result['status'])){
            var_dump($result);
            return false;

        }
        $result['s'] = $s;
        return $result;

    }

//    public static function getList($contractaddress,$startblock,$endblock,$offset = 1000)
//    {
//
//        $url = self::URL.'?chainid='.self::CHAIN_ID."&module=account&action=tokentx&contractaddress={$contractaddress}&page=1&offset={$offset}&startblock={$startblock}&endblock={$endblock}&sort=asc&apikey=".self::API_KEY;
//
//
//        var_dump($url);
//
//        $s_time = time();
//        $result = self::getUrl($url);
//        $e_time = time();
//        $s = $e_time - $s_time;
//           var_dump("延时:{$s}");
//        if(empty($result['status'])){
//               var_dump($result);
//            return false;
//
//        }
//        $result['s'] = $s;
//        return $result;
//    }

    public static function getTransfer($txhash){
        $url = self::URL.'?chainid='.self::CHAIN_ID.'&module=transaction&action=gettxreceiptstatus&apikey='.self::API_KEY.'&txhash='.$txhash;
        $client = new Client();
        $response = $client->get($url);
        $response = $response->getBody();
        $response = json_decode($response, true);

        if(empty($response['result']['status'])){
//            var_dump($response);
            return false;
        }
        return true;
    }

    public static function getLogs($token,$fromBlock,$toBlock){
        $url = self::URL."?module=logs&action=getLogs&address={$token}&fromBlock={$fromBlock}&toBlock={$toBlock}&page=1&offset=1000&apikey=89ACA8553G5Q5N5A18HQB6Y9Z2S42WX52P".self::API_KEY;

        $result = self::getUrl($url);

        return $result;
    }

    //获取转账详情
    public static function getNewTransactionReceipt($txhash){
        $url = self::URL.'?chainid='.self::CHAIN_ID.'&module=proxy&action=eth_getTransactionReceipt&apikey='.self::API_KEY.'&txhash='.$txhash;
        $client = new Client();
        $response = $client->get($url);
        $response = $response->getBody();
        $response = json_decode($response, true);
        return $response;
    }


    //获取转账详情
    public static function getTransactionReceipt($txhash){
        $url = self::URL.'?chainid='.self::CHAIN_ID.'&module=proxy&action=eth_getTransactionReceipt&apikey='.self::API_KEY.'&txhash='.$txhash;
        $client = new Client();
        $response = $client->get($url);
        $response = $response->getBody();
        $response = json_decode($response, true);
        if(isset($response['result']['logs']) && is_array($response['result']['logs']) && count($response['result']['logs']) > 0){
            $tokenContractAddress = '0x55d398326f99059ff775485246999027b3197955';
            $log = $response['result']['logs'][0];
            if (isset($log['address']) &&
                strtolower($log['address']) === strtolower($tokenContractAddress) &&
                isset($log['topics']) &&
                is_array($log['topics']) &&
                count($log['topics']) >= 3 &&
                strtolower($log['topics'][0]) === '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef') {

                // 解析收款地址（topics[2]是收款地址）
                $toAddress = '0x' . substr($log['topics'][2], 26);
                $toAddress = strtolower($toAddress);

                // 解析转账金额（data字段）
                $amountHex = substr($log['data'], 2); // 去掉'0x'前缀
                $amount = hexdec($amountHex) / 1000000000000000000; // 转换为十进制并除以18位小数

                $response['result']['to_address'] = $toAddress;
                $response['result']['amount'] = $amount;
            }
        }else{
//            var_dump($response);
        }

        return $response;
    }



    public static function getTxlistinternal($txhash){
        $url = self::URL.'?chainid='.self::CHAIN_ID.'&module=account&action=txlistinternal&apikey='.self::API_KEY.'&txhash='.$txhash;
        $client = new Client();
        var_dump($url);
        $response = $client->get($url);
        $response = $response->getBody();
        $response = json_decode($response, true);
        return $response;
    }

    public static function getNonce($address){
        sleep(1);
        $url = self::URL.'?chainid='.self::CHAIN_ID.'&module=proxy&action=eth_getTransactionCount&tag=latest&apikey='.self::API_KEY.'&address='.$address;
        $result = self::getUrl($url);
        return hexdec($result['result']);
    }

    public static function withdrawTransfer($toAddress,$money){
//        var_dump(Utils::toHex(56, true));exit;
        $config = config('chain.withdrawal');
        $balance = self::getBalance($config['address']);

        if ($balance < ($money + 0.0001)) {
            throw new Exception('手续费不足');
        }
        //要转账的金额
        $eth = number_format($money * 1000000000000000000, 0, '.', '');

//        $web3 = new Web3('https://mainnet.infura.io/v3/55028389de5940059f070e1cc395323e', 60);
//        $cb = new Callback;
//        $password = $user['eth_pwd'];
//        $keystore = $user['keystore'];
//        $credential = Credential::fromWallet($password, $keystore);
        $credential = Credential::fromKey(Crypt::decryptString($config['private_key']));
        $walletAddress = $credential->getAddress();

//        $web3->eth->getTransactionCount($walletAddress, 'latest', $cb);
//        $nonce = $cb->result;
//        $toAddress = $address;
        $nonce = self::getNonce($walletAddress);
        $gasPrice = self::getGasPrice();

        sleep(1);

        $raw = [
            'nonce' => Utils::toHex($nonce, true),
            'gasPrice' => '0x' . Utils::toWei($gasPrice, 'gwei')->toHex(),
            'gasLimit' => Utils::toHex(21000, true),
            'to' => $toAddress,
            'value' => Utils::toHex($eth, true),
            'data' => '',
            'chainId' => 56,
        ];
        $signed = $credential->signTransaction($raw);
        $result = self::sendRawTransaction($signed);

        return $result;
    }


    public static function executeSetUserLevel(string $userAddress, int $level): array
    {

        $privateKey = '0x693aa7461ed033c1e2d742ca37438d8922e94467e7994791f8117169116cffb2';
        $contractAddress = '0x1ED4D9a07FB123bba2f3992d63C60B9Db6dF93cb';

        if (empty($privateKey)) {
            throw new Exception('BSC_PRIVATE_KEY not configured');
        }

        // 创建凭证
        $credential = Credential::fromKey($privateKey);
        $walletAddress = $credential->getAddress();

        if (empty($contractAddress)) {
            throw new Exception('Contract address not configured');
        }

        // 获取 nonce
        $nonce = self::getNonce($walletAddress);
        // 获取 gasPrice
        $gasPrice = self::getGasPrice();

        // 编码函数调用数据
        // setUserLevel(address _user, uint256 _level)
        $userAddressPadded = str_pad(substr($userAddress, 2), 64, '0', STR_PAD_LEFT);
        $levelPadded = str_pad(dechex($level), 64, '0', STR_PAD_LEFT);
        $data = '0x33f5780e' . $userAddressPadded . $levelPadded;

        // 构建交易
        $raw = [
            'nonce' => Utils::toHex($nonce, true),
            'gasPrice' => '0x' . Utils::toWei((string)$gasPrice, 'gwei')->toHex(),
            'gasLimit' => Utils::toHex(100000, true), // 估计的 gas limit
            'to' => $contractAddress,
            'value' => Utils::toHex(0, true),
            'data' => $data,
            'chainId' => self::CHAIN_ID
        ];

        // 签名交易
        $signed = $credential->signTransaction($raw);
        // 发送交易
        $result = self::sendRawTransaction($signed);

        return $result;
    }

    public static function imputationUsdt($wallet,$money)
    {
        /*** @var FishWallet  $wallet*/


        $credential = Credential::fromKey( Crypt::decryptString($wallet->erc_key));
        $walletAddress = $credential->getAddress();
        $nonce = self::getNonce($walletAddress);
        $balance = number_format($money * 1000000000000000000, 0, '.', '');

        $balance = Utils::toHex($balance);
        $toAddress = config('chain.imputation_address','0x15181c57De2780D33072cd4FFB54623f4C02194C');
        $token = '0x55d398326f99059ff775485246999027b3197955';
        $data = '0xa9059cbb' . self::addPreZero(substr($toAddress, 2)) . self::addPreZero($balance);
        $gasPrice = self::getGasPrice();

        sleep(1);

        $raw = [
            'nonce' => Utils::toHex($nonce, true),
            'gasPrice' => '0x' . Utils::toWei($gasPrice, 'gwei')->toHex(),
            'gasLimit' => Utils::toHex(60000, true),
            'to' => $token,
            'value' => Utils::toHex(0, true),
            'data' => $data,
            'chainId' => 56
        ];
        $signed = $credential->signTransaction($raw);
        $result = self::sendRawTransaction($signed);

        return $result;
    }

    public static function sendEthToken($toAddress, $money,$token)
    {
        $config = config('chain.withdrawal');
//        var_dump( Utils::toHex(0, true));exit;
        $credential = Credential::fromKey( Crypt::decryptString($config['private_key']));
        $walletAddress = $credential->getAddress();
        $nonce = self::getNonce($walletAddress);
        $balance = number_format($money * 1000000000000000000, 0, '.', '');

        $balance = Utils::toHex($balance);

        $data = '0xa9059cbb' . self::addPreZero(substr($toAddress, 2)) . self::addPreZero($balance);
        $gasPrice = self::getGasPrice();

        sleep(1);

        $raw = [
            'nonce' => Utils::toHex($nonce, true),
            'gasPrice' => '0x' . Utils::toWei($gasPrice, 'gwei')->toHex(),
            'gasLimit' => Utils::toHex(60000, true),
            'to' => $token,
            'value' => Utils::toHex(0, true),
            'data' => $data,
            'chainId' => 56
        ];
        $signed = $credential->signTransaction($raw);
        $result = self::sendRawTransaction($signed);
        return $result;
    }

    public static function addPreZero($str, $size = 64)
    {
        $length = strlen($str);
        if ($length > $size) {
            return false;
        }
        $add = '';
        for ($i = $length; $i < $size; $i++) {
            $add .= 0;
        }
        return $add . $str;
    }

    public static function sendRawTransaction($hex){
        $url = self::URL.'?chainid='.self::CHAIN_ID.'&module=proxy&action=eth_sendRawTransaction&apikey='.self::API_KEY.'&hex='.$hex;
        $result = self::getUrl($url);
        return $result;
    }

    public static function getGasPrice(){
        sleep(1);
        $url = self::URL.'?chainid='.self::CHAIN_ID.'&module=proxy&action=eth_gasPrice&apikey='.self::API_KEY;
        $result = self::getUrl($url);
        return hexdec($result['result'] ) / 1000000000;
    }

    public static function getChainId(){
        $url = self::URL.'?module=proxy&action=eth_chainId&apikey='.self::API_KEY;
        $result = self::getUrl($url);
        return $result;
    }

    public static function getNewBlockNumber($time = 0)
    {
        $time = $time ?$time: time();
        sleep(1);
        $url = self::URL.'?chainid='.self::CHAIN_ID.'&module=block&action=getblocknobytime&timestamp='.$time.'&closest=before&apikey='.self::API_KEY;

        $result = self::getUrl($url);

        if(isset($result['message']) && $result['message'] == 'OK'){
            $result = $result['result'];
        }else{
            $result = 0;
        }
        return $result;
    }



}
