<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 当前快照表已恢复为按 symbol + snapshot_at 聚合的一行一秒结构。
    }

    public function down(): void
    {
        // no-op
    }
};
