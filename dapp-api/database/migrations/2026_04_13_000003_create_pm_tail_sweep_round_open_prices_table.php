<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_tail_sweep_round_open_prices', function (Blueprint $table) {
            $table->id();
            $table->string('symbol', 32)->index();
            $table->timestamp('round_start_at')->index();
            $table->timestamp('round_end_at')->nullable();
            $table->string('round_open_price', 32)->nullable();
            $table->timestamps();

            $table->unique(['symbol', 'round_start_at'], 'pm_ts_round_open_prices_sym_start_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_tail_sweep_round_open_prices');
    }
};
