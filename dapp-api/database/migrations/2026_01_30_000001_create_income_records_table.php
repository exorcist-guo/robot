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
        Schema::create('income_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->comment('用户ID');
            $table->decimal('amount', 20, 8)->default(0)->comment('收益金额');
            $table->string('type', 30)->default('random_reward')->comment('收益类型: random_reward=随机奖励, team_reward=团队奖励, performance_reward=业绩奖励');
            $table->string('tx_hash', 66)->nullable()->comment('交易哈希');
            $table->unsignedBigInteger('contract_dynamic_id')->nullable()->comment('合约动态记录ID');
            $table->unsignedBigInteger('performance_record_id')->nullable()->comment('关联的业绩记录ID');
            $table->unsignedBigInteger('from_grab_id')->nullable()->comment('来源抢红包记录ID');
            $table->string('from_address', 42)->nullable()->comment('发起地址');
            $table->unsignedInteger('block_number')->nullable()->comment('区块号');
            $table->timestamp('time_stamp')->nullable()->comment('交易时间');
            $table->text('remark')->nullable()->comment('备注');
            $table->timestamps();

            $table->index(['member_id']);
            $table->index(['tx_hash']);
            $table->index(['contract_dynamic_id']);
            $table->index(['performance_record_id']);
            $table->index(['type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('income_records');
    }
};
