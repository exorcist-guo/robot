<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_tail_sweep_market_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32)->index();
            $table->timestamp('snapshot_at')->index();
            $table->string('current_price', 32);
            $table->string('up_entry_price5m', 32)->nullable();
            $table->string('down_entry_price5m', 32)->nullable();
            $table->string('up_entry_price15m', 32)->nullable();
            $table->string('down_entry_price15m', 32)->nullable();
            $table->unsignedBigInteger('target_usdc')->default(0)->comment('按1e6存储的固定金额');
            $table->timestamps();

            $table->unique(['symbol', 'snapshot_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_tail_sweep_market_snapshots');
    }
};
