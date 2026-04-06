<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_custody_wallets', function (Blueprint $table) {
            $table->string('address', 42)
                ->nullable()
                ->after('member_id')
                ->comment('登录地址(小写)');
            $table->text('en_private_key')
                ->nullable()
                ->after('funder_address')
                ->comment('Google 加密后的私钥');
            $table->unique(['member_id', 'wallet_role'], 'pm_custody_wallets_member_role_unique');
            $table->index('address', 'pm_custody_wallets_address_idx');
        });
    }

    public function down(): void
    {
        Schema::table('pm_custody_wallets', function (Blueprint $table) {
            $table->dropUnique('pm_custody_wallets_member_role_unique');
            $table->dropIndex('pm_custody_wallets_address_idx');
            $table->dropColumn(['address', 'en_private_key']);
        });
    }
};
