<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('pm_leaderboard_daily_stats');
        Schema::dropIfExists('pm_leaderboard_user_trades');

        Schema::create('pm_leaderboard_user_trades', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leaderboard_user_id')->index();
            $table->string('address', 42)->index();
            $table->string('external_position_id')->index();
            $table->string('market_id')->nullable()->index();
            $table->string('token_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->string('outcome')->nullable();
            $table->string('opposite_outcome')->nullable();
            $table->decimal('avg_price', 20, 10)->nullable();
            $table->decimal('price', 20, 10)->nullable();
            $table->decimal('size', 30, 10)->nullable();
            $table->bigInteger('invested_amount_usdc')->default(0);
            $table->bigInteger('pnl_amount_usdc')->default(0);
            $table->bigInteger('profit_amount_usdc')->default(0);
            $table->bigInteger('loss_amount_usdc')->default(0);
            $table->boolean('is_win')->nullable()->index();
            $table->string('pnl_status', 30)->nullable()->index();
            $table->integer('pnl_ratio_bps')->nullable();
            $table->string('order_status', 30)->nullable()->index();
            $table->boolean('is_settled')->default(true)->index();
            $table->timestamp('traded_at')->nullable()->index();
            $table->timestamp('settled_at')->nullable()->index();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['leaderboard_user_id', 'external_position_id'], 'pm_lb_user_position_unique');
            $table->foreign('leaderboard_user_id')
                ->references('id')
                ->on('pm_leaderboard_users')
                ->cascadeOnDelete();
        });

        Schema::create('pm_leaderboard_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leaderboard_user_id')->index();
            $table->date('stat_date')->index();

            foreach (['day', 'week', 'month', 'all'] as $scope) {
                $table->unsignedInteger($scope . '_total_orders')->default(0);
                $table->unsignedInteger($scope . '_win_orders')->default(0);
                $table->unsignedInteger($scope . '_loss_orders')->default(0);
                $table->unsignedInteger($scope . '_win_rate_bps')->default(0);
                $table->bigInteger($scope . '_invested_amount_usdc')->default(0);
                $table->bigInteger($scope . '_profit_amount_usdc')->default(0);
            }

            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['leaderboard_user_id', 'stat_date'], 'pm_lb_daily_stats_unique');
            $table->foreign('leaderboard_user_id')
                ->references('id')
                ->on('pm_leaderboard_users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_leaderboard_daily_stats');
        Schema::dropIfExists('pm_leaderboard_user_trades');
    }
};
