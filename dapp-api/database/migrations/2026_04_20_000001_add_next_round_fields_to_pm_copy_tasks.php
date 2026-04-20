<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_copy_tasks', function (Blueprint $table) {
            if (!Schema::hasColumn('pm_copy_tasks', 'next_round_enabled')) {
                $table->boolean('next_round_enabled')->default(false)->after('tail_price_time_config');
            }
            if (!Schema::hasColumn('pm_copy_tasks', 'next_round_min_predict_diff')) {
                $table->string('next_round_min_predict_diff', 32)->nullable()->after('next_round_enabled');
            }
            if (!Schema::hasColumn('pm_copy_tasks', 'next_round_prepare_seconds')) {
                $table->unsignedInteger('next_round_prepare_seconds')->default(20)->after('next_round_min_predict_diff');
            }
            if (!Schema::hasColumn('pm_copy_tasks', 'next_round_last_prepared_round_key')) {
                $table->string('next_round_last_prepared_round_key', 32)->nullable()->after('next_round_prepare_seconds');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pm_copy_tasks', function (Blueprint $table) {
            foreach ([
                'next_round_enabled',
                'next_round_min_predict_diff',
                'next_round_prepare_seconds',
                'next_round_last_prepared_round_key',
            ] as $column) {
                if (Schema::hasColumn('pm_copy_tasks', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
