<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('crm_commission_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name', 200);
            $table->unsignedBigInteger('lender_id')->nullable();
            $table->string('deal_type', 50)->default('new');
            $table->string('commission_type', 30)->default('percentage');
            $table->decimal('value', 10, 4)->default(0);
            $table->decimal('min_funded_amount', 12, 2)->nullable();
            $table->decimal('max_funded_amount', 12, 2)->nullable();
            $table->decimal('split_agent_pct', 5, 2)->default(50.00);
            $table->string('agent_role', 30)->default('closer');
            $table->unsignedInteger('priority')->default(0);
            $table->tinyInteger('status')->default(1);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['status', 'deal_type']);
            $table->index('lender_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_commission_rules');
    }
};
