<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('pm_custody_wallets', function (Blueprint $table) {
            $table->unsignedTinyInteger('possible_deposit_status')->default(0)->comment('0=否 1=是')->after('status');
            $table->timestamp('possible_deposit_at')->nullable()->after('possible_deposit_status');
            $table->unsignedInteger('deposit_scan_count')->default(0)->after('possible_deposit_at');
            $table->index(['possible_deposit_status', 'deposit_scan_count'], 'pm_custody_wallets_possible_deposit_scan_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pm_custody_wallets', function (Blueprint $table) {
            $table->dropIndex('pm_custody_wallets_possible_deposit_scan_idx');
            $table->dropColumn(['possible_deposit_status', 'possible_deposit_at', 'deposit_scan_count']);
        });
    }
};
