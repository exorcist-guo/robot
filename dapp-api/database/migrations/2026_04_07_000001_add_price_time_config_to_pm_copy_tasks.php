<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pm_copy_tasks', 'tail_price_time_config')) {
            Schema::table('pm_copy_tasks', function (Blueprint $table) {
                $table->json('tail_price_time_config')->nullable()->after('tail_loss_stopped_at')->comment('扫尾盘价格-时间限制配置 JSON');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pm_copy_tasks', 'tail_price_time_config')) {
            Schema::table('pm_copy_tasks', function (Blueprint $table) {
                $table->dropColumn('tail_price_time_config');
            });
        }
    }
};
