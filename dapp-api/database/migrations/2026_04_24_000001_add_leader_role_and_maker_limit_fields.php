<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_leader_trades', function (Blueprint $table) {
            $table->string('leader_role', 16)->nullable()->after('side')->index();
        });

        Schema::table('pm_order_intents', function (Blueprint $table) {
            $table->string('leader_role', 16)->nullable()->after('side')->index();
        });

        Schema::table('pm_orders', function (Blueprint $table) {
            $table->string('leader_role', 16)->nullable()->after('order_intent_id')->index();
            $table->string('token_id', 78)->nullable()->after('leader_role')->index();
        });

        Schema::table('pm_copy_tasks', function (Blueprint $table) {
            $table->decimal('maker_max_quantity_per_token', 24, 8)->nullable()->after('daily_max_usdc')->comment('maker 跟单时单个 token 的最大持仓数量');
        });
    }

    public function down(): void
    {
        Schema::table('pm_copy_tasks', function (Blueprint $table) {
            $table->dropColumn('maker_max_quantity_per_token');
        });

        Schema::table('pm_orders', function (Blueprint $table) {
            $table->dropColumn(['leader_role', 'token_id']);
        });

        Schema::table('pm_order_intents', function (Blueprint $table) {
            $table->dropColumn('leader_role');
        });

        Schema::table('pm_leader_trades', function (Blueprint $table) {
            $table->dropColumn('leader_role');
        });
    }
};
