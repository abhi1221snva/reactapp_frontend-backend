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
        Schema::create('crm_agent_commissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('lead_id');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->string('agent_role', 30)->default('closer');
            $table->string('deal_type', 50)->default('new');
            $table->decimal('funded_amount', 12, 2)->default(0);
            $table->string('commission_type', 30);
            $table->decimal('commission_rate', 10, 4);
            $table->decimal('gross_commission', 12, 2)->default(0);
            $table->decimal('agent_commission', 12, 2)->default(0);
            $table->decimal('company_commission', 12, 2)->default(0);
            $table->decimal('override_amount', 12, 2)->default(0);
            $table->unsignedBigInteger('override_from')->nullable();
            $table->enum('status', ['pending', 'approved', 'paid', 'clawback'])->default('pending');
            $table->date('pay_period_start')->nullable();
            $table->date('pay_period_end')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->unsignedBigInteger('paid_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('deal_id');
            $table->index('lead_id');
            $table->index('agent_id');
            $table->index(['status', 'agent_id']);
            $table->index(['agent_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_agent_commissions');
    }
};
