<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTeamUserPresenceTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::connection('master')->create('team_user_presence', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->primary();
            $table->enum('status', ['online', 'away', 'busy', 'offline'])->default('offline');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('current_conversation_uuid', 36)->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::connection('master')->dropIfExists('team_user_presence');
    }
}
