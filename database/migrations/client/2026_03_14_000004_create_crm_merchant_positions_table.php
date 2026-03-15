<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmMerchantPositionsTable extends Migration
{
    public function up()
    {
        Schema::create('crm_merchant_positions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->string('lender_name', 200);
            $table->decimal('funded_amount', 12, 2)->default(0);
            $table->decimal('factor_rate', 6, 4)->nullable();
            $table->decimal('daily_payment', 12, 2)->default(0);
            $table->date('start_date');
            $table->date('est_payoff_date')->nullable();
            $table->decimal('remaining_balance', 12, 2)->nullable();
            $table->unsignedTinyInteger('position_number')->default(1);
            $table->enum('source', ['self','reported','imported'])->default('reported');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('lead_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_merchant_positions');
    }
}
