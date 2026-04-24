<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('pm_skip_round_strategies', function (Blueprint $table) {
            $table->id();
            $table->string('strategy_key', 64)->unique();
            $table->string('strategy_name', 128);
            $table->unsignedBigInteger('member_id');
            $table->string('market_slug', 191);
            $table->string('resolution_source', 255)->nullable();
            $table->string('symbol', 32);
            $table->decimal('base_bet_amount', 20, 8);
            $table->unsignedInteger('max_lose_reset_limit')->default(5);
            $table->decimal('min_predict_diff', 20, 8);
            $table->char('next_line', 1)->default('A');
            $table->tinyInteger('status')->default(1);
            $table->string('last_signal_round_key', 32)->nullable();
            $table->string('last_target_round_key', 32)->nullable();
            $table->json('config_snapshot')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_ran_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'status']);
        });

        Schema::create('pm_skip_round_strategy_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('strategy_id');
            $table->char('line_code', 1);
            $table->decimal('current_bet_amount', 20, 8);
            $table->unsignedInteger('lose_streak_count')->default(0);
            $table->unsignedInteger('total_bet_count')->default(0);
            $table->unsignedInteger('total_win_count')->default(0);
            $table->unsignedInteger('total_lose_count')->default(0);
            $table->string('last_bet_round_key', 32)->nullable();
            $table->string('last_settled_round_key', 32)->nullable();
            $table->unsignedBigInteger('last_order_id')->nullable();
            $table->string('last_result', 16)->nullable();
            $table->timestamps();
            $table->unique(['strategy_id', 'line_code']);
            $table->index(['strategy_id']);
        });

        Schema::create('pm_skip_round_markets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('strategy_id');
            $table->string('round_key', 32);
            $table->timestamp('round_start_at');
            $table->timestamp('round_end_at');
            $table->string('base_slug', 191);
            $table->string('round_slug', 191)->unique();
            $table->string('market_id', 64)->nullable();
            $table->string('question', 255)->nullable();
            $table->string('resolution_source', 255)->nullable();
            $table->string('token_yes_id', 128)->nullable();
            $table->string('token_no_id', 128)->nullable();
            $table->string('price_to_beat', 64)->nullable();
            $table->json('market_payload')->nullable();
            $table->string('status', 32)->default('resolved');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['strategy_id', 'round_key']);
            $table->index(['strategy_id', 'round_start_at']);
        });

        Schema::create('pm_skip_round_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('strategy_id');
            $table->unsignedBigInteger('strategy_line_id');
            $table->unsignedBigInteger('member_id');
            $table->char('line_code', 1);
            $table->string('signal_round_key', 32);
            $table->string('target_round_key', 32);
            $table->string('prediction_source_round_key', 32)->nullable();
            $table->string('market_id', 64)->nullable();
            $table->string('market_slug', 191);
            $table->string('token_id', 128);
            $table->string('predicted_side', 8);
            $table->string('order_side', 8)->default('BUY');
            $table->decimal('predict_diff', 20, 8);
            $table->decimal('predict_abs_diff', 20, 8);
            $table->decimal('prev_round_open_price', 20, 8)->nullable();
            $table->decimal('current_round_open_price', 20, 8)->nullable();
            $table->decimal('bet_amount', 20, 8);
            $table->decimal('limit_price', 20, 8)->nullable();
            $table->decimal('limit_order_size', 20, 8)->nullable();
            $table->decimal('limit_order_notional', 20, 8)->nullable();
            $table->string('remote_order_id', 128)->nullable();
            $table->string('remote_client_order_id', 128)->nullable();
            $table->decimal('matched_size', 20, 8)->default(0);
            $table->decimal('matched_notional', 20, 8)->default(0);
            $table->decimal('remaining_notional', 20, 8)->default(0);
            $table->decimal('market_buy_notional', 20, 8)->default(0);
            $table->decimal('avg_fill_price', 20, 8)->nullable();
            $table->timestamp('place_started_at')->nullable();
            $table->timestamp('limit_placed_at')->nullable();
            $table->timestamp('cancel_requested_at')->nullable();
            $table->timestamp('cancel_confirmed_at')->nullable();
            $table->timestamp('market_buy_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('result', 16)->nullable();
            $table->decimal('pnl_amount', 20, 8)->nullable();
            $table->string('status', 32)->default('predicted');
            $table->string('fail_reason', 255)->nullable();
            $table->json('snapshot')->nullable();
            $table->timestamps();
            $table->unique(['strategy_id', 'target_round_key']);
            $table->index(['member_id', 'status']);
            $table->index(['strategy_line_id', 'created_at']);
            $table->index(['remote_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_skip_round_orders');
        Schema::dropIfExists('pm_skip_round_markets');
        Schema::dropIfExists('pm_skip_round_strategy_lines');
        Schema::dropIfExists('pm_skip_round_strategies');
    }
};
