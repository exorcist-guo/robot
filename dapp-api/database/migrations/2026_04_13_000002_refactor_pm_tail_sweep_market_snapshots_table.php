<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_tail_sweep_market_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'round_start_at')) {
                $table->dropIndex(['symbol', 'round_start_at']);
                $table->dropColumn('round_start_at');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'round_end_at')) {
                $table->dropColumn('round_end_at');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'market_slug')) {
                $table->dropColumn('market_slug');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'market_id')) {
                $table->dropIndex(['market_id', 'snapshot_at']);
                $table->dropColumn('market_id');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'token_yes_id')) {
                $table->dropColumn('token_yes_id');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'token_no_id')) {
                $table->dropColumn('token_no_id');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'round_open_price')) {
                $table->dropColumn('round_open_price');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'up_entry_price')) {
                $table->dropColumn('up_entry_price');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'down_entry_price')) {
                $table->dropColumn('down_entry_price');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'price_source')) {
                $table->dropColumn('price_source');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'open_price_source')) {
                $table->dropColumn('open_price_source');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'entry_price_source')) {
                $table->dropColumn('entry_price_source');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'raw')) {
                $table->dropColumn('raw');
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'up_entry_price5m')) {
                $table->string('up_entry_price5m', 32)->nullable()->after('current_price');
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'down_entry_price5m')) {
                $table->string('down_entry_price5m', 32)->nullable()->after('up_entry_price5m');
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'up_entry_price15m')) {
                $table->string('up_entry_price15m', 32)->nullable()->after('down_entry_price5m');
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'down_entry_price15m')) {
                $table->string('down_entry_price15m', 32)->nullable()->after('up_entry_price15m');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pm_tail_sweep_market_snapshots', function (Blueprint $table) {
            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'up_entry_price5m')) {
                $table->dropColumn('up_entry_price5m');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'down_entry_price5m')) {
                $table->dropColumn('down_entry_price5m');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'up_entry_price15m')) {
                $table->dropColumn('up_entry_price15m');
            }

            if (Schema::hasColumn('pm_tail_sweep_market_snapshots', 'down_entry_price15m')) {
                $table->dropColumn('down_entry_price15m');
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'round_start_at')) {
                $table->timestamp('round_start_at')->nullable()->index();
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'round_end_at')) {
                $table->timestamp('round_end_at')->nullable()->index();
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'market_slug')) {
                $table->string('market_slug', 191)->nullable();
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'market_id')) {
                $table->string('market_id', 128)->nullable()->index();
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'token_yes_id')) {
                $table->string('token_yes_id', 78)->nullable();
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'token_no_id')) {
                $table->string('token_no_id', 78)->nullable();
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'round_open_price')) {
                $table->string('round_open_price', 32)->nullable();
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'up_entry_price')) {
                $table->string('up_entry_price', 32)->nullable();
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'down_entry_price')) {
                $table->string('down_entry_price', 32)->nullable();
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'price_source')) {
                $table->string('price_source', 32)->nullable();
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'open_price_source')) {
                $table->string('open_price_source', 32)->nullable();
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'entry_price_source')) {
                $table->string('entry_price_source', 32)->nullable();
            }

            if (!Schema::hasColumn('pm_tail_sweep_market_snapshots', 'raw')) {
                $table->json('raw')->nullable();
            }
        });
    }
};
