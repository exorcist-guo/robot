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
            $table->timestamp('round_start_at')->index();
            $table->timestamp('round_end_at')->index();
            $table->string('market_slug', 191)->nullable();
            $table->string('market_id', 128)->nullable()->index();
            $table->string('token_yes_id', 78)->nullable();
            $table->string('token_no_id', 78)->nullable();
            $table->string('current_price', 32);
            $table->string('round_open_price', 32)->nullable();
            $table->string('up_entry_price', 32)->nullable();
            $table->string('down_entry_price', 32)->nullable();
            $table->unsignedBigInteger('target_usdc')->default(0)->comment('按1e6存储的固定金额');
            $table->string('price_source', 32)->nullable();
            $table->string('open_price_source', 32)->nullable();
            $table->string('entry_price_source', 32)->nullable();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'snapshot_at']);
            $table->index(['symbol', 'round_start_at']);
            $table->index(['market_id', 'snapshot_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_tail_sweep_market_snapshots');
    }
};
