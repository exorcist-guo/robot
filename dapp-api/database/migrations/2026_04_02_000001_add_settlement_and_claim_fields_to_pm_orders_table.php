<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('pm_orders', 'original_size')) {
                $table->string('original_size', 32)->nullable()->after('avg_price');
            }
            if (!Schema::hasColumn('pm_orders', 'filled_size')) {
                $table->string('filled_size', 32)->nullable()->after('original_size');
            }
            if (!Schema::hasColumn('pm_orders', 'order_price')) {
                $table->string('order_price', 32)->nullable()->after('filled_size');
            }
            if (!Schema::hasColumn('pm_orders', 'outcome')) {
                $table->string('outcome', 32)->nullable()->after('order_price');
            }
            if (!Schema::hasColumn('pm_orders', 'order_type')) {
                $table->string('order_type', 32)->nullable()->after('outcome');
            }
            if (!Schema::hasColumn('pm_orders', 'remote_order_status')) {
                $table->string('remote_order_status', 32)->nullable()->after('order_type');
            }
            if (!Schema::hasColumn('pm_orders', 'is_settled')) {
                $table->boolean('is_settled')->default(false)->after('remote_order_status');
            }
            if (!Schema::hasColumn('pm_orders', 'settled_at')) {
                $table->timestamp('settled_at')->nullable()->after('is_settled');
            }
            if (!Schema::hasColumn('pm_orders', 'winning_outcome')) {
                $table->string('winning_outcome', 32)->nullable()->after('settled_at');
            }
            if (!Schema::hasColumn('pm_orders', 'settlement_source')) {
                $table->string('settlement_source', 32)->nullable()->after('winning_outcome');
            }
            if (!Schema::hasColumn('pm_orders', 'position_notional_usdc')) {
                $table->bigInteger('position_notional_usdc')->nullable()->after('settlement_source');
            }
            if (!Schema::hasColumn('pm_orders', 'pnl_usdc')) {
                $table->bigInteger('pnl_usdc')->nullable()->after('position_notional_usdc');
            }
            if (!Schema::hasColumn('pm_orders', 'profit_usdc')) {
                $table->unsignedBigInteger('profit_usdc')->nullable()->after('pnl_usdc');
            }
            if (!Schema::hasColumn('pm_orders', 'roi_bps')) {
                $table->integer('roi_bps')->nullable()->after('profit_usdc');
            }
            if (!Schema::hasColumn('pm_orders', 'is_win')) {
                $table->boolean('is_win')->nullable()->after('roi_bps');
            }
            if (!Schema::hasColumn('pm_orders', 'last_profit_sync_at')) {
                $table->timestamp('last_profit_sync_at')->nullable()->after('is_win');
            }
            if (!Schema::hasColumn('pm_orders', 'claim_status')) {
                $table->unsignedTinyInteger('claim_status')->default(0)->after('last_profit_sync_at');
            }
            if (!Schema::hasColumn('pm_orders', 'claimable_usdc')) {
                $table->unsignedBigInteger('claimable_usdc')->nullable()->after('claim_status');
            }
            if (!Schema::hasColumn('pm_orders', 'claim_tx_hash')) {
                $table->string('claim_tx_hash', 128)->nullable()->after('claimable_usdc');
            }
            if (!Schema::hasColumn('pm_orders', 'claim_attempts')) {
                $table->unsignedInteger('claim_attempts')->default(0)->after('claim_tx_hash');
            }
            if (!Schema::hasColumn('pm_orders', 'claim_last_error')) {
                $table->text('claim_last_error')->nullable()->after('claim_attempts');
            }
            if (!Schema::hasColumn('pm_orders', 'claim_requested_at')) {
                $table->timestamp('claim_requested_at')->nullable()->after('claim_last_error');
            }
            if (!Schema::hasColumn('pm_orders', 'claim_completed_at')) {
                $table->timestamp('claim_completed_at')->nullable()->after('claim_requested_at');
            }
            if (!Schema::hasColumn('pm_orders', 'claim_last_checked_at')) {
                $table->timestamp('claim_last_checked_at')->nullable()->after('claim_completed_at');
            }
            if (!Schema::hasColumn('pm_orders', 'settlement_payload')) {
                $table->json('settlement_payload')->nullable()->after('claim_last_checked_at');
            }
            if (!Schema::hasColumn('pm_orders', 'claim_payload')) {
                $table->json('claim_payload')->nullable()->after('settlement_payload');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pm_orders', function (Blueprint $table) {
            foreach ([
                'original_size',
                'filled_size',
                'order_price',
                'outcome',
                'order_type',
                'remote_order_status',
                'is_settled',
                'settled_at',
                'winning_outcome',
                'settlement_source',
                'position_notional_usdc',
                'pnl_usdc',
                'profit_usdc',
                'roi_bps',
                'is_win',
                'last_profit_sync_at',
                'claim_status',
                'claimable_usdc',
                'claim_tx_hash',
                'claim_attempts',
                'claim_last_error',
                'claim_requested_at',
                'claim_completed_at',
                'claim_last_checked_at',
                'settlement_payload',
                'claim_payload',
            ] as $column) {
                if (Schema::hasColumn('pm_orders', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
