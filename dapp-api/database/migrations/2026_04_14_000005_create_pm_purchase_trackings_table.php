<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_purchase_trackings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('copy_task_id')->nullable()->index();
            $table->unsignedBigInteger('leader_trade_id')->nullable()->index();
            $table->unsignedBigInteger('order_intent_id')->nullable()->index();
            $table->unsignedBigInteger('order_id')->nullable()->unique();
            $table->string('market_id', 64)->nullable()->index();
            $table->string('token_id', 78)->index();
            $table->string('bought_size', 32);
            $table->string('remaining_size', 32);
            $table->string('avg_price', 32)->nullable();
            $table->string('source_type', 32)->default('leader_copy');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->foreign('member_id')->references('id')->on('pm_members')->cascadeOnDelete();
            $table->foreign('copy_task_id')->references('id')->on('pm_copy_tasks')->nullOnDelete();
            $table->foreign('leader_trade_id')->references('id')->on('pm_leader_trades')->nullOnDelete();
            $table->foreign('order_intent_id')->references('id')->on('pm_order_intents')->nullOnDelete();
            $table->foreign('order_id')->references('id')->on('pm_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_purchase_trackings');
    }
};
