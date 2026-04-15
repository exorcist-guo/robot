<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_order_intents', function (Blueprint $table) {
            if (!Schema::hasColumn('pm_order_intents', 'processing_started_at')) {
                $table->timestamp('processing_started_at')->nullable()->after('attempt_count');
            }
            if (!Schema::hasColumn('pm_order_intents', 'processed_at')) {
                $table->timestamp('processed_at')->nullable()->after('processing_started_at');
            }
            if (!Schema::hasColumn('pm_order_intents', 'execution_mode')) {
                $table->string('execution_mode', 16)->default('live')->after('processed_at');
            }
            if (!Schema::hasColumn('pm_order_intents', 'execution_stage')) {
                $table->string('execution_stage', 32)->nullable()->after('execution_mode');
            }
            if (!Schema::hasColumn('pm_order_intents', 'decision_payload')) {
                $table->json('decision_payload')->nullable()->after('risk_snapshot');
            }
            if (!Schema::hasColumn('pm_order_intents', 'skip_category')) {
                $table->string('skip_category', 64)->nullable()->after('skip_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pm_order_intents', function (Blueprint $table) {
            foreach (['processing_started_at', 'processed_at', 'execution_mode', 'execution_stage', 'decision_payload', 'skip_category'] as $column) {
                if (Schema::hasColumn('pm_order_intents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
