<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePackages extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::dropIfExists('packages');
        Schema::create('packages', function (Blueprint $table) {
            $table->uuid('key')->primary();
            $table->string('name');
            $table->string('description');
            $table->unsignedTinyInteger('is_active')->default(0);
            $table->unsignedTinyInteger('is_trial')->default(0);
            $table->unsignedTinyInteger('display_order');

            #1 - B2B, 2 - B2C
            $table->unsignedTinyInteger('applicable_for');

            #website, portal
            $table->json('show_on');

            $table->json('modules');
            $table->string('currency_code', 3);
            $table->decimal('base_rate_monthly_billed', 5,2);
            $table->decimal('base_rate_quarterly_billed', 5,2);
            $table->decimal('base_rate_half_yearly_billed', 5,2);
            $table->decimal('base_rate_yearly_billed', 5,2);
            $table->decimal('call_rate_per_minute', 5,3);
            $table->decimal('rate_per_sms', 5,2);
            $table->decimal('rate_per_did', 5,2);
            $table->decimal('rate_per_fax', 5,2);
            $table->decimal('rate_per_email', 5,4);
            $table->integer('free_call_minute_monthly')->default(0)->comment('Free minutes per user per month');
            $table->integer('free_sms_monthly')->default(0)->comment('Free sms  per user per month');
            $table->integer('free_fax_monthly')->default(0)->comment('Free fax  per user per month');
            $table->integer('free_emails_monthly')->default(0)->comment('Free emails  per user per month');
            $table->integer('free_did_monthly')->default(0)->comment('Free did per user per month');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->default(DB::raw('CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP'));
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('packages');
    }
}
