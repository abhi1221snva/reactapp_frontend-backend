<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateCallMatrixReportTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
   public function up(): void
    {
        Schema::create('call_matrix_report', function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->enum('report_type', ['lead', 'agent', 'summary']);
            $table->string('category', 100)->nullable();
            $table->string('score', 10)->nullable();
            $table->string('score_display', 10)->nullable();
            $table->text('notes')->nullable();

            // Summary specific fields
            $table->string('summary_emoji', 10)->nullable();
            $table->text('summary_description')->nullable();
            $table->text('coaching_description')->nullable();

            $table->integer('total_score')->nullable();
            $table->integer('max_score')->nullable();
            $table->decimal('percentage', 5, 2)->nullable();

            // Only for agent summary
            $table->decimal('average_score', 4, 2)->nullable();

            $table->unsignedBigInteger('cdr_id')->nullable();
            $table->timestamps();

       
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_matrix_report');
    }
}
