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
        Schema::table('ring_group', function (Blueprint $table) {
            DB::statement("ALTER TABLE ring_group 
            MODIFY receive_on ENUM('web_phone', 'mobile', 'desk_phone') 
            NOT NULL DEFAULT 'web_phone'");
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ring_group', function (Blueprint $table) {
            //
        });
    }
};
