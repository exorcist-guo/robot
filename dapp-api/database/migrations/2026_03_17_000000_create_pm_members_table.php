<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_members', function (Blueprint $table) {
            $table->id();

            $table->string('address', 42)->unique()->comment('钱包地址(小写)');
            $table->string('nickname', 64)->nullable()->comment('昵称');
            $table->string('avatar_url')->nullable()->comment('头像');

            $table->unsignedBigInteger('inviter_id')->nullable()->index()->comment('邀请人 member_id');
            $table->string('path', 500)->default('/')->comment('邀请层级路径 /1/2/3/');
            $table->unsignedSmallInteger('deep')->default(0)->comment('邀请层级深度');

            $table->unsignedTinyInteger('status')->default(1)->comment('状态: 1=正常 0=禁用');
            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();

            $table->foreign('inviter_id')
                ->references('id')
                ->on('pm_members')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_members');
    }
};
