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
        Schema::table('prospects', function (Blueprint $table) {
              $table->string('country_code', 4)->nullable()->change();
            $table->string('password', 255)->nullable()->change();
            $table->string('mobile', 20)->nullable()->change();
            $table->char('mobile_otp', 36)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prospects', function (Blueprint $table) {
            //
        });
    }
};
