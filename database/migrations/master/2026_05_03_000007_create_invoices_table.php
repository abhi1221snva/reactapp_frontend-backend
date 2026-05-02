<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateInvoicesTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('invoices', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('client_id');
            $table->string('stripe_invoice_id', 60)->unique();
            $table->string('stripe_subscription_id', 60)->nullable();
            $table->string('type', 20)->default('subscription');
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('amount_due')->default(0);
            $table->unsignedInteger('amount_paid')->default(0);
            $table->string('currency', 3)->default('usd');
            $table->string('hosted_invoice_url', 500)->nullable();
            $table->string('invoice_pdf_url', 500)->nullable();
            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('client_id', 'idx_invoices_client');
            $table->index('stripe_subscription_id', 'idx_invoices_sub');
        });
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('invoices');
    }
}
