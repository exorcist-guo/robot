<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_custody_wallets', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('member_id')->unique();
            $table->string('signer_address', 42)->unique()->comment('签名地址(EOA, 小写)');
            $table->string('funder_address', 42)->nullable()->index()->comment('资金地址/ProxyWallet(小写)');

            $table->text('private_key_ciphertext')->comment('加密后的私钥');
            $table->unsignedInteger('encryption_version')->default(1);

            $table->unsignedTinyInteger('signature_type')->default(0)->comment('签名类型: 0=EOA 1=ProxyEmail 2=ProxyWallet/Safe');
            $table->string('exchange_nonce', 78)->default('0')->comment('Exchange nonce (默认0)');

            $table->unsignedTinyInteger('status')->default(1)->comment('状态: 1=启用 0=锁定');
            $table->timestamps();

            $table->foreign('member_id')
                ->references('id')
                ->on('pm_members')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_custody_wallets');
    }
};
