<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_copy_tasks', function (Blueprint $table) {
            $table->decimal('size_limit', 24, 8)
                ->nullable()
                ->after('maker_max_quantity_per_token')
                ->comment('跟单张数限制');
        });
    }

    public function down(): void
    {
        Schema::table('pm_copy_tasks', function (Blueprint $table) {
            $table->dropColumn('size_limit');
        });
    }
};
