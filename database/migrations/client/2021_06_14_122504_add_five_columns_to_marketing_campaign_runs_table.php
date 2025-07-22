<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddFiveColumnsToMarketingCampaignRunsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('marketing_campaign_runs', function (Blueprint $table) {
            $table->string('currency_code', 3)->comment('ISO 4217');
            $table->integer('client_package_id')->comment('Reference from master.permissions.client_package_id')->nullable();
            $table->integer('user_id')->comment('Ref from master.user.id')->nullable();
            $table->unsignedTinyInteger("isFree")->default(0)->comment('0–No, 1-Yes');
            $table->decimal('charge', 8,4)->unsigned();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('marketing_campaign_runs', function (Blueprint $table) {
            $table->dropColumn('currency_code');
            $table->dropColumn('client_package_id');
            $table->dropColumn('user_id');
            $table->dropColumn('isFree');
            $table->dropColumn('charge');
        });
    }
}
