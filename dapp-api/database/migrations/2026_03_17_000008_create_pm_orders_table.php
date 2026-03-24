<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_orders', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('order_intent_id')->unique();

            $table->string('poly_order_id', 128)->nullable()->index()->comment('CLOB order id');
            $table->unsignedTinyInteger('status')->default(0)->comment('0=new 1=submitted 2=filled 3=partial 4=canceled 5=rejected 6=error');

            $table->json('request_payload');
            $table->json('response_payload')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();

            $table->unsignedBigInteger('filled_usdc')->default(0);
            $table->string('avg_price', 32)->nullable();

            $table->timestamps();

            $table->foreign('order_intent_id')
                ->references('id')
                ->on('pm_order_intents')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_orders');
    }
};
