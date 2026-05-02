<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class EnhanceWalletTransactions extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->unsignedInteger('actor_id')->nullable()->after('description');
            $table->string('billable_type', 30)->nullable()->after('actor_id');
            // 'call', 'sms', 'ringless', 'topup', 'signup_credit', 'admin_adjust'
            $table->unsignedBigInteger('billable_id')->nullable()->after('billable_type');
            $table->decimal('balance_after', 10, 4)->nullable()->after('billable_id');

            $table->index(['billable_type', 'billable_id'], 'idx_wt_billable');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_wt_billable');
            $table->dropColumn(['actor_id', 'billable_type', 'billable_id', 'balance_after']);
        });
    }
}
