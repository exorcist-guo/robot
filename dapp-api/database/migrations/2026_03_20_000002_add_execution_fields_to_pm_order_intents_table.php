<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_order_intents', function (Blueprint $table) {
            if (!Schema::hasColumn('pm_order_intents', 'attempt_count')) {
                $table->unsignedInteger('attempt_count')->default(0)->after('skip_reason');
            }
            if (!Schema::hasColumn('pm_order_intents', 'last_error_code')) {
                $table->string('last_error_code', 64)->nullable()->after('attempt_count');
            }
            if (!Schema::hasColumn('pm_order_intents', 'last_error_message')) {
                $table->text('last_error_message')->nullable()->after('last_error_code');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pm_order_intents', function (Blueprint $table) {
            foreach (['attempt_count', 'last_error_code', 'last_error_message'] as $column) {
                if (Schema::hasColumn('pm_order_intents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
