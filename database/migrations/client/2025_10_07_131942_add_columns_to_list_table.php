<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('list', function (Blueprint $table) {
                $table->boolean('duplicate_check')->default(0)->comment('1 = Yes, 0 = No')->after('type');
                $table->boolean('is_dialing')->default(0)->comment('1 = Yes, 0 = No')->after('duplicate_check');
                $table->integer('lead_count')->comment('Total number of leads')->after('is_dialing')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('list', function (Blueprint $table) {
            //
        });
    }
};
