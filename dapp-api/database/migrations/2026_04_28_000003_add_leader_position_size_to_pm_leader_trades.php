<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_leader_trades', function (Blueprint $table) {
            $table->decimal('leader_position_size', 24, 8)
                ->default(0)
                ->after('size_usdc')
                ->comment('leader 当前 token 持仓数量');
        });
    }

    public function down(): void
    {
        Schema::table('pm_leader_trades', function (Blueprint $table) {
            $table->dropColumn('leader_position_size');
        });
    }
};
