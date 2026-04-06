<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_auth_nonces', function (Blueprint $table) {
            $table->id();

            $table->string('address', 42)->index()->comment('钱包地址(小写)');
            $table->string('nonce', 64)->unique()->comment('一次性 nonce');
            $table->timestamp('expires_at')->index();
            $table->timestamp('used_at')->nullable()->index();
            $table->string('ip', 45)->nullable();
            $table->string('ua_hash', 64)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_auth_nonces');
    }
};
