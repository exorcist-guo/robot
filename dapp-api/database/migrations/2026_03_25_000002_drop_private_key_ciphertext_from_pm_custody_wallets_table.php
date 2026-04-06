<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_custody_wallets', function (Blueprint $table) {
            $table->dropColumn('private_key_ciphertext');
        });
    }

    public function down(): void
    {
        Schema::table('pm_custody_wallets', function (Blueprint $table) {
            $table->text('private_key_ciphertext')
                ->nullable()
                ->after('en_private_key')
                ->comment('加密后的私钥');
        });
    }
};
