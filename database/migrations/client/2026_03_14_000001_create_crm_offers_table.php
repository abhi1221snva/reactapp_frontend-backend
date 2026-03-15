<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmOffersTable extends Migration
{
    public function up()
    {
        Schema::create('crm_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('lender_id')->nullable();
            $table->string('lender_name', 200)->nullable();
            $table->decimal('offered_amount', 12, 2)->default(0);
            $table->decimal('factor_rate', 6, 4)->default(1.0000);
            $table->unsignedInteger('term_days')->default(0);
            $table->decimal('daily_payment', 12, 2)->default(0);
            $table->decimal('total_payback', 12, 2)->default(0);
            $table->json('stips_required')->nullable();
            $table->timestamp('offer_expires_at')->nullable();
            $table->enum('status', ['pending', 'received', 'accepted', 'declined', 'expired'])->default('pending');
            $table->string('decline_reason', 500)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index('lead_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_offers');
    }
}
