<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->decimal('total_earnings', 20, 8)->default(0)->after('performance')->comment('总收益');
            $table->decimal('total_consumption', 20, 8)->default(0)->after('total_earnings')->comment('抢购消费总额');
            $table->unsignedInteger('total_grab_count')->default(0)->after('total_consumption')->comment('抢红包次数');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['total_earnings', 'total_consumption', 'total_grab_count']);
        });
    }
};
