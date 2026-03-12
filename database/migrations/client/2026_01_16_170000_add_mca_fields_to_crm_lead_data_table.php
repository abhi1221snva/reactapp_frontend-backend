<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AddMcaFieldsToCrmLeadDataTable extends Migration
{
    /**
     * Helper: check if a named index exists on a table.
     */
    private function hasIndex(string $table, string $index): bool
    {
        $result = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]);
        return count($result) > 0;
    }

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
            if (!Schema::hasColumn('crm_lead_data', 'business_name')) $table->string('business_name', 255)->nullable()->after('company_name');
            if (!Schema::hasColumn('crm_lead_data', 'dba_name')) $table->string('dba_name', 255)->nullable()->comment('Doing Business As');
            if (!Schema::hasColumn('crm_lead_data', 'ein_tax_id')) $table->string('ein_tax_id', 20)->nullable()->comment('Employer Identification Number');
            if (!Schema::hasColumn('crm_lead_data', 'business_start_date')) $table->date('business_start_date')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'industry_type')) $table->string('industry_type', 100)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'business_type')) $table->string('business_type', 50)->nullable()->comment('LLC, Corp, Sole Prop, etc.');
            if (!Schema::hasColumn('crm_lead_data', 'business_address')) $table->string('business_address', 255)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'business_city')) $table->string('business_city', 100)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'business_state')) $table->string('business_state', 50)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'business_zip')) $table->string('business_zip', 20)->nullable();

            // Financial Information
            if (!Schema::hasColumn('crm_lead_data', 'monthly_revenue')) $table->decimal('monthly_revenue', 15, 2)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'annual_revenue')) $table->decimal('annual_revenue', 15, 2)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'average_daily_balance')) $table->decimal('average_daily_balance', 15, 2)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'monthly_deposits')) $table->decimal('monthly_deposits', 15, 2)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'num_monthly_deposits')) $table->integer('num_monthly_deposits')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'num_nsf_last_90_days')) $table->integer('num_nsf_last_90_days')->nullable()->comment('Non-Sufficient Funds count');
            if (!Schema::hasColumn('crm_lead_data', 'num_negative_days')) $table->integer('num_negative_days')->nullable()->comment('Days with negative balance');

            // Funding Request Details
            if (!Schema::hasColumn('crm_lead_data', 'requested_amount')) $table->decimal('requested_amount', 15, 2)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'approved_amount')) $table->decimal('approved_amount', 15, 2)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'funded_amount')) $table->decimal('funded_amount', 15, 2)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'factor_rate')) $table->decimal('factor_rate', 5, 3)->nullable()->comment('e.g., 1.35');
            if (!Schema::hasColumn('crm_lead_data', 'payback_amount')) $table->decimal('payback_amount', 15, 2)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'daily_payment')) $table->decimal('daily_payment', 15, 2)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'weekly_payment')) $table->decimal('weekly_payment', 15, 2)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'term_length')) $table->integer('term_length')->nullable()->comment('Term in months');
            if (!Schema::hasColumn('crm_lead_data', 'payment_frequency')) $table->string('payment_frequency', 20)->nullable()->comment('daily, weekly, monthly');
            if (!Schema::hasColumn('crm_lead_data', 'funding_date')) $table->date('funding_date')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'first_payment_date')) $table->date('first_payment_date')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'estimated_payoff_date')) $table->date('estimated_payoff_date')->nullable();

            // Banking Information
            if (!Schema::hasColumn('crm_lead_data', 'bank_name')) $table->string('bank_name', 100)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'bank_account_last4')) $table->string('bank_account_last4', 4)->nullable()->comment('Last 4 digits only');
            if (!Schema::hasColumn('crm_lead_data', 'bank_routing_last4')) $table->string('bank_routing_last4', 4)->nullable()->comment('Last 4 digits only');
            if (!Schema::hasColumn('crm_lead_data', 'time_with_bank')) $table->integer('time_with_bank')->nullable()->comment('Months with current bank');

            // Position/Stacking Information
            if (!Schema::hasColumn('crm_lead_data', 'current_positions')) $table->integer('current_positions')->default(0)->comment('Number of current MCA positions');
            if (!Schema::hasColumn('crm_lead_data', 'total_outstanding_balance')) $table->decimal('total_outstanding_balance', 15, 2)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'total_daily_payments')) $table->decimal('total_daily_payments', 15, 2)->nullable()->comment('Total daily from all positions');
            if (!Schema::hasColumn('crm_lead_data', 'has_existing_mca')) $table->boolean('has_existing_mca')->default(false);
            if (!Schema::hasColumn('crm_lead_data', 'stacking_details')) $table->text('stacking_details')->nullable()->comment('JSON with existing position details');

            // Owner Information
            if (!Schema::hasColumn('crm_lead_data', 'owner_name')) $table->string('owner_name', 255)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'owner_ssn_last4')) $table->string('owner_ssn_last4', 4)->nullable()->comment('Last 4 digits only');
            if (!Schema::hasColumn('crm_lead_data', 'credit_score_range')) $table->string('credit_score_range', 20)->nullable()->comment('e.g., 650-700');
            if (!Schema::hasColumn('crm_lead_data', 'ownership_percentage')) $table->integer('ownership_percentage')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'owner_dob')) $table->date('owner_dob')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'owner_address')) $table->string('owner_address', 255)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'owner_city')) $table->string('owner_city', 100)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'owner_state')) $table->string('owner_state', 50)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'owner_zip')) $table->string('owner_zip', 20)->nullable();

            // Second Owner (if applicable)
            if (!Schema::hasColumn('crm_lead_data', 'owner2_name')) $table->string('owner2_name', 255)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'owner2_ssn_last4')) $table->string('owner2_ssn_last4', 4)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'owner2_percentage')) $table->integer('owner2_percentage')->nullable();

            // ISO/Broker Information
            if (!Schema::hasColumn('crm_lead_data', 'iso_id')) $table->unsignedBigInteger('iso_id')->nullable()->comment('ISO/Broker ID');
            if (!Schema::hasColumn('crm_lead_data', 'iso_name')) $table->string('iso_name', 255)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'commission_rate')) $table->decimal('commission_rate', 5, 2)->nullable()->comment('Commission percentage');
            if (!Schema::hasColumn('crm_lead_data', 'commission_amount')) $table->decimal('commission_amount', 15, 2)->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'commission_paid')) $table->boolean('commission_paid')->default(false);
            if (!Schema::hasColumn('crm_lead_data', 'commission_paid_date')) $table->date('commission_paid_date')->nullable();

            // Document Tracking
            if (!Schema::hasColumn('crm_lead_data', 'has_bank_statements')) $table->boolean('has_bank_statements')->default(false);
            if (!Schema::hasColumn('crm_lead_data', 'has_application')) $table->boolean('has_application')->default(false);
            if (!Schema::hasColumn('crm_lead_data', 'has_drivers_license')) $table->boolean('has_drivers_license')->default(false);
            if (!Schema::hasColumn('crm_lead_data', 'has_voided_check')) $table->boolean('has_voided_check')->default(false);
            if (!Schema::hasColumn('crm_lead_data', 'has_tax_returns')) $table->boolean('has_tax_returns')->default(false);
            if (!Schema::hasColumn('crm_lead_data', 'has_business_license')) $table->boolean('has_business_license')->default(false);
            if (!Schema::hasColumn('crm_lead_data', 'docs_requested_at')) $table->timestamp('docs_requested_at')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'docs_received_at')) $table->timestamp('docs_received_at')->nullable();

            // Underwriting
            if (!Schema::hasColumn('crm_lead_data', 'submitted_to_underwriting_at')) $table->timestamp('submitted_to_underwriting_at')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'underwriting_completed_at')) $table->timestamp('underwriting_completed_at')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'underwriting_decision')) $table->string('underwriting_decision', 20)->nullable()->comment('approved, declined, conditional');
            if (!Schema::hasColumn('crm_lead_data', 'underwriting_notes')) $table->text('underwriting_notes')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'decline_reason')) $table->string('decline_reason', 255)->nullable();

            // Contract
            if (!Schema::hasColumn('crm_lead_data', 'contract_sent_at')) $table->timestamp('contract_sent_at')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'contract_signed_at')) $table->timestamp('contract_signed_at')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'contract_status')) $table->string('contract_status', 20)->nullable();

            // MCA Pipeline Stage
            if (!Schema::hasColumn('crm_lead_data', 'mca_stage')) $table->string('mca_stage', 50)->nullable()->comment('new, docs_requested, docs_received, underwriting, approved, contract_sent, funded, declined, dead');
            if (!Schema::hasColumn('crm_lead_data', 'mca_stage_updated_at')) $table->timestamp('mca_stage_updated_at')->nullable();

            // Renewal/Reoffer
            if (!Schema::hasColumn('crm_lead_data', 'is_renewal')) $table->boolean('is_renewal')->default(false);
            if (!Schema::hasColumn('crm_lead_data', 'original_deal_id')) $table->unsignedBigInteger('original_deal_id')->nullable();
            if (!Schema::hasColumn('crm_lead_data', 'renewal_number')) $table->integer('renewal_number')->default(0);
            if (!Schema::hasColumn('crm_lead_data', 'eligible_for_renewal_date')) $table->date('eligible_for_renewal_date')->nullable();
        });

        // Indexes for common queries — added outside closure to allow hasIndex check
        if (!$this->hasIndex('crm_lead_data', 'crm_lead_data_mca_stage_index')) {
            Schema::table('crm_lead_data', function (Blueprint $table) {
                $table->index('mca_stage');
            });
        }
        if (!$this->hasIndex('crm_lead_data', 'crm_lead_data_funding_date_index')) {
            Schema::table('crm_lead_data', function (Blueprint $table) {
                $table->index('funding_date');
            });
        }
        if (!$this->hasIndex('crm_lead_data', 'crm_lead_data_iso_id_index')) {
            Schema::table('crm_lead_data', function (Blueprint $table) {
                $table->index('iso_id');
            });
        }
        if (!$this->hasIndex('crm_lead_data', 'crm_lead_data_mca_stage_created_at_index')) {
            Schema::table('crm_lead_data', function (Blueprint $table) {
                $table->index(['mca_stage', 'created_at']);
            });
        }
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
