<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_copy_tasks', function (Blueprint $table) {
            $table->dropForeign(['leader_id']);
        });

        DB::statement('ALTER TABLE pm_copy_tasks MODIFY leader_id BIGINT UNSIGNED NULL');

        Schema::table('pm_copy_tasks', function (Blueprint $table) {
            $table->string('mode', 32)->default('leader_copy')->after('status')->comment('leader_copy|tail_sweep');
            $table->string('market_slug', 191)->nullable()->after('leader_id');
            $table->string('market_id', 64)->nullable()->after('market_slug');
            $table->string('market_question', 255)->nullable()->after('market_id');
            $table->string('market_symbol', 32)->nullable()->after('market_question');
            $table->string('resolution_source', 255)->nullable()->after('market_symbol');
            $table->string('token_yes_id', 78)->nullable()->after('resolution_source');
            $table->string('token_no_id', 78)->nullable()->after('token_yes_id');
            $table->string('price_to_beat', 32)->nullable()->after('token_no_id');
            $table->timestamp('market_end_at')->nullable()->after('price_to_beat');
            $table->unsignedBigInteger('tail_order_usdc')->default(0)->after('daily_max_usdc')->comment('扫尾盘固定下单金额(1e6)');
            $table->string('tail_trigger_amount', 32)->nullable()->after('tail_order_usdc')->comment('扫尾盘触发阈值');
            $table->unsignedInteger('tail_time_limit_seconds')->default(30)->after('tail_trigger_amount')->comment('最后多少秒允许触发');
            $table->unsignedInteger('tail_loss_stop_count')->default(0)->after('tail_time_limit_seconds')->comment('累计亏损多少单自动停');
            $table->unsignedInteger('tail_loss_count')->default(0)->after('tail_loss_stop_count')->comment('当前累计亏损单数');
            $table->string('tail_round_started_value', 32)->nullable()->after('tail_loss_count')->comment('本轮开始时的(Current price - Price to beat)');
            $table->string('tail_last_triggered_round_key', 64)->nullable()->after('tail_round_started_value')->comment('最后一次触发轮次');
            $table->timestamp('tail_loss_stopped_at')->nullable()->after('tail_last_triggered_round_key');

            $table->index(['member_id', 'mode']);
            $table->index(['mode', 'status']);
            $table->index(['market_slug', 'mode']);

            $table->foreign('leader_id')
                ->references('id')
                ->on('pm_leaders')
                ->cascadeOnDelete();
        });

        Schema::table('pm_order_intents', function (Blueprint $table) {
            $table->dropForeign(['leader_trade_id']);
        });

        DB::statement('ALTER TABLE pm_order_intents MODIFY leader_trade_id BIGINT UNSIGNED NULL');

        Schema::table('pm_order_intents', function (Blueprint $table) {
            $table->foreign('leader_trade_id')
                ->references('id')
                ->on('pm_leader_trades')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pm_order_intents', function (Blueprint $table) {
            $table->dropForeign(['leader_trade_id']);
        });

        DB::statement('ALTER TABLE pm_order_intents MODIFY leader_trade_id BIGINT UNSIGNED NOT NULL');

        Schema::table('pm_order_intents', function (Blueprint $table) {
            $table->foreign('leader_trade_id')
                ->references('id')
                ->on('pm_leader_trades')
                ->cascadeOnDelete();
        });

        Schema::table('pm_copy_tasks', function (Blueprint $table) {
            $table->dropForeign(['leader_id']);
            $table->dropIndex(['member_id', 'mode']);
            $table->dropIndex(['mode', 'status']);
            $table->dropIndex(['market_slug', 'mode']);
            $table->dropColumn([
                'mode',
                'market_slug',
                'market_id',
                'market_question',
                'market_symbol',
                'resolution_source',
                'token_yes_id',
                'token_no_id',
                'price_to_beat',
                'market_end_at',
                'tail_order_usdc',
                'tail_trigger_amount',
                'tail_time_limit_seconds',
                'tail_loss_stop_count',
                'tail_loss_count',
                'tail_round_started_value',
                'tail_last_triggered_round_key',
                'tail_loss_stopped_at',
            ]);
        });

        DB::statement('ALTER TABLE pm_copy_tasks MODIFY leader_id BIGINT UNSIGNED NOT NULL');

        Schema::table('pm_copy_tasks', function (Blueprint $table) {
            $table->foreign('leader_id')
                ->references('id')
                ->on('pm_leaders')
                ->cascadeOnDelete();
        });
    }
};
