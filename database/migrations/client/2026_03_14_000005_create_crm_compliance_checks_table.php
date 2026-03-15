<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCrmComplianceChecksTable extends Migration
{
    public function up()
    {
        Schema::create('crm_compliance_checks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('lead_id');
            $table->enum('check_type', ['ofac','kyc','fraud_flag','credit_pull','background','sos_verification','custom']);
            $table->enum('result', ['pass','fail','pending','skipped'])->default('pending');
            $table->string('score', 50)->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->unsignedBigInteger('run_by')->nullable();
            $table->timestamps();
            $table->index('lead_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('crm_compliance_checks');
    }
}
