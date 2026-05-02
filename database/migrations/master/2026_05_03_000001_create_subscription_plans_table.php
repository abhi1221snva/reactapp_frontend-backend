<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateSubscriptionPlansTable extends Migration
{
    public function up()
    {
        Schema::connection('master')->create('subscription_plans', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug', 30)->unique();
            $table->string('name', 60);
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 8, 2)->default(0);
            $table->decimal('price_annual', 8, 2)->default(0);
            $table->unsignedInteger('max_agents')->default(0);          // 0 = unlimited
            $table->unsignedInteger('max_calls_monthly')->default(0);   // 0 = unlimited
            $table->unsignedInteger('max_sms_monthly')->default(0);     // 0 = unlimited

            // Feature flags
            $table->boolean('has_predictive_dialer')->default(false);
            $table->boolean('has_full_crm')->default(false);
            $table->boolean('has_api_access')->default(false);
            $table->boolean('has_ai_coaching')->default(false);
            $table->boolean('has_custom_integrations')->default(false);
            $table->boolean('has_sso')->default(false);
            $table->boolean('has_dedicated_csm')->default(false);
            $table->boolean('has_white_label')->default(false);
            $table->boolean('has_on_premise')->default(false);
            $table->boolean('has_compliance_packages')->default(false);

            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('display_order')->default(0);
            $table->unsignedSmallInteger('trial_days')->default(14);
            $table->timestamps();
        });

        // Seed the 4 default plans
        DB::connection('master')->table('subscription_plans')->insert([
            [
                'slug'                   => 'starter',
                'name'                   => 'Starter',
                'description'            => 'For small teams getting started with outbound calling.',
                'price_monthly'          => 49.00,
                'price_annual'           => 39.00,
                'max_agents'             => 5,
                'max_calls_monthly'      => 1000,
                'max_sms_monthly'        => 500,
                'has_predictive_dialer'  => false,
                'has_full_crm'           => true,
                'has_api_access'         => false,
                'has_ai_coaching'        => false,
                'has_custom_integrations'=> false,
                'has_sso'                => false,
                'has_dedicated_csm'      => false,
                'has_white_label'        => false,
                'has_on_premise'         => false,
                'has_compliance_packages'=> false,
                'is_active'              => true,
                'display_order'          => 1,
                'trial_days'             => 14,
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
            [
                'slug'                   => 'growth',
                'name'                   => 'Growth',
                'description'            => 'Scale your outreach with advanced dialing and CRM.',
                'price_monthly'          => 99.00,
                'price_annual'           => 79.00,
                'max_agents'             => 25,
                'max_calls_monthly'      => 10000,
                'max_sms_monthly'        => 5000,
                'has_predictive_dialer'  => true,
                'has_full_crm'           => true,
                'has_api_access'         => true,
                'has_ai_coaching'        => false,
                'has_custom_integrations'=> false,
                'has_sso'                => false,
                'has_dedicated_csm'      => false,
                'has_white_label'        => false,
                'has_on_premise'         => false,
                'has_compliance_packages'=> false,
                'is_active'              => true,
                'display_order'          => 2,
                'trial_days'             => 14,
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
            [
                'slug'                   => 'pro',
                'name'                   => 'Pro',
                'description'            => 'The complete platform for high-performance teams.',
                'price_monthly'          => 199.00,
                'price_annual'           => 159.00,
                'max_agents'             => 0,   // unlimited
                'max_calls_monthly'      => 0,   // unlimited
                'max_sms_monthly'        => 0,   // unlimited
                'has_predictive_dialer'  => true,
                'has_full_crm'           => true,
                'has_api_access'         => true,
                'has_ai_coaching'        => true,
                'has_custom_integrations'=> true,
                'has_sso'                => true,
                'has_dedicated_csm'      => true,
                'has_white_label'        => false,
                'has_on_premise'         => false,
                'has_compliance_packages'=> true,
                'is_active'              => true,
                'display_order'          => 3,
                'trial_days'             => 14,
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
            [
                'slug'                   => 'enterprise',
                'name'                   => 'Enterprise',
                'description'            => 'Tailored solutions for large organizations.',
                'price_monthly'          => 0.00,  // custom pricing
                'price_annual'           => 0.00,
                'max_agents'             => 0,     // unlimited
                'max_calls_monthly'      => 0,     // unlimited
                'max_sms_monthly'        => 0,     // unlimited
                'has_predictive_dialer'  => true,
                'has_full_crm'           => true,
                'has_api_access'         => true,
                'has_ai_coaching'        => true,
                'has_custom_integrations'=> true,
                'has_sso'                => true,
                'has_dedicated_csm'      => true,
                'has_white_label'        => true,
                'has_on_premise'         => true,
                'has_compliance_packages'=> true,
                'is_active'              => true,
                'display_order'          => 4,
                'trial_days'             => 14,
                'created_at'             => now(),
                'updated_at'             => now(),
            ],
        ]);
    }

    public function down()
    {
        Schema::connection('master')->dropIfExists('subscription_plans');
    }
}
