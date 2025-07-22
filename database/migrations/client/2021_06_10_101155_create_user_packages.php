<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserPackages extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('user_packages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->integer('client_package_id')->comment('Ref from master.client_package.id');
            $table->integer('user_id')->nullable()->default(null)->comment('Ref from master.users.id (NULL means licence not assigned)');
            $table->integer('free_call_minutes')->default(0)->comment('Free minutes balance');
            $table->integer('free_sms')->default(0)->comment('Free sms balance');
            $table->integer('free_fax')->default(0)->comment('Free fax balance');
            $table->integer('free_emails')->default(0)->comment('Free emails balance');
            $table->timestamp('free_reset_time')->default(DB::raw('CURRENT_TIMESTAMP'))->comment('Time when freebies counter to reset');
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
        Schema::dropIfExists('user_packages');
    }
}
