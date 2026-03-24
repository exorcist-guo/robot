<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_polymarket_api_credentials', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('custody_wallet_id')->unique();

            $table->text('api_key_ciphertext');
            $table->text('api_secret_ciphertext');
            $table->text('passphrase_ciphertext');
            $table->unsignedInteger('encryption_version')->default(1);

            $table->timestamp('derived_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();

            $table->timestamps();

            $table->foreign('custody_wallet_id')
                ->references('id')
                ->on('pm_custody_wallets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_polymarket_api_credentials');
    }
};
