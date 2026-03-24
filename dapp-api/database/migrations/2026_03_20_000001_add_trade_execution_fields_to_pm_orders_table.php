<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('pm_orders', 'failure_category')) {
                $table->string('failure_category', 64)->nullable()->after('error_code');
            }
            if (!Schema::hasColumn('pm_orders', 'is_retryable')) {
                $table->boolean('is_retryable')->default(false)->after('failure_category');
            }
            if (!Schema::hasColumn('pm_orders', 'retry_count')) {
                $table->unsignedInteger('retry_count')->default(0)->after('is_retryable');
            }
            if (!Schema::hasColumn('pm_orders', 'exchange_nonce')) {
                $table->string('exchange_nonce', 64)->nullable()->after('poly_order_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pm_orders', function (Blueprint $table) {
            foreach (['failure_category', 'is_retryable', 'retry_count', 'exchange_nonce'] as $column) {
                if (Schema::hasColumn('pm_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
