<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * rvm_wallet + rvm_wallet_ledger — credit accounting.
 *
 * Reserve/commit/refund pattern:
 *   reserve  — move N cents from balance → reserved (atomic row update)
 *   commit   — drop reserved by N (on successful delivery)
 *   refund   — move N cents from reserved back to balance (on failure)
 *
 * Every mutation appends a ledger row for audit + reconciliation.
 */
class CreateRvmWalletTables extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('rvm_wallet', function (Blueprint $table) {
            $table->unsignedInteger('client_id')->primary();
            $table->bigInteger('balance_cents')->default(0);
            $table->bigInteger('reserved_cents')->default(0);
            $table->integer('low_balance_threshold_cents')->default(1000);
            $table->boolean('low_balance_notified')->default(false);
            $table->timestamps();
        });

        Schema::connection('master')->create('rvm_wallet_ledger', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('client_id');
            $table->char('drop_id', 26)->nullable();
            $table->char('reservation_id', 26)->nullable();

            // Signed amount: positive = credit, negative = debit
            $table->enum('type', ['reserve', 'commit', 'refund', 'topup', 'adjust']);
            $table->integer('amount_cents');
            $table->bigInteger('balance_after');

            $table->string('reference', 128)->nullable();   // stripe charge id, manual note, etc.
            $table->string('created_by', 64)->nullable();   // user id | api_key_id | system

            $table->dateTime('created_at');

            $table->index(['client_id', 'created_at'], 'idx_rvm_ledger_client_time');
            $table->index('drop_id', 'idx_rvm_ledger_drop');
            $table->index('reservation_id', 'idx_rvm_ledger_reservation');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('rvm_wallet_ledger');
        Schema::connection('master')->dropIfExists('rvm_wallet');
    }
}
