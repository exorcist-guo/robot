<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contract_dynamics', function (Blueprint $table) {
            $table->id();

            $table->unsignedInteger('chain_id')->default(97)->comment('链ID (56/97)');
            $table->string('contract_address', 42)->comment('合约地址');

            $table->unsignedBigInteger('block_number')->comment('区块号');
            $table->unsignedBigInteger('time_stamp')->comment('时间戳');

            $table->string('tx_hash', 66)->unique()->comment('交易哈希');
            $table->string('block_hash', 66)->nullable()->comment('区块哈希');
            $table->unsignedBigInteger('nonce')->nullable()->comment('nonce');
            $table->unsignedInteger('transaction_index')->nullable()->comment('交易索引');

            $table->string('from_address', 42)->nullable()->comment('from');
            $table->string('to_address', 42)->nullable()->comment('to');

            // 这些字段来自浏览器 API，可能超出 bigint，统一用 string 存
            $table->string('value', 80)->default('0')->comment('value (原样)');
            $table->string('gas', 80)->nullable()->comment('gas (原样)');
            $table->string('gas_price', 80)->nullable()->comment('gasPrice (原样)');
            $table->string('cumulative_gas_used', 80)->nullable()->comment('cumulativeGasUsed (原样)');
            $table->string('gas_used', 80)->nullable()->comment('gasUsed (原样)');
            $table->string('confirmations', 80)->nullable()->comment('confirmations (原样)');

            $table->tinyInteger('is_error')->default(0)->comment('是否失败 (isError)');
            $table->tinyInteger('txreceipt_status')->default(0)->comment('回执状态');

            $table->longText('input')->nullable()->comment('input data');
            $table->string('method_id', 50)->nullable()->comment('methodId');
            $table->string('function_name', 50)->nullable()->comment('functionName');

            $table->timestamps();

            $table->index(['contract_address', 'block_number']);
            $table->index(['from_address']);
            $table->index(['to_address']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_dynamics');
    }
};
