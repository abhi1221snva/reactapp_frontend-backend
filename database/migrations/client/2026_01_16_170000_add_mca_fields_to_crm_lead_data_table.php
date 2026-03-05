<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMcaFieldsToCrmLeadDataTable extends Migration
{
    /**
     * Run the migrations.
     * Adds MCA (Merchant Cash Advance) specific fields to the CRM lead data table
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_lead_data', function (Blueprint $table) {
            // Business Information
            $table->string('business_name', 255)->nullable()->after('company_name');
            $table->string('dba_name', 255)->nullable()->comment('Doing Business As');
            $table->string('ein_tax_id', 20)->nullable()->comment('Employer Identification Number');
            $table->date('business_start_date')->nullable();
            $table->string('industry_type', 100)->nullable();
            $table->string('business_type', 50)->nullable()->comment('LLC, Corp, Sole Prop, etc.');
            $table->string('business_address', 255)->nullable();
            $table->string('business_city', 100)->nullable();
            $table->string('business_state', 50)->nullable();
            $table->string('business_zip', 20)->nullable();

            // Financial Information
            $table->decimal('monthly_revenue', 15, 2)->nullable();
            $table->decimal('annual_revenue', 15, 2)->nullable();
            $table->decimal('average_daily_balance', 15, 2)->nullable();
            $table->decimal('monthly_deposits', 15, 2)->nullable();
            $table->integer('num_monthly_deposits')->nullable();
            $table->integer('num_nsf_last_90_days')->nullable()->comment('Non-Sufficient Funds count');
            $table->integer('num_negative_days')->nullable()->comment('Days with negative balance');

            // Funding Request Details
            $table->decimal('requested_amount', 15, 2)->nullable();
            $table->decimal('approved_amount', 15, 2)->nullable();
            $table->decimal('funded_amount', 15, 2)->nullable();
            $table->decimal('factor_rate', 5, 3)->nullable()->comment('e.g., 1.35');
            $table->decimal('payback_amount', 15, 2)->nullable();
            $table->decimal('daily_payment', 15, 2)->nullable();
            $table->decimal('weekly_payment', 15, 2)->nullable();
            $table->integer('term_length')->nullable()->comment('Term in months');
            $table->string('payment_frequency', 20)->nullable()->comment('daily, weekly, monthly');
            $table->date('funding_date')->nullable();
            $table->date('first_payment_date')->nullable();
            $table->date('estimated_payoff_date')->nullable();

            // Banking Information
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_account_last4', 4)->nullable()->comment('Last 4 digits only');
            $table->string('bank_routing_last4', 4)->nullable()->comment('Last 4 digits only');
            $table->integer('time_with_bank')->nullable()->comment('Months with current bank');

            // Position/Stacking Information
            $table->integer('current_positions')->default(0)->comment('Number of current MCA positions');
            $table->decimal('total_outstanding_balance', 15, 2)->nullable();
            $table->decimal('total_daily_payments', 15, 2)->nullable()->comment('Total daily from all positions');
            $table->boolean('has_existing_mca')->default(false);
            $table->text('stacking_details')->nullable()->comment('JSON with existing position details');

            // Owner Information
            $table->string('owner_name', 255)->nullable();
            $table->string('owner_ssn_last4', 4)->nullable()->comment('Last 4 digits only');
            $table->string('credit_score_range', 20)->nullable()->comment('e.g., 650-700');
            $table->integer('ownership_percentage')->nullable();
            $table->date('owner_dob')->nullable();
            $table->string('owner_address', 255)->nullable();
            $table->string('owner_city', 100)->nullable();
            $table->string('owner_state', 50)->nullable();
            $table->string('owner_zip', 20)->nullable();

            // Second Owner (if applicable)
            $table->string('owner2_name', 255)->nullable();
            $table->string('owner2_ssn_last4', 4)->nullable();
            $table->integer('owner2_percentage')->nullable();

            // ISO/Broker Information
            $table->unsignedBigInteger('iso_id')->nullable()->comment('ISO/Broker ID');
            $table->string('iso_name', 255)->nullable();
            $table->decimal('commission_rate', 5, 2)->nullable()->comment('Commission percentage');
            $table->decimal('commission_amount', 15, 2)->nullable();
            $table->boolean('commission_paid')->default(false);
            $table->date('commission_paid_date')->nullable();

            // Document Tracking
            $table->boolean('has_bank_statements')->default(false);
            $table->boolean('has_application')->default(false);
            $table->boolean('has_drivers_license')->default(false);
            $table->boolean('has_voided_check')->default(false);
            $table->boolean('has_tax_returns')->default(false);
            $table->boolean('has_business_license')->default(false);
            $table->timestamp('docs_requested_at')->nullable();
            $table->timestamp('docs_received_at')->nullable();

            // Underwriting
            $table->timestamp('submitted_to_underwriting_at')->nullable();
            $table->timestamp('underwriting_completed_at')->nullable();
            $table->string('underwriting_decision', 20)->nullable()->comment('approved, declined, conditional');
            $table->text('underwriting_notes')->nullable();
            $table->string('decline_reason', 255)->nullable();

            // Contract
            $table->timestamp('contract_sent_at')->nullable();
            $table->timestamp('contract_signed_at')->nullable();
            $table->string('contract_status', 20)->nullable();

            // MCA Pipeline Stage
            $table->string('mca_stage', 50)->nullable()->comment('new, docs_requested, docs_received, underwriting, approved, contract_sent, funded, declined, dead');
            $table->timestamp('mca_stage_updated_at')->nullable();

            // Renewal/Reoffer
            $table->boolean('is_renewal')->default(false);
            $table->unsignedBigInteger('original_deal_id')->nullable();
            $table->integer('renewal_number')->default(0);
            $table->date('eligible_for_renewal_date')->nullable();

            // Indexes for common queries
            $table->index('mca_stage');
            $table->index('funding_date');
            $table->index('iso_id');
            $table->index(['mca_stage', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_lead_data', function (Blueprint $table) {
            $columns = [
                'business_name', 'dba_name', 'ein_tax_id', 'business_start_date', 'industry_type',
                'business_type', 'business_address', 'business_city', 'business_state', 'business_zip',
                'monthly_revenue', 'annual_revenue', 'average_daily_balance', 'monthly_deposits',
                'num_monthly_deposits', 'num_nsf_last_90_days', 'num_negative_days',
                'requested_amount', 'approved_amount', 'funded_amount', 'factor_rate', 'payback_amount',
                'daily_payment', 'weekly_payment', 'term_length', 'payment_frequency',
                'funding_date', 'first_payment_date', 'estimated_payoff_date',
                'bank_name', 'bank_account_last4', 'bank_routing_last4', 'time_with_bank',
                'current_positions', 'total_outstanding_balance', 'total_daily_payments',
                'has_existing_mca', 'stacking_details',
                'owner_name', 'owner_ssn_last4', 'credit_score_range', 'ownership_percentage',
                'owner_dob', 'owner_address', 'owner_city', 'owner_state', 'owner_zip',
                'owner2_name', 'owner2_ssn_last4', 'owner2_percentage',
                'iso_id', 'iso_name', 'commission_rate', 'commission_amount', 'commission_paid', 'commission_paid_date',
                'has_bank_statements', 'has_application', 'has_drivers_license', 'has_voided_check',
                'has_tax_returns', 'has_business_license', 'docs_requested_at', 'docs_received_at',
                'submitted_to_underwriting_at', 'underwriting_completed_at', 'underwriting_decision',
                'underwriting_notes', 'decline_reason',
                'contract_sent_at', 'contract_signed_at', 'contract_status',
                'mca_stage', 'mca_stage_updated_at',
                'is_renewal', 'original_deal_id', 'renewal_number', 'eligible_for_renewal_date'
            ];

            $table->dropIndex(['mca_stage']);
            $table->dropIndex(['funding_date']);
            $table->dropIndex(['iso_id']);
            $table->dropIndex(['mca_stage', 'created_at']);

            $table->dropColumn($columns);
        });
    }
}
