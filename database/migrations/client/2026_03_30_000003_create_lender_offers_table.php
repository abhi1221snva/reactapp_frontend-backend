<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores individual offers returned by external lenders (OnDeck, etc.).
 * One row per offer. Multiple offers can exist per lead/application.
 */
class CreateLenderOffersTable extends Migration
{
    public function up(): void
    {
        Schema::create('lender_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id')->index();
            $table->string('business_id', 100)->index();
            $table->string('lender_name', 50)->default('ondeck');
            $table->string('offer_id', 100)->nullable()->index();
            $table->string('product_type', 50)->nullable();       // term_loan | line_of_credit
            $table->decimal('loan_amount', 15, 2)->nullable();
            $table->integer('term_months')->nullable();
            $table->decimal('factor_rate', 10, 6)->nullable();     // centsOnDollar
            $table->decimal('apr', 10, 4)->nullable();
            $table->string('payment_frequency', 20)->nullable();   // Daily | Weekly | Monthly
            $table->decimal('payment_amount', 15, 2)->nullable();  // periodicPayment
            $table->decimal('origination_fee', 15, 2)->nullable();
            $table->decimal('total_payback', 15, 2)->nullable();
            $table->enum('status', ['active', 'confirmed', 'expired', 'declined'])->default('active')->index();
            $table->json('raw_offer')->nullable();
            $table->json('raw_pricing')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'lender_name', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lender_offers');
    }
}
