<?php

namespace App\Models;

use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * ContractDynamic
 *
 * @property int $id
 * @property int $chain_id
 * @property string $contract_address
 * @property int $block_number
 * @property int $time_stamp
 * @property string $tx_hash
 * @property string|null $block_hash
 * @property int|null $nonce
 * @property int|null $transaction_index
 * @property string|null $from_address
 * @property string|null $to_address
 * @property string $value
 * @property string|null $gas
 * @property string|null $gas_price
 * @property string|null $cumulative_gas_used
 * @property string|null $gas_used
 * @property string|null $confirmations
 * @property int $is_error
 * @property int $txreceipt_status
 * @property string|null $input
 * @property string|null $method_id
 * @property string|null $function_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic query()
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereBlockHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereBlockNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereChainId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereConfirmations($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereContractAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereCumulativeGasUsed($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereFromAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereFunctionName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereGas($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereGasPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereGasUsed($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereInput($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereIsError($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereMethodId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereNonce($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereTimeStamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereToAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereTransactionIndex($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereTxHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereTxreceiptStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ContractDynamic whereValue($value)
 * @mixin \Eloquent
 */
class ContractDynamic extends Model
{
    use HasDateTimeFormatter;

    protected $table = 'contract_dynamics';

    protected $guarded = [];


    const STATUS_MAP = [
        1 => '处理完成',
        2 => '未知操作',
        3 => '无效记录',
        4 => '上链失败',
        5 => '处理中有报错',
        6 => '交易解析失败',

    ];

}
