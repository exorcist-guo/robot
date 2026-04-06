<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pm_custody_transfer_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->unsignedBigInteger('sub_wallet_id')->index();
            $table->unsignedBigInteger('master_wallet_id')->index();
            $table->unsignedBigInteger('chain_id')->default(137);
            $table->string('token_address', 42);
            $table->string('from_address', 42);
            $table->string('to_address', 42);
            $table->string('amount', 78);
            $table->string('nonce', 78);
            $table->unsignedBigInteger('deadline_at');
            $table->string('action', 50)->default('erc20_transfer');
            $table->string('signature_payload_hash', 66)->nullable();
            $table->text('signature')->nullable();
            $table->string('tx_hash', 66)->nullable();
            $table->unsignedTinyInteger('status')->default(0)->comment('0=draft 1=signed 2=submitted 3=confirmed 4=failed 5=expired');
            $table->string('failure_reason', 255)->nullable();
            $table->json('raw_request_json')->nullable();
            $table->json('raw_response_json')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->unique(['sub_wallet_id', 'nonce'], 'pm_custody_transfer_req_sub_nonce_uq');
            $table->foreign('member_id')->references('id')->on('pm_members')->cascadeOnDelete();
            $table->foreign('sub_wallet_id')->references('id')->on('pm_custody_wallets')->cascadeOnDelete();
            $table->foreign('master_wallet_id')->references('id')->on('pm_custody_wallets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_custody_transfer_requests');
    }
};
