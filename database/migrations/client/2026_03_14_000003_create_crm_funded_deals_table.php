<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmFundedDealsTable extends Migration
{
    public function up()
    {
        Schema::create('crm_funded_deals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('lender_id')->nullable();
            $table->string('lender_name', 200)->nullable();
            $table->decimal('funded_amount', 12, 2)->default(0);
            $table->decimal('factor_rate', 6, 4)->default(1.0000);
            $table->unsignedInteger('term_days')->default(0);
            $table->decimal('total_payback', 12, 2)->default(0);
            $table->decimal('daily_payment', 12, 2)->default(0);
            $table->date('funding_date')->nullable();
            $table->date('first_debit_date')->nullable();
            $table->string('contract_number', 100)->nullable();
            $table->string('wire_confirmation', 200)->nullable();
            $table->date('renewal_eligible_at')->nullable();
            $table->enum('status', ['funded','in_repayment','paid_off','defaulted','renewed'])->default('funded');
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index('lead_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_funded_deals');
    }
}
