<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_portfolio_snapshots', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('member_id')->index();

            $table->unsignedBigInteger('available_usdc')->nullable();
            $table->unsignedBigInteger('equity_usdc')->nullable();
            $table->bigInteger('pnl_today_usdc')->nullable();
            $table->bigInteger('pnl_total_usdc')->nullable();

            $table->timestamp('as_of')->index();
            $table->json('raw')->nullable();

            $table->timestamps();

            $table->foreign('member_id')
                ->references('id')
                ->on('pm_members')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_portfolio_snapshots');
    }
};
