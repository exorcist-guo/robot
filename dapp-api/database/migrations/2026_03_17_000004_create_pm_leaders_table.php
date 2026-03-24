<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_leaders', function (Blueprint $table) {
            $table->id();

            $table->string('input_address', 42)->index()->comment('录入地址(小写)');
            $table->string('proxy_wallet', 42)->unique()->comment('proxyWallet(小写)');

            $table->string('display_name', 64)->nullable();
            $table->string('avatar_url')->nullable();

            $table->unsignedTinyInteger('status')->default(1)->comment('状态: 1=启用 0=禁用');
            $table->timestamp('last_seen_trade_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_leaders');
    }
};
