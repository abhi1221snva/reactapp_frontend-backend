<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSenderType extends Migration
{

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('smtp_setting', function (Blueprint $table) {
            $table->integer('user_id')->nullable()->default(null)->change();
        });

        Schema::table('smtp_setting', function (Blueprint $table) {
            $table->enum('sender_type', array(
                'system',
                'campaign',
                'user'
            ))->nullable();
            $table->unsignedInteger('campaign_id')->nullable();
        });

        Schema::table('smtp_setting', function (Blueprint $table) {
            $table->foreign('campaign_id')->references('id')->on('campaign');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('smtp_setting', function (Blueprint $table) {
            $table->dropColumn([
                "sender_type",
                "campaign_id"
            ]);
        });
    }
}
