<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTrunkingWalletTransactionsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('trunking_wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('currency_code', 3)->comment('ISO 4217');
            $table->decimal('amount', 8,4)->unsigned();
            $table->enum('transaction_type', array('credit','debit'));
            $table->string('transaction_reference', 100);
            $table->string('description');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('trunking_wallet_transactions');
    }
}
