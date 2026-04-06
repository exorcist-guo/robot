<?php

use App\Models\Pm\PmCustodyWallet;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_custody_wallets', function (Blueprint $table) {
            $table->dropForeign(['member_id']);
            $table->dropUnique('pm_custody_wallets_member_id_unique');
            $table->string('wallet_role', 20)
                ->default(PmCustodyWallet::ROLE_MASTER)
                ->after('member_id')
                ->comment('钱包角色: master/sub');
            $table->unsignedBigInteger('parent_wallet_id')
                ->nullable()
                ->after('wallet_role')
                ->comment('父钱包ID');
            $table->string('purpose', 50)
                ->nullable()
                ->after('parent_wallet_id')
                ->comment('用途');

            $table->index(['member_id', 'wallet_role'], 'pm_custody_wallets_member_role_idx');
            $table->index('parent_wallet_id', 'pm_custody_wallets_parent_wallet_idx');
            $table->foreign('parent_wallet_id', 'pm_custody_wallets_parent_wallet_fk')
                ->references('id')
                ->on('pm_custody_wallets')
                ->nullOnDelete();
            $table->foreign('member_id', 'pm_custody_wallets_member_id_foreign')
                ->references('id')
                ->on('pm_members')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pm_custody_wallets', function (Blueprint $table) {
            $table->dropForeign('pm_custody_wallets_parent_wallet_fk');
            $table->dropForeign('pm_custody_wallets_member_id_foreign');
            $table->dropIndex('pm_custody_wallets_parent_wallet_idx');
            $table->dropIndex('pm_custody_wallets_member_role_idx');
            $table->dropColumn(['wallet_role', 'parent_wallet_id', 'purpose']);
            $table->unique('member_id');
            $table->foreign('member_id')
                ->references('id')
                ->on('pm_members')
                ->cascadeOnDelete();
        });
    }
};
