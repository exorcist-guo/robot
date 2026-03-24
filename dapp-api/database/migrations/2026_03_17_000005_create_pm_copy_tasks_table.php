<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_copy_tasks', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('leader_id')->index();

            $table->unsignedTinyInteger('status')->default(1)->comment('状态: 1=启用 0=暂停');

            $table->unsignedInteger('ratio_bps')->default(10000)->comment('跟单比例(bps), 10000=100%');
            $table->unsignedBigInteger('min_usdc')->default(0)->comment('最小单笔USDC(1e6)');
            $table->unsignedBigInteger('max_usdc')->default(0)->comment('最大单笔USDC(1e6)');

            $table->unsignedInteger('max_slippage_bps')->default(50)->comment('最大滑点(bps)');
            $table->boolean('allow_partial_fill')->default(true);
            $table->unsignedBigInteger('daily_max_usdc')->nullable()->comment('每日最大USDC(1e6)');

            $table->timestamps();

            $table->unique(['member_id', 'leader_id']);

            $table->foreign('member_id')
                ->references('id')
                ->on('pm_members')
                ->cascadeOnDelete();

            $table->foreign('leader_id')
                ->references('id')
                ->on('pm_leaders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_copy_tasks');
    }
};
