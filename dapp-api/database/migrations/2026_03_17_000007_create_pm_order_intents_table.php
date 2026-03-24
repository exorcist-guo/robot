<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_order_intents', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('copy_task_id')->index();
            $table->unsignedBigInteger('leader_trade_id')->index();
            $table->unsignedBigInteger('member_id')->index();

            $table->string('token_id', 78)->index();
            $table->string('side', 8)->comment('BUY|SELL');
            $table->string('leader_price', 32)->nullable();

            $table->unsignedBigInteger('target_usdc')->comment('目标USDC(1e6)');
            $table->unsignedBigInteger('clamped_usdc')->comment('限制后USDC(1e6)');

            $table->unsignedTinyInteger('status')->default(0)->comment('0=pending 1=submitted 2=skipped 3=failed');
            $table->string('skip_reason', 255)->nullable();
            $table->json('risk_snapshot')->nullable();

            $table->timestamps();

            $table->unique(['copy_task_id', 'leader_trade_id']);

            $table->foreign('copy_task_id')
                ->references('id')
                ->on('pm_copy_tasks')
                ->cascadeOnDelete();

            $table->foreign('leader_trade_id')
                ->references('id')
                ->on('pm_leader_trades')
                ->cascadeOnDelete();

            $table->foreign('member_id')
                ->references('id')
                ->on('pm_members')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_order_intents');
    }
};
