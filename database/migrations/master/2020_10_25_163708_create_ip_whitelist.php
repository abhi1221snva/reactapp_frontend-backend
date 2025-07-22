<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateIpWhitelist extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        /*
            server_ip       - telephony server ip address
            whitelist_ip    - user ip address
            ip_location     - ip location
            from_web        - 0 - No / 1 - Yes
            client_id       - clients.id to whom approval needs to be sent
            approval_status - 0 pending, -1 denied, 1 approved
            last_login_user - user.id from where this login happened
            last_login_at   - last time when login was done from this IP
            created_at      - time when this record was added
            updated_at      - time when last updated
            updated_by      - user.id who approved/rejected this entry
         */
        Schema::create('ip_whitelists', function (Blueprint $table) {
            $table->ipAddress("server_ip");
            $table->ipAddress("whitelist_ip");
            $table->string("ip_location")->nullable();;
            $table->unsignedTinyInteger("from_web")->default(0);
            $table->unsignedInteger("client_id")->nullable();
            $table->tinyInteger("approval_status")->default(0);
            $table->unsignedInteger("last_login_user")->nullable();
            $table->dateTime("last_login_at")->nullable();
            $table->timestamps();
            $table->unsignedInteger("updated_by")->nullable();
            $table->primary(['server_ip','whitelist_ip']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('ip_whitelists');
    }
}
