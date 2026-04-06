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
            $table->string('level_hash', 66)->default('')->comment('交易哈希')->after('level');
            $table->tinyInteger('check_num')->default(0)->comment('检查次数')->after('level');
            $table->tinyInteger('set_level')->default(0)->comment('是否手动设置等级 0-否 1-是')->after('level');


        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('set_level');
            $table->dropColumn('check_num');
            $table->dropColumn('level_hash');
        });
    }
};
