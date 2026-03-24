<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_leader_trades', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('leader_id')->index();
            $table->string('trade_id', 128)->unique()->comment('外部trade id');

            $table->string('market_id', 128)->nullable()->index();
            $table->string('token_id', 78)->nullable()->index()->comment('Outcome token id');

            $table->string('side', 8)->comment('BUY|SELL');
            $table->string('price', 32)->nullable()->comment('成交价(字符串)');
            $table->unsignedBigInteger('size_usdc')->nullable()->comment('成交金额USDC(1e6)');

            $table->json('raw')->nullable();
            $table->timestamp('traded_at')->index();

            $table->timestamps();

            $table->foreign('leader_id')
                ->references('id')
                ->on('pm_leaders')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_leader_trades');
    }
};
