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
        Schema::create('performance_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->comment('用户ID');
            $table->unsignedBigInteger('parent_id')->default(0)->comment('上级用户ID');
            $table->decimal('amount', 20, 8)->default(0)->comment('业绩金额');
            $table->unsignedBigInteger('contract_dynamic_id')->nullable()->comment('合约动态记录ID');
            $table->timestamp('time_stamp')->nullable()->comment('交易时间');
            $table->string('type', 20)->default('grab')->comment('类型: grab=抢红包, team_grab=团队抢红包');
            $table->timestamps();

            $table->index(['member_id']);
            $table->index(['parent_id']);
            $table->index(['contract_dynamic_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('performance_records');
    }
};
