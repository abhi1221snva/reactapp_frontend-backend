<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AlterCrmNotificationsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('crm_notifications', function (Blueprint $table) {
            // Make lead_id nullable
            if (Schema::hasColumn('crm_notifications', 'lead_id')) $table->unsignedBigInteger('lead_id')->nullable()->change();
            
            // Add new columns
            if (!Schema::hasColumn('crm_notifications', 'data')) $table->json('data')->nullable()->after('message');
            if (!Schema::hasColumn('crm_notifications', 'title')) $table->string('title')->nullable()->after('lead_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('crm_notifications', function (Blueprint $table) {
            // Revert lead_id to not null (CAUTION: might fail if null values exist)
            // We usually don't revert nullable->not null without data cleanup, 
            // but for strict reversal:
            // $table->unsignedBigInteger('lead_id')->nullable(false)->change(); 
            
            // For safety in dev environment, we'll leave it nullable or just drop built columns
            $table->dropColumn('data');
            $table->dropColumn('title');
        });
    }
}
