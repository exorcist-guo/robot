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
        Schema::create('members', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('pid')->default(0)->comment('上级ID');
            $table->unsignedTinyInteger('deep')->default(0)->comment('层级深度');
            $table->string('path', 500)->default('/')->comment('层级路径 (如 /1/2/3)');
            $table->string('address', 42)->unique()->comment('钱包地址');
            $table->unsignedTinyInteger('level')->default(0)->comment('用户等级 (0-15)');
            $table->decimal('performance', 20, 8)->default(0)->comment('业绩');

            $table->timestamps();

            $table->index(['pid']);
            $table->index(['address']);
            $table->index(['level']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
