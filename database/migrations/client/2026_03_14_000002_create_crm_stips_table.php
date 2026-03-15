<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmStipsTable extends Migration
{
    public function up()
    {
        Schema::create('crm_stips', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('lender_id')->nullable();
            $table->string('stip_name', 200);
            $table->enum('stip_type', ['bank_statement','voided_check','drivers_license','tax_return','lease_agreement','business_license','void_check','articles_of_incorporation','custom'])->default('custom');
            $table->enum('status', ['requested','uploaded','approved','rejected'])->default('requested');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('requested_by');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('lead_id');
            $table->index(['lead_id', 'lender_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_stips');
    }
}
